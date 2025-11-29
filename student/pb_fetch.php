<?php
// student/pb_fetch.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
  if ($attempt_id <= 0) throw new RuntimeException('bad_attempt');

  // 1) Verify attempt belongs to this student + PB + in_progress
  $st = $conn->prepare("
    SELECT a.attempt_id, a.student_id, a.set_id, a.level_id, a.status
    FROM assessment_attempts a
    WHERE a.attempt_id=? AND a.student_id=? AND a.set_type='PB' AND a.status='in_progress'
    LIMIT 1
  ");
  $st->bind_param('ii', $attempt_id, $_SESSION['user_id']);
  $st->execute();
  $att = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$att) throw new RuntimeException('attempt_not_found');

  // 2) Kunin ang FIRST story ng attempt (kasama ang stories.notes para sa directions)
// 2) Kunin ang FIRST UNFINISHED story ng attempt
$st = $conn->prepare("
  SELECT ast.attempt_story_id, ast.story_id, ast.sequence,
         s.title, s.passage_html, s.image_path, s.time_limit_seconds, s.notes
  FROM attempt_stories ast
  JOIN stories s ON s.story_id = ast.story_id
  WHERE ast.attempt_id = ?
    AND (ast.score IS NULL)                -- <- unfinished only
  ORDER BY ast.sequence ASC, ast.attempt_story_id ASC
  LIMIT 1
");
$st->bind_param('i', $attempt_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  // wala nang unfinished => done
  echo json_encode(['ok'=>true, 'done'=>true], JSON_UNESCAPED_UNICODE);
  exit;
}


  if (!$row) throw new RuntimeException('no_stories_for_attempt');

  $story_id = (int)$row['story_id'];
  $story = [
    'attempt_story_id' => (int)$row['attempt_story_id'],
    'story_id'         => $story_id,
    'title'            => (string)$row['title'],
    'passage_html'     => (string)($row['passage_html'] ?? ''),
    'image'            => $row['image_path'] ?: null,
    'author'        => $row['author'], 
    'time_limit'       => (int)($row['time_limit_seconds'] ?? 0),
  ];

  // 2b) Decode directions / banks mula stories.notes
  $meta = ['read'=>['directions'=>''], 'vocab'=>[], 'wordstudy'=>[]];
  if (!empty($row['notes'])) {
    $cfg = json_decode($row['notes'], true);
    if (isset($cfg['sections']['read']['directions'])) {
      $meta['read']['directions'] = (string)$cfg['sections']['read']['directions'];
    }
    foreach (($cfg['setConfigs'] ?? []) as $key => $scfg) {
      if (preg_match('/^(vocab|wordstudy):([A-E])$/', (string)$key, $m)) {
        $sec = $m[1]; $L = $m[2];
        $meta[$sec][$L] = [
          'directions' => (string)($scfg['directions'] ?? ''),
          'bank'       => is_array($scfg['bank'] ?? null) ? array_values($scfg['bank']) : [],
        ];
      }
    }
  }

  // 3) Items for this story (READ → VOCAB → WORDSTUDY → IMAGINE)
  $it = $conn->prepare("
    SELECT item_id, number, question_text, section_code, sub_label, item_type, answer_key_json
    FROM story_items
    WHERE story_id = ?
    ORDER BY FIELD(section_code,'read','vocab','wordstudy','imagine'), number, item_id
  ");
  $it->bind_param('i', $story_id);
  $it->execute();
  $ri = $it->get_result();

  $items = [];
  while ($I = $ri->fetch_assoc()) {
    $iid  = (int)$I['item_id'];
    $type = (string)$I['item_type'];
    $ak   = $I['answer_key_json'] ? json_decode($I['answer_key_json'], true) : null;

    $item = [
      'item_id'      => $iid,
      'number'       => (int)$I['number'],
      'section_code' => (string)$I['section_code'],
      'sub_label'    => (string)($I['sub_label'] ?? ''),
      'item_type'    => $type,
      'question'     => (string)$I['question_text'],
      'answer_key'   => $ak,
      'choices'      => []
    ];

// Load choices for choice-based items (single, ab, tf, yn)
if (in_array($type, ['single','ab','tf','yn'], true)) {
  $ch = $conn->prepare("
    SELECT label, text
    FROM story_choices
    WHERE item_id=?
    ORDER BY sequence ASC, label ASC
  ");
  $ch->bind_param('i', $iid);
  $ch->execute();
  $rch = $ch->get_result();
  while ($C = $rch->fetch_assoc()) {
    $item['choices'][] = [
      'label' => (string)($C['label'] ?? ''),
      'text'  => (string)($C['text'] ?? ''),
    ];
  }
  $ch->close();

  // Fallback kung walang rows (hindi dapat mangyari pero safe)
  if ($type === 'ab' && empty($item['choices'])) {
    $item['choices'] = [['label'=>'A','text'=>'A'],['label'=>'B','text'=>'B']];
  }
  if (($type === 'tf' || $type === 'yn') && empty($item['choices'])) {
    $item['choices'] = ($type === 'tf')
      ? [['label'=>'T','text'=>'True'], ['label'=>'F','text'=>'False']]
      : [['label'=>'Y','text'=>'Yes'],  ['label'=>'N','text'=>'No']];
  }
}


    $items[] = $item;
  }
  $it->close();

  echo json_encode([
    'ok'   => true,
    'time' => [
      'limit_seconds' => 0,
      'now_ts'        => time(),
      'started_ts'    => time(),
      'deadline_ts'   => null,
      'time_up'       => false
    ],
    'story' => $story,
    'items' => $items,
    'meta'  => $meta
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
