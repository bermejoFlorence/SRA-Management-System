<?php
// admin/slt_stories_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------------- Flash + guards ---------------- */
function flash_set($type,$msg){ $_SESSION['slt_flash']=['t'=>$type,'m'=>$msg]; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flash_set('error','Invalid request method.');
  header('Location: stories_sl.php'); exit;
}

$action = $_POST['action'] ?? '';
$set_id = isset($_POST['set_id']) ? (int)$_POST['set_id'] : 0;
if ($set_id <= 0) { flash_set('error','Missing set.'); header('Location: stories_sl.php'); exit; }

/* Ensure the set exists and is SLT */
$okSet = false;
if ($stmt = $conn->prepare("SELECT set_id FROM story_sets WHERE set_id=? AND set_type='SLT' LIMIT 1")) {
  $stmt->bind_param("i", $set_id);
  $stmt->execute(); $res = $stmt->get_result();
  if ($res && $res->num_rows) $okSet = true;
}
if (!$okSet) { flash_set('error','Invalid set.'); header('Location: stories_sl.php'); exit; }

/* ---------------- Helpers ---------------- */
function compute_words($html){
  $plain = trim(strip_tags((string)$html));
  if ($plain === '') return 0;
  $parts = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
  return $parts ? count($parts) : 0;
}
function norm_status($v){
  $v = strtolower(trim((string)$v));
  if ($v === 'published') $v = 'active';
  if ($v === 'draft' || $v === 'archived' || $v === '') $v = 'inactive';
  return in_array($v, ['active','inactive'], true) ? $v : 'inactive';
}
function has_column(mysqli $conn, $table, $col){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $q = $conn->prepare($sql);
  if (!$q) return false;
  $q->bind_param("ss", $table, $col);
  $q->execute();
  $r = $q->get_result();
  return $r && $r->num_rows > 0;
}
function handle_image_upload($input_name, $set_id, $old_path = null){
  if (!isset($_FILES[$input_name]) || $_FILES[$input_name]['error'] === UPLOAD_ERR_NO_FILE) {
    return $old_path; // keep existing image / none if add
  }
  $f = $_FILES[$input_name];
  if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Image upload failed (code '.$f['error'].').');

  $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
  if (function_exists('finfo_open')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($f['tmp_name']);
  } else {
    $mime = mime_content_type($f['tmp_name']);
  }
  if (!isset($allowed[$mime])) throw new Exception('Invalid image type.');
  if ($f['size'] > 3*1024*1024) throw new Exception('Image too large (max 3MB).');

  $base = realpath(__DIR__ . '/../');
  $dir  = $base . '/uploads/stories';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $name = 'st'.$set_id.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$allowed[$mime];
  $abs  = $dir . '/' . $name;
  if (!move_uploaded_file($f['tmp_name'], $abs)) throw new Exception('Failed to save image.');

  if ($old_path) {
    $oldAbs = $base . $old_path;
    if (is_file($oldAbs)) @unlink($oldAbs);
  }

  // return web path with app base
  $baseWeb = rtrim(str_replace('\\','/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/'); // e.g. "/sra"
  return $baseWeb . '/uploads/stories/' . $name;
}
// minutes (string/number) → seconds int or NULL (no limit)
function parse_time_limit_seconds($min) {
  $min = trim((string)$min);
  if ($min === '' || !is_numeric($min)) return null;  // no limit
  $m = max(0, (int)$min);
  if ($m === 0) return null;                           // 0 = no limit
  // optional cap, e.g. $m = min($m, 600);
  return $m * 60;
}

/* ---------------- ADD STORY ---------------- */
if ($action === 'add_story') {
  $title   = trim($_POST['title'] ?? '');
  $status  = norm_status($_POST['status'] ?? 'inactive');
  $passage = $_POST['passage_html'] ?? '';
  $time_limit_secs = parse_time_limit_seconds($_POST['time_limit_min'] ?? '');

  if ($title === '') {
    flash_set('error','Title is required.');
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  $words     = compute_words($passage);
  $hasImgCol = has_column($conn, 'stories', 'image_path');
  $hasTLCol  = has_column($conn, 'stories', 'time_limit_seconds');

  // Optional image
  $image_path = null;
  if ($hasImgCol) {
    try { $image_path = handle_image_upload('cover_image', $set_id, null); }
    catch (Exception $e) { flash_set('error', 'Image not saved: '.$e->getMessage()); }
  }

  /* Build INSERT with permutations (image/time limit optional) */
  if ($hasImgCol && $hasTLCol) {
    if ($time_limit_secs === null) { // store NULL (no limit)
      $stmt = $conn->prepare("
        INSERT INTO stories (set_id, title, passage_html, word_count, status, image_path, time_limit_seconds)
        VALUES (?, ?, ?, ?, ?, ?, NULL)  -- <-- if NOT NULL, use 0 instead of NULL
      ");
      $stmt->bind_param("ississ", $set_id, $title, $passage, $words, $status, $image_path);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO stories (set_id, title, passage_html, word_count, status, image_path, time_limit_seconds)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("ississi", $set_id, $title, $passage, $words, $status, $image_path, $time_limit_secs);
    }
  } elseif ($hasImgCol && !$hasTLCol) {
    $stmt = $conn->prepare("
      INSERT INTO stories (set_id, title, passage_html, word_count, status, image_path)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ississ", $set_id, $title, $passage, $words, $status, $image_path);
  } elseif (!$hasImgCol && $hasTLCol) {
    if ($time_limit_secs === null) {
      $stmt = $conn->prepare("
        INSERT INTO stories (set_id, title, passage_html, word_count, status, time_limit_seconds)
        VALUES (?, ?, ?, ?, ?, NULL)     -- <-- if NOT NULL, use 0 instead of NULL
      ");
      $stmt->bind_param("issis", $set_id, $title, $passage, $words, $status);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO stories (set_id, title, passage_html, word_count, status, time_limit_seconds)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("issisi", $set_id, $title, $passage, $words, $status, $time_limit_secs);
    }
  } else {
    $stmt = $conn->prepare("
      INSERT INTO stories (set_id, title, passage_html, word_count, status)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issis", $set_id, $title, $passage, $words, $status);
  }

  if (!$stmt || !$stmt->execute()) {
    flash_set('error','Failed to add story: '.$conn->error);
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  $new_story_id = $conn->insert_id;

  // If set to active, ensure it's the only one
  if ($status === 'active') {
    $conn->begin_transaction();
    try {
      $q1 = $conn->prepare("UPDATE stories SET status='inactive' WHERE set_id=? AND story_id<>?");
      $q1->bind_param("ii", $set_id, $new_story_id);
      $q1->execute();

      $q2 = $conn->prepare("UPDATE stories SET status='active' WHERE story_id=? AND set_id=?");
      $q2->bind_param("ii", $new_story_id, $set_id);
      $q2->execute();

      $conn->commit();
    } catch (Throwable $t) {
      $conn->rollback();
      flash_set('error','Added but failed to enforce active uniqueness: '.$t->getMessage());
      header('Location: slt_manage.php?set_id='.$set_id); exit;
    }
  }

  flash_set('ok','Story added ('.$words.' words).');
  header('Location: slt_manage.php?set_id='.$set_id); exit;
}

/* ---------------- UPDATE STORY ---------------- */
if ($action === 'update_story') {
  $story_id = (int)($_POST['story_id'] ?? 0);
  $title    = trim($_POST['title'] ?? '');
  $status   = norm_status($_POST['status'] ?? 'inactive');
  $passage  = $_POST['passage_html'] ?? '';
  $time_limit_secs = parse_time_limit_seconds($_POST['time_limit_min'] ?? '');

  if ($story_id<=0 || $title==='') {
    flash_set('error','Invalid story.');
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  $words     = compute_words($passage);
  $hasImgCol = has_column($conn, 'stories', 'image_path');
  $hasTLCol  = has_column($conn, 'stories', 'time_limit_seconds');

  // get current image_path (if any)
  $image_path = null;
  if ($hasImgCol) {
    $cur = null;
    if ($q = $conn->prepare("SELECT image_path FROM stories WHERE story_id=? AND set_id=?")) {
      $q->bind_param("ii", $story_id, $set_id);
      $q->execute(); $r = $q->get_result();
      $cur = $r && $r->num_rows ? $r->fetch_assoc() : null;
    }
    $image_path = $cur['image_path'] ?? null;

    try { $image_path = handle_image_upload('cover_image', $set_id, $image_path); }
    catch (Exception $e) { flash_set('error', 'Image not saved: '.$e->getMessage()); }
  }

  /* Build UPDATE with permutations (image/time limit optional) */
  if ($hasImgCol && $hasTLCol) {
    if ($time_limit_secs === null) {
      $stmt = $conn->prepare("
        UPDATE stories
        SET title=?, passage_html=?, word_count=?, status=?, image_path=?, time_limit_seconds=NULL, updated_at=NOW()
        WHERE story_id=? AND set_id=?                                -- <-- if NOT NULL, use 0 instead of NULL
      ");
      $stmt->bind_param("ssissii", $title, $passage, $words, $status, $image_path, $story_id, $set_id);
    } else {
      $stmt = $conn->prepare("
        UPDATE stories
        SET title=?, passage_html=?, word_count=?, status=?, image_path=?, time_limit_seconds=?, updated_at=NOW()
        WHERE story_id=? AND set_id=?
      ");
      $stmt->bind_param("ssissiii", $title, $passage, $words, $status, $image_path, $time_limit_secs, $story_id, $set_id);
    }
  } elseif ($hasImgCol && !$hasTLCol) {
    $stmt = $conn->prepare("
      UPDATE stories
      SET title=?, passage_html=?, word_count=?, status=?, image_path=?, updated_at=NOW()
      WHERE story_id=? AND set_id=?
    ");
    $stmt->bind_param("ssissii", $title, $passage, $words, $status, $image_path, $story_id, $set_id);
  } elseif (!$hasImgCol && $hasTLCol) {
    if ($time_limit_secs === null) {
      $stmt = $conn->prepare("
        UPDATE stories
        SET title=?, passage_html=?, word_count=?, status=?, time_limit_seconds=NULL, updated_at=NOW()
        WHERE story_id=? AND set_id=?                                -- <-- if NOT NULL, use 0 instead of NULL
      ");
      $stmt->bind_param("ssisii", $title, $passage, $words, $status, $story_id, $set_id);
    } else {
      $stmt = $conn->prepare("
        UPDATE stories
        SET title=?, passage_html=?, word_count=?, status=?, time_limit_seconds=?, updated_at=NOW()
        WHERE story_id=? AND set_id=?
      ");
      $stmt->bind_param("ssisiii", $title, $passage, $words, $status, $time_limit_secs, $story_id, $set_id);
    }
  } else {
    $stmt = $conn->prepare("
      UPDATE stories
      SET title=?, passage_html=?, word_count=?, status=?, updated_at=NOW()
      WHERE story_id=? AND set_id=?
    ");
    $stmt->bind_param("ssisii", $title, $passage, $words, $status, $story_id, $set_id);
  }

  if (!$stmt || !$stmt->execute()) {
    flash_set('error','Update failed: '.$conn->error);
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  // Enforce only-one-active per set
  if ($status === 'active') {
    $conn->begin_transaction();
    try {
      $q1 = $conn->prepare("UPDATE stories SET status='inactive' WHERE set_id=? AND story_id<>?");
      $q1->bind_param("ii", $set_id, $story_id);
      $q1->execute();

      $q2 = $conn->prepare("UPDATE stories SET status='active' WHERE story_id=? AND set_id=?");
      $q2->bind_param("ii", $story_id, $set_id);
      $q2->execute();

      $conn->commit();
    } catch (Throwable $t) {
      $conn->rollback();
      flash_set('error','Updated but failed to enforce active uniqueness: '.$t->getMessage());
      header('Location: slt_manage.php?set_id='.$set_id); exit;
    }
  }

  flash_set('ok','Story updated ('.$words.' words).');
  header('Location: slt_manage.php?set_id='.$set_id); exit;
}

/* ---------------- SET ACTIVE ---------------- */
if ($action === 'set_active') {
  $story_id = (int)($_POST['story_id'] ?? 0);
  if ($story_id <= 0) {
    flash_set('error','Invalid story.');
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  $conn->begin_transaction();
  try {
    $q1 = $conn->prepare("UPDATE stories SET status='inactive' WHERE set_id=?");
    $q1->bind_param("i", $set_id);
    $q1->execute();

    $q2 = $conn->prepare("UPDATE stories SET status='active' WHERE story_id=? AND set_id=?");
    $q2->bind_param("ii", $story_id, $set_id);
    $q2->execute();

    $conn->commit();
    flash_set('ok','Story set as active.');
  } catch (Throwable $t) {
    $conn->rollback();
    flash_set('error','Failed to set active: '.$t->getMessage());
  }

  header('Location: slt_manage.php?set_id='.$set_id); exit;
}

/* ---------------- SET INACTIVE ---------------- */
if ($action === 'set_inactive') {
  $story_id = (int)($_POST['story_id'] ?? 0);
  if ($story_id <= 0) {
    flash_set('error','Invalid story.');
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }

  if ($stmt = $conn->prepare("UPDATE stories SET status='inactive' WHERE story_id=? AND set_id=?")) {
    $stmt->bind_param("ii", $story_id, $set_id);
    if ($stmt->execute()) flash_set('ok','Story set to inactive.');
    else flash_set('error','Failed to set inactive: '.$conn->error);
  } else {
    flash_set('error','Failed to set inactive: '.$conn->error);
  }

  header('Location: slt_manage.php?set_id='.$set_id); exit;
}

/* ---------------- (Optional) DELETE ---------------- */
if ($action === 'delete_story') {
  $story_id = (int)($_POST['story_id'] ?? 0);
  if ($story_id <= 0) {
    flash_set('error','Invalid story.');
    header('Location: slt_manage.php?set_id='.$set_id); exit;
  }
  if ($stmt = $conn->prepare("DELETE FROM stories WHERE story_id=? AND set_id=?")) {
    $stmt->bind_param("ii", $story_id, $set_id);
    if ($stmt->execute()) flash_set('ok','Story deleted.');
    else flash_set('error','Delete failed: '.$conn->error);
  } else {
    flash_set('error','Delete failed: '.$conn->error);
  }
  header('Location: slt_manage.php?set_id='.$set_id); exit;
}

/* ---------------- Fallback ---------------- */
flash_set('error','Unknown action.');
header('Location: slt_manage.php?set_id='.$set_id); exit;
