<?php
// admin/rb_stories_action.php

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

/* ---------- helpers ---------- */
function flash_set($type,$msg){ $_SESSION['rb_flash']=['t'=>$type,'m'=>$msg]; }
function csrf_ok(){ return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']); }
function back_to_manage($set_id){
  header('Location: rb_manage.php?set_id='.(int)$set_id); exit;
}
function back_to_index(){ header('Location: stories_rb.php'); exit; }

$action = $_POST['action'] ?? '';
$set_id = (int)($_POST['set_id'] ?? 0);
if ($set_id <= 0) { flash_set('err','Invalid set.'); back_to_index(); }

/* ---------- ensure set exists and is RB ---------- */
$okSet = false;
if ($stmt = $conn->prepare("SELECT 1 FROM story_sets WHERE set_id=? AND set_type='RB' LIMIT 1")) {
  $stmt->bind_param('i',$set_id); $stmt->execute(); $stmt->store_result();
  $okSet = $stmt->num_rows > 0; $stmt->close();
}
if (!$okSet) { flash_set('err','RB set not found.'); back_to_index(); }

/* upload helper */
function rb_handle_upload(&$error_msg){
  $error_msg=null;
  if (empty($_FILES['cover_image']['name']) || !is_uploaded_file($_FILES['cover_image']['tmp_name'])) return null;
  $f=$_FILES['cover_image']; if ($f['error'] !== UPLOAD_ERR_OK){ $error_msg='Image upload failed (code '.$f['error'].').'; return false; }
  if ($f['size'] > 3*1024*1024){ $error_msg='Image too large (max 3MB).'; return false; }
  $mime=null;
  if (function_exists('finfo_open')){ $fi=finfo_open(FILEINFO_MIME_TYPE); if($fi){ $mime=finfo_file($fi,$f['tmp_name']); finfo_close($fi);} }
  if(!$mime && function_exists('getimagesize')){ $info=@getimagesize($f['tmp_name']); if($info && isset($info['mime'])) $mime=$info['mime']; }
  if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){ $error_msg='Unsupported image type.'; return false; }
  $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION) ?: '');
  if(!in_array($ext,['jpg','jpeg','png','webp','gif'],true)){
    $ext = $mime==='image/png'?'png':($mime==='image/webp'?'webp':($mime==='image/gif'?'gif':'jpg'));
  }
  $dirAbs=__DIR__ . '/../uploads/rb_covers'; if(!is_dir($dirAbs)) @mkdir($dirAbs,0775,true);
  $name='rb_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
  $dest=$dirAbs.'/'.$name;
  if(!move_uploaded_file($f['tmp_name'],$dest)){ $error_msg='Failed to move uploaded image.'; return false; }
  return '/uploads/rb_covers/'.$name;
}

/* ======================================================
   ADD STORY
   ====================================================== */
if ($action === 'add_story') {
  if (!csrf_ok()){ flash_set('err','Invalid request.'); back_to_manage($set_id); }

  $title   = trim((string)($_POST['title'] ?? ''));
  $status  = $_POST['status'] ?? 'draft';
  $allowed = ['draft','published','archived'];
  if (!in_array($status,$allowed,true)) $status='draft';
  $passage = (string)($_POST['passage_html'] ?? '');

  // UI is minutes → store as seconds (0/blank = NULL)
  $mins = isset($_POST['time_limit_minutes']) && $_POST['time_limit_minutes'] !== ''
        ? max(0, (int)$_POST['time_limit_minutes']) : 0;
  $tls  = $mins * 60; // time_limit_seconds

  if ($title === '') { flash_set('err','Title is required.'); back_to_manage($set_id); }

  // duplicate title within set?
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE set_id=? AND title=? LIMIT 1")) {
    $stmt->bind_param('is',$set_id,$title); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { $stmt->close(); flash_set('err','A story with the same title already exists.'); back_to_manage($set_id); }
    $stmt->close();
  }

  $img_err=null; $image_path = rb_handle_upload($img_err);
  if ($image_path === false) { flash_set('err',$img_err ?? 'Image upload error.'); back_to_manage($set_id); }

  if ($stmt = $conn->prepare("
    INSERT INTO stories
      (set_id, title, passage_html, image_path, time_limit_seconds, status, created_at, updated_at)
    VALUES
      (      ?,     ?,            ?,         ?,   NULLIF(?,0),     ?,       NOW(),      NOW())
  ")) {
    $stmt->bind_param('isssis', $set_id, $title, $passage, $image_path, $tls, $status);
    if ($stmt->execute()) flash_set('ok','Story saved.');
    else flash_set('err','Insert failed: '.$conn->error);
    $stmt->close();
  } else {
    flash_set('err','Insert failed: '.$conn->error);
  }
  back_to_manage($set_id);
}

