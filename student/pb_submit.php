<?php
// student/pb_submit.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);

try {
  // ---- Read JSON body ----
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true) ?: $_POST;

  $attempt_id    = isset($in['attempt_id']) ? (int)$in['attempt_id'] : 0;
  $story_id      = isset($in['story_id'])   ? (int)$in['story_id']   : 0;
  $answersInput  = isset($in['answers'])    ? (array)$in['answers']  : []; // { item_id: value }
  $reading_secs  = isset($in['reading_secs']) ? max(0, (int)$in['reading_secs']) : 0;
  $passage_words = isset($in['passage_words']) ? max(0, (int)$in['passage_words']) : 0;

  if ($attempt_id <= 0 || $story_id <= 0) {
    throw new RuntimeException('bad_params');
  }

  // ---- Attempt ownership ----
  $stmt = $conn->prepare("
    SELECT attempt_id
    FROM assessment_attempts
    WHERE attempt_id=? AND student_id=? AND set_type='PB'
    LIMIT 1
  ");
  $stmt->bind_param('ii', $attempt_id, $student_id);
  $stmt->execute();
  $own = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$own) throw new RuntimeException('attempt_not_found');

  // ---- Load items for this story ----
  $it = $conn->prepare("
    SELECT item_id, number, item_type, section_code, sub_label, answer_key_json
    FROM story_items
    WHERE story_id=?
    ORDER BY number ASC, item_id ASC
  ");
  $it->bind_param('i', $story_id);
  $it->execute();
  $rs = $it->get_result();

  $items = [];
  $ids   = [];
  while ($row = $rs->fetch_assoc()) {
    $ids[]   = (int)$row['item_id'];
    $items[] = $row;
  }
  $it->close();

  // Choices (include choice_id para ma-persist)
  $choicesMap = [];           // item_id => [ ['label'=>'A','text'=>'…','choice_id'=>123], ... ]
  $choiceIdByLabel = [];      // item_id => [ 'A'=>123, 'B'=>124, ... ]
  if ($ids) {
    $inQ   = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT item_id, choice_id, label, text
            FROM story_choices
            WHERE item_id IN ($inQ)
            ORDER BY item_id ASC, sequence ASC, label ASC";
    $ch = $conn->prepare($sql);
    $ch->bind_param($types, ...$ids);
    $ch->execute();
    $crs = $ch->get_result();
    while ($c = $crs->fetch_assoc()) {
      $iid = (int)$c['item_id'];
      $lbl = (string)$c['label'];
      $rec = ['label'=>$lbl, 'text'=>(string)$c['text'], 'choice_id'=>(int)$c['choice_id']];
      if (!isset($choicesMap[$iid])) $choicesMap[$iid] = [];
      $choicesMap[$iid][] = $rec;
      $choiceIdByLabel[$iid][$lbl] = (int)$c['choice_id'];
    }
    $ch->close();
  }

  // ---- Grade ----
  $details = [];
  $correctCount = 0;
  $total = count($items);

  $norm = function($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/u',' ', $s);
    return mb_strtolower($s, 'UTF-8');
  };

  $answersToPersist = []; // each: [item_id, choice_id|null, answer_text|null, is_correct, score]

  foreach ($items as $row) {
    $iid   = (int)$row['item_id'];
    $type  = (string)$row['item_type'];
    $num   = (int)$row['number'];
    $ak    = $row['answer_key_json'] ? json_decode($row['answer_key_json'], true) : null;
    if (!is_array($ak)) $ak = [];

    $studentRaw   = $answersInput[$iid] ?? null; // could be index(0..), letter, or string
    $studentDisp  = '';
    $correctDisp  = '';
    $isCorrect    = false;
    $studentChoiceId = null;
    $answerText   = null;

    if (in_array($type, ['single','ab','tf','yn'], true)) {
      $correctLetter = (string)($ak['correct'] ?? $ak['key'] ?? '');
      // allow numeric index -> map to label
      if (is_numeric($studentRaw)) {
        $idx     = (int)$studentRaw;
        $letters = array_map(fn($r) => $r['label'], $choicesMap[$iid] ?? []);
        $studentLetter = isset($letters[$idx]) ? $letters[$idx] : '';
      } else {
        $studentLetter = (string)$studentRaw;
      }
      $studentLetter  = strtoupper($studentLetter);
      $correctLetter  = strtoupper($correctLetter);
      $studentDisp    = $studentLetter ?: '—';
      $correctDisp    = $correctLetter ?: '—';
      $isCorrect      = ($studentLetter !== '' && $studentLetter === $correctLetter);
      // choice_id (nullable)
      if ($studentLetter !== '') {
        $studentChoiceId = $choiceIdByLabel[$iid][$studentLetter] ?? null;
      }
    }
    elseif ($type === 'text' || $type === 'short') {
      $accepted = [];
      if (!empty($ak['one_of']) && is_array($ak['one_of'])) $accepted = array_values($ak['one_of']);
      if (!$accepted && !empty($ak['answer']))  $accepted = [$ak['answer']];
      if (!$accepted && !empty($ak['correct'])) $accepted = [$ak['correct']];

      $studentDisp = (string)$studentRaw;
      $answerText  = $studentDisp;
      $correctDisp = $accepted ? (string)$accepted[0] : '';
      $ns = $norm($studentDisp);
      foreach ($accepted as $cand) {
        if ($ns === $norm($cand)) { $isCorrect = true; break; }
      }
    }
    elseif ($type === 'text_bank' || $type === 'bank') {
      $correctWord = (string)($ak['word'] ?? $ak['correct'] ?? $ak['answer'] ?? '');
      $studentWord = (string)$studentRaw;
      $studentDisp = $studentWord ?: '—';
      $answerText  = $studentWord;
      $correctDisp = $correctWord ?: '—';
      $isCorrect   = ($correctWord !== '' && $norm($studentWord) === $norm($correctWord));
    } else {
      $studentDisp = (string)$studentRaw;
      $answerText  = $studentDisp;
      $correctDisp = (string)($ak['correct'] ?? $ak['answer'] ?? '');
      $isCorrect   = ($studentDisp !== '' && $norm($studentDisp) === $norm($correctDisp));
    }

    if ($isCorrect) $correctCount++;

    $details[] = [
      'qno'        => $num,
      'item_id'    => $iid,
      'type'       => $type,
      'your'       => $studentDisp,
      'correct'    => $correctDisp,
      'is_correct' => $isCorrect
    ];

    $answersToPersist[] = [
      'item_id'    => $iid,
      'choice_id'  => $studentChoiceId,          // may be null
      'answer_txt' => $answerText,               // may be null
      'is_correct' => $isCorrect ? 1 : 0,
      'score'      => $isCorrect ? 1 : 0
    ];
  }

  // ---- Score + reading metrics ----
  $percent = $total > 0 ? round(($correctCount/$total)*100, 2) : 0.0;

  $wpm = null; $wpm_note = null;
  if ($reading_secs > 0 && $passage_words > 0) {
    if ($reading_secs < 15 || $passage_words < 35) {
      $wpm_note = 'reading too short';
    } else {
      $wpm = (int)round(($passage_words / $reading_secs) * 60);
    }
  }

  // ---- Persist: attempt_stories + attempt_answers (+ attempt status) ----
  $conn->begin_transaction();

  // attempt_story_id
  $st = $conn->prepare("SELECT attempt_story_id FROM attempt_stories WHERE attempt_id=? AND story_id=? LIMIT 1");
  $st->bind_param('ii', $attempt_id, $story_id);
  $st->execute();
  $as = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$as) {
    // fallback: create if missing (should already exist)
    $seq = 999;
    $ins = $conn->prepare("INSERT INTO attempt_stories (attempt_id, story_id, sequence) VALUES (?,?,?)");
    $ins->bind_param('iii', $attempt_id, $story_id, $seq);
    $ins->execute();
    $attempt_story_id = (int)$conn->insert_id;
    $ins->close();
  } else {
    $attempt_story_id = (int)$as['attempt_story_id'];
  }

  // wipe old answers
  $del = $conn->prepare("DELETE FROM attempt_answers WHERE attempt_story_id=?");
  $del->bind_param('i', $attempt_story_id);
  $del->execute();
  $del->close();

  // insert answers
  $ins = $conn->prepare("
    INSERT INTO attempt_answers (attempt_story_id, item_id, choice_id, answer_text, is_correct, score_awarded, answered_at)
    VALUES (?,?,?,?,?,?,NOW())
  ");
  foreach ($answersToPersist as $a) {
    $cid = $a['choice_id'];   // may be null
    $txt = $a['answer_txt'];  // may be null
    // bind: i i i s i i
    $ins->bind_param(
      'iiisii',
      $attempt_story_id,
      $a['item_id'],
      $cid,
      $txt,
      $a['is_correct'],
      $a['score']
    );
    $ins->execute();
  }
  $ins->close();

 // ---- update per-story result (CLEAN VERSION) ----
// ---- update per-story result (CLEAN VERSION) ----
$max_score = $total;
$percFloat = (float)$percent;
$wpmVal    = ($wpm === null) ? null : (float)$wpm;

$upd = $conn->prepare("
  UPDATE attempt_stories
  SET reading_seconds = ?,
      wpm             = ?,
      score           = ?,
      max_score       = ?,
      percent         = ?
  WHERE attempt_story_id = ?
");
$upd->bind_param(
  'idiidi',           // i, d, i, i, d, i
  $reading_secs,      // i
  $wpmVal,            // d (puwedeng NULL)
  $correctCount,      // i
  $max_score,         // i
  $percFloat,         // d
  $attempt_story_id   // i
);
$upd->execute();
$upd->close();

  // If all stories are done, close attempt
  $q1 = $conn->prepare("SELECT COUNT(*) AS t FROM attempt_stories WHERE attempt_id=?");
  $q1->bind_param('i', $attempt_id);
  $q1->execute();
  $t = (int)($q1->get_result()->fetch_assoc()['t'] ?? 0);
  $q1->close();

  $q2 = $conn->prepare("SELECT COUNT(*) AS d FROM attempt_stories WHERE attempt_id=? AND score IS NOT NULL");
  $q2->bind_param('i', $attempt_id);
  $q2->execute();
  $d = (int)($q2->get_result()->fetch_assoc()['d'] ?? 0);
  $q2->close();

  if ($t > 0 && $d >= $t) {
    // aggregate totals
    $q3 = $conn->prepare("SELECT COALESCE(SUM(score),0) AS s, COALESCE(SUM(max_score),0) AS m FROM attempt_stories WHERE attempt_id=?");
    $q3->bind_param('i', $attempt_id);
    $q3->execute();
    $agg = $q3->get_result()->fetch_assoc();
    $q3->close();

    $S = (int)($agg['s'] ?? 0);
    $M = (int)($agg['m'] ?? 0);
    $P = $M > 0 ? round(($S/$M)*100, 2) : 0.0;

    $u = $conn->prepare("
      UPDATE assessment_attempts
      SET status='submitted',
          total_score=?, total_max=?, percent=?, submitted_at=NOW()
      WHERE attempt_id=?
    ");
    $u->bind_param('iidi', $S, $M, $P, $attempt_id);
    $u->execute();
    $u->close();
  }

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'score' => [
      'correct' => $correctCount,
      'total'   => $total,
      'percent' => (int)round($percent)
    ],
    'reading' => [
      'secs' => $reading_secs,
      'words'=> $passage_words,
      'wpm'  => $wpm,
      'note' => $wpm === null ? $wpm_note : null
    ],
    'recap' => array_values(array_filter($details, fn($d) => !$d['is_correct']))
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn && $conn->errno === 0) { /* ignore */ }
  http_response_code(200);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
