<?php
// admin/pb_stories_action.php

// Start session only if not already active (avoid notices)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

function flash_set($type,$msg){ $_SESSION['pb_flash']=['t'=>$type,'m'=>$msg]; }
function csrf_ok(){ return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']); }

$action = $_POST['action'] ?? '';
$set_id = (int)($_POST['set_id'] ?? 0);

if ($set_id <= 0) { flash_set('err','Invalid set.'); header('Location: stories_pb.php'); exit; }

// ensure set exists and is PB
$okSet = false;
if ($stmt = $conn->prepare("SELECT 1 FROM story_sets WHERE set_id=? AND set_type='PB' LIMIT 1")) {
  $stmt->bind_param('i',$set_id);
  $stmt->execute();
  $stmt->store_result();
  $okSet = $stmt->num_rows > 0;
  $stmt->close();
}
if (!$okSet) { flash_set('err','PB set not found.'); header('Location: stories_pb.php'); exit; }

/* ============================================
   ADD STORY (Title + Status + Passage + Image)
   ============================================ */
if ($action === 'add_story') {
  if (!csrf_ok()) { flash_set('err','Invalid request.'); header('Location: pb_manage.php?set_id='.$set_id); exit; }

  $title   = trim((string)($_POST['title'] ?? ''));
  $status  = $_POST['status'] ?? 'draft';                // from the modal select
  $allowed = ['draft','published','archived'];           // PB statuses
  if (!in_array($status,$allowed,true)) $status='draft';

  $passage = (string)($_POST['passage_html'] ?? '');
    $author  = trim((string)($_POST['author'] ?? ''));  // ✅ NEW

  // ✅ NEW: minutes from form → seconds for DB
  $time_limit_minutes = isset($_POST['time_limit_minutes']) ? (int)$_POST['time_limit_minutes'] : 0;
  $time_limit = ($time_limit_minutes > 0) ? ($time_limit_minutes * 60) : 0; // 0 = no limit

  if ($title === '') {
    flash_set('err','Title is required.');
    header('Location: pb_manage.php?set_id='.$set_id); exit;
  }

  // Optional: prevent duplicate title within set
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE set_id=? AND title=? LIMIT 1")) {
    $stmt->bind_param('is',$set_id, $title);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) {
      $stmt->close();
      flash_set('err','A story with the same title already exists.');
      header('Location: pb_manage.php?set_id='.$set_id); exit;
    }
    $stmt->close();
  }

  /* ----- Cover Image Upload (optional) ----- */
  $image_path = null; // web path to save in DB (e.g., /uploads/pb_covers/abc.jpg)

  if (!empty($_FILES['cover_image']['name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
    $f = $_FILES['cover_image'];

    if ($f['error'] === UPLOAD_ERR_OK) {

      // size check (<= 3MB)
      $okSize = ($f['size'] <= 3 * 1024 * 1024);

      // mime check (prefer finfo; fallback to getimagesize)
      $mime = null;
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
      }
      if (!$mime && function_exists('getimagesize')) {
        $info = @getimagesize($f['tmp_name']);
        if ($info && isset($info['mime'])) $mime = $info['mime'];
      }

      $okMime = in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true);

      if ($okSize && $okMime) {
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg');
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
          // normalize extension by mime if odd
          $ext = ($mime === 'image/png' ? 'png' :
                 ($mime === 'image/webp' ? 'webp' :
                 ($mime === 'image/gif' ? 'gif' : 'jpg')));
        }

        $dirAbs = __DIR__ . '/../uploads/pb_covers';
        if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

        $name = 'pb_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dirAbs . '/' . $name;

        if (move_uploaded_file($f['tmp_name'], $dest)) {
          // Web path (adjust base if your public root differs)
          $image_path = '/uploads/pb_covers/' . $name;
        } else {
          flash_set('err','Failed to move uploaded image.');
          header('Location: pb_manage.php?set_id='.$set_id); exit;
        }
      } else {
        $msg = !$okSize ? 'Image too large (max 3MB).' : 'Unsupported image type.';
        flash_set('err', $msg);
        header('Location: pb_manage.php?set_id='.$set_id); exit;
      }
    } else {
      flash_set('err', 'Image upload failed (code '.$f['error'].').');
      header('Location: pb_manage.php?set_id='.$set_id); exit;
    }
  }

  // Insert story (now includes passage_html, image_path, status, time_limit_seconds)
  if ($stmt = $conn->prepare("
    INSERT INTO stories (set_id, title, author, passage_html, image_path, time_limit_seconds, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ")) {
    $stmt->bind_param(
      'issssis',
      $set_id,
      $title,
      $author,
      $passage,
      $image_path,
      $time_limit,
      $status
    );

    if ($stmt->execute()) {
      flash_set('ok','Story saved.');
    } else {
      flash_set('err','Insert failed: '.$conn->error);
    }
    $stmt->close();
  } else {
    flash_set('err','Insert failed: '.$conn->error);
  }

  header('Location: pb_manage.php?set_id='.$set_id); exit;
}

/* =========================
   PUBLISH / ARCHIVE STATUS
   ========================= */
if ($action === 'set_status') {
  if (!csrf_ok()) { flash_set('err','Invalid request.'); header('Location: pb_manage.php?set_id='.$set_id); exit; }
  $story_id = (int)($_POST['story_id'] ?? 0);
  $status   = $_POST['status'] ?? 'draft';
  $allowed  = ['draft','published','archived'];
  if (!in_array($status,$allowed,true)) $status='draft';

  // ensure story belongs to this set
  $own = false;
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
    $stmt->bind_param('ii',$story_id,$set_id);
    $stmt->execute(); $stmt->store_result();
    $own = $stmt->num_rows > 0;
    $stmt->close();
  }
  if (!$own) { flash_set('err','Story not found.'); header('Location: pb_manage.php?set_id='.$set_id); exit; }

  if ($stmt = $conn->prepare("UPDATE stories SET status=?, updated_at=NOW() WHERE story_id=? LIMIT 1")) {
    $stmt->bind_param('si',$status,$story_id);
    if ($stmt->execute()) {
      $msg = $status==='published' ? 'Story published.' : ($status==='archived' ? 'Story archived.' : 'Story updated.');
      flash_set('ok',$msg);
    } else {
      flash_set('err','Update failed: '.$conn->error);
    }
    $stmt->close();
  } else {
    flash_set('err','Update failed: '.$conn->error);
  }

  header('Location: pb_manage.php?set_id='.$set_id); exit;
}

