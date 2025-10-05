<?php
// student/pb_story_fetch.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
$story_id   = isset($_GET['story_id'])   ? (int)$_GET['story_id']   : 0;
if ($attempt_id<=0 || $story_id<=0) { echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }

/* Validate attempt belongs to student and is PB in_progress */
$sql = "SELECT a.set_id
          FROM assessment_attempts a
         WHERE a.attempt_id=? AND a.student_id=? AND a.set_type='PB' AND a.status='in_progress'
         LIMIT 1";
if (!$st = $conn->prepare($sql)) { echo json_encode(['ok'=>false,'error'=>'prep_err']); exit; }
$st->bind_param('ii',$attempt_id,$student_id);
$st->execute();
$res = $st->get_result();
$row = $res ? $res->fetch_assoc() : null;
$st->close();
if (!$row) { echo json_encode(['ok'=>false,'error'=>'attempt_not_found']); exit; }
$set_id = (int)$row['set_id'];

/* Story must belong to that set */
$story = null;
if ($st = $conn->prepare("SELECT story_id, title, passage_html, image_path, word_count, sequence
                            FROM stories
                           WHERE story_id=? AND set_id=? AND status IN('published','active')
                           LIMIT 1")) {
  $st->bind_param('ii',$story_id,$set_id);
  $st->execute();
  $r = $st->get_result();
  $story = $r ? $r->fetch_assoc() : null;
  $st->close();
}
if (!$story) { echo json_encode(['ok'=>false,'error'=>'story_not_found']); exit; }

/* Ensure attempt_stories row exists (created at reading stage) */
$attempt_story_id = null;
if ($st = $conn->prepare("SELECT attempt_story_id FROM attempt_stories WHERE attempt_id=? AND story_id=? LIMIT 1")) {
  $st->bind_param('ii',$attempt_id,$story_id);
  $st->execute(); $r = $st->get_result();
  $row = $r ? $r->fetch_assoc() : null;
  $st->close();
  if ($row) $attempt_story_id = (int)$row['attempt_story_id'];
}
if (!$attempt_story_id) {
  if ($st = $conn->prepare("INSERT INTO attempt_stories(attempt_id, story_id, sequence, reading_seconds)
                            VALUES(?, ?, ?, 0)")) {
    $seq = (int)($story['sequence'] ?? 0);
    $st->bind_param('iii',$attempt_id,$story_id,$seq);
    $st->execute();
    $attempt_story_id = (int)$conn->insert_id;
    $st->close();
  }
}

/* Position in set (Story X of N) */
$pos = 1; $total = 1;
if ($st = $conn->prepare("SELECT story_id FROM stories WHERE set_id=? AND status IN('published','active') ORDER BY sequence ASC, story_id ASC")) {
  $st->bind_param('i',$set_id); $st->execute();
  $rs = $st->get_result(); $arr=[];
  while($r=$rs->fetch_assoc()) $arr[] = (int)$r['story_id'];
  $total = count($arr);
  $pos = 1 + array_search($story_id, $arr, true);
  $st->close();
}

/* Items + choices */
$items = [];
if ($st = $conn->prepare("SELECT item_id, number, question_text FROM story_items WHERE story_id=? ORDER BY number ASC, item_id ASC")) {
  $st->bind_param('i',$story_id);
  $st->execute();
  $rs = $st->get_result();
  while($it = $rs->fetch_assoc()){
    $iid = (int)$it['item_id'];
    $choices = [];
    if ($sc = $conn->prepare("SELECT label, text FROM story_choices WHERE item_id=? ORDER BY sequence ASC, choice_id ASC")) {
      $sc->bind_param('i',$iid);
      $sc->execute(); $rc = $sc->get_result();
      while($c=$rc->fetch_assoc()){
        $choices[] = ['label'=>$c['label'], 'text'=>$c['text']];
      }
      $sc->close();
    }
    $items[] = [
      'item_id'=>(int)$it['item_id'],
      'number' =>(int)$it['number'],
      'question_text'=>$it['question_text'] ?? '',
      'choices'=>$choices
    ];
  }
  $st->close();
}

echo json_encode([
  'ok'=>true,
  'story'=>[
    'story_id'=>(int)$story['story_id'],
    'title'=>$story['title'] ?? 'Story',
    'passage_html'=>$story['passage_html'] ?? '',
    'image'=>$story['image_path'] ?? null,
    'word_count'=>(int)($story['word_count'] ?? 0),
    'pos'=>$pos, 'total'=>$total,
    'attempt_story_id'=>$attempt_story_id
  ],
  'items'=>$items
], JSON_UNESCAPED_UNICODE);
