<?php
// admin/slt_questions_fetch.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$set_id   = (int)($_GET['set_id'] ?? 0);
$story_id = (int)($_GET['story_id'] ?? 0);
if ($set_id <= 0 || $story_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing parameters.']); exit; }

// verify story belongs to set (SLT)
$chk = $conn->prepare("
  SELECT s.story_id
  FROM stories s JOIN story_sets ss ON ss.set_id = s.set_id
  WHERE s.story_id=? AND s.set_id=? AND ss.set_type='SLT' LIMIT 1
");
$chk->bind_param('ii', $story_id, $set_id);
$chk->execute(); $res = $chk->get_result();
if (!$res || !$res->num_rows) { echo json_encode(['ok'=>false,'error'=>'Story not found.']); exit; }

$q = $conn->prepare("
  SELECT i.item_id, i.number, i.points, i.question_text,
         c.label, c.text AS choice_text, c.is_correct, c.sequence
  FROM story_items i
  LEFT JOIN story_choices c ON c.item_id = i.item_id
  WHERE i.story_id = ?
  ORDER BY i.number ASC, c.sequence ASC
");
$q->bind_param('i', $story_id);
$q->execute(); $rs = $q->get_result();

$rows = [];
while ($r = $rs->fetch_assoc()) {
  $id = (int)$r['item_id'];
  if (!isset($rows[$id])) {
    $rows[$id] = [
      'item_id'=>$id,
      'number'=>(int)$r['number'],
      'points'=>(int)$r['points'],
      'question'=>(string)$r['question_text'],
      'choices'=>['A'=>'','B'=>'','C'=>'','D'=>''],
      'key'=>null
    ];
  }
  if ($r['label'] !== null) {
    $L = strtoupper($r['label']);
    if (isset($rows[$id]['choices'][$L])) {
      $rows[$id]['choices'][$L] = (string)$r['choice_text'];
      if ((int)$r['is_correct'] === 1) $rows[$id]['key'] = $L;
    }
  }
}
echo json_encode(['ok'=>true,'items'=>array_values($rows)]);
