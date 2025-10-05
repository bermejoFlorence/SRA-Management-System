<?php
// admin/slt_sets_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

// Simple flash helper (gumagana dahil naka-start na ang session sa auth.php)
function flash_set($type,$msg){ $_SESSION['slt_flash']=['t'=>$type,'m'=>$msg]; }

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flash_set('error','Invalid request method.');
  header('Location: stories_sl.php'); exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'add_set') {
  $title = trim($_POST['title'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  if ($title === '') {
    flash_set('error','Please provide a title.');
    header('Location: stories_sl.php'); exit;
  }

  // Compute next sequence for SLT
  $nextSeq = 1;
  if ($rs = $conn->query("SELECT COALESCE(MAX(sequence),0)+1 AS nxt FROM story_sets WHERE set_type='SLT'")) {
    if ($row = $rs->fetch_assoc()) $nextSeq = (int)$row['nxt'];
  }

  $stmt = $conn->prepare("
    INSERT INTO story_sets (set_type, level_id, title, notes, sequence, status)
    VALUES ('SLT', NULL, ?, ?, ?, 'draft')
  ");
  $stmt->bind_param("ssi", $title, $notes, $nextSeq);

  if ($stmt->execute()) {
    flash_set('ok','SLT set created.');
  } else {
    flash_set('error','Failed to create set: '.$conn->error);
  }
  header('Location: stories_sl.php'); exit;
}

if ($action === 'set_status') {
  $set_id = (int)($_POST['set_id'] ?? 0);
  $status = $_POST['status'] ?? 'draft';

  if ($set_id > 0 && in_array($status, ['draft','published','archived'], true)) {
    $stmt = $conn->prepare("UPDATE story_sets SET status=? WHERE set_id=? AND set_type='SLT'");
    $stmt->bind_param("si", $status, $set_id);
    if ($stmt->execute()) {
      flash_set('ok','Status updated.');
    } else {
      flash_set('error','Update failed: '.$conn->error);
    }
  } else {
    flash_set('error','Invalid request.');
  }
  header('Location: stories_sl.php'); exit;
}

// Fallback
flash_set('error','Unknown action.');
header('Location: stories_sl.php'); exit;
