<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$set_id     = (int)($_POST['set_id'] ?? 0);
$title      = trim($_POST['title'] ?? '');
$word_count = (int)($_POST['word_count'] ?? 0);
if ($set_id<=0 || $title==='') die('Invalid input.');

# Enforce <= 15
$sr = $conn->prepare("SELECT stories_required, level_id, set_type FROM story_sets WHERE set_id=?");
$sr->bind_param("i",$set_id);
$sr->execute();
$set = $sr->get_result()->fetch_assoc();
if (!$set) die('Set not found.');

$cnt = $conn->query("SELECT COUNT(*) c FROM stories WHERE set_id=$set_id AND is_active=1 AND status<>'archived'")->fetch_assoc()['c'] ?? 0;
$limit = (int)($set['stories_required'] ?: 15);
if ($cnt >= $limit) die('Max stories reached.');

# Generate code (e.g., L<level>-<type>-NN)
$nn = $cnt + 1;
$code = sprintf('L%s-%s-%02d', $set['level_id'], $set['set_type'], $nn);

# Next sort order
$nextSort = (int)($conn->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM stories WHERE set_id=$set_id")->fetch_assoc()['n']);

$stmt = $conn->prepare("INSERT INTO stories(set_id,code,title,passage_text,word_count,skill_tags,status,sort_order,is_active,created_at,updated_at)
                        VALUES(?, ?, ?, '', ?, '', 'draft', ?, 1, NOW(), NOW())");
$stmt->bind_param("issii", $set_id, $code, $title, $word_count, $nextSort);
$stmt->execute();

header("Location: stories.php?level_id={$set['level_id']}&type={$set['set_type']}");
