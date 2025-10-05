<?php
// admin/pb_questions_fetch.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $set_id    = isset($_GET['set_id'])   ? (int)$_GET['set_id']   : 0;
  $story_id  = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;
  $forEditor = (isset($_GET['for']) && $_GET['for'] === 'editor');
  $withDebug = isset($_GET['debug']);

  if ($set_id <= 0 || $story_id <= 0) {
    throw new RuntimeException('bad_params');
  }

  // ---- Story + notes (for directions, defaults, banks) ----
  $st = $conn->prepare("SELECT title, notes FROM stories WHERE set_id=? AND story_id=? LIMIT 1");
  $st->bind_param('ii', $set_id, $story_id);
  $st->execute();
  $srow = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$srow) throw new RuntimeException('story_not_found');

  $notes = [];
  if (!empty($srow['notes'])) {
    $tmp = json_decode($srow['notes'], true);
    if (is_array($tmp)) $notes = $tmp;
  }

  // =========================================================
  // =========== Directions Image Map (READ + per-set) =======
  // =========================================================
  // Make relative paths work from /admin/*
  $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // e.g. /sra/admin
  $appRoot   = preg_replace('#/admin$#', '', $scriptDir);    // e.g. /sra

  $rootify = function($p) use ($appRoot){
    if (!$p) return '';
    if (preg_match('#^https?://#i', $p)) return $p;         // already absolute URL
    if (strpos($p, $appRoot . '/') === 0) return $p;        // already app-rooted (/sra/...)
    if ($p[0] !== '/') $p = '/' . ltrim($p, './');          // make root-absolute
    return $appRoot . $p;                                   // prefix app root
  };

  $normalizeImg = function($arr) use ($rootify, $story_id){
    if (!is_array($arr)) return null;
    $pos  = strtolower((string)($arr['position'] ?? 'below'));
    $alt  = (string)($arr['alt'] ?? '');
    $url  = trim((string)($arr['url'] ?? ''));
    $path = trim((string)($arr['path'] ?? ''));
    $name = trim((string)($arr['name'] ?? '')); // legacy (filename only)

    // choose final path/URL
    $final = $url ?: $path;
    if (!$final && $name !== '') {
      // filename only -> assume uploads/stories/{story_id}/filename
      $final = "/uploads/stories/{$story_id}/{$name}";
    }

    if (!$final) return null;

    return [
      'url' => $rootify($final),
      'alt' => $alt,
      'pos' => in_array($pos, ['above','below'], true) ? $pos : 'below',
    ];
  };

  $imgMap = [];

  // READ image (sections.read.image)
  if ($n = $normalizeImg($notes['sections']['read']['image'] ?? null)) {
    $imgMap['read:'] = $n;
  }

  // Per-set images (setConfigs["vocab:A"].image / setConfigs["wordstudy:B"].image)
  foreach ((array)($notes['setConfigs'] ?? []) as $k => $cfg) {
    if ($n = $normalizeImg($cfg['image'] ?? null)) {
      $imgMap[$k] = $n;
    }
  }

  // =========================================================
  // ================= Directions text map ===================
  // =========================================================
  $dirMap = [];
  $dirMap['read:'] = trim((string)($notes['sections']['read']['directions'] ?? ''));
  $cfgFromNotes = (array)($notes['setConfigs'] ?? []);
  foreach ($cfgFromNotes as $k => $cfg) {
    $dirMap[$k] = trim((string)($cfg['directions'] ?? ''));
  }

  // =========================================================
  // ================= Helpers for grouping ==================
  // =========================================================
  $catTitle = function(string $sec, string $sub) {
    if ($sec === 'read')      return 'Well, Did You Read?';
    if ($sec === 'vocab')     return 'Vocabulary — Set ' . ($sub ?: '?');
    if ($sec === 'wordstudy') return 'Word Study — Set ' . ($sub ?: '?');
    return 'Questions';
  };
  $groupKey = function(string $sec, string $sub) { return $sec . ':' . $sub; };

  // =========================================================
  // ==================== Fetch items ========================
  // =========================================================
  $it = $conn->prepare("
    SELECT item_id, story_id, number, question_text, section_code, sub_label, item_type, answer_key_json
      FROM story_items
     WHERE story_id=?
     ORDER BY FIELD(section_code,'read','vocab','wordstudy','imagine'),
              sub_label, number, item_id
  ");
  $it->bind_param('i', $story_id);
  $it->execute();
  $rs = $it->get_result();

  $previewItems   = [];  // for modal/preview
  $editorBuckets  = [];  // for editor
  $editorSetCfg   = [];  // per-set cfg for editor
  $debugNotes     = [];  // optional debug

  while ($r = $rs->fetch_assoc()) {
    $iid  = (int)$r['item_id'];
    $sec  = (string)$r['section_code'];
    $sub  = (string)($r['sub_label'] ?? '');
    $type = (string)$r['item_type']; // DB types: single | tf | yn | ab | text | text_bank

    // --- Read answer_key_json ---
    $akArr = $r['answer_key_json'] ? json_decode($r['answer_key_json'], true) : null;
    if (!is_array($akArr)) $akArr = [];

    // --- Build choices for choice-based items ---
    $choicesAssoc = null;   // preview: ['A' => 'text', ...]
    $choicesArray = [];     // editor:  [['label'=>'A','text'=>'...'], ...]

    if (in_array($type, ['single','tf','yn','ab'], true)) {
      $ch = $conn->prepare("
        SELECT label, text
          FROM story_choices
         WHERE item_id=?
         ORDER BY sequence ASC, label ASC
      ");
      $ch->bind_param('i', $iid);
      $ch->execute();
      $crs = $ch->get_result();

      $choicesAssoc = [];
      while ($c = $crs->fetch_assoc()) {
        $choicesAssoc[$c['label']] = $c['text'];
        $choicesArray[] = ['label' => (string)$c['label'], 'text' => (string)$c['text']];
      }
      $ch->close();

      // If TF/YN but choices table is empty, provide a safe fallback
      if (!$choicesAssoc && $type === 'tf') {
        $choicesAssoc = ['A' => 'True', 'B' => 'False'];
        $choicesArray = [['label'=>'A','text'=>'True'],['label'=>'B','text'=>'False']];
      } elseif (!$choicesAssoc && $type === 'yn') {
        $choicesAssoc = ['A' => 'Yes', 'B' => 'No'];
        $choicesArray = [['label'=>'A','text'=>'Yes'],['label'=>'B','text'=>'No']];
      }

      if ($withDebug && !$choicesAssoc) {
        $debugNotes[] = ['item_id'=>$iid, 'issue'=>'missing_choices', 'type'=>$type];
      }
    }

    // --- Normalize answer info per type ---
    $previewKey     = null;  // letter for choice-types, or correct word for bank, or first text answer
    $previewAnsList = null;  // for 'text'
    $bankWords      = null;  // optional (if ever stored)

    if (in_array($type, ['single','tf','yn','ab'], true)) {
      // accept {"correct":"A"} or {"key":"A"}
      $previewKey = (string)($akArr['correct'] ?? $akArr['key'] ?? '');
      if ($withDebug && $previewKey === '') {
        $debugNotes[] = ['item_id'=>$iid, 'issue'=>'missing_choice_answer', 'type'=>$type];
      }
    } elseif ($type === 'text') {
      $previewAnsList = (is_array($akArr['one_of'] ?? null)) ? array_values($akArr['one_of']) : [];
      $previewKey     = !empty($previewAnsList) ? (string)$previewAnsList[0] : '';
      if ($withDebug && $previewKey === '') {
        $debugNotes[] = ['item_id'=>$iid, 'issue'=>'missing_text_answers', 'type'=>$type];
      }
    } elseif ($type === 'text_bank' || $type === 'bank') {
      $bankWords  = (is_array($akArr['bank'] ?? null)) ? array_values($akArr['bank']) : [];
      // accept {"word":"…"} (primary), or {"correct":"…"}, or {"answer":"…"}
      $previewKey = (string)($akArr['word'] ?? $akArr['correct'] ?? $akArr['answer'] ?? '');
      $type = 'bank'; // normalize
      if ($withDebug && $previewKey === '') {
        $debugNotes[] = ['item_id'=>$iid, 'issue'=>'missing_bank_answer', 'type'=>'bank'];
      }
    }

    // --- Compute unified display fields for preview ---
    $answerLabel   = null;   // A/B/C/D for choice-types
    $answerText    = null;   // the text behind that label
    $answerWord    = null;   // bank correct word
    $answerDisplay = null;   // compact display

    if (in_array($type, ['single','tf','yn','ab'], true)) {
      $answerLabel   = $previewKey ?: null;
      $answerText    = ($answerLabel && isset($choicesAssoc[$answerLabel])) ? (string)$choicesAssoc[$answerLabel] : null;
      $answerDisplay = $answerLabel ? ($answerText ? ($answerLabel . ' — ' . $answerText) : $answerLabel) : null;
    } elseif ($type === 'bank') {
      $answerWord    = $previewKey ?: null;
      $answerDisplay = $answerWord;
    } elseif ($type === 'text') {
      $answerDisplay = !empty($previewAnsList) ? (string)$previewAnsList[0] : null;
    }

    // ---- PREVIEW RECORD ----
    $gkey    = $groupKey($sec, $sub);   // 'read:' | 'vocab:A' | 'wordstudy:B'
    $imgMeta = $imgMap[$gkey] ?? [];    // image meta for this group

    $previewItems[] = [
      'category'       => $catTitle($sec, $sub),
      'directions'     => (string)($dirMap[$gkey] ?? ''),

      // image meta for modal
      'dir_image_url'  => (string)($imgMeta['url'] ?? ''),
      'dir_image_alt'  => (string)($imgMeta['alt'] ?? ''),
      'dir_image_pos'  => (string)($imgMeta['pos'] ?? 'below'),

      'question'       => (string)$r['question_text'],
      'type'           => $type,             // 'single' | 'ab' | 'bank' | 'text'
      'choices'        => $choicesAssoc,

      // extra unified fields
      'answer_label'   => $answerLabel,
      'answer_text'    => $answerText,
      'answer_word'    => $answerWord,
      'answer_display' => $answerDisplay,

      // Legacy 'key' so existing modal keeps showing something
      // (choice => letter, bank => word, text => first accepted answer)
      'key'            => (
        $type === 'bank'
          ? (string)($answerWord ?? '')
          : ($type === 'text'
              ? (string)(!empty($previewAnsList) ? $previewAnsList[0] : '')
              : (string)($answerLabel ?? '')
            )
      ),

      // keep for backward-compat
      'answers'        => $previewAnsList,
    ];

    // ---- EDITOR RECORDS (bucketed) ----
    $bucketKey = $gkey; // 'read:' | 'vocab:A' | 'wordstudy:B'
    if (!isset($editorBuckets[$bucketKey])) $editorBuckets[$bucketKey] = [];

    // Build editor-side answer payload
    $editorAnswer = null;
    if (in_array($r['item_type'], ['single','tf','yn','ab'], true)) {
      $editorAnswer = (string)($akArr['correct'] ?? $akArr['key'] ?? '');
    } elseif ($r['item_type'] === 'text_bank' || $r['item_type'] === 'bank') {
      $editorAnswer = (string)($akArr['word'] ?? $akArr['correct'] ?? $akArr['answer'] ?? '');
    }

    $editorBuckets[$bucketKey][] = [
      'item_id'       => $iid,
      'item_type'     => (string)$r['item_type'],  // raw DB type; client can migrate tf/yn->ab
      'number'        => (int)$r['number'],
      'question_text' => (string)$r['question_text'],
      'choices'       => $choicesArray,
      'answer_key'    => $editorAnswer
    ];

    // ensure set config shells exist for non-read buckets (editor defaults/bank/directions)
    if ($bucketKey !== 'read:') {
      if (!isset($editorSetCfg[$bucketKey])) {
        $editorSetCfg[$bucketKey] = [
          'default_type' => '',
          'directions'   => (string)($dirMap[$bucketKey] ?? ''),
          'bank'         => (array)($cfgFromNotes[$bucketKey]['bank'] ?? [])
        ];
      }
    }
  }
  $it->close();

  // Sections (READ) for editor (from notes)
  $editorSections = [
    'read' => [
      'default_type' => (string)($notes['sections']['read']['default_type'] ?? ''),
      'directions'   => (string)($notes['sections']['read']['directions']   ?? '')
    ]
  ];

  // If there are bank items but notes didn't have bank list, infer from items (fallback)
  foreach ($editorBuckets as $k => $list) {
    if ($k === 'read:') continue;
    $hasBankItem = false;
    foreach ($list as $row) {
      if (in_array(($row['item_type'] ?? ''), ['bank','text_bank'], true)) { $hasBankItem = true; break; }
    }
    if ($hasBankItem && empty($editorSetCfg[$k]['bank'])) {
      $agg = [];
      foreach ($list as $row) {
        if (in_array(($row['item_type'] ?? ''), ['bank','text_bank'], true) && !empty($row['answer_key'])) {
          $agg[] = (string)$row['answer_key'];
        }
      }
      $editorSetCfg[$k]['bank'] = array_values(array_unique($agg));
    }
  }

  // ---- Output ----
  $out = [
    'ok'    => true,
    'title' => (string)($srow['title'] ?? 'Story'),
    'items' => $previewItems
  ];
  if ($forEditor) {
    $out['editor'] = [
      'sections'   => $editorSections,
      'setConfigs' => $editorSetCfg,
      'items'      => $editorBuckets
    ];
  }
  if ($withDebug) {
    $out['debug'] = $debugNotes;
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