/* ======================
   UPDATE STORY (Edit)
   ====================== */
if ($action === 'update_story') {
  if (!csrf_ok()) { flash_set('err','Invalid request.'); header('Location: pb_manage.php?set_id='.$set_id); exit; }

  $story_id = (int)($_POST['story_id'] ?? 0);
  $title    = trim((string)($_POST['title'] ?? ''));
  $status   = $_POST['status'] ?? 'draft';
  $allowed  = ['draft','published','archived'];
  if (!in_array($status,$allowed,true)) $status='draft';
  $passage  = (string)($_POST['passage_html'] ?? '');
  $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

    $author  = trim((string)($_POST['author'] ?? ''));   // ✅ NEW

  // ✅ NEW: minutes from form → seconds for DB
  $time_limit_minutes = isset($_POST['time_limit_minutes']) ? (int)$_POST['time_limit_minutes'] : 0;
  $time_limit = ($time_limit_minutes > 0) ? ($time_limit_minutes * 60) : 0; // 0 = no limit

  // validate story belongs to this set
  $row = null;
  if ($stmt = $conn->prepare("SELECT story_id, image_path FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
    $stmt->bind_param('ii', $story_id, $set_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
  }
  if (!$row) { flash_set('err','Story not found.'); header('Location: pb_manage.php?set_id='.$set_id); exit; }

  if ($title === '') {
    flash_set('err','Title is required.');
    header('Location: pb_manage.php?set_id='.$set_id); exit;
  }

  // prevent duplicate title within set (exclude self)
  if ($stmt = $conn->prepare("SELECT 1 FROM stories WHERE set_id=? AND title=? AND story_id<>? LIMIT 1")) {
    $stmt->bind_param('isi', $set_id, $title, $story_id);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { 
      $stmt->close(); 
      flash_set('err','Another story with the same title exists.'); 
      header('Location: pb_manage.php?set_id='.$set_id); 
      exit; 
    }
    $stmt->close();
  }

  // image handling
  $new_image_path = $row['image_path'] ?? null;

  // removal takes precedence
  if ($remove_image && $new_image_path) {
    // try to unlink physical file
    $abs = realpath(__DIR__ . '/..' . $new_image_path);
    if ($abs && is_file($abs)) { @unlink($abs); }
    $new_image_path = null;
  }

  // upload new file if any
  if (!$remove_image && !empty($_FILES['cover_image']['name']) && is_uploaded_file($_FILES['cover_image']['tmp_name'])) {
    $f = $_FILES['cover_image'];
    if ($f['error'] === UPLOAD_ERR_OK) {
      $okSize = ($f['size'] <= 3 * 1024 * 1024);
      $mime = null;
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
      }
      if (!$mime && function_exists('getimagesize')) {
        $info = @getimagesize($f['tmp_name']);
        if ($info && isset($info['mime'])) $mime = $info['mime'];
      }
      $okMime = in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true);

      if ($okSize && $okMime) {
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg');
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
          $ext = ($mime === 'image/png' ? 'png' :
                 ($mime === 'image/webp' ? 'webp' :
                 ($mime === 'image/gif' ? 'gif' : 'jpg')));
        }
        $dirAbs = __DIR__ . '/../uploads/pb_covers';
        if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }
        $name = 'pb_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dirAbs . '/' . $name;

        if (move_uploaded_file($f['tmp_name'], $dest)) {
          // delete old file if replacing
          if (!empty($new_image_path)) {
            $old = realpath(__DIR__ . '/..' . $new_image_path);
            if ($old && is_file($old)) { @unlink($old); }
          }
          $new_image_path = '/uploads/pb_covers/' . $name;
        } else {
          flash_set('err','Failed to move uploaded image.');
          header('Location: pb_manage.php?set_id='.$set_id); exit;
        }
      } else {
        $msg = !$okSize ? 'Image too large (max 3MB).' : 'Unsupported image type.';
        flash_set('err',$msg);
        header('Location: pb_manage.php?set_id='.$set_id); exit;
      }
    } else {
      flash_set('err','Image upload failed (code '.$f['error'].').');
      header('Location: pb_manage.php?set_id='.$set_id); exit;
    }
  }

  // perform update
    if ($stmt = $conn->prepare("
    UPDATE stories
       SET title=?,
           author=?,
           status=?,
           passage_html=?,
           image_path=?,
           time_limit_seconds=?,
           updated_at=NOW()
     WHERE story_id=? AND set_id=? LIMIT 1
  ")) {
    $stmt->bind_param(
      'ssssssii',
      $title,
      $author,
      $status,
      $passage,
      $new_image_path,
      $time_limit,
      $story_id,
      $set_id
    );
if ($stmt->execute()) {
      flash_set('ok','Story updated.');
    } else {
      flash_set('err','Update failed: '.$conn->error);
    }
    $stmt->close();
  } else {
    flash_set('err','Update failed: '.$conn->error);
  }

  header('Location: pb_manage.php?set_id='.$set_id); exit;
}


/* fallback */
flash_set('err','Unknown action.');
header('Location: pb_manage.php?set_id='.$set_id); exit;
