<?php
// admin/pb_items_import.php
// Move questions from stories.notes JSON -> story_items + story_choices
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function neat($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function map_item_type_for_db($t){
  // JSON: single | tf | yn | text | bank  -> DB enum
  return $t === 'bank' ? 'text_bank' : $t;
}
function section_code_from_bucket($bucket){
  if ($bucket === 'read:') return 'read';
  if (preg_match('/^vocab:/',$bucket)) return 'vocab';
  if (preg_match('/^wordstudy:/',$bucket)) return 'wordstudy';
  return 'read';
}
function sub_label_from_bucket($bucket){
  if (preg_match('/^[a-z]+:([A-E])$/i', $bucket, $m)) return $m[1];
  return null;
}

$set_id   = isset($_GET['set_id'])   ? (int)$_GET['set_id']   : 0;
$story_id = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;
$wipe     = isset($_GET['wipe'])     ? (int)$_GET['wipe']     : 1;

if ($set_id <= 0 || $story_id <= 0) {
  http_response_code(400);
  echo "Missing ?set_id=&story_id=";
  exit;
}

// pull story + notes (PB only)
$stmt = $conn->prepare("
  SELECT s.title, s.notes
  FROM stories s
  JOIN story_sets ss ON ss.set_id = s.set_id AND ss.set_type='PB'
  WHERE s.set_id = ? AND s.story_id = ?
  LIMIT 1
");
$stmt->bind_param('ii', $set_id, $story_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo "Story not found (or not PB)."; exit; }

$title = (string)($row['title'] ?? 'Story');
$doc   = [];
if (!empty($row['notes'])) {
  $tmp = json_decode($row['notes'], true);
  if (is_array($tmp)) $doc = $tmp;
}

$itemsBuckets = isset($doc['items']) && is_array($doc['items']) ? $doc['items'] : [];
$setConfigs   = isset($doc['setConfigs']) && is_array($doc['setConfigs']) ? $doc['setConfigs'] : [];

// ---- Build a flat ordered list of items (read -> vocab A..E -> wordstudy A..E) ----
$orderKeys = [];
if (!empty($itemsBuckets['read:'])) $orderKeys[] = 'read:';
foreach (['A','B','C','D','E'] as $L){ if (isset($itemsBuckets["vocab:$L"]))     $orderKeys[] = "vocab:$L"; }
foreach (['A','B','C','D','E'] as $L){ if (isset($itemsBuckets["wordstudy:$L"])) $orderKeys[] = "wordstudy:$L"; }

$flat = []; // each: ['bucket','db_item_type','section_code','sub_label','question','choices','key','answers','bank']
$typeCounts = ['single'=>0,'tf'=>0,'yn'=>0,'text'=>0,'bank'=>0];

foreach ($orderKeys as $bucket) {
  $list = $itemsBuckets[$bucket] ?? [];
  $bank = [];
  if ($bucket !== 'read:' && isset($setConfigs[$bucket])) {
    $cfg = $setConfigs[$bucket];
    if (is_array($cfg['bank'] ?? null)) {
      foreach ($cfg['bank'] as $w) { $w = trim((string)$w); if ($w!=='') $bank[] = $w; }
    }
  }

  foreach ((array)$list as $it) {
    if (!is_array($it)) continue;
    $t = (string)($it['item_type'] ?? '');
    if (!isset($typeCounts[$t])) $typeCounts[$t]=0;
    $typeCounts[$t]++;

    $row = [
      'bucket'       => $bucket,
      'db_item_type' => map_item_type_for_db($t),
      'section_code' => section_code_from_bucket($bucket),
      'sub_label'    => sub_label_from_bucket($bucket),
      'question'     => (string)($it['question_text'] ?? ''),
      'choices'      => [],     // map [A=>text, ...] for single/tf/yn
      'key'          => '',     // 'A'/'B'/... or '' for text/text_bank
      'answers'      => [],     // for text one_of[]
      'bank'         => $bank,  // for text_bank
    ];

    if ($t === 'single'){
      $chs = is_array($it['choices'] ?? null) ? $it['choices'] : [];
      foreach (['A','B','C','D'] as $i=>$L){ $row['choices'][$L] = (string)($chs[$i]['text'] ?? ''); }
      $row['key'] = in_array(($it['answer_key'] ?? 'A'), ['A','B','C','D'], true) ? $it['answer_key'] : 'A';
    } elseif ($t==='tf' || $t==='yn'){
      $chs = is_array($it['choices'] ?? null) ? $it['choices'] : [];
      foreach (['A','B'] as $i=>$L){ $row['choices'][$L] = (string)($chs[$i]['text'] ?? ''); }
      $row['key'] = in_array(($it['answer_key'] ?? 'A'), ['A','B'], true) ? $it['answer_key'] : 'A';
    } elseif ($t==='text'){
      $ak = $it['answer_key'] ?? [];
      $one = [];
      if (is_array($ak['one_of'] ?? null)) {
        foreach ($ak['one_of'] as $s){ $s=trim((string)$s); if($s!=='') $one[]=$s; }
      }
      $row['answers'] = array_values(array_unique($one));
    } elseif ($t==='bank'){
      $row['key'] = (string)($it['answer_key'] ?? '');
    }

    $flat[] = $row;
  }
}

$inserted_items = 0;
$inserted_choices = 0;

$conn->begin_transaction();
try {
  if ($wipe) {
    // delete in correct order (choices depend on items)
    $del = $conn->prepare("DELETE sc FROM story_choices sc JOIN story_items si ON si.item_id=sc.item_id WHERE si.story_id=?");
    $del->bind_param('i',$story_id); $del->execute(); $del->close();

    $del2 = $conn->prepare("DELETE FROM story_items WHERE story_id=?");
    $del2->bind_param('i',$story_id); $del2->execute(); $del2->close();
  }

  // prepare inserts
  $insItem = $conn->prepare("
    INSERT INTO story_items
      (story_id, number, question_text, stem, section_code, sub_label, item_type, skill_tag, points, explanation, answer_key_json, created_at, updated_at)
    VALUES
      (?,?,?,?,?,?,?,NULL,1,NULL,?, NOW(), NOW())
  ");
  $insChoice = $conn->prepare("
    INSERT INTO story_choices (item_id, label, text, is_correct, sequence)
    VALUES (?,?,?,?,?)
  ");

  $num = 0;
  foreach ($flat as $it) {
    $num++;
    $ak_json = null;
    // store compact answer json for text/text_bank/mcq
    if ($it['db_item_type'] === 'text') {
      $ak_json = json_encode(['one_of'=>$it['answers'],'normalize'=>['lower','trim']], JSON_UNESCAPED_UNICODE);
    } elseif ($it['db_item_type'] === 'text_bank') {
      $ak_json = json_encode(['correct'=>$it['key'],'bank'=>$it['bank']], JSON_UNESCAPED_UNICODE);
    } else {
      // mcq/tf/yn: store key for convenience
      $ak_json = json_encode(['key'=>$it['key']], JSON_UNESCAPED_UNICODE);
    }

    $stem = ''; // optional field; leave blank
    $insItem->bind_param(
      'iissssss',
      $story_id, $num, $it['question'], $stem,
      $it['section_code'], $it['sub_label'], $it['db_item_type'],
      $ak_json
    );
    $insItem->execute();
    $item_id = $insItem->insert_id;
    $inserted_items++;

    // Insert choices for MCQ/TF/YN
    if (in_array($it['db_item_type'], ['single','tf','yn'], true)) {
      $seq = 0;
      foreach ($it['choices'] as $L => $txt) {
        $seq++;
        $is_correct = ($it['key'] === $L) ? 1 : 0;
        $insChoice->bind_param('issii', $item_id, $L, $txt, $is_correct, $seq);
        $insChoice->execute();
        $inserted_choices++;
      }
    }
  }

  $insItem->close();
  $insChoice->close();

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Import failed: ".neat($e->getMessage());
  exit;
}

// ---- simple result page ----
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>PB Import Result</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f6faf7;margin:0;padding:18px;color:#0b2919}
  .card{background:#fff;border:1px solid #e6efe6;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:14px;max-width:900px;margin:0 auto}
  .ok{color:#14532d}
  .muted{color:#6b7c6b}
  .row{margin:6px 0}
  .btn{display:inline-block;margin-top:10px;padding:9px 12px;border-radius:10px;border:1px solid #dfe5e8;background:#fff;text-decoration:none;color:inherit;font-weight:700}
  .btn:hover{box-shadow:0 2px 10px rgba(0,0,0,.08)}
</style>
</head><body>
<div class="card">
  <h3 style="margin:0 0 6px">Imported: <?= neat($title) ?></h3>
  <div class="muted">Set ID: <?= (int)$set_id ?> • Story ID: <?= (int)$story_id ?></div>
  <div class="row ok"><b>Items inserted:</b> <?= (int)$inserted_items ?></div>
  <div class="row ok"><b>Choices inserted:</b> <?= (int)$inserted_choices ?></div>
  <div class="row">
    Types — single: <b><?= (int)$typeCounts['single'] ?></b>,
    tf: <b><?= (int)$typeCounts['tf'] ?></b>,
    yn: <b><?= (int)$typeCounts['yn'] ?></b>,
    text: <b><?= (int)$typeCounts['text'] ?></b>,
    bank: <b><?= (int)$typeCounts['bank'] ?></b> (saved as <code>text_bank</code>)
  </div>
  <div class="row muted"><?= $wipe ? 'Old items/choices were deleted for this story before import.' : 'Existing items were kept (no wipe).' ?></div>

  <a class="btn" href="pb_manage.php?set_id=<?= (int)$set_id ?>">← Back to PB Stories</a>
  <a class="btn" href="pb_items_preview.php?set_id=<?= (int)$set_id ?>&story_id=<?= (int)$story_id ?>">🛈 Preview JSON</a>
</div>
</body></html>
