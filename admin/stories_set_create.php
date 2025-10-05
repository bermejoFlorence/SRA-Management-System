<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$level_id = (int)($_POST['level_id'] ?? 0);
$set_type = $_POST['set_type'] ?? 'PB';
$title    = trim($_POST['title'] ?? '');

$allowed  = ['PB','RB','SLT'];
if (!in_array($set_type, $allowed, true)) $set_type = 'PB';

function go($url, $params=[]) {
  if (!empty($params)) $url .= (strpos($url,'?')===false?'?':'&') . http_build_query($params);
  header("Location: $url"); exit;
}

if ($set_type === 'SLT') {
  // ensure we have a level to bind
  if ($level_id <= 0) {
    $row = $conn->query("SELECT level_id FROM sra_levels WHERE is_active=1 ORDER BY sort_order, level_id LIMIT 1")->fetch_assoc();
    if (!$row) go('stories_sl.php', ['err' => 'No levels available to bind the SLT set.']);
    $level_id = (int)$row['level_id'];
  }

  // only one active SLT set
  $exists = $conn->query("SELECT set_id FROM story_sets WHERE set_type='SLT' AND is_active=1 LIMIT 1")->fetch_assoc();
  if ($exists) {
    go('stories_sl.php', ['ok'=>'exists','msg'=>'SLT set already exists.']);
  }

   $stmt = $conn->prepare("
    INSERT INTO story_sets (level_id,set_type,title,description,stories_required,is_active,created_at,updated_at)
    VALUES (?, 'SLT', ?, 'Starting Level Test', 0, 1, NOW(), NOW())
  ");
  
  if (!$stmt) go('stories_sl.php', ['err'=>"Prep failed: ".$conn->error]);

  $stmt->bind_param('is', $level_id, $title);
  if (!$stmt->execute()) {
    go('stories_sl.php', ['err'=>"Insert failed: ".$stmt->error]);
  }

  go('stories_sl.php', ['ok'=>'created']);
  // ---- end SLT ----
}

/* PB/RB create (1 per level+type) */
if ($level_id <= 0) go(($set_type==='PB'?'stories_pb.php':'stories_rb.php'), ['err'=>'Invalid level.']);

$stmt = $conn->prepare("SELECT set_id FROM story_sets WHERE level_id=? AND set_type=? LIMIT 1");
$stmt->bind_param('is', $level_id, $set_type);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
  $stmt2 = $conn->prepare("
    INSERT INTO story_sets(level_id,set_type,title,stories_required,is_active,created_at,updated_at)
    VALUES(?, ?, ?, 15, 1, NOW(), NOW())
  ");
  if (!$stmt2) go(($set_type==='PB'?'stories_pb.php':'stories_rb.php'), ['err'=>"Prep failed: ".$conn->error]);
  $stmt2->bind_param('iss', $level_id, $set_type, $title);
  if (!$stmt2->execute()) {
    go(($set_type==='PB'?'stories_pb.php':'stories_rb.php'), ['err'=>"Insert failed: ".$stmt2->error]);
  }
  go(($set_type==='PB'?'stories_pb.php':'stories_rb.php'), ['ok'=>'created']);
} else {
  go(($set_type==='PB'?'stories_pb.php':'stories_rb.php'), ['ok'=>'exists','msg'=>'Set already exists.']);
}
