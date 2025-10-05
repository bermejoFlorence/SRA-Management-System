<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $student_id = (int)($_SESSION['user_id'] ?? 0);

  $attempt_id = (int)($_GET['attempt_id'] ?? 0);
  if ($attempt_id <= 0) throw new Exception('Bad input');

  // validate ownership
  $q = $conn->prepare("SELECT student_id, set_type, level_id, percent FROM assessment_attempts WHERE attempt_id=? LIMIT 1");
  $q->bind_param('i',$attempt_id); $q->execute();
  $att = $q->get_result()->fetch_assoc(); $q->close();
  if (!$att || (int)$att['student_id'] !== $student_id || $att['set_type'] !== 'SLT') throw new Exception('Invalid attempt');

  // assigned level info
  $lv = null;
  if (!empty($att['level_id'])) {
    $s = $conn->prepare("SELECT name AS level_name, color_hex FROM sra_levels WHERE level_id=? LIMIT 1");
    $s->bind_param('i',$att['level_id']); $s->execute();
    $lv = $s->get_result()->fetch_assoc(); $s->close();
  }

  // per-story breakdown
  $st = $conn->prepare("
      SELECT ats.story_id, st.title,
             ats.score, ats.max_score, ats.wpm, ats.reading_seconds
        FROM attempt_stories ats
        JOIN stories st ON st.story_id = ats.story_id
       WHERE ats.attempt_id = ?
       ORDER BY ats.sequence ASC, ats.attempt_story_id ASC
  ");
  $st->bind_param('i',$attempt_id); $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

  echo json_encode([
    'ok' => true,
    'overall_pct' => (float)$att['percent'],
    'assigned_level_id'   => (int)($att['level_id'] ?? 0),
    'assigned_level_name' => $lv['level_name'] ?? null,
    'assigned_color_hex'  => $lv['color_hex'] ?? null,
    'stories' => array_map(function($r){
      return [
        'story_id'   => (int)$r['story_id'],
        'title'      => $r['title'],
        'score'      => (int)$r['score'],
        'total'      => (int)$r['max_score'],
        'wpm'        => ($r['wpm']===null ? null : (int)$r['wpm']),
        'read_secs'  => (int)$r['reading_seconds'],
      ];
    }, $rows),
  ], JSON_UNESCAPED_UNICODE);

} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
