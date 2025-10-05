<?php
// student/slt_submit_story.php — save one story’s answers, score, wpm (per-story submit)
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $student_id = (int)($_SESSION['user_id'] ?? 0);
  if ($student_id <= 0) throw new Exception('Auth required');

  $raw  = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) throw new Exception('Bad payload');

  $attempt_id       = (int)($body['attempt_id'] ?? 0);
  $attempt_story_id = (int)($body['attempt_story_id'] ?? 0);
  $story_id_in      = (int)($body['story_id'] ?? 0); // backup resolver
  $answers          = $body['answers'] ?? [];        // [{item_id, choice_index}]
  $reading_seconds  = (int)($body['reading_seconds'] ?? 0);

  if ($attempt_id <= 0) throw new Exception('Bad input');

  // Validate attempt ownership & type
  $q = $conn->prepare("
    SELECT student_id, set_type
      FROM assessment_attempts
     WHERE attempt_id = ?
     LIMIT 1
  ");
  $q->bind_param('i', $attempt_id);
  $q->execute();
  $att = $q->get_result()->fetch_assoc();
  $q->close();

  if (!$att || (int)$att['student_id'] !== $student_id || $att['set_type'] !== 'SLT') {
    throw new Exception('Invalid attempt');
  }

  // Resolve attempt_story_id if missing (no completed_at column in schema)
  if ($attempt_story_id <= 0) {
    if ($story_id_in <= 0) throw new Exception('Bad input');
    $rq = $conn->prepare("
      SELECT attempt_story_id
        FROM attempt_stories
       WHERE attempt_id = ? AND story_id = ?
       LIMIT 1
    ");
    $rq->bind_param('ii', $attempt_id, $story_id_in);
    $rq->execute();
    $row = $rq->get_result()->fetch_assoc();
    $rq->close();
    if (!$row) throw new Exception('Story not in attempt');
    $attempt_story_id = (int)$row['attempt_story_id'];
  }

  // Load story info (for WPM calc and linkage)
  $qs = $conn->prepare("
    SELECT st.story_id,
           st.passage_html,
           COALESCE(st.word_count, 0) AS word_count
      FROM attempt_stories ats
      JOIN stories st ON st.story_id = ats.story_id
     WHERE ats.attempt_story_id = ? AND ats.attempt_id = ?
     LIMIT 1
  ");
  $qs->bind_param('ii', $attempt_story_id, $attempt_id);
  $qs->execute();
  $sinfo = $qs->get_result()->fetch_assoc();
  $qs->close();

  if (!$sinfo) throw new Exception('Story not in attempt');

  $story_id   = (int)$sinfo['story_id'];
  $passage    = (string)$sinfo['passage_html'];
  $word_count = (int)$sinfo['word_count'];
  if ($word_count <= 0) {
    $plain = trim(strip_tags($passage));
    $word_count = max(1, str_word_count($plain));
  }

  // Build choice map: item_id -> [ seq(0-based) => {choice_id,is_correct} ]
  $choices = [];
  $questionOrder = [];   // item_id -> Q number
  $correctIndex  = [];   // item_id -> correct choice index (0-based)
  $rc = $conn->prepare("
    SELECT c.item_id, c.choice_id, c.is_correct, c.sequence
      FROM story_choices c
      JOIN story_items i ON i.item_id = c.item_id
     WHERE i.story_id = ?
     ORDER BY c.item_id ASC, c.sequence ASC, c.choice_id ASC
  ");
  $rc->bind_param('i', $story_id);
  $rc->execute();
  $rs = $rc->get_result();

  $seen = [];
  $qno  = 0;
  while ($r = $rs->fetch_assoc()) {
    $iid = (int)$r['item_id'];
    $seq = max(1, (int)$r['sequence']) - 1; // 0-based

    if (!isset($choices[$iid])) $choices[$iid] = [];
    $choices[$iid][$seq] = [
      'choice_id'  => (int)$r['choice_id'],
      'is_correct' => (int)$r['is_correct']
    ];

    if (!isset($seen[$iid])) {
      $seen[$iid] = true;
      $qno++;
      $questionOrder[$iid] = $qno; // Q number
    }
    if ((int)$r['is_correct'] === 1) {
      $correctIndex[$iid] = $seq;
    }
  }
  $rc->close();

  // Compute max_score for this story (number of items)
  $qm = $conn->prepare("SELECT COUNT(*) AS max_score FROM story_items WHERE story_id = ?");
  $qm->bind_param('i', $story_id);
  $qm->execute();
  $maxRow = $qm->get_result()->fetch_assoc();
  $qm->close();
  $max_score = (int)($maxRow['max_score'] ?? 0);
  if ($max_score <= 0) $max_score = count($choices); // fallback

  $conn->begin_transaction();

  // Idempotency: remove any previous answers for this attempt_story_id
  $del = $conn->prepare("DELETE FROM attempt_answers WHERE attempt_story_id = ?");
  $del->bind_param('i', $attempt_story_id);
  $del->execute();
  $del->close();

  // Insert answers + compute score
  $score = 0;
  $answersByItem = [];      // item_id -> picked index (0-based)
  foreach ($answers as $a) {
    $item_id = (int)($a['item_id'] ?? 0);
    $idx     = (int)($a['choice_index'] ?? -1);
    $answersByItem[$item_id] = $idx;

    $pick    = $choices[$item_id][$idx] ?? null;

    $choice_id  = $pick['choice_id']  ?? null;
    $is_correct = (int)($pick['is_correct'] ?? 0);
    if ($is_correct) $score++;

    if ($choice_id) {
      $ins = $conn->prepare("
        INSERT INTO attempt_answers (attempt_story_id, item_id, choice_id, is_correct, answered_at)
        VALUES (?, ?, ?, ?, NOW())
      ");
      $ins->bind_param('iiii', $attempt_story_id, $item_id, $choice_id, $is_correct);
    } else {
      $ins = $conn->prepare("
        INSERT INTO attempt_answers (attempt_story_id, item_id, choice_id, is_correct, answered_at)
        VALUES (?, ?, NULL, 0, NOW())
      ");
      $ins->bind_param('ii', $attempt_story_id, $item_id);
    }
    $ins->execute();
    $ins->close();
  }

  // Build wrong_items breakdown (optional to return)
  $wrong_items = [];
  foreach ($questionOrder as $iid => $q_no) {
    $picked  = $answersByItem[$iid] ?? null;
    $correct = $correctIndex[$iid]  ?? null;
    if ($picked === null || $correct === null || (int)$picked !== (int)$correct) {
      $wrong_items[] = [
        'q_no'          => (int)$q_no,
        'item_id'       => (int)$iid,
        'picked_index'  => ($picked === null ? null : (int)$picked),
        'correct_index' => ($correct === null ? null : (int)$correct),
      ];
    }
  }

  // WPM & percent (safe rules)
  $elapsed  = max(1, (int)$reading_seconds);
  $percent  = $max_score > 0 ? (int)round(($score / $max_score) * 100) : 0;

  // Only compute WPM if may sapat na reading time at salita
  $wpm = null;
  if ($elapsed >= 15 && $word_count >= 50) {
    $wpm = (int) round($word_count / ($elapsed / 60));
    // Optional capping to realistic ranges; adjust if needed
    $wpm = max(20, min(400, $wpm));
  }

  // Update per-story results (no completed_at column in your schema)
  $u = $conn->prepare("
    UPDATE attempt_stories
       SET reading_seconds = ?,
           wpm             = ?,
           score           = ?,
           max_score       = ?,
           percent         = ?
     WHERE attempt_story_id = ? AND attempt_id = ?
  ");
  // NOTE: if your 'wpm' column is NOT NULL, replace ($wpm ?? 0)
  $wpm_for_db = $wpm; // leave as null if column allows NULL
  $u->bind_param('iiiiiii', $elapsed, $wpm_for_db, $score, $max_score, $percent, $attempt_story_id, $attempt_id);
  $u->execute();
  $u->close();

  // Keep attempt in progress
  $conn->query("UPDATE assessment_attempts SET status='in_progress' WHERE attempt_id={$attempt_id} AND status<>'completed'");

  $conn->commit();

  echo json_encode([
    'ok'          => true,
    'score'       => $score,
    'total'       => $max_score,
    'pct'         => $percent,
    'read_secs'   => $elapsed,
    'word_count'  => $word_count,
    'wpm'         => $wpm,         // null if too short
    'wrong_items' => $wrong_items, // optional
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($conn) && $conn->errno) { $conn->rollback(); }
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
