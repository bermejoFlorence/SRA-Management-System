<?php
// admin/pb_questions_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Normalize image metadata coming from the editor UI.
 * Returns:
 *   - array{position, alt, name, type, size} when present
 *   - null if empty/removed (e.g., name=='' and size==0)
 */
// add/modify this helper near the top
function normImage($img){
  if (!$img || !is_array($img)) return null;
  $name = trim((string)($img['name'] ?? ''));
  $type = trim((string)($img['type'] ?? ''));
  $size = (int)($img['size'] ?? 0);
  $url  = trim((string)($img['url']  ?? ''));   // <-- add this
  $pos  = strtolower((string)($img['position'] ?? 'below'));
  $alt  = trim((string)($img['alt'] ?? ''));

  if ($name === '' && $size === 0 && $type === '' && $url === '') return null;

  return [
    'position' => in_array($pos, ['above','below'], true) ? $pos : 'below',
    'alt'      => $alt,
    'name'     => $name,
    'type'     => $type,
    'size'     => $size,
    'url'      => $url,    // <-- keep url
  ];
}


// ---- CSRF (matches your fetch header 'X-CSRF-Token') ----
$hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$hdr || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $hdr)) {
  fail('Invalid CSRF token', 403);
}

// ---- Read JSON body ----
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) fail('Invalid JSON payload');

$set_id     = (int)($body['set_id']   ?? 0);
$story_id   = (int)($body['story_id'] ?? 0);
$sections   = $body['sections']   ?? [];
$setConfigs = $body['setConfigs'] ?? null;
$items      = $body['items']      ?? [];

if ($set_id <= 0 || $story_id <= 0) fail('Missing set_id or story_id');

// ---- Validate story belongs to set ----
$story = null;
if ($stmt = $conn->prepare("SELECT story_id FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
  $stmt->bind_param('ii', $story_id, $set_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $story = $res->fetch_assoc();
  $stmt->close();
}
if (!$story) fail('Story not found for this set', 404);

// ---- Normalize/validate items (single, ab, bank) ----
function normItem($it) {
  $t = strtolower(trim((string)($it['item_type'] ?? '')));
  if (!in_array($t, ['single','ab','bank'], true)) $t = 'single';

  $out = [
    'item_id'       => $it['item_id'] ?? null,
    'item_type'     => $t,
    'number'        => (int)($it['number'] ?? 0),
    'question_text' => trim((string)($it['question_text'] ?? '')),
    'choices'       => [],
    'answer_key'    => $it['answer_key'] ?? null,
  ];

  if ($t === 'single') {
    // A–D
    $choices = $it['choices'] ?? [];
    $def = [['label'=>'A'],['label'=>'B'],['label'=>'C'],['label'=>'D']];
    $out['choices'] = [];
    for ($i=0;$i<4;$i++){
      $lab = $def[$i]['label'];
      $txt = isset($choices[$i]['text']) ? (string)$choices[$i]['text'] : '';
      $out['choices'][] = ['label'=>$lab,'text'=>$txt];
    }
    $ak = strtoupper((string)($it['answer_key'] ?? 'A'));
    if (!in_array($ak, ['A','B','C','D'], true)) $ak = 'A';
    $out['answer_key'] = $ak;
  }
  elseif ($t === 'ab') {
    // A–B
    $choices = $it['choices'] ?? [];
    $a = isset($choices[0]['text']) ? (string)$choices[0]['text'] : '';
    $b = isset($choices[1]['text']) ? (string)$choices[1]['text'] : '';
    $out['choices'] = [['label'=>'A','text'=>$a],['label'=>'B','text'=>$b]];
    $ak = strtoupper((string)($it['answer_key'] ?? 'A'));
    if (!in_array($ak, ['A','B'], true)) $ak = 'A';
    $out['answer_key'] = $ak;
  }
  else { // bank
    // selection from set bank (UI enforces)
    $out['choices']    = [];
    $out['answer_key'] = trim((string)($it['answer_key'] ?? ''));
  }

  return $out;
}

// ensure buckets and renumber
$items = is_array($items) ? $items : [];
$normItems = [];
$total = 0;
foreach ($items as $bucket => $arr) {
  if (!is_array($arr)) $arr = [];
  $num = 1;
  $normList = [];
  foreach ($arr as $it) {
    $n = normItem($it);
    $n['number'] = $num++;
    $normList[] = $n;
    $total++;
  }
  $normItems[$bucket] = $normList;
}

// ---- Normalize sections + READ image metadata (no file upload here)
$sec = is_array($sections) ? $sections : [];
$sec['read'] = $sec['read'] ?? ['default_type'=>'', 'directions'=>''];
$sec['read']['image'] = normImage($sec['read']['image'] ?? null);

// ---- Normalize per-set configs (default_type, directions, bank, image)
$cfg = is_array($setConfigs) ? $setConfigs : [];
foreach ($cfg as $k => $v) {
  $cfg[$k] = [
    'default_type' => (string)($v['default_type'] ?? ''),
    'directions'   => (string)($v['directions'] ?? ''),
    'bank'         => array_values(
                        array_filter(
                          array_map('strval', (array)($v['bank'] ?? [])),
                          fn($w)=>$w!==''
                        )
                      ),
    'image'        => normImage($v['image'] ?? null), // <-- save Learn set image metadata
  ];
}

// ---- Persist into stories.notes ----
$notes = [
  'pb_editor_version' => 2,
  'sections'   => $sec,
  'setConfigs' => $cfg,
  'items'      => $normItems
];

$json = json_encode($notes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if ($json === false) fail('JSON encode failed: '.json_last_error_msg(), 500);

$conn->begin_transaction();
try {
  if ($stmt = $conn->prepare("UPDATE stories SET notes=?, updated_at=NOW() WHERE story_id=? LIMIT 1")) {
    $stmt->bind_param('si', $json, $story_id);
    $stmt->execute();
    $stmt->close();
  } else {
    throw new Exception('Failed to prepare UPDATE stories');
  }

  $conn->commit();
  echo json_encode(['ok'=>true, 'count'=>$total], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  $conn->rollback();
  fail('DB error: '.$e->getMessage(), 500);
}
