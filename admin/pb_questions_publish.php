<?php
// admin/pb_questions_publish.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json');

// Make mysqli throw exceptions so our try/catch works consistently
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

try {
  // ---- Inputs ----
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true) ?: $_POST;

  $set_id   = isset($in['set_id'])   ? (int)$in['set_id']   : 0;
  $story_id = isset($in['story_id']) ? (int)$in['story_id'] : 0;

  if ($set_id <= 0 || $story_id <= 0) {
    throw new Exception('Missing set_id or story_id.');
  }

  // ---- Validate story & get notes JSON ----
  $stmt = $conn->prepare("
    SELECT s.story_id, s.set_id, s.title, s.notes
    FROM stories s
    WHERE s.story_id=? AND s.set_id=? LIMIT 1
  ");
  $stmt->bind_param('ii', $story_id, $set_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) throw new Exception('Story not found for that set.');
  if (!$row['notes']) throw new Exception('No editor data found in stories.notes. Save from the editor first.');

  $data = json_decode($row['notes'], true);
  if (!is_array($data)) throw new Exception('Invalid editor JSON in stories.notes.');

  $itemsBuckets = $data['items'] ?? [];
  $safe = fn($s) => is_string($s) ? trim($s) : '';

  // Build ordered buckets (read first, then A→E vocab/wordstudy)
  $ordered = [];
  $ordered[] = ['read', null, $itemsBuckets['read:'] ?? []];
  foreach (['A','B','C','D','E'] as $L) {
    if (!empty($itemsBuckets["vocab:$L"]))     $ordered[] = ['vocab',     $L, $itemsBuckets["vocab:$L"]];
    if (!empty($itemsBuckets["wordstudy:$L"])) $ordered[] = ['wordstudy', $L, $itemsBuckets["wordstudy:$L"]];
  }

  // ---- Transaction ----
  $txStarted = false;
  $conn->begin_transaction();
  $txStarted = true;

  // wipe previous choices/items for this story
  $delC = $conn->prepare("
    DELETE sc FROM story_choices sc
    JOIN story_items si ON si.item_id = sc.item_id
    WHERE si.story_id = ?
  ");
  $delC->bind_param('i', $story_id);
  $delC->execute();
  $delC->close();

  $delI = $conn->prepare("DELETE FROM story_items WHERE story_id = ?");
  $delI->bind_param('i', $story_id);
  $delI->execute();
  $delI->close();

  // Prepare inserts
  $insItem = $conn->prepare("
    INSERT INTO story_items
      (story_id, number, question_text, stem, section_code, sub_label,
       item_type, skill_tag, points, explanation, answer_key_json,
       created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");

  $insChoice = $conn->prepare("
    INSERT INTO story_choices
      (item_id, label, text, is_correct, sequence)
    VALUES (?, ?, ?, ?, ?)
  ");

  $seq = 1; $nItems = 0; $nChoices = 0;

  foreach ($ordered as [$section_code, $sub_label, $list]) {
    if (!is_array($list)) continue;

    foreach ($list as $it) {
      if (!is_array($it)) continue;

      $type     = $it['item_type'] ?? '';
      $question = $safe($it['question_text'] ?? '');

      // Map UI type → DB
      $dbType = null; $mode = null;
      if ($type === 'single')      { $dbType = 'single';    $mode = 'choices'; }
      elseif ($type === 'ab')      { $dbType = 'ab';        $mode = 'choices'; }
      elseif ($type === 'bank')    { $dbType = 'text_bank'; $mode = 'bank';    }
      else { continue; } // skip unknown

      // Answer key JSON
      if ($mode === 'choices') {
        $answerKeyJson = json_encode(['correct' => ($it['answer_key'] ?? 'A')], JSON_UNESCAPED_UNICODE);
      } else {
        $answerKeyJson = json_encode(['word' => $safe($it['answer_key'] ?? '')], JSON_UNESCAPED_UNICODE);
      }

      $stem = '';
      $skill_tag = '';
      $points = 1;
      $explanation = null;
      $sub = $sub_label ? mb_substr($sub_label, 0, 10) : null;

      $insItem->bind_param(
        'iissssssiss',
        $story_id, $seq, $question, $stem, $section_code, $sub,
        $dbType, $skill_tag, $points, $explanation, $answerKeyJson
      );
      $insItem->execute();
      $item_id = $conn->insert_id;
      $nItems++;

      if ($mode === 'choices') {
        $choices = $it['choices'] ?? [];
        if (!is_array($choices) || !$choices) {
          $choices = [['label'=>'A','text'=>''],['label'=>'B','text'=>'']];
          if ($dbType==='single'){ $choices[]=['label'=>'C','text'=>'']; $choices[]=['label'=>'D','text'=>'']; }
        }
        $correct = $it['answer_key'] ?? 'A';

        $seqC = 1;
        foreach ($choices as $ch) {
          $label = $ch['label'] ?? '';
          if ($label === '') continue;
          $text = $safe($ch['text'] ?? '');
          $isCorrect = ($label === $correct) ? 1 : 0;

          $insChoice->bind_param('issii', $item_id, $label, $text, $isCorrect, $seqC);
          $insChoice->execute();
          $nChoices++; $seqC++;
        }
      }

      $seq++;
    }
  }

  $insChoice->close();
  $insItem->close();

  // touch updated_at
  $touch = $conn->prepare("UPDATE stories SET updated_at=NOW() WHERE story_id=?");
  $touch->bind_param('i', $story_id);
  $touch->execute();
  $touch->close();

  $conn->commit();
  $txStarted = false;

  echo json_encode([
    'ok' => true,
    'story_id' => $story_id,
    'inserted_items' => $nItems,
    'inserted_choices' => $nChoices
  ]);
  exit;

} catch (Throwable $e) {
  // Attempt rollback only if we started a transaction
  if (isset($txStarted) && $txStarted) {
    try { $conn->rollback(); } catch (Throwable $ignore) {}
  }
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