/* =========================
   PUBLISH / ARCHIVE STATUS
   ========================= */
if ($action === 'set_status') {
  if (!csrf_ok()){ flash_set('err','Invalid request.'); back_to_manage($set_id); }
  $story_id = (int)($_POST['story_id'] ?? 0);
  $status   = $_POST['status'] ?? 'draft';
  $allowed  = ['draft','published','archived'];
  if (!in_array($status,$allowed,true)) $status='draft';

  // belongs to set?
  $own=false;
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
    $stmt->bind_param('ii',$story_id,$set_id); $stmt->execute(); $stmt->store_result();
    $own = $stmt->num_rows > 0; $stmt->close();
  }
  if (!$own){ flash_set('err','Story not found.'); back_to_manage($set_id); }

  if ($stmt = $conn->prepare("UPDATE stories SET status=?, updated_at=NOW() WHERE story_id=? LIMIT 1")) {
    $stmt->bind_param('si',$status,$story_id);
    if ($stmt->execute()) flash_set('ok', $status==='published'?'Story published.':($status==='archived'?'Story archived.':'Story updated.'));
    else flash_set('err','Update failed: '.$conn->error);
    $stmt->close();
  } else {
    flash_set('err','Update failed: '.$conn->error);
  }
  back_to_manage($set_id);
}

/* ======================
   UPDATE STORY (Edit)
   ====================== */
if ($action === 'update_story') {
  if (!csrf_ok()){ flash_set('err','Invalid request.'); back_to_manage($set_id); }

  $story_id = (int)($_POST['story_id'] ?? 0);
  $title    = trim((string)($_POST['title'] ?? ''));
  $status   = $_POST['status'] ?? 'draft';
  $allowed  = ['draft','published','archived'];
  if (!in_array($status,$allowed,true)) $status='draft';
  $passage  = (string)($_POST['passage_html'] ?? '');
  $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

  // minutes → seconds (0/blank => NULL)
  $mins = isset($_POST['time_limit_minutes']) && $_POST['time_limit_minutes'] !== ''
        ? max(0, (int)$_POST['time_limit_minutes']) : 0;
  $tls  = $mins * 60;

  // validate belongs to set
  $row=null;
  if ($stmt = $conn->prepare("SELECT story_id, image_path FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
    $stmt->bind_param('ii',$story_id,$set_id); $stmt->execute();
    $res=$stmt->get_result(); $row=$res?$res->fetch_assoc():null; $stmt->close();
  }
  if (!$row){ flash_set('err','Story not found.'); back_to_manage($set_id); }
  if ($title === ''){ flash_set('err','Title is required.'); back_to_manage($set_id); }

  // duplicate (exclude self)
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE set_id=? AND title=? AND story_id<>? LIMIT 1")) {
    $stmt->bind_param('isi',$set_id,$title,$story_id); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0){ $stmt->close(); flash_set('err','Another story with the same title exists.'); back_to_manage($set_id); }
    $stmt->close();
  }

  $new_image_path = $row['image_path'] ?? null;
  if ($remove_image && $new_image_path){
    $abs = realpath(__DIR__.'/..'.$new_image_path); if ($abs && is_file($abs)) @unlink($abs);
    $new_image_path = null;
  }
  if (!$remove_image && !empty($_FILES['cover_image']['name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])){
    $img_err=null; $uploaded = rb_handle_upload($img_err);
    if ($uploaded === false){ flash_set('err',$img_err ?? 'Image upload error.'); back_to_manage($set_id); }
    if ($uploaded){
      if (!empty($new_image_path)){ $old=realpath(__DIR__.'/..'.$new_image_path); if($old && is_file($old)) @unlink($old); }
      $new_image_path = $uploaded;
    }
  }

  if ($stmt = $conn->prepare("
    UPDATE stories
       SET title=?,
           status=?,
           passage_html=?,
           image_path=?,
           time_limit_seconds = NULLIF(?,0),
           updated_at=NOW()
     WHERE story_id=? AND set_id=? LIMIT 1
  ")) {
    $stmt->bind_param('ssssiii', $title, $status, $passage, $new_image_path, $tls, $story_id, $set_id);
    if ($stmt->execute()) flash_set('ok','Story updated.');
    else flash_set('err','Update failed: '.$conn->error);
    $stmt->close();
  } else {
    flash_set('err','Update failed: '.$conn->error);
  }
  back_to_manage($set_id);
}

/* fallback */
flash_set('err','Unknown action.');
back_to_manage($set_id);
