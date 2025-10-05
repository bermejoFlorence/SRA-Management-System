<?php
// student/stories_sl_start.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ---------------- Detect AJAX/JSON mode ---------------- */
$isAjax = (
  (isset($_POST['ajax']) && $_POST['ajax'] === '1') ||
  (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);
if ($isAjax) {
  // Capture any stray output so we can return pure JSON
  ob_start();
  ini_set('display_errors', '0'); // don't print warnings into JSON
  error_reporting(E_ALL);         // still log warnings to error_log
}

/* ---------------- Guards ---------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['ok'=>false,'error'=>'POST required']);
    exit;
  }
  header('Location: stories_sl.php'); exit;
}

if (empty($_POST['ack'])) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['ok'=>false,'error'=>'Please confirm you have read the instructions.']);
    exit;
  }
  $_SESSION['flash_notice'] = 'Please confirm you have read the instructions.';
  header('Location: stories_sl.php'); exit;
}

$studentId = (int)($_SESSION['user_id'] ?? 0);
if ($studentId <= 0) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['ok'=>false,'error'=>'Auth required']);
    exit;
  }
  header('Location: ../login.php'); exit;
}

/* ---------------- Core transaction ---------------- */
mysqli_begin_transaction($conn);

try {
  // 0) Resolve up to TWO published SLT sets (these are your two "categories/slots")
  $setIds = [];
  $sqlSets = "
      SELECT set_id
        FROM story_sets
       WHERE set_type='SLT' AND status='published'
       ORDER BY sequence ASC, set_id ASC
       LIMIT 2
  ";
  if ($res = $conn->query($sqlSets)) {
    while ($row = $res->fetch_assoc()) { $setIds[] = (int)$row['set_id']; }
    $res->free();
  }
  if (!$setIds) { throw new Exception('No published SLT sets found.'); }

  // Choose a "primary" set_id to store on the attempt record (first published SLT set)
  $primarySetId = $setIds[0];

  // 1) Invalidate any existing in-progress SLT attempt (no resume allowed)
  $stmt = $conn->prepare("
      UPDATE assessment_attempts
         SET status='invalidated',
             remarks=CONCAT(COALESCE(remarks,''),' | auto-invalidated on new SLT start ', NOW())
       WHERE student_id=? AND set_type='SLT' AND status='in_progress'
  ");
  $stmt->bind_param('i', $studentId);
  $stmt->execute();
  $stmt->close();

  // 2) Create a fresh attempt (store primary set_id for audit/reference)
  $stmt = $conn->prepare("
      INSERT INTO assessment_attempts
        (student_id, set_id, set_type, level_id, total_score, total_max, percent, status, started_at)
      VALUES
        (?, ?, 'SLT', NULL, 0, 0, 0, 'in_progress', NOW())
  ");
  $stmt->bind_param('ii', $studentId, $primarySetId);
  $stmt->execute();
  $attemptId = (int)$conn->insert_id;
  $stmt->close();

  if ($attemptId <= 0) { throw new Exception('Failed to create attempt.'); }

  // 3) For each published SLT set, pick the FIRST active story (by sequence)
  $pickedStories = [];
  $stmt = $conn->prepare("
      SELECT story_id, COALESCE(sequence,0) AS sequence
        FROM stories
       WHERE set_id=? AND status='active'
       ORDER BY sequence ASC, story_id ASC
       LIMIT 1
  ");
  foreach ($setIds as $sid) {
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    if ($r = $stmt->get_result()) {
      if ($row = $r->fetch_assoc()) {
        $pickedStories[] = [
          'story_id' => (int)$row['story_id'],
          'sequence' => (int)$row['sequence'],
        ];
      }
      $r->free();
    }
  }
  $stmt->close();

  if (!$pickedStories) { throw new Exception('No active stories found inside the published SLT set(s).'); }

  // Order by their own sequence then story_id; assign attempt-local sequence 1..N
  usort($pickedStories, function($a,$b){
    return ($a['sequence'] <=> $b['sequence']) ?: ($a['story_id'] <=> $b['story_id']);
  });

  // 4) Queue the selected stories into attempt_stories (one per SLT set)
  $stmt = $conn->prepare("
    INSERT INTO attempt_stories
      (attempt_id, story_id, sequence, reading_seconds, wpm, score, max_score, percent)
    VALUES
      (?, ?, ?, 0, 0, 0, 0, 0)
  ");
  foreach ($pickedStories as $i => $ps) {
    $seq = $i + 1; // attempt-local order
    $sid = $ps['story_id'];
    $stmt->bind_param('iii', $attemptId, $sid, $seq);
    $stmt->execute();
  }
  $stmt->close();

  mysqli_commit($conn);

  /* ---------------- Respond ---------------- */
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['ok'=>true, 'attempt_id'=>$attemptId]);
    exit;
  }

  // Non-AJAX: redirect to runner page (if you have one)
  header('Location: stories_sl_run.php?aid=' . $attemptId);
  exit;

} catch (Throwable $e) {
  mysqli_rollback($conn);

  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['ok'=>false, 'error'=>'Could not start the test.']);
    exit;
  }

  $_SESSION['flash_notice'] = 'Could not start the test. Please contact your teacher.';
  header('Location: stories_sl.php'); exit;
}
