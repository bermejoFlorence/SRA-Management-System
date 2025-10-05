<?php
// admin/rb_questions_fetch.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json');

$set_id   = isset($_GET['set_id'])   ? (int)$_GET['set_id']   : 0;
$story_id = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;

if ($set_id<=0 || $story_id<=0) { echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }

/* Guard: story belongs to **RB** set */
$sql = "SELECT s.story_id
        FROM stories s
        INNER JOIN story_sets ss ON ss.set_id = s.set_id AND ss.set_type = 'RB'
        WHERE s.story_id = ? AND s.set_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'prep_fail_guard']); exit; }
$stmt->bind_param('ii', $story_id, $set_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if (!$res || !$res->num_rows) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

/* Pull items (then choices per item, gaya ng PB) */
$out = [];
$q = "SELECT si.item_id, si.number, si.points, si.question_text
      FROM story_items si
      WHERE si.story_id = ?
      ORDER BY si.number ASC, si.item_id ASC";
$stmt = $conn->prepare($q);
if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'prep_fail_items']); exit; }
$stmt->bind_param('i', $story_id);
$stmt->execute();
$rs = $stmt->get_result();

while ($row = $rs->fetch_assoc()) {
  $item_id = (int)$row['item_id'];
  $choices = ['A'=>'','B'=>'','C'=>'','D'=>''];
  $key = 'A';

  $qc = "SELECT label, text, is_correct
         FROM story_choices
         WHERE item_id = ?
         ORDER BY sequence ASC";
  $sc = $conn->prepare($qc);
  if (!$sc) { echo json_encode(['ok'=>false,'error'=>'prep_fail_choices']); exit; }
  $sc->bind_param('i', $item_id);
  $sc->execute();
  $rc = $sc->get_result();
  while ($c = $rc->fetch_assoc()) {
    $L = strtoupper($c['label']);
    if (isset($choices[$L])) $choices[$L] = $c['text'];
    if ((int)$c['is_correct'] === 1) $key = $L;
  }
  $sc->close();

  $out[] = [
    'item_id'  => $item_id,
    'number'   => (int)$row['number'],
    'points'   => (int)($row['points'] ?? 1),
    'question' => $row['question_text'] ?? '',
    'choices'  => $choices,
    'key'      => $key
  ];
}
$stmt->close();

echo json_encode(['ok'=>true, 'items'=>$out]);
