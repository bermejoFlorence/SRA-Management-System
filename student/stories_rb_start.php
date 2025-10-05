<?php
// student/stories_rb_start.php — create RB attempt (dynamic story count)
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);
if ($student_id <= 0) {
  header('Location: ../login.php#login');
  exit;
}

/* ---------- Optional: set override via ?set_id=123 ---------- */
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;

/* ---------- Resume shortcut: ?aid=###&next=1 ---------- */
$aid  = isset($_GET['aid'])  ? (int)$_GET['aid']  : 0;
$next = isset($_GET['next']) ? (int)$_GET['next'] : 0;

if ($aid > 0 && $next === 1) {
  // verify ownership + in_progress + RB
  $st = $conn->prepare("
    SELECT attempt_id FROM assessment_attempts
    WHERE attempt_id=? AND student_id=? AND set_type='RB' AND status='in_progress'
    LIMIT 1
  ");
  $st->bind_param('ii', $aid, $student_id);
  $st->execute();
  $ok = $st->get_result()->fetch_assoc();
  $st->close();

  if ($ok) {
    header('Location: stories_rb_run.php?attempt_id=' . $aid);
    exit;
  }
  // else: fall-through to create a new attempt
}

/* ---------- Fetch student's current level ---------- */
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

/* ---------- Optional: gate RB (same rules as your RB page) ---------- */
function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
  $val = null;
  if (!$stmt = $c->prepare($sql)) return $val;
  if ($params) $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
  if ($stmt->execute()){
    $res = $stmt->get_result();
    if ($res) { $row = $res->fetch_row(); $val = $row ? $row[0] : null; $res->free(); }
  }
  $stmt->close();
  return $val;
}

$PB_OVERALL_PASS = 75.0;

// how many PB stories are published for this level?
$pbPublishedTotal = (int)(scalar($conn, "
  SELECT COUNT(*)
  FROM stories s
  JOIN story_sets ss ON ss.set_id = s.set_id
  WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
    AND (ss.status IS NULL OR ss.status IN ('published','draft'))
", [$student_level_id], 'i') ?? 0);

// dynamic pass count: scale 8/15 by what’s actually published
$requiredPBPass = ($pbPublishedTotal > 0)
  ? max(1, (int)ceil($pbPublishedTotal * (8/15)))
  : 8;

// latest submitted PB attempt overall percent
$pbOverallPercent = (float)(scalar($conn, "
  SELECT percent
  FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status='submitted'
  ORDER BY submitted_at DESC, attempt_id DESC
  LIMIT 1
", [$student_id], 'i') ?? 0.0);
$pbOverallPass = ($pbOverallPercent >= $PB_OVERALL_PASS);

// per-story PB pass threshold (default 75 if not set)
$pbPassThreshold = (float)(scalar($conn, "
  SELECT min_percent FROM level_thresholds
  WHERE applies_to='PB' AND level_id=? LIMIT 1
", [$student_level_id], 'i') ?? 75.0);

// how many PB stories passed?
$pbPassed = (int)(scalar($conn, "
  SELECT COUNT(DISTINCT s.story_id)
  FROM attempt_stories s
  JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
  WHERE a.student_id = ? AND a.set_type='PB' AND a.status='submitted'
    AND s.percent >= ?
", [$student_id, $pbPassThreshold], 'id') ?? 0);

// final unlock decision
$rbUnlocked = $pbOverallPass || ($pbPassed >= $requiredPBPass);
if (!$rbUnlocked) {
  header('Location: stories_rb.php'); // bounce back to the RB intro if someone deep-links here
  exit;
}

/* ---------- Create attempt ---------- */
$conn->begin_transaction();
try {
  /* 1) Resolve RB set + level_id */
  if ($set_id <= 0) {
    $row = null;

    // prefer a published RB set for the student's current level
    if ($student_level_id > 0) {
      $st = $conn->prepare("
        SELECT set_id, level_id
        FROM story_sets
        WHERE set_type='RB' AND status='published' AND level_id=?
        ORDER BY sequence, set_id
        LIMIT 1
      ");
      $st->bind_param('i', $student_level_id);
      $st->execute();
      $res = $st->get_result();
      $row = $res->fetch_assoc();
      $st->close();
    }

    // fallback: any published RB set
    if (!$row) {
      $q = $conn->query("
        SELECT set_id, level_id
        FROM story_sets
        WHERE set_type='RB' AND status='published'
        ORDER BY sequence, set_id
        LIMIT 1
      ");
      $row = $q->fetch_assoc();
    }

    if (!$row) {
      throw new RuntimeException('No published RB set found.');
    }

    $set_id   = (int)$row['set_id'];
    $level_id = (int)$row['level_id'];
  } else {
    $st = $conn->prepare("
      SELECT level_id
      FROM story_sets
      WHERE set_id=? AND set_type='RB' AND status='published'
      LIMIT 1
    ");
    $st->bind_param('i', $set_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
      throw new RuntimeException('RB set not found or not published.');
    }

    $level_id = (int)$row['level_id'];
  }

  /* 2) Create attempt (set_type = RB) */
  $ins = $conn->prepare("
    INSERT INTO assessment_attempts (student_id, set_id, set_type, level_id, status)
    VALUES (?, ?, 'RB', ?, 'in_progress')
  ");
  $ins->bind_param('iii', $student_id, $set_id, $level_id);
  $ins->execute();
  $attempt_id = (int)$conn->insert_id;
  $ins->close();

  /* 3) Queue ALL published stories in the set */
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
    $seq = $pos;
    $ins2->bind_param('iii', $attempt_id, $sid, $seq);
    $ins2->execute();
    $pos++;
  }
  $ins2->close();
  $pick->close();

  $count = $pos - 1;
  if ($count < 1) {
    throw new RuntimeException('No published stories in this RB set.');
  }

  $conn->commit();

  // Go to runner
  header('Location: stories_rb_run.php?attempt_id=' . $attempt_id);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  echo "Start error: " . htmlspecialchars($e->getMessage());
}
