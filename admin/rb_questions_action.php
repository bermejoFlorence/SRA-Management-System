<?php
// admin/rb_questions_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---- flash + redirect helpers ---- */
function rb_flash_set($type, $msg){ $_SESSION['rb_flash']=['t'=>$type,'m'=>$msg]; }
function redirect_back($set_id){
  header('Location: rb_manage.php?set_id='.(int)$set_id);
  exit;
}
function clean_str($s){ return trim(preg_replace('/\s+/',' ', (string)$s)); }

/* ---- guard ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  rb_flash_set('err','Invalid request method.');
  redirect_back($_POST['set_id'] ?? 0);
}
$action   = $_POST['action']   ?? '';
$set_id   = (int)($_POST['set_id']   ?? 0);
$story_id = (int)($_POST['story_id'] ?? 0);
if ($action !== 'batch_upsert' || $set_id<=0 || $story_id<=0){
  rb_flash_set('err','Missing or invalid parameters.');
  redirect_back($set_id);
}

/* ---- optional CSRF (enable if you send csrf_token) ---- */
// if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
//   rb_flash_set('err','Security token mismatch.');
//   redirect_back($set_id);
// }

/* ---- verify: story belongs to this RB set ---- */
$okStory = false;
if ($stmt = $conn->prepare("
  SELECT 1
  FROM stories s
  JOIN story_sets ss ON ss.set_id=s.set_id
  WHERE s.story_id=? AND s.set_id=? AND ss.set_type='RB'
  LIMIT 1
")){
  $stmt->bind_param('ii',$story_id,$set_id);
  $stmt->execute();
  $stmt->bind_result($d);
  $okStory = $stmt->fetch() ? true : false;
  $stmt->close();
}
if (!$okStory){
  rb_flash_set('err','Story not found for this RB set.');
  redirect_back($set_id);
}

/* ---- collect arrays ---- */
$item_ids        = $_POST['item_id']        ?? [];
$numbers         = $_POST['number']         ?? [];
$questions       = $_POST['question_text']  ?? [];
$correct_letters = $_POST['correct_letter'] ?? [];
$choice_a        = $_POST['choice_a']       ?? [];
$choice_b        = $_POST['choice_b']       ?? [];
$choice_c        = $_POST['choice_c']       ?? [];
$choice_d        = $_POST['choice_d']       ?? [];
$points_arr      = $_POST['points']         ?? [];
$delete_ids_csv  = $_POST['delete_ids']     ?? '';

$conn->begin_transaction();
try {
  /* --- delete requested items (and their choices) --- */
  $delIds = array_filter(array_map('intval', array_filter(array_map('trim', explode(',', $delete_ids_csv)))));
  if (!empty($delIds)) {
    $in     = implode(',', array_fill(0, count($delIds), '?'));
    $typesI = str_repeat('i', count($delIds));

    // delete choices first
    $sql = "DELETE c FROM story_choices c
            JOIN story_items i ON i.item_id=c.item_id
            WHERE i.story_id=? AND i.item_id IN ($in)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
    $bindTypes = 'i'.$typesI;
    $bindVals  = array_merge([$story_id], $delIds);
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $stmt->close();

    // delete items
    $sql = "DELETE FROM story_items WHERE story_id=? AND item_id IN ($in)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $stmt->close();
  }

  /* --- reusable statements (normalized like PB) --- */
  $insItem = $conn->prepare("
    INSERT INTO story_items (story_id, number, item_type, points, question_text, created_at, updated_at)
    VALUES (?, ?, 'single', ?, ?, NOW(), NOW())
  ");
  if (!$insItem) throw new Exception($conn->error);

  $updItem = $conn->prepare("
    UPDATE story_items
       SET number=?, points=?, question_text=?, updated_at=NOW()
     WHERE item_id=? AND story_id=?
  ");
  if (!$updItem) throw new Exception($conn->error);

  $delChoices = $conn->prepare("DELETE FROM story_choices WHERE item_id=?");
  if (!$delChoices) throw new Exception($conn->error);

  $insChoice = $conn->prepare("
    INSERT INTO story_choices (item_id, label, text, is_correct, sequence)
    VALUES (?, ?, ?, ?, ?)
  ");
  if (!$insChoice) throw new Exception($conn->error);

  /* --- upserts --- */
  $N = max(
    count($questions), count($item_ids), count($numbers), count($correct_letters),
    count($choice_a), count($choice_b), count($choice_c), count($choice_d), count($points_arr)
  );

  for ($i=0; $i<$N; $i++){
    $iid = (int)($item_ids[$i] ?? 0);
    $num = (int)($numbers[$i]  ?? ($i+1));
    $pts = (int)($points_arr[$i] ?? 1);
    $txt = clean_str($questions[$i] ?? '');
    $key = strtoupper(trim((string)($correct_letters[$i] ?? 'A')));

    $A = clean_str($choice_a[$i] ?? '');
    $B = clean_str($choice_b[$i] ?? '');
    $C = clean_str($choice_c[$i] ?? '');
    $D = clean_str($choice_d[$i] ?? '');

    // skip if totally empty
    if ($txt==='' && $A==='' && $B==='' && $C==='' && $D==='') continue;

    $map = ['A'=>$A,'B'=>$B,'C'=>$C,'D'=>$D];
    $nonEmpty = array_values(array_filter([$A,$B,$C,$D], fn($x)=>$x!==''));
    if ($txt==='' || count($nonEmpty)<2 || empty($map[$key])) {
      throw new Exception('Each item must have a question, at least 2 choices, and a valid answer key.');
    }

    if ($iid>0){
      $updItem->bind_param('iisii', $num, $pts, $txt, $iid, $story_id);
      $updItem->execute();
    } else {
      $insItem->bind_param('iiis', $story_id, $num, $pts, $txt);
      $insItem->execute();
      $iid = $insItem->insert_id;
    }

    // replace choices
    $delChoices->bind_param('i', $iid);
    $delChoices->execute();

    $labels = ['A','B','C','D'];
    foreach ($labels as $seq=>$L){
      $val = $map[$L];
      if ($val==='') continue;
      $is = ($L===$key) ? 1 : 0;
      $s  = $seq+1;
      $insChoice->bind_param('issii', $iid, $L, $val, $is, $s);
      $insChoice->execute();
    }
  }

  // close statements + commit
  $insItem->close();
  $updItem->close();
  $delChoices->close();
  $insChoice->close();

  $conn->commit();
  rb_flash_set('ok','Questions saved successfully.');
  redirect_back($set_id);

} catch (Throwable $e){
  $conn->rollback();
  rb_flash_set('err','Save failed: '.$e->getMessage());
  redirect_back($set_id);
}
