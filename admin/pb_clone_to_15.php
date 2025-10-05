<?php
// admin/pb_clone_to_15.php
// Fill a PB set to 15 published stories by cloning a template story (with its items & choices)

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// flash helper (same shape as your other admin pages)
function flash_set($type, $msg){ $_SESSION['pb_flash'] = ['t'=>$type,'m'=>$msg]; }

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('POST required');
  }

  // CSRF
  $csrf = $_POST['csrf_token'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    throw new Exception('Invalid CSRF token.');
  }

  $set_id = (int)($_POST['set_id'] ?? 0);
  $src_id = (int)($_POST['source_story_id'] ?? 0);
  if ($set_id <= 0 || $src_id <= 0) throw new Exception('Bad input.');

  // Count current PUBLISHED stories in this set
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM stories WHERE set_id=? AND status='published'");
  $stmt->bind_param('i', $set_id);
  $stmt->execute();
  $curPub = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();

  $TARGET = 15;
  $needed = max(0, $TARGET - $curPub);
  if ($needed <= 0) {
    flash_set('ok', "Already at $TARGET published stories. Nothing to clone.");
    header('Location: pb_manage.php?set_id=' . $set_id); exit;
  }

  // Load source story (must belong to same set)
  $stmt = $conn->prepare("
    SELECT story_id, set_id, title, passage_html, image_path,
           COALESCE(word_count,0) AS word_count,
           COALESCE(time_limit_seconds,0) AS time_limit_seconds
      FROM stories
     WHERE story_id=? AND set_id=?
     LIMIT 1
  ");
  $stmt->bind_param('ii', $src_id, $set_id);
  $stmt->execute();
  $src = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$src) throw new Exception('Source story not found in this set.');

  // Fallback word_count
  $word_count = (int)$src['word_count'];
  if ($word_count <= 0) {
    $plain = trim(strip_tags($src['passage_html'] ?? ''));
    $wc = str_word_count($plain);
    $word_count = max(1, (int)$wc);
  }

  // Load items of source story
  $items = [];
  $stmt = $conn->prepare("
    SELECT item_id, number, question_text, section_code, sub_label,
           item_type, skill_tag, points, explanation, answer_key_json
      FROM story_items
     WHERE story_id=?
     ORDER BY number ASC, item_id ASC
  ");
  $stmt->bind_param('i', $src_id);
  $stmt->execute();
  $rs = $stmt->get_result();
  while ($row = $rs->fetch_assoc()) $items[] = $row;
  $stmt->close();
  if (!$items) throw new Exception('Source story has no questions to clone.');

  // Load choices for ALL items (one query with IN)
  $itemIds = array_column($items, 'item_id');
  $in      = implode(',', array_fill(0, count($itemIds), '?'));
  $types   = str_repeat('i', count($itemIds));
  $choicesByItem = [];

  if ($itemIds) {
    $sqlCh = "
      SELECT item_id, label, text, is_correct, sequence
        FROM story_choices
       WHERE item_id IN ($in)
       ORDER BY item_id ASC, sequence ASC, choice_id ASC
    ";
    $stmt = $conn->prepare($sqlCh);
    $stmt->bind_param($types, ...$itemIds);
    $stmt->execute();
    $rc = $stmt->get_result();
    while ($r = $rc->fetch_assoc()) {
      $iid = (int)$r['item_id'];
      if (!isset($choicesByItem[$iid])) $choicesByItem[$iid] = [];
      $choicesByItem[$iid][] = $r;
    }
    $stmt->close();
  }

  // Find current max sequence in the set to append clones nicely
  $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence),0) AS m FROM stories WHERE set_id=?");
  $stmt->bind_param('i', $set_id);
  $stmt->execute();
  $maxSeq = (int)($stmt->get_result()->fetch_assoc()['m'] ?? 0);
  $stmt->close();

  // Prepare inserts
  $insStory = $conn->prepare("
    INSERT INTO stories
      (set_id, title, passage_html, image_path, word_count, time_limit_seconds, sequence, status, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, 'published', NOW(), NOW())
  ");
  $insItem = $conn->prepare("
    INSERT INTO story_items
      (story_id, number, question_text, section_code, sub_label, item_type, skill_tag, points, explanation, answer_key_json, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");
  $insChoice = $conn->prepare("
    INSERT INTO story_choices (item_id, label, text, is_correct, sequence)
    VALUES (?, ?, ?, ?, ?)
  ");

  $conn->begin_transaction();

  $made = 0;
  for ($i = 1; $i <= $needed; $i++) {
    $seq   = ++$maxSeq;
    $title = ($src['title'] ?? 'Story') . ' (Copy ' . ($curPub + $i) . ')';

    // story
    $insStory->bind_param(
      'isssiii',
      $set_id,
      $title,
      $src['passage_html'],
      $src['image_path'],
      $word_count,
      $src['time_limit_seconds'],
      $seq
    );
    $insStory->execute();
    $newStoryId = (int)$conn->insert_id;

    // items + choices
    $idMap = [];
    foreach ($items as $it) {
      $insItem->bind_param(
        'iisssssiss',
        $newStoryId,
        $it['number'],
        $it['question_text'],
        $it['section_code'],
        $it['sub_label'],
        $it['item_type'],
        $it['skill_tag'],
        $it['points'],
        $it['explanation'],
        $it['answer_key_json']
      );
      $insItem->execute();
      $newItemId = (int)$conn->insert_id;
      $idMap[(int)$it['item_id']] = $newItemId;

      $srcChoices = $choicesByItem[(int)$it['item_id']] ?? [];
      foreach ($srcChoices as $ch) {
        $iid = $newItemId;
        $lab = (string)$ch['label'];
        $txt = (string)$ch['text'];
        $isc = (int)$ch['is_correct'];
        $cseq= (int)$ch['sequence'];
        $insChoice->bind_param('issii', $iid, $lab, $txt, $isc, $cseq);
        $insChoice->execute();
      }
    }

    $made++;
  }

  $insChoice->close();
  $insItem->close();
  $insStory->close();

  $conn->commit();

  flash_set('ok', "Cloned {$made} stor" . ($made>1?'ies':'y') . " — set now has up to $TARGET published stories.");
  header('Location: pb_manage.php?set_id=' . $set_id); exit;

} catch (Throwable $e) {
  if (isset($conn) && !$conn->connect_errno) { @mysqli_rollback($conn); }
  flash_set('err', 'Clone failed: ' . $e->getMessage());
  $set_id = (int)($_POST['set_id'] ?? 0);
  header('Location: pb_manage.php?set_id=' . $set_id); exit;
}
    