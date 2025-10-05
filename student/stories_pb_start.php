<?php
// student/stories_pb_start.php — create PB attempt (dynamic story count)
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$student_id = (int)($_SESSION['user_id'] ?? 0);
if ($student_id <= 0) {
  header('Location: ../login.php#login');
  exit;
}

// Optional set override via ?set_id=123
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;

/* Get student's current level (prefer this when picking a PB set) */
$student_level_id = 0;
if ($st = $conn->prepare("
      SELECT level_id
      FROM student_level
      WHERE student_id = ? AND (is_current = 1 OR current_flag = 1)
      ORDER BY assigned_at DESC
      LIMIT 1
")) {
  $st->bind_param('i', $student_id);
  $st->execute();
  $st->bind_result($student_level_id);
  $st->fetch();
  $st->close();
}
// ⬇️ Ilagay agad pagkatapos makuha ang $student_id (bago ang $conn->begin_transaction())
$aid  = isset($_GET['aid'])  ? (int)$_GET['aid']  : 0;
$next = isset($_GET['next']) ? (int)$_GET['next'] : 0;

// ⬇️ after reading $aid / $next …
if ($aid > 0 && $next === 1) {
  // Basahin ang status ng attempt (hindi lang in_progress)
  $st = $conn->prepare("
    SELECT status
    FROM assessment_attempts
    WHERE attempt_id=? AND student_id=? AND set_type='PB'
    LIMIT 1
  ");
  $st->bind_param('ii', $aid, $student_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if ($row && $row['status'] === 'in_progress') {
    // may natitirang story; runner na ulit
    header('Location: stories_pb_run.php?attempt_id=' . $aid);
    exit;
  } elseif ($row && $row['status'] !== 'in_progress') {
    // tapos na ang attempt – go to PB summary page
    header('Location: stories_pb_done.php?attempt_id=' . $aid);
    exit;
  }

  // else: walang attempt na ganoon -> babagsak sa normal flow (create new)
}

/* ---- PASS GATE: if student already passed PB, go to summary (no new attempt) ---- */
$pass_threshold = 75;
if ($student_level_id > 0) {
  $th = $conn->prepare("
    SELECT min_percent FROM level_thresholds
    WHERE applies_to='PB' AND level_id=? LIMIT 1
  ");
  $th->bind_param('i', $student_level_id);
  $th->execute();
  $row = $th->get_result()->fetch_assoc();
  $th->close();
  if ($row && $row['min_percent'] !== null) {
    $pass_threshold = (int)round((float)$row['min_percent']);
  }
}

/* Latest submitted/scored PB attempt that meets threshold */
$pa = $conn->prepare("
  SELECT attempt_id
  FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status IN ('submitted','scored')
    AND percent >= ?
  ORDER BY submitted_at DESC, attempt_id DESC
  LIMIT 1
");
$pa->bind_param('ii', $student_id, $pass_threshold);
$pa->execute();
$passed = $pa->get_result()->fetch_assoc();
$pa->close();

if ($passed) {
  header('Location: stories_pb_done.php?attempt_id='.(int)$passed['attempt_id']);
  exit;
}

$conn->begin_transaction();
try {
  /* 1) Resolve PB set + level_id */
  if ($set_id <= 0) {
    $row = null;

    // Prefer a published PB set for the student's current level
    if ($student_level_id > 0) {
      $st = $conn->prepare("
        SELECT set_id, level_id
        FROM story_sets
        WHERE set_type='PB' AND status='published' AND level_id=?
        ORDER BY sequence, set_id
        LIMIT 1
      ");
      $st->bind_param('i', $student_level_id);
      $st->execute();
      $res = $st->get_result();
      $row = $res->fetch_assoc();
      $st->close();
    }

    // Fallback: any published PB set
    if (!$row) {
      $q = $conn->query("
        SELECT set_id, level_id
        FROM story_sets
        WHERE set_type='PB' AND status='published'
        ORDER BY sequence, set_id
        LIMIT 1
      ");
      $row = $q->fetch_assoc();
    }

    if (!$row) {
      throw new RuntimeException('No published PB set found.');
    }

    $set_id  = (int)$row['set_id'];
    $level_id = (int)$row['level_id'];
  } else {
    $st = $conn->prepare("
      SELECT level_id
      FROM story_sets
      WHERE set_id=? AND set_type='PB' AND status='published'
      LIMIT 1
    ");
    $st->bind_param('i', $set_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
      throw new RuntimeException('PB set not found or not published.');
    }

    $level_id = (int)$row['level_id'];
  }

  /* 2) Create attempt (set_type = PB) */
  $ins = $conn->prepare("
    INSERT INTO assessment_attempts (student_id, set_id, set_type, level_id, status)
    VALUES (?, ?, 'PB', ?, 'in_progress')
  ");
  $ins->bind_param('iii', $student_id, $set_id, $level_id);
  $ins->execute();
  $attempt_id = (int)$conn->insert_id;
  $ins->close();

  /* 3) Queue ALL published stories in the set (no fixed 15) */
  $pick = $conn->prepare("
    SELECT s.story_id
    FROM stories s
    WHERE s.set_id = ? AND s.status = 'published'
    ORDER BY s.sequence ASC, s.story_id ASC
  ");
  $pick->bind_param('i', $set_id);
  $pick->execute();
  $res = $pick->get_result();

  $pos  = 1;
  $ins2 = $conn->prepare("
    INSERT INTO attempt_stories (attempt_id, story_id, sequence)
    VALUES (?, ?, ?)
  ");
  while ($r = $res->fetch_assoc()) {
    $sid = (int)$r['story_id'];
    $seq = $pos; // snapshot order
    $ins2->bind_param('iii', $attempt_id, $sid, $seq);
    $ins2->execute();
    $pos++;
  }
  $ins2->close();
  $pick->close();

  $count = $pos - 1;
  if ($count < 1) { // require at least 1 story
    throw new RuntimeException('No published stories in this PB set.');
  }

  $conn->commit();

  // Go to runner
  header('Location: stories_pb_run.php?attempt_id=' . $attempt_id);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  echo "Start error: " . htmlspecialchars($e->getMessage());
}
