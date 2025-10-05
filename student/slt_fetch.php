<?php
// student/slt_fetch.php — grouped-by-story + time limit info
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jerr($msg, $stage = '') {
  if (ob_get_length()) ob_clean();
  echo json_encode(['ok'=>false, 'error'=>$msg, 'stage'=>$stage], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $studentId = (int)($_SESSION['user_id'] ?? 0);
  if ($studentId <= 0) jerr('Auth required','auth');

  $attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
  if ($attemptId <= 0) jerr('Missing attempt_id','input');

  /* ---------- 0) Get DB "now" (single source of truth) ---------- */
  $rowNow = $conn->query("SELECT UNIX_TIMESTAMP(NOW()) AS now_ts")->fetch_assoc();
  $nowTs  = (int)($rowNow['now_ts'] ?? time());

  /* ---------- 1) Validate attempt & lock started_at on first load ---------- */
  $q = $conn->prepare("
    SELECT attempt_id, set_id, set_type, status,
           UNIX_TIMESTAMP(started_at) AS started_ts
      FROM assessment_attempts
     WHERE attempt_id=? AND student_id=?
     LIMIT 1
  ");
  $q->bind_param('ii', $attemptId, $studentId);
  $q->execute();
  $att = $q->get_result()->fetch_assoc();
  $q->close();

  if (!$att)                          jerr('Attempt not found','attempt-missing');
  if ($att['set_type'] !== 'SLT')     jerr('Attempt is not SLT','attempt-type');
  if ($att['status']  !== 'in_progress') jerr('Attempt not in progress','attempt-status');

  $startedTs = isset($att['started_ts']) ? (int)$att['started_ts'] : 0;
  if ($startedTs <= 0) {
    // first open: set started_at = NOW() atomically (only if still NULL)
    $upd = $conn->prepare("
      UPDATE assessment_attempts
         SET started_at = FROM_UNIXTIME(?), updated_at = NOW()
       WHERE attempt_id = ? AND student_id = ? AND started_at IS NULL
    ");
    $upd->bind_param('iii', $nowTs, $attemptId, $studentId);
    $upd->execute();
    $upd->close();
    $startedTs = $nowTs; // lock to DB time just written
  }

  /* ---------- 2) Load attempt stories (with story time_limit_seconds) ---------- */
  $stories = [];
  $storyIds = [];
  $totalLimitSec = 0;

// 2) Load attempt stories
$s = $conn->prepare("
  SELECT ats.attempt_story_id, ats.story_id, ats.sequence,
         COALESCE(s.title,'')              AS title,
         COALESCE(s.passage_html,'')       AS passage_html,
         COALESCE(s.image_path,'')         AS image_path,
         COALESCE(s.time_limit_seconds,0)  AS time_limit_seconds     -- NEW
    FROM attempt_stories ats
    JOIN stories s ON s.story_id = ats.story_id
   WHERE ats.attempt_id = ?
   ORDER BY ats.sequence ASC, ats.story_id ASC
");

  $s->bind_param('i', $attemptId);
  $s->execute();
  $rs = $s->get_result();
  while ($row = $rs->fetch_assoc()) {
    $sid = (int)$row['story_id'];
    $storyIds[] = $sid;

    $limitSec = (int)$row['time_limit_seconds'];
    $totalLimitSec += max(0, $limitSec);

$stories[] = [
  'attempt_story_id'  => (int)$row['attempt_story_id'],
  'story_id'          => $sid,
  'sequence'          => (int)$row['sequence'],
  'title'             => (string)$row['title'],
  'passage_html'      => (string)$row['passage_html'],
  'image'             => (string)$row['image_path'],
  'time_limit_seconds'=> (int)$row['time_limit_seconds'],   // NEW
  'items'             => []
];

  }
  $rs->free();
  $s->close();

  if (empty($stories) || empty($storyIds)) jerr('No stories queued for this attempt','stories-empty');

  /* ---------- 3) Load items for all stories ---------- */
  $storyIdsIn = implode(',', array_map('intval', $storyIds));

  $items = [];
  $itemIds = [];

  $sqlItems = "
    SELECT item_id, story_id,
           COALESCE(number, 0)          AS number,
           COALESCE(question_text, '')  AS question_text
      FROM story_items
     WHERE story_id IN ($storyIdsIn)
     ORDER BY story_id ASC, number ASC, item_id ASC
  ";
  $ri = $conn->query($sqlItems);
  while ($row = $ri->fetch_assoc()) {
    $iid = (int)$row['item_id'];
    $itemIds[] = $iid;
    $items[] = [
      'story_id'      => (int)$row['story_id'],
      'item_id'       => $iid,
      'passage'       => '',
      'question_text' => (string)$row['question_text'],
      'choices'       => [],
      'number'        => (int)$row['number']
    ];
  }
  $ri->free();

  if (empty($items)) jerr('No items found for these stories','items-empty');

  /* ---------- 4) Load choices for all items ---------- */
  $choicesByItem = [];
  if (!empty($itemIds)) {
    $itemIdsIn = implode(',', array_map('intval', $itemIds));
    $sqlChoices = "
      SELECT item_id, COALESCE(text,'') AS text
        FROM story_choices
       WHERE item_id IN ($itemIdsIn)
       ORDER BY item_id ASC, sequence ASC, choice_id ASC
    ";
    $rc = $conn->query($sqlChoices);
    while ($row = $rc->fetch_assoc()) {
      $iid = (int)$row['item_id'];
      if (!isset($choicesByItem[$iid])) $choicesByItem[$iid] = [];
      $choicesByItem[$iid][] = ['text' => (string)$row['text']];
    }
    $rc->free();
  }

  foreach ($items as &$it) {
    $it['choices'] = $choicesByItem[$it['item_id']] ?? [];
  }
  unset($it);

  /* ---------- 5) Group items into stories (preserve sequence) ---------- */
  $byId = [];
  foreach ($stories as $st) { $byId[$st['story_id']] = $st; }
  foreach ($items as $it) {
    $sid = $it['story_id'];
    if (!isset($byId[$sid])) continue;
    $byId[$sid]['items'][] = [
      'item_id'       => $it['item_id'],
      'number'        => $it['number'],
      'question_text' => $it['question_text'],
      'choices'       => $it['choices']
    ];
  }
  $storiesOut = [];
  foreach ($stories as $st) $storiesOut[] = $byId[$st['story_id']];

  /* ---------- 6) Compute attempt-level time window ---------- */
  // Single-story SLT → totalLimitSec is just that story’s limit.
  $limitSec = max(0, (int)$totalLimitSec);              // 0 means "no time limit"
  $deadlineTs = $limitSec > 0 ? ($startedTs + $limitSec) : null;
  $remaining  = $limitSec > 0 ? max(0, $deadlineTs - $nowTs) : null;
  $timeUp     = $limitSec > 0 ? ($nowTs >= $deadlineTs) : false;

  if (ob_get_length()) ob_clean();
  echo json_encode([
    'ok'       => true,
    'attempt'  => [
      'attempt_id' => (int)$attemptId,
      'set_id'     => (int)$att['set_id'],
    ],
    'time' => [
      'limit_seconds'      => $limitSec,
      'started_ts'         => $startedTs,   // UNIX timestamp
      'now_ts'             => $nowTs,       // UNIX timestamp (DB NOW())
      'deadline_ts'        => $deadlineTs,  // null if no limit
      'remaining_seconds'  => $remaining,   // null if no limit
      'time_up'            => $timeUp
    ],
    'stories'  => $storiesOut,  // grouped with items
    'items'    => $items        // legacy flat (kept for compatibility)
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if (ob_get_length()) ob_clean();
  echo json_encode([
    'ok'    => false,
    'error' => 'Unhandled: ' . $e->getMessage(),
    'code'  => $e->getCode()
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
