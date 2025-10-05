<?php
// student/slt_submit.php — finalize an SLT attempt by aggregating per-story results
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $student_id = (int)($_SESSION['user_id'] ?? 0);
  if ($student_id <= 0) throw new Exception('Auth required');

  // Read JSON body
  $raw  = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) throw new Exception('Bad payload');

  $attempt_id      = (int)($body['attempt_id'] ?? 0);
  $elapsed_seconds = (int)($body['elapsed_seconds'] ?? 0);
  if ($attempt_id <= 0) throw new Exception('Bad input');

  // Validate attempt ownership & type
  $q = $conn->prepare("
    SELECT student_id, set_type, status
      FROM assessment_attempts
     WHERE attempt_id = ?
     LIMIT 1
  ");
  $q->bind_param('i', $attempt_id);
  $q->execute();
  $att = $q->get_result()->fetch_assoc();
  $q->close();

  if (!$att || (int)$att['student_id'] !== $student_id || $att['set_type'] !== 'SLT') {
    throw new Exception('Invalid attempt');
  }

  // Aggregate per-story results
  $qa = $conn->prepare("
    SELECT
      COALESCE(SUM(score), 0)            AS total_score,
      COALESCE(SUM(max_score), 0)        AS total_max,
      COALESCE(SUM(reading_seconds), 0)  AS total_read_secs,
      COALESCE(AVG(NULLIF(wpm,0)), 0)    AS avg_wpm,
      COUNT(*)                            AS story_count
    FROM attempt_stories
    WHERE attempt_id = ?
  ");
  $qa->bind_param('i', $attempt_id);
  $qa->execute();
  $agg = $qa->get_result()->fetch_assoc();
  $qa->close();

  $total_score     = (int)($agg['total_score'] ?? 0);
  $total_max       = (int)($agg['total_max'] ?? 0);
  $total_read_secs = (int)($agg['total_read_secs'] ?? 0);
  $avg_wpm         = (float)($agg['avg_wpm'] ?? 0);
  $story_count     = (int)($agg['story_count'] ?? 0);

  if ($story_count <= 0) throw new Exception('No stories found for attempt');

  $percent = $total_max > 0 ? round(($total_score / $total_max) * 100, 2) : 0.0;

  // ---- Map percent to level (name + color) via thresholds ----
  $assigned_level_id   = null;
  $assigned_level_name = null;
  $assigned_color_hex  = null;

  $stmt = $conn->prepare("
    SELECT lt.level_id,
           lv.name      AS level_name,
           lv.color_hex AS color_hex
      FROM level_thresholds lt
      JOIN sra_levels lv ON lv.level_id = lt.level_id
     WHERE lt.applies_to = 'SLT'
       AND lt.min_percent <= ?
       AND (? <= IFNULL(lt.max_percent, 100))
     ORDER BY lt.min_percent DESC
     LIMIT 1
  ");
  $stmt->bind_param('dd', $percent, $percent);
  $stmt->execute();
  $band = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($band) {
    $assigned_level_id   = (int)$band['level_id'];
    $assigned_level_name = (string)$band['level_name'];
    $assigned_color_hex  = (string)$band['color_hex'];
  }

  // ---- Finalize attempt + write current level in a small transaction ----
  $conn->begin_transaction();

  // 1) finalize attempt
  $u = $conn->prepare("
    UPDATE assessment_attempts
       SET total_score = ?, total_max = ?, percent = ?, level_id = ?,
           status = 'submitted',
           submitted_at = NOW()
     WHERE attempt_id = ?
  ");
  $u->bind_param('iidii', $total_score, $total_max, $percent, $assigned_level_id, $attempt_id);
  $u->execute();
  $u->close();

  // 2) update student_level (current row)
  if ($assigned_level_id) {
    // mark previous rows as not-current
    $clear = $conn->prepare("UPDATE student_level SET is_current=0, current_flag=0 WHERE student_id=?");
    $clear->bind_param('i', $student_id);
    $clear->execute();
    $clear->close();

    // insert the new current level
    $ins = $conn->prepare("
      INSERT INTO student_level (student_id, level_id, source, assigned_at, is_current, current_flag)
      VALUES (?, ?, 'SLT', NOW(), 1, 1)
    ");
    $ins->bind_param('ii', $student_id, $assigned_level_id);
    $ins->execute();
    $ins->close();

    /* Optional: record a PB unlock (audit)
    $unlock = $conn->prepare("
      INSERT INTO module_unlocks (student_id, module_type, level_id, unlocked_at, reason)
      VALUES (?, 'PB', ?, NOW(), 'Unlocked by SLT')
    ");
    $unlock->bind_param('ii', $student_id, $assigned_level_id);
    $unlock->execute();
    $unlock->close();
    */
  }

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'summary' => [
      'total_score'      => $total_score,
      'total_max'        => $total_max,
      'percent'          => $percent,
      'stories'          => $story_count,
      'elapsed_seconds'  => max(0, $elapsed_seconds),
      'avg_wpm'          => (int)round($avg_wpm),
    ],
    'overall_pct'          => $percent,
    'assigned_level_id'    => $assigned_level_id,
    'assigned_level_name'  => $assigned_level_name,
    'assigned_color_hex'   => $assigned_color_hex,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn && $conn->errno === 0) { /* noop */ }
  // best-effort rollback
  if ($conn && $conn->connect_errno === 0) { @mysqli_rollback($conn); }
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
