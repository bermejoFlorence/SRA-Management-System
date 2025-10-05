<?php
// admin/api/funnel_counts.php
require_once __DIR__ . '/../../includes/auth.php';
require_role('admin', '../../login.php#login');
require_once __DIR__ . '/../../db_connect.php';

header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$days     = max(1, (int)($_GET['days'] ?? 30));     // default 30
$level_id = max(0, (int)($_GET['level_id'] ?? 0));  // 0 = all levels

$to   = date('Y-m-d H:i:s');
$from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
  $v = null; $st = $c->prepare($sql);
  if (!$st) return null;
  if ($params) $st->bind_param($types ?: str_repeat('s', count($params)), ...$params);
  $st->execute();
  if ($res = $st->get_result()) { $row = $res->fetch_row(); $v = $row ? $row[0] : null; $res->free(); }
  $st->close(); return $v;
}

/* ---------- Stage 1: SLT Completed ---------- */
if ($level_id > 0) {
  $sltCompleted = (int) scalar($conn, "
    SELECT COUNT(DISTINCT a.student_id)
      FROM assessment_attempts a
      JOIN student_level sl ON sl.student_id=a.student_id AND sl.is_current=1 AND sl.level_id=?
     WHERE a.set_type='SLT' AND a.status='submitted'
       AND a.submitted_at BETWEEN ? AND ?
  ", [$level_id, $from, $to], 'iss');
} else {
  $sltCompleted = (int) scalar($conn, "
    SELECT COUNT(DISTINCT a.student_id)
      FROM assessment_attempts a
     WHERE a.set_type='SLT' AND a.status='submitted'
       AND a.submitted_at BETWEEN ? AND ?
  ", [$from, $to], 'ss');
}

/* ---------- Stage 2: PB Started ---------- */
$pbStarted = (int) scalar($conn, "
  SELECT COUNT(DISTINCT a.student_id)
    FROM assessment_attempts a
   WHERE a.set_type='PB'
     AND a.started_at BETWEEN ? AND ?
     AND (? = 0 OR a.level_id = ?)
", [$from, $to, $level_id, $level_id], 'ssii');

/* ---------- Stage 3: PB Passed (overall or dynamic rule) ---------- */
$pbPassed = (int) scalar($conn, "
  SELECT COUNT(DISTINCT a.student_id)
    FROM assessment_attempts a
   WHERE a.set_type='PB' AND a.status='submitted'
     AND a.submitted_at BETWEEN ? AND ?
     AND (? = 0 OR a.level_id = ?)
     AND (
       /* overall percent pass (default 75) */
       a.percent >= COALESCE(
         (SELECT lt.min_percent FROM level_thresholds lt
           WHERE lt.applies_to='PB' AND lt.level_id=a.level_id LIMIT 1),
         75
       )
       OR
       /* dynamic rule: passed-stories threshold */
       (
         (
           SELECT COUNT(*)
             FROM attempt_stories ats
            WHERE ats.attempt_id=a.attempt_id
              AND ats.percent >= COALESCE(
                (SELECT lt.min_percent FROM level_thresholds lt
                  WHERE lt.applies_to='PB' AND lt.level_id=a.level_id LIMIT 1),
                75
              )
         ) >= GREATEST(
               1,
               CEIL( (
                 SELECT COUNT(*)
                   FROM stories s
                   JOIN story_sets ss ON ss.set_id=s.set_id
                  WHERE ss.set_type='PB' AND ss.level_id=a.level_id
                    AND s.status='published'
               ) * (8.0/15.0) )
           )
       )
     )
", [$from, $to, $level_id, $level_id], 'ssii');

/* ---------- Stage 4: RB Started ---------- */
$rbStarted = (int) scalar($conn, "
  SELECT COUNT(DISTINCT a.student_id)
    FROM assessment_attempts a
   WHERE a.set_type='RB'
     AND a.started_at BETWEEN ? AND ?
     AND (? = 0 OR a.level_id = ?)
", [$from, $to, $level_id, $level_id], 'ssii');

/* ---------- Stage 5: RB Passed (level threshold, default 75) ---------- */
$rbPassed = (int) scalar($conn, "
  SELECT COUNT(DISTINCT a.student_id)
    FROM assessment_attempts a
   WHERE a.set_type='RB' AND a.status='submitted'
     AND a.submitted_at BETWEEN ? AND ?
     AND (? = 0 OR a.level_id = ?)
     AND a.percent >= COALESCE(
       (SELECT lt.min_percent FROM level_thresholds lt
         WHERE lt.applies_to='RB' AND lt.level_id=a.level_id LIMIT 1),
       75
     )
", [$from, $to, $level_id, $level_id], 'ssii');

echo json_encode([
  'ok'     => true,
  'range_days' => $days,
  'level_id'   => $level_id,
  'labels' => ['SLT Completed','PB Started','PB Passed','RB Started','RB Passed'],
  'data'   => [$sltCompleted, $pbStarted, $pbPassed, $rbStarted, $rbPassed]
]);
