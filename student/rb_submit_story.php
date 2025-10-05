<?php
// student/rb_submit_story.php
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

  $attempt_id       = isset($in['attempt_id']) ? (int)$in['attempt_id'] : 0;
  $attempt_story_id = isset($in['attempt_story_id']) ? (int)$in['attempt_story_id'] : 0; // optional; we can resolve if 0
  $story_id         = isset($in['story_id']) ? (int)$in['story_id'] : 0;
  $answersInput     = isset($in['answers']) && is_array($in['answers']) ? $in['answers'] : []; // [{item_id, choice_label}]
  $reading_seconds  = isset($in['reading_seconds']) ? max(0, (int)$in['reading_seconds']) : 0;

  if ($student_id <= 0 || $attempt_id <= 0 || $story_id <= 0) {
    throw new RuntimeException('bad_params');
  }

  // ---- Guard: attempt ownership + RB + in_progress ----
  $stmt = $conn->prepare("
    SELECT attempt_id, status
    FROM assessment_attempts
    WHERE attempt_id=? AND student_id=? AND set_type='RB'
    LIMIT 1
  ");
  $stmt->bind_param('ii', $attempt_id, $student_id);
  $stmt->execute();
  $att = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$att) throw new RuntimeException('attempt_not_found');
  if (($att['status'] ?? '') !== 'in_progress') {
    // Already submitted; treat as done
    echo json_encode(['ok'=>false,'error'=>'attempt_already_submitted']); exit;
  }

  // ---- Resolve attempt_story_id (and verify story belongs to this attempt) ----
  if ($attempt_story_id <= 0) {
    $rs = $conn->prepare("
      SELECT attempt_story_id
      FROM attempt_stories
      WHERE attempt_id=? AND story_id=? AND percent IS NULL
      ORDER BY sequence ASC, attempt_story_id ASC
      LIMIT 1
    ");
    $rs->bind_param('ii', $attempt_id, $story_id);
    $rs->execute();
    $row = $rs->get_result()->fetch_assoc();
    $rs->close();
    if (!$row) throw new RuntimeException('attempt_story_not_found');
    $attempt_story_id = (int)$row['attempt_story_id'];
  } else {
    // Validate that the given attempt_story_id matches the attempt + story
    $rs = $conn->prepare("
      SELECT 1
      FROM attempt_stories
      WHERE attempt_story_id=? AND attempt_id=? AND story_id=?
      LIMIT 1
    ");
    $rs->bind_param('iii', $attempt_story_id, $attempt_id, $story_id);
    $rs->execute();
    $ok = $rs->get_result()->fetch_row();
    $rs->close();
    if (!$ok) throw new RuntimeException('attempt_story_mismatch');
  }

  // ---- Load story items (RB) ----
  $items = [];                  // item_id => [number, ...]
  $ids   = [];
  $qi = $conn->prepare("
    SELECT item_id, number, question_text
    FROM story_items
    WHERE story_id=?
    ORDER BY number ASC, item_id ASC
  ");
  $qi->bind_param('i', $story_id);
  $qi->execute();
  $res = $qi->get_result();
  while ($r = $res->fetch_assoc()) {
    $iid = (int)$r['item_id'];
    $items[$iid] = [
      'item_id'  => $iid,
      'number'   => (int)$r['number'],
      'question' => (string)$r['question_text'],
    ];
    $ids[] = $iid;
  }
  $qi->close();

  if (!$ids) throw new RuntimeException('no_items_for_story');

  // ---- Load choices (labels + correct flags) in one go ----
  $inQ   = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));
  $ch = $conn->prepare("
    SELECT item_id, choice_id, label, text, is_correct
    FROM story_choices
    WHERE item_id IN ($inQ)
    ORDER BY item_id ASC, sequence ASC, choice_id ASC
  ");
  $ch->bind_param($types, ...$ids);
  $ch->execute();
  $rc = $ch->get_result();

  $choiceIdByLabel = [];   // item_id => [ 'A' => choice_id, ... ]
  $correctLabel    = [];   // item_id => 'A'
  while ($c = $rc->fetch_assoc()) {
    $iid  = (int)$c['item_id'];
    $lbl  = strtoupper(trim((string)$c['label']));
    if ($lbl === '') continue;
    $choiceIdByLabel[$iid][$lbl] = (int)$c['choice_id'];
    if ((int)$c['is_correct'] === 1 && !isset($correctLabel[$iid])) {
      $correctLabel[$iid] = $lbl;
    }
  }
  $ch->close();

  // ---- Normalize student answers: map to [iid => 'A'|'B'|...] ----
  $studentAns = []; // iid => LETTER
  foreach ($answersInput as $a) {
    if (!isset($a['item_id'])) continue;
    $iid = (int)$a['item_id'];
    if (!isset($items[$iid])) continue; // ignore answers for other stories
    $letter = isset($a['choice_label']) ? strtoupper(trim((string)$a['choice_label'])) : '';
    if ($letter === '' && isset($a['choice_index'])) {
      // (optional) index -> letter fallback
      $idx = (int)$a['choice_index']; // 0-based?
      $letters = array_keys($choiceIdByLabel[$iid] ?? []);
      $letter = $letters[$idx] ?? '';
    }
    if ($letter !== '') $studentAns[$iid] = $letter;
  }

  // ---- Grade + build answers to persist ----
  $answersToPersist = [];     // rows for attempt_answers
  $wrong = [];                // for response: [{q_no, picked_label, correct_label}]
  $correctCount = 0;
  $total = count($items);

  foreach ($items as $iid => $meta) {
    $num  = (int)$meta['number'];
    $pick = $studentAns[$iid] ?? '';                  // student's letter
    $corr = $correctLabel[$iid] ?? '';                // correct letter
    $cid  = $pick !== '' ? ($choiceIdByLabel[$iid][$pick] ?? null) : null;

    $isCorrect = ($pick !== '' && $corr !== '' && $pick === $corr);
    if ($isCorrect) $correctCount++;

    if (!$isCorrect) {
      $wrong[] = [
        'q_no'          => $num,
        'picked_label'  => $pick ?: null,
        'correct_label' => $corr ?: null,
      ];
    }

    $answersToPersist[] = [
      'item_id'    => $iid,
      'choice_id'  => $cid,      // may be null
      'answer_txt' => null,      // RB is choice-based
      'is_correct' => $isCorrect ? 1 : 0,
      'score'      => $isCorrect ? 1 : 0
    ];
  }

  // ---- Compute WPM (server-side: count words in passage_html) ----
  $wpm = null;
  $readSecs = $reading_seconds;
  if ($readSecs > 0) {
    $pStmt = $conn->prepare("SELECT passage_html FROM stories WHERE story_id=? LIMIT 1");
    $pStmt->bind_param('i', $story_id);
    $pStmt->execute();
    $pass = $pStmt->get_result()->fetch_assoc()['passage_html'] ?? '';
    $pStmt->close();

    $plain = trim(preg_replace('/<[^>]*>/', ' ', (string)$pass));
    $plain = preg_replace('/\s+/u', ' ', $plain);
    $wordCount = 0;
    if ($plain !== '') {
      $parts = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
      $wordCount = is_array($parts) ? count($parts) : 0;
    }

    if ($readSecs >= 15 && $wordCount >= 35) {
      $wpm = (int)round(($wordCount / $readSecs) * 60);
    } else {
      $wpm = null; // too short to be meaningful
    }
  }

  // ---- Persist (transaction) ----
  $conn->begin_transaction();

  // wipe old answers (if any)
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
    $txt = $a['answer_txt'];  // null
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

  // update attempt_stories (reading_seconds, wpm, score, max_score, percent)
  $max_score = $total;
  $percent   = $total > 0 ? round(($correctCount / $total) * 100, 2) : 0.0;
  $wpmVal    = $wpm === null ? null : (float)$wpm;

  $upd = $conn->prepare("
    UPDATE attempt_stories
       SET reading_seconds=?,
           wpm=?,
           score=?,
           max_score=?,
           percent=?
     WHERE attempt_story_id=?
  ");
  // i d i i d i
  $upd->bind_param(
    'idiidi',
    $readSecs,
    $wpmVal,
    $correctCount,
    $max_score,
    $percent,
    $attempt_story_id
  );
  $upd->execute();
  $upd->close();

  // check if all stories in this attempt are now done
  $tq = $conn->prepare("SELECT COUNT(*) AS t FROM attempt_stories WHERE attempt_id=?");
  $tq->bind_param('i', $attempt_id);
  $tq->execute();
  $T = (int)($tq->get_result()->fetch_assoc()['t'] ?? 0);
  $tq->close();

  $dq = $conn->prepare("SELECT COUNT(*) AS d FROM attempt_stories WHERE attempt_id=? AND score IS NOT NULL");
  $dq->bind_param('i', $attempt_id);
  $dq->execute();
  $D = (int)($dq->get_result()->fetch_assoc()['d'] ?? 0);
  $dq->close();

  $attemptCompleted = ($T > 0 && $D >= $T);

  if ($attemptCompleted) {
    // aggregate totals
    $ag = $conn->prepare("
      SELECT COALESCE(SUM(score),0) AS s, COALESCE(SUM(max_score),0) AS m
      FROM attempt_stories
      WHERE attempt_id=?
    ");
    $ag->bind_param('i', $attempt_id);
    $ag->execute();
    $agg = $ag->get_result()->fetch_assoc();
    $ag->close();

    $S = (int)($agg['s'] ?? 0);
    $M = (int)($agg['m'] ?? 0);
    $P = $M > 0 ? round(($S / $M) * 100, 2) : 0.0;

    $ua = $conn->prepare("
      UPDATE assessment_attempts
         SET status='submitted',
             total_score=?,
             total_max=?,
             percent=?,
             submitted_at=NOW()
       WHERE attempt_id=?
    ");
    $ua->bind_param('iidi', $S, $M, $P, $attempt_id);
    $ua->execute();
    $ua->close();
  }

  $conn->commit();

  echo json_encode([
    'ok'                => true,
    'attempt_completed' => $attemptCompleted,
    'total'             => $total,
    'score'             => $correctCount,
    'percent'           => (int)round($percent),
    'read_secs'         => $readSecs,
    'wpm'               => $wpm,
    'wrong_items'       => $wrong,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn && $conn->errno === 0) { /* ignore */ }
  http_response_code(200);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
