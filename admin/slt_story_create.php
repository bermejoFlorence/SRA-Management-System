<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$set_id       = (int)($_POST['set_id'] ?? 0);
$title        = trim($_POST['title'] ?? '');
$code         = trim($_POST['code'] ?? '');
$word_count   = (int)($_POST['word_count'] ?? 0);
$passage_text = (string)($_POST['passage_text'] ?? '');

function go($p){ header('Location: stories_sl.php?'.http_build_query($p)); exit; }

if ($set_id<=0 || $title==='') go(['err'=>'Missing required fields.']);

/* ensure SLT set */
$S = $conn->prepare("SELECT set_id, set_type FROM story_sets WHERE set_id=? AND is_active=1 LIMIT 1");
$S->bind_param('i',$set_id);
$S->execute();
$set = $S->get_result()->fetch_assoc();
if (!$set || $set['set_type']!=='SLT') go(['err'=>'SLT set not found.']);

/* next sort order */
$nextSort = (int)($conn->query("SELECT COALESCE(MAX(sort_order),0)+1 n FROM stories WHERE set_id={$set_id}")->fetch_assoc()['n']);

/* auto code if blank */
if ($code==='') {
  $n = (int)($conn->query("SELECT COUNT(*) c FROM stories WHERE set_id={$set_id}")->fetch_assoc()['c']) + 1;
  $code = sprintf('SLT-%02d',$n);
}

$stmt = $conn->prepare("
  INSERT INTO stories (set_id, code, title, passage_text, word_count, skill_tags, status, sort_order, is_active, created_at, updated_at)
  VALUES (?, ?, ?, ?, ?, '', 'draft', ?, 1, NOW(), NOW())
");
if (!$stmt) go(['err'=>"Prep failed: ".$conn->error]);
$stmt->bind_param('isssii', $set_id, $code, $title, $passage_text, $word_count, $nextSort);
if (!$stmt->execute()) go(['err'=>"Insert failed: ".$stmt->error]);

go(['ok'=>'story_created']);
