<?php
// admin/pb_sets_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* --------- helpers --------- */
function flash_set($type, $msg){ $_SESSION['pb_flash'] = ['t'=>$type, 'm'=>$msg]; }
function go(){ header('Location: stories_pb.php'); exit; }

$action = $_POST['action'] ?? '';

try {

  /* ============ ADD PB SET ============ */
  if ($action === 'add_set') {
    $level_id = (int)($_POST['level_id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if ($level_id <= 0 || $title === '') {
      flash_set('error', 'Please complete the form.');
      go();
    }

    // verify level exists
// insert PB set (default status=draft, sequence=0)
if (!$st = $conn->prepare("
  INSERT INTO story_sets (set_type, level_id, title, notes, status, sequence, created_at, updated_at)
  VALUES ('PB', ?, ?, ?, 'draft', 0, NOW(), NOW())
")) {
  flash_set('error', 'DB error: '.$conn->error); go();
}
$st->bind_param('iss', $level_id, $title, $notes);
if (!$st->execute()) {
  flash_set('error', 'Insert failed: '.$st->error); go();
}
$pb_set_id = (int)$conn->insert_id;

/* ---------------------------------------------------------
   AUTO-CREATE an RB set for the same color (if none exists)
   --------------------------------------------------------- */

// 1) Alamin ang level name para sa default RB title
$lvlName = '';
if ($st = $conn->prepare("SELECT name FROM sra_levels WHERE level_id=? LIMIT 1")) {
  $st->bind_param('i', $level_id);
  $st->execute();
  $lvlName = (string)($st->get_result()->fetch_assoc()['name'] ?? '');
  $st->close();
}

// 2) Check kung may existing RB set na para dito (per color)
$rb_exists = 0;
if ($st = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM story_sets
  WHERE set_type='RB' AND level_id=?
  LIMIT 1
")) {
  $st->bind_param('i', $level_id);
  $st->execute();
  $rb_exists = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
  $st->close();
}

// 3) Kung wala pa, create RB set (draft)
if ($rb_exists === 0) {
  $rb_title = $lvlName ? ('RB – ' . $lvlName) : 'RB – Set';
  $rb_notes = 'Auto-created with PB set: ' . $title;

  if ($st = $conn->prepare("
    INSERT INTO story_sets (set_type, level_id, title, notes, status, sequence, created_at, updated_at)
    VALUES ('RB', ?, ?, ?, 'draft', 0, NOW(), NOW())
  ")) {
    $st->bind_param('iss', $level_id, $rb_title, $rb_notes);
    // ok lang kung mag-fail (e.g. duplicate title); hindi natin ibabagsak ang buong flow
    $st->execute();
    $st->close();
  }
}

flash_set('ok', 'PB set created. (RB set also prepared)');
go();

  }

  /* ============ PUBLISH / ARCHIVE ============ */
  elseif ($action === 'set_status') {
    $set_id = (int)($_POST['set_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['draft','published','archived'];

    if ($set_id <= 0 || !in_array($status, $allowed, true)) {
      flash_set('error', 'Invalid request.'); go();
    }

    // confirm PB set exists
    if (!$st = $conn->prepare("SELECT set_id FROM story_sets WHERE set_id=? AND set_type='PB' LIMIT 1")) {
      flash_set('error', 'DB error: '.$conn->error); go();
    }
    $st->bind_param('i', $set_id);
    $st->execute();
    if (!$st->get_result()->num_rows) {
      flash_set('error', 'PB set not found.'); go();
    }

    // update status
    if (!$st = $conn->prepare("UPDATE story_sets SET status=?, updated_at=NOW() WHERE set_id=?")) {
      flash_set('error', 'DB error: '.$conn->error); go();
    }
    $st->bind_param('si', $status, $set_id);
    if (!$st->execute()) {
      flash_set('error', 'Update failed: '.$st->error); go();
    }

    $msg = $status === 'published' ? 'Set published.' :
           ($status === 'archived' ? 'Set archived.' : 'Status updated.');
    flash_set('ok', $msg);
    go();
  }

  /* ============ DELETE (optional, for future) ============ */
  elseif ($action === 'delete_set') {
    $set_id = (int)($_POST['set_id'] ?? 0);
    if ($set_id <= 0) { flash_set('error','Invalid set.'); go(); }

    // block delete if may laman na stories
    if (!$st = $conn->prepare("SELECT COUNT(*) c FROM stories WHERE set_id=?")) {
      flash_set('error', 'DB error: '.$conn->error); go();
    }
    $st->bind_param('i', $set_id);
    $st->execute();
    $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    if ($c > 0) {
      flash_set('error','Cannot delete: there are stories in this set.');
      go();
    }

    if (!$st = $conn->prepare("DELETE FROM story_sets WHERE set_id=? AND set_type='PB'")) {
      flash_set('error', 'DB error: '.$conn->error); go();
    }
    $st->bind_param('i', $set_id);
    if (!$st->execute()) {
      flash_set('error', 'Delete failed: '.$st->error); go();
    }

    flash_set('ok', 'PB set deleted.');
    go();
  }

  /* ============ default ============ */
  else {
    flash_set('error', 'Unknown action.');
    go();
  }

} catch (Throwable $e) {
  flash_set('error', 'Unexpected error: '.$e->getMessage());
  go();
}
