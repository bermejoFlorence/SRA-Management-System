<?php
// admin/slt_questions_action.php (UPSERT)
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

function back_with_flash($ok,$msg,$set_id){
  $_SESSION['slt_flash'] = ['t'=>$ok?'ok':'err','m'=>$msg];
  header('Location: slt_manage.php?set_id='.(int)$set_id);
  exit;
}
function clean($v){ return trim((string)$v); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back_with_flash(false,'Invalid request method.', $_POST['set_id']??0);

$action   = $_POST['action']   ?? '';
$set_id   = (int)($_POST['set_id']   ?? 0);
$story_id = (int)($_POST['story_id'] ?? 0);
if ($action !== 'batch_upsert' || !$set_id || !$story_id) back_with_flash(false,'Missing parameters.', $set_id);

// verify story belongs to set (SLT)
$chk = $conn->prepare("
  SELECT s.story_id
  FROM stories s JOIN story_sets ss ON ss.set_id = s.set_id
  WHERE s.story_id=? AND s.set_id=? AND ss.set_type='SLT' LIMIT 1
");
$chk->bind_param('ii',$story_id,$set_id);
$chk->execute(); $res=$chk->get_result();
if (!$res || !$res->num_rows) back_with_flash(false,'Story not found.', $set_id);

// collect arrays
$ids = $_POST['item_id']        ?? [];
$qs  = $_POST['question_text']  ?? [];
$as  = $_POST['choice_a']       ?? [];
$bs  = $_POST['choice_b']       ?? [];
$cs  = $_POST['choice_c']       ?? [];
$ds  = $_POST['choice_d']       ?? [];
$ks  = $_POST['correct_letter'] ?? [];
$pts = $_POST['points']         ?? [];
$ord = $_POST['number']         ?? [];
$delete_csv = $_POST['delete_ids'] ?? '';

$N = is_array($qs) ? count($qs) : 0;
if ($N<=0 && $delete_csv==='') back_with_flash(false,'Nothing to save.', $set_id);

// build rows (validate first)
$rows=[]; $keepIds=[];
for ($i=0;$i<$N;$i++){
  $id = (int)($ids[$i] ?? 0);
  $q  = clean($qs[$i]  ?? '');
  $a  = clean($as[$i]  ?? '');
  $b  = clean($bs[$i]  ?? '');
  $c  = clean($cs[$i]  ?? '');
  $d  = clean($ds[$i]  ?? '');
  $kk = strtoupper(clean($ks[$i] ?? ''));
  $p  = (int)($pts[$i] ?? 1); if ($p<=0) $p=1;

  if ($q==='') back_with_flash(false,'Question #'.($i+1).' is empty.', $set_id);
  if (!in_array($kk,['A','B','C','D'],true)) back_with_flash(false,'Question #'.($i+1).': invalid correct letter.', $set_id);
  $map=['A'=>$a,'B'=>$b,'C'=>$c,'D'=>$d];
  $nonEmpty = array_values(array_filter($map, fn($t)=>$t!==''));
  if (count($nonEmpty)<2) back_with_flash(false,'Question #'.($i+1).': at least 2 choices required.', $set_id);
  if ($map[$kk]==='') back_with_flash(false,'Question #'.($i+1).': correct letter has empty choice.', $set_id);

  $rows[]=['item_id'=>$id,'number'=>($i+1),'points'=>$p,'q'=>$q,'choices'=>$map,'key'=>$kk];
  if ($id>0) $keepIds[$id]=true;
}

// deletions (remove any kept ids)
$delete_ids=[];
if ($delete_csv!==''){
  foreach (explode(',',$delete_csv) as $x){
    $v=(int)trim($x);
    if ($v>0 && empty($keepIds[$v])) $delete_ids[$v]=true;
  }
}
$delete_ids=array_keys($delete_ids);

// write
$conn->begin_transaction();
try{
  $stmtUpd = $conn->prepare("UPDATE story_items SET number=?, points=?, question_text=?, updated_at=NOW() WHERE item_id=? AND story_id=? LIMIT 1");
  $stmtIns = $conn->prepare("INSERT INTO story_items (story_id, number, item_type, points, question_text, created_at, updated_at) VALUES (?, ?, 'single', ?, ?, NOW(), NOW())");
  $stmtDelChoices = $conn->prepare("DELETE FROM story_choices WHERE item_id=?");
  $stmtInsChoice  = $conn->prepare("INSERT INTO story_choices (item_id, label, text, is_correct, sequence) VALUES (?, ?, ?, ?, ?)");

  // delete selected items
  if (!empty($delete_ids)){
    $in = implode(',', array_fill(0,count($delete_ids),'?'));
    $types = str_repeat('i', count($delete_ids)+1);
    $sql = "DELETE FROM story_items WHERE story_id=? AND item_id IN ($in)";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$types, $story_id], $delete_ids);
    $stmt->bind_param(...$params);
    $stmt->execute();
  }

  $ins=0; $upd=0;
  foreach ($rows as $r){
    $id=(int)$r['item_id']; $num=(int)$r['number']; $p=(int)$r['points']; $q=$r['q'];

    if ($id>0){
      $stmtUpd->bind_param('iisii',$num,$p,$q,$id,$story_id);
      if (!$stmtUpd->execute()) throw new Exception('Update item failed.');
      $stmtDelChoices->bind_param('i',$id); $stmtDelChoices->execute();
      $seq=0;
      foreach(['A','B','C','D'] as $L){
        $txt=$r['choices'][$L]; if($txt==='') continue;
        $seq++; $lab=strtolower($L); $ok=(int)($L===$r['key']);
        $stmtInsChoice->bind_param('issii',$id,$lab,$txt,$ok,$seq);
        if(!$stmtInsChoice->execute()) throw new Exception('Replace choice failed.');
      }
      $upd++;
    }else{
      $stmtIns->bind_param('iiis',$story_id,$num,$p,$q);
      if(!$stmtIns->execute()) throw new Exception('Insert item failed.');
      $newId=(int)$conn->insert_id;
      $seq=0;
      foreach(['A','B','C','D'] as $L){
        $txt=$r['choices'][$L]; if($txt==='') continue;
        $seq++; $lab=strtolower($L); $ok=(int)($L===$r['key']);
        $stmtInsChoice->bind_param('issii',$newId,$lab,$txt,$ok,$seq);
        if(!$stmtInsChoice->execute()) throw new Exception('Insert choice failed.');
      }
      $ins++;
    }
  }

  $touch=$conn->prepare("UPDATE stories SET updated_at=NOW() WHERE story_id=? LIMIT 1");
  $touch->bind_param('i',$story_id);
  $touch->execute();

  $conn->commit();
  $parts=[];
  if($ins) $parts[]="$ins inserted";
  if($upd) $parts[]="$upd updated";
  if(!empty($delete_ids)) $parts[]=(count($delete_ids))." deleted";
  back_with_flash(true, 'Saved: '.($parts?implode(', ',$parts):'no changes'), $set_id);

}catch(Throwable $e){
  $conn->rollback();
  back_with_flash(false, 'Save failed. '.$e->getMessage(), $set_id);
}
