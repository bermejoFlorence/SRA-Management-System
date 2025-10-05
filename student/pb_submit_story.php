<?php
// student/pb_submit_story.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $student_id = (int)($_SESSION['user_id'] ?? 0);
  if ($student_id <= 0) throw new Exception('Auth required');

  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) throw new Exception('Bad payload');

  $attempt_id       = (int)($body['attempt_id'] ?? 0);
  $attempt_story_id = (int)($body['attempt_story_id'] ?? 0);
  $story_id_in      = (int)($body['story_id'] ?? 0);
  $answers          = $body['answers'] ?? [];
  $reading_seconds  = (int)($body['reading_seconds'] ?? 0);

  if ($attempt_id <= 0) throw new Exception('Bad input');

  // Validate attempt ownership & type; also fetch level_id for threshold
  $q = $conn->prepare("
    SELECT student_id, set_type, level_id
    FROM assessment_attempts
    WHERE attempt_id=?
    LIMIT 1
  ");
  $q->bind_param('i', $attempt_id);
  $q->execute();
  $att = $q->get_result()->fetch_assoc();
  $q->close();

  if (!$att || (int)$att['student_id'] !== $student_id || $att['set_type'] !== 'PB') {
    throw new Exception('Invalid attempt');
  }
  $level_id = (int)$att['level_id'];

  // Resolve attempt_story_id if missing
  if ($attempt_story_id <= 0) {
    if ($story_id_in <= 0) throw new Exception('Bad input');
    $rq = $conn->prepare("
      SELECT attempt_story_id FROM attempt_stories
      WHERE attempt_id=? AND story_id=?
      LIMIT 1
    ");
    $rq->bind_param('ii', $attempt_id, $story_id_in);
    $rq->execute();
    $row = $rq->get_result()->fetch_assoc();
    $rq->close();
    if (!$row) throw new Exception('Story not in attempt');
    $attempt_story_id = (int)$row['attempt_story_id'];
  }

  // Load story info (for WPM calc)
  $qs = $conn->prepare("
    SELECT st.story_id, st.passage_html, COALESCE(st.word_count,0) AS word_count
    FROM attempt_stories ats
    JOIN stories st ON st.story_id = ats.story_id
    WHERE ats.attempt_story_id=? AND ats.attempt_id=?
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

  // Build choice map for correctness
  $choices = []; $questionOrder=[]; $correctIndex=[];
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
  $seen=[]; $qno=0;
  while ($r=$rs->fetch_assoc()) {
    $iid = (int)$r['item_id'];
    $seq = max(1,(int)$r['sequence']) - 1;
    if (!isset($choices[$iid])) $choices[$iid]=[];
    $choices[$iid][$seq] = [
      'choice_id'=>(int)$r['choice_id'],
      'is_correct'=>(int)$r['is_correct']
    ];
    if (!isset($seen[$iid])) { $seen[$iid]=true; $qno++; $questionOrder[$iid]=$qno; }
    if ((int)$r['is_correct'] === 1) $correctIndex[$iid] = $seq;
  }
  $rc->close();

  // Max score for this story
  $qm = $conn->prepare("SELECT COUNT(*) AS max_score FROM story_items WHERE story_id=?");
  $qm->bind_param('i', $story_id);
  $qm->execute();
  $maxRow = $qm->get_result()->fetch_assoc();
  $qm->close();
  $max_score = (int)($maxRow['max_score'] ?? 0);
  if ($max_score <= 0) $max_score = count($choices);

  $conn->begin_transaction();

  // Idempotent: clear old answers
  $del = $conn->prepare("DELETE FROM attempt_answers WHERE attempt_story_id=?");
  $del->bind_param('i', $attempt_story_id);
  $del->execute(); $del->close();

  // Insert answers + compute score
  $score = 0; $answersByItem=[];
  foreach ($answers as $a) {
    $item_id = (int)($a['item_id'] ?? 0);
    $idx     = (int)($a['choice_index'] ?? -1);
    $answersByItem[$item_id] = $idx;

    $pick = $choices[$item_id][$idx] ?? null;
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
    $ins->execute(); $ins->close();
  }

  // Wrong items list
  $wrong_items = [];
  foreach ($questionOrder as $iid=>$q_no) {
    $picked  = $answersByItem[$iid] ?? null;
    $correct = $correctIndex[$iid]  ?? null;
    if ($picked === null || $correct === null || (int)$picked !== (int)$correct) {
      $wrong_items[] = [
        'q_no'=>(int)$q_no,
        'item_id'=>(int)$iid,
        'picked_index'=>($picked===null?null:(int)$picked),
        'correct_index'=>($correct===null?null:(int)$correct),
      ];
    }
  }

  // WPM + percent
  $elapsed = max(1,(int)$reading_seconds);
  $percent = $max_score>0 ? (int)round(($score/$max_score)*100) : 0;
  $wpm = null;
  if ($elapsed >= 15 && $word_count >= 50) {
    $wpm = (int) round($word_count / ($elapsed/60));
    $wpm = max(20, min(400, $wpm));
  }

  // Determine pass threshold for PB (per level or default 60)
  $pass_threshold = 60.0;
  $pt = $conn->prepare("
    SELECT min_percent
    FROM level_thresholds
    WHERE applies_to='PB' AND level_id=?
    ORDER BY min_percent DESC
    LIMIT 1
  ");
  $pt->bind_param('i', $level_id);
  $pt->execute();
  if ($row = $pt->get_result()->fetch_assoc()) {
    $pass_threshold = (float)$row['min_percent'];
  }
  $pt->close();
  $pass_flag = ($percent >= $pass_threshold) ? 1 : 0;

  // Update per-story
  $u = $conn->prepare("
    UPDATE attempt_stories
    SET reading_seconds=?, wpm=?, score=?, max_score=?, percent=?, pass_flag=?
    WHERE attempt_story_id=? AND attempt_id=?
  ");
  $wpm_for_db = $wpm; // can be null if column allows NULL
  $u->bind_param('diiiiiii', $elapsed, $wpm_for_db, $score, $max_score, $percent, $pass_flag, $attempt_story_id, $attempt_id);
  $u->execute(); $u->close();

  // Keep attempt in_progress (do NOT regress if already submitted)
  $conn->query("UPDATE assessment_attempts SET status='in_progress' WHERE attempt_id={$attempt_id} AND status='in_progress'");

  $conn->commit();

  echo json_encode([
    'ok'=>true,
    'score'=>$score,
    'total'=>$max_score,
    'pct'=>$percent,
    'read_secs'=>$elapsed,
    'word_count'=>$word_count,
    'wpm'=>$wpm,
    'pass_flag'=>$pass_flag,
    'wrong_items'=>$wrong_items
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($conn) && !$conn->connect_errno) { @mysqli_rollback($conn); }
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
