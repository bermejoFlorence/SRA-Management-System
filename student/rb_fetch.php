<?php
// student/rb_fetch.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student','../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');

$student_id = (int)($_SESSION['user_id'] ?? 0);
$attempt_id = (int)($_GET['attempt_id'] ?? 0);
if ($student_id <= 0 || $attempt_id <= 0) {
  echo json_encode(['ok' => false, 'error' => 'bad_request']); exit;
}

/*
 * 1) Kunin ang NEXT unsubmitted attempt_story para sa attempt na ’to.
 *    Since wala tayong per-story status/timestamps sa schema,
 *    gagamit tayo ng "unsubmitted = percent IS NULL".
 */
$sql = "
  SELECT
      ats.attempt_story_id,
      ats.story_id,
      s.title,
      s.author AS author_name,          -- ⬅️ BAGONG LINYA
      s.passage_html,
      s.image_path,
      COALESCE(s.time_limit_seconds, 0) AS time_limit_seconds
  FROM attempt_stories ats
  JOIN assessment_attempts a ON a.attempt_id = ats.attempt_id
  JOIN stories s            ON s.story_id      = ats.story_id
  WHERE ats.attempt_id = ? AND a.student_id = ? AND ats.percent IS NULL
  ORDER BY ats.sequence ASC, ats.attempt_story_id ASC
  LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $attempt_id, $student_id);
$stmt->execute();
$st = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$st) {
  // wala nang pending story sa attempt na ito
  echo json_encode(['ok' => false, 'error' => 'attempt_done']); exit;
}

$storyId = (int)$st['story_id'];
$limit   = (int)($st['time_limit_seconds'] ?? 0);

/*
 * 2) Load items + choices (A–D, etc.) mula sa story_items at story_choices.
 */
$items = [];

// items (questions)
$qi = $conn->prepare("
  SELECT si.item_id, si.number, si.question_text
  FROM story_items si
  WHERE si.story_id = ?
  ORDER BY si.number ASC, si.item_id ASC
");
$qi->bind_param('i', $storyId);
$qi->execute();
$res = $qi->get_result();
$itemsById = [];
while ($row = $res->fetch_assoc()) {
  $iid = (int)$row['item_id'];
  $itemsById[$iid] = [
    'item_id'  => $iid,
    'number'   => (int)$row['number'],
    'question' => $row['question_text'],
    'choices'  => [],  // pupunuin sa susunod na query
  ];
}
$qi->close();

if ($itemsById) {
  // choices per item (label/text) mula sa story_choices
  $ids = array_map('intval', array_keys($itemsById));
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $sqlC = "
    SELECT sc.item_id, sc.label, sc.text
    FROM story_choices sc
    WHERE sc.item_id IN ($in)
    ORDER BY sc.item_id ASC, sc.sequence ASC, sc.choice_id ASC
  ";
  $stmtC = $conn->prepare($sqlC);
  $stmtC->bind_param($types, ...$ids);
  $stmtC->execute();
  $rc = $stmtC->get_result();
  while ($c = $rc->fetch_assoc()) {
    $iid = (int)$c['item_id'];
    if (!isset($itemsById[$iid])) continue;
    $label = strtoupper(trim((string)$c['label']));
    $txt   = (string)$c['text'];
    // ensure label like A/B/C/D; fallback to generated letter if empty
    if ($label === '') $label = chr(65 + count($itemsById[$iid]['choices']));
    $itemsById[$iid]['choices'][] = ['label' => $label, 'text' => $txt];
  }
  $stmtC->close();

  // finalize ordered list
  usort($itemsById, function($a,$b){
    return ($a['number'] <=> $b['number']) ?: ($a['item_id'] <=> $b['item_id']);
  });
  $items = array_values($itemsById);
}

echo json_encode([
  'ok' => true,
  'story' => [
    'attempt_story_id' => (int)$st['attempt_story_id'],
    'story_id'         => $storyId,
    'title'            => $st['title'],
    'author'           => $st['author_name'] ?? null,   // ⬅️
    'passage_html'     => $st['passage_html'],
    'image'            => $st['image_path'],
    'quiz_started'     => false,   // walang per-story timestamps sa schema
    'time_limit'       => $limit,  // seconds (0 => unlimited)
    // 'time_left'      => null,   // hindi supported nang walang started_quiz_at
  ],
  'items' => $items
]);
