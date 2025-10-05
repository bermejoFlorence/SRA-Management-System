<?php
// admin/pb_items_preview.php  — READ-ONLY preview from stories.notes (no DB writes)
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$set_id   = isset($_GET['set_id'])   ? (int)$_GET['set_id']   : 0;
$story_id = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;
if ($set_id <= 0 || $story_id <= 0) {
  http_response_code(400);
  echo "Missing ?set_id=&story_id=";
  exit;
}

$stmt = $conn->prepare("
  SELECT s.title, s.notes, ss.set_type
  FROM stories s
  JOIN story_sets ss ON ss.set_id = s.set_id
  WHERE s.set_id = ? AND s.story_id = ? AND ss.set_type='PB'
  LIMIT 1
");
$stmt->bind_param('ii', $set_id, $story_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo "Story not found or not PB set."; exit; }

$title = (string)($row['title'] ?? 'Story');
$doc   = [];
if (!empty($row['notes'])) {
  $tmp = json_decode($row['notes'], true);
  if (is_array($tmp)) $doc = $tmp;
}

// -------- helpers ----------
function neat($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function map_item_type_for_db($t){
  // JSON types we use: single | tf | yn | text | bank
  // DB enum (per your screenshot): single, tf, yn, ab, text, text_bank, essay
  if ($t === 'bank') return 'text_bank';
  return $t; // single/tf/yn/text are 1:1
}
function section_code_from_bucket($bucket){
  if ($bucket === 'read:') return 'read';
  if (preg_match('/^vocab:/',$bucket)) return 'vocab';
  if (preg_match('/^wordstudy:/',$bucket)) return 'wordstudy';
  return 'read';
}

// -------- parse ----------
$itemsBuckets = isset($doc['items']) && is_array($doc['items']) ? $doc['items'] : [];
$setConfigs   = isset($doc['setConfigs']) && is_array($doc['setConfigs']) ? $doc['setConfigs'] : [];

$groups = [];    // [ ['label'=>..., 'bucket'=>'read:', 'items'=>[ ... ]] ... ]
$total  = 0;
$typeCounts = ['single'=>0,'tf'=>0,'yn'=>0,'text'=>0,'bank'=>0];

$pushGroup = function($label,$bucket) use (&$groups){ $groups[]=['label'=>$label,'bucket'=>$bucket,'items'=>[]]; };
$pushItem = function(&$group, $bucket, $it, $bankWords=null) use (&$total,&$typeCounts){
  $t = (string)($it['item_type'] ?? '');
  if (!isset($typeCounts[$t])) $typeCounts[$t]=0;
  $typeCounts[$t]++;

  $out = [
    'json_type'   => $t,
    'db_item_type'=> map_item_type_for_db($t),
    'section_code'=> section_code_from_bucket($bucket),
    'q'           => (string)($it['question_text'] ?? ''),
    'choices'     => [],   // map ['A'=>'text', ...]
    'key'         => '',   // 'A'/'B'/... or '' for text/bank
    'answers'     => [],   // for text one_of[]
    'bank'        => $bankWords ?: [],
  ];

  if ($t === 'single'){
    $chs = is_array($it['choices'] ?? null) ? $it['choices'] : [];
    $letters = ['A','B','C','D'];
    foreach ($letters as $i=>$L){ $out['choices'][$L] = (string)($chs[$i]['text'] ?? ''); }
    $out['key'] = in_array(($it['answer_key'] ?? 'A'), $letters, true) ? $it['answer_key'] : 'A';
  } elseif ($t==='tf' || $t==='yn'){
    $chs = is_array($it['choices'] ?? null) ? $it['choices'] : [];
    $letters = ['A','B'];
    foreach ($letters as $i=>$L){ $out['choices'][$L] = (string)($chs[$i]['text'] ?? ''); }
    $out['key'] = in_array(($it['answer_key'] ?? 'A'), $letters, true) ? $it['answer_key'] : 'A';
  } elseif ($t==='text'){
    $ak = $it['answer_key'] ?? [];
    $one = [];
    if (isset($ak['one_of']) && is_array($ak['one_of'])) {
      foreach ($ak['one_of'] as $s){ $s = trim((string)$s); if ($s!=='') $one[]=$s; }
    }
    $out['answers'] = array_values(array_unique($one));
  } elseif ($t==='bank'){
    $out['key'] = (string)($it['answer_key'] ?? '');
  }

  $group['items'][] = $out; $total++;
};

// 1) READ
if (!empty($itemsBuckets['read:']) && is_array($itemsBuckets['read:'])) {
  $pushGroup('Well, Did You Read? (read:)', 'read:');
  foreach ($itemsBuckets['read:'] as $it) if (is_array($it)) $pushItem($groups[array_key_last($groups)], 'read:', $it, null);
}

// 2) Learn About Words (vocab/wordstudy)
foreach ($itemsBuckets as $bucket => $list){
  if ($bucket==='read:') continue;
  if (!preg_match('/^(vocab|wordstudy):([A-E])$/', (string)$bucket, $m)) continue;
  $block = $m[1]; $L = $m[2];
  $label = ($block==='vocab') ? "Learn About Words — Vocabulary (Set $L)" : "Learn About Words — Word Study (Set $L)";
  $cfg   = isset($setConfigs[$bucket]) && is_array($setConfigs[$bucket]) ? $setConfigs[$bucket] : [];
  $bank  = [];
  if (is_array($cfg['bank'] ?? null)) {
    foreach ($cfg['bank'] as $w){ $w = trim((string)$w); if($w!=='') $bank[]=$w; }
  }
  $pushGroup($label, $bucket);
  if (is_array($list)){
    foreach ($list as $it) if (is_array($it)) $pushItem($groups[array_key_last($groups)], $bucket, $it, $bank);
  }
}

// -------- view ----------
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<title>PB Preview — <?= neat($title) ?></title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f5f4;margin:0;padding:16px;color:#0b2919}
  .wrap{max-width:1100px;margin:0 auto}
  .head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .card{background:#fff;border:1px solid #e6efe6;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:14px;margin:10px 0}
  .group{margin-top:10px}
  .gtitle{background:#f8fbf8;border:1px solid #e5eee5;border-radius:10px;padding:10px 12px;font-weight:900}
  .item{border:1px solid #eef2f4;border-radius:12px;padding:12px;margin:10px 0;background:#fcfcfc}
  .muted{color:#6b8b6b}
  .badge{display:inline-block;border:1px solid #d9e3d9;background:#eff3ef;border-radius:999px;padding:3px 8px;font-weight:700;margin-left:6px}
  .ok{background:#eaf7ea;border-color:#bfe3c6;color:#155724}
  .row{display:flex;gap:14px;align-items:flex-start}
  .num{width:28px;height:28px;border-radius:999px;border:1px solid #cfe3ff;background:#eef7ff;color:#1b3a7a;font-weight:800;display:grid;place-items:center;margin-top:3px}
  .q{font-weight:700}
  .choices{margin-top:6px}
  .answers{margin-top:6px}
  code{background:#f6f8fa;border:1px solid #e5e7eb;border-radius:6px;padding:2px 6px}
  .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:6px}
</style>
</head><body><div class="wrap">

<div class="head">
  <h2 style="margin:0">PB Preview — <?= neat($title) ?></h2>
  <div class="muted">Set ID: <?= (int)$set_id ?> • Story ID: <?= (int)$story_id ?></div>
</div>

<div class="card">
  <div><b>Total items:</b> <?= (int)$total ?></div>
  <div class="muted" style="margin-top:6px">
    Types —
    single: <b><?= (int)$typeCounts['single'] ?></b>,
    tf: <b><?= (int)$typeCounts['tf'] ?></b>,
    yn: <b><?= (int)$typeCounts['yn'] ?></b>,
    text: <b><?= (int)$typeCounts['text'] ?></b>,
    bank: <b><?= (int)$typeCounts['bank'] ?></b>
  </div>
  <div class="muted" style="margin-top:6px">
    <b>DB mapping:</b>
    single → single, tf → tf, yn → yn, text → text, bank → <code>text_bank</code>
  </div>
</div>

<?php foreach ($groups as $g): ?>
  <div class="group card">
    <div class="gtitle"><?= neat($g['label']) ?> <span class="badge"><?= count($g['items']) ?></span></div>
    <?php foreach ($g['items'] as $i=>$it): ?>
      <div class="item">
        <div class="row">
          <div class="num"><?= $i+1 ?></div>
          <div style="flex:1">
            <div class="q"><?= neat($it['q']) ?></div>
            <div class="muted" style="margin-top:2px">
              json_type: <code><?= neat($it['json_type']) ?></code> •
              db_item_type: <code><?= neat($it['db_item_type']) ?></code> •
              section_code: <code><?= neat($it['section_code']) ?></code>
            </div>

            <?php if (in_array($it['json_type'], ['single','tf','yn'], true)): ?>
              <div class="choices grid">
                <?php foreach ($it['choices'] as $L=>$txt): ?>
                  <div><?= strtolower($L) ?>. <?= neat($txt) ?> <?= ($it['key']===$L?'<span class="badge ok">correct</span>':'') ?></div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($it['json_type']==='text'): ?>
              <div class="answers">
                <b>Accepted answers:</b>
                <?php if (!$it['answers']): ?>
                  <span class="muted">none</span>
                <?php else: ?>
                  <?php foreach ($it['answers'] as $a): ?>
                    <code><?= neat($a) ?></code>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php elseif ($it['json_type']==='bank'): ?>
              <div class="answers" style="margin-top:6px">
                <b>Correct word:</b> <code><?= neat($it['key']) ?></code>
              </div>
              <div class="answers">
                <b>Word Bank:</b>
                <?php if (!$it['bank']): ?>
                  <span class="muted">empty</span>
                <?php else: ?>
                  <?php foreach ($it['bank'] as $w): ?><code><?= neat($w) ?></code><?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

</div></body></html>
