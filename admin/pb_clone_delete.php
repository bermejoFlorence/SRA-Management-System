<?php
// admin/pb_clone_delete.php
// Delete PB "clone" stories. When `force=1`, also removes any attempts
// tied to those clones (DEV-only). Otherwise deletes only UNUSED clones.
//
// POST expected: set_id, csrf_token [, force=1]
// Redirects back to pb_manage.php?set_id=...

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------- flash helper (same key as pb_manage) ---------- */
function flash_set(string $type, string $msg){
  $_SESSION['pb_flash'] = ['t' => $type, 'm' => $msg];
}

/* ---------- guards ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flash_set('err', 'POST required.');
  header('Location: stories_pb.php');
  exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!$csrf || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  flash_set('err', 'Invalid CSRF token.');
  header('Location: stories_pb.php');
  exit;
}

$set_id = isset($_POST['set_id']) ? (int)$_POST['set_id'] : 0;
if ($set_id <= 0) {
  flash_set('err', 'Missing set_id.');
  header('Location: stories_pb.php');
  exit;
}

$force = isset($_POST['force']) && $_POST['force'] === '1';

/* ---------- verify PB set exists ---------- */
$stmt = $conn->prepare("SELECT 1 FROM story_sets WHERE set_id=? AND set_type='PB' LIMIT 1");
$stmt->bind_param('i', $set_id);
$stmt->execute();
$ok = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

if (!$ok) {
  flash_set('err', 'PB set not found.');
  header('Location: stories_pb.php');
  exit;
}

/* ---------- find clone stories ---------- */
/*
   "Clone" heuristics:
   - title contains "(Copy"
   - or title contains " Copy" or starts with "Copy "
   - or notes contains CLONED_FROM / cloned from
*/
$whereClone = "(s.title LIKE '%(Copy%' 
               OR s.title LIKE '% Copy%' 
               OR s.title LIKE 'Copy %'
               OR s.notes LIKE '%CLONED_FROM%' 
               OR s.notes LIKE '%cloned from%')";

if ($force) {
  // Any clone story in this set (used or not)
  $sql = "SELECT s.story_id, s.title 
            FROM stories s
           WHERE s.set_id = ? AND $whereClone";
} else {
  // Only clones with NO attempts
  $sql = "SELECT s.story_id, s.title 
            FROM stories s
           WHERE s.set_id = ? AND $whereClone
             AND NOT EXISTS(SELECT 1 FROM attempt_stories ats WHERE ats.story_id = s.story_id)";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $set_id);
$stmt->execute();
$res = $stmt->get_result();

$targets = [];
while ($row = $res->fetch_assoc()) {
  $targets[] = ['story_id' => (int)$row['story_id'], 'title' => (string)$row['title']];
}
$stmt->close();

if (!$targets) {
  if ($force) {
    flash_set('ok', 'No clone stories found.');
  } else {
    flash_set('ok', 'No unused clone stories found to delete.');
  }
  header('Location: pb_manage.php?set_id=' . $set_id);
  exit;
}

/* ---------- delete (transaction) ---------- */
$conn->begin_transaction();

try {
  // If FORCE: remove attempts tied to these stories first (answers -> story links -> empty attempt headers)
  if ($force) {
    $ids = array_column($targets, 'story_id');
    if ($ids) {
      $phIds  = implode(',', array_fill(0, count($ids), '?'));
      $tIds   = str_repeat('i', count($ids));

      // Collect attempt_ids that touched any of the clone stories
      $stmtGetAtt = $conn->prepare("
        SELECT DISTINCT a.attempt_id
          FROM assessment_attempts a
          JOIN attempt_stories ast ON ast.attempt_id = a.attempt_id
         WHERE ast.story_id IN ($phIds)
      ");
      $stmtGetAtt->bind_param($tIds, ...$ids);
      $stmtGetAtt->execute();
      $attempt_ids = [];
      $r = $stmtGetAtt->get_result();
      while ($x = $r->fetch_assoc()) $attempt_ids[] = (int)$x['attempt_id'];
      $stmtGetAtt->close();

      if ($attempt_ids) {
        $phAtt  = implode(',', array_fill(0, count($attempt_ids), '?'));
        $tAtt   = str_repeat('i', count($attempt_ids));

        // 1) attempt_answers via attempt_stories
        $stmtDelAns = $conn->prepare("
          DELETE aa
            FROM attempt_answers aa
            JOIN attempt_stories ast ON ast.attempt_story_id = aa.attempt_story_id
           WHERE ast.attempt_id IN ($phAtt)
        ");
        $stmtDelAns->bind_param($tAtt, ...$attempt_ids);
        $stmtDelAns->execute();
        $stmtDelAns->close();

        // 2) attempt_stories rows for these STORY IDs
        $stmtDelAst = $conn->prepare("DELETE FROM attempt_stories WHERE story_id IN ($phIds)");
        $stmtDelAst->bind_param($tIds, ...$ids);
        $stmtDelAst->execute();
        $stmtDelAst->close();

        // 3) remove empty attempt headers
        $stmtDelHdr = $conn->prepare("
          DELETE a FROM assessment_attempts a
          LEFT JOIN attempt_stories ast ON ast.attempt_id = a.attempt_id
          WHERE a.attempt_id IN ($phAtt) AND ast.attempt_id IS NULL
        ");
        $stmtDelHdr->bind_param($tAtt, ...$attempt_ids);
        $stmtDelHdr->execute();
        $stmtDelHdr->close();
      }
    }
  }

  // Prepared deletes for story → items/choices → story row
  $stmtDelChoices = $conn->prepare("
    DELETE sc FROM story_choices sc
    JOIN story_items si ON si.item_id = sc.item_id
   WHERE si.story_id = ?
  ");
  $stmtDelItems = $conn->prepare("DELETE FROM story_items WHERE story_id = ?");

  if ($force) {
    $stmtDelStory = $conn->prepare("DELETE FROM stories WHERE story_id = ? AND set_id = ?");
  } else {
    $stmtDelStory = $conn->prepare("
      DELETE s FROM stories s
      WHERE s.story_id = ? AND s.set_id = ?
        AND NOT EXISTS(SELECT 1 FROM attempt_stories ats WHERE ats.story_id = s.story_id)
    ");
  }

  $deleted = 0;
  foreach ($targets as $t) {
    $sid = $t['story_id'];

    $stmtDelChoices->bind_param('i', $sid);
    $stmtDelChoices->execute();

    $stmtDelItems->bind_param('i', $sid);
    $stmtDelItems->execute();

    $stmtDelStory->bind_param('ii', $sid, $set_id);
    $stmtDelStory->execute();

    if ($stmtDelStory->affected_rows > 0) {
      $deleted++;
    }
  }

  $stmtDelChoices->close();
  $stmtDelItems->close();
  $stmtDelStory->close();

  $conn->commit();

  if ($deleted > 0) {
    $label = $force ? ' (forced)' : '';
    flash_set('ok', "Deleted $deleted clone stor" . ($deleted === 1 ? 'y' : 'ies') . "$label.");
  } else {
    // This can happen if BETWEEN scan & delete a story became used (non-force),
    // or all were already removed by a parallel process.
    flash_set('ok', $force ? 'No clone stories deleted.' : 'No clone stories deleted (possibly already used).');
  }

} catch (Throwable $e) {
  $conn->rollback();
  flash_set('err', 'Delete failed: ' . $e->getMessage());
}

header('Location: pb_manage.php?set_id=' . $set_id);
exit;
