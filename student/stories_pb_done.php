<?php
// student/stories_pb_done.php — PB final summary with gating + retake + previous results

require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fmt_hms($secs){
  $s = max(0,(int)$secs);
  $m = intdiv($s,60); $ss = $s%60;
  return sprintf('%02d:%02d', $m, $ss);
}

/* 1) Resolve to the LATEST submitted/scored PB attempt by default */
$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($attempt_id <= 0) {
  $q = $conn->prepare("
    SELECT attempt_id
    FROM assessment_attempts
    WHERE student_id=? AND set_type='PB' AND status IN ('submitted','scored')
    ORDER BY submitted_at DESC, attempt_id DESC
    LIMIT 1
  ");
  $q->bind_param('i', $student_id);
  $q->execute();
  $attempt_id = (int)($q->get_result()->fetch_assoc()['attempt_id'] ?? 0);
  $q->close();
}
if ($attempt_id <= 0) { header('Location: stories_pb.php'); exit; }

/* 2) Validate + load attempt summary */
$st = $conn->prepare("
  SELECT a.attempt_id, a.set_id, a.level_id, a.total_score, a.total_max, a.percent, a.status, a.submitted_at,
         L.name AS level_name, L.color_hex
  FROM assessment_attempts a
  LEFT JOIN sra_levels L ON L.level_id = a.level_id
  WHERE a.attempt_id=? AND a.student_id=? AND a.set_type='PB' AND a.status IN ('submitted','scored')
  LIMIT 1
");
$st->bind_param('ii', $attempt_id, $student_id);
$st->execute();
$att = $st->get_result()->fetch_assoc();
$st->close();
if (!$att) { header('Location: stories_pb.php'); exit; }

/* 3) Aggregate per-story for THIS attempt (kasama na ang WPM + range) */
$ag = $conn->prepare("
  SELECT
    COUNT(*)                         AS stories,
    COALESCE(SUM(score),0)           AS sum_score,
    COALESCE(SUM(max_score),0)       AS sum_max,
    COALESCE(SUM(reading_seconds),0) AS sum_secs,
    AVG(NULLIF(wpm,0))               AS avg_wpm,
    MIN(NULLIF(wpm,0))               AS min_wpm,
    MAX(NULLIF(wpm,0))               AS max_wpm
  FROM attempt_stories
  WHERE attempt_id=?
");
$ag->bind_param('i', $attempt_id);
$ag->execute();
$agg = $ag->get_result()->fetch_assoc();
$ag->close();

$stories   = (int)($agg['stories'] ?? 0);
$sum_score = (int)($agg['sum_score'] ?? 0);
$sum_max   = (int)($agg['sum_max'] ?? 0);
$sum_secs  = (int)($agg['sum_secs'] ?? 0);
$overall_pct = $sum_max > 0 ? (int)round(($sum_score/$sum_max)*100) : (int)round((float)($att['percent'] ?? 0));

$avg_wpm = isset($agg['avg_wpm']) ? (int)round((float)$agg['avg_wpm']) : 0;
$min_wpm = isset($agg['min_wpm']) ? (int)round((float)$agg['min_wpm']) : 0;
$max_wpm = isset($agg['max_wpm']) ? (int)round((float)$agg['max_wpm']) : 0;

/* 4) Passing threshold per level (fallback 75%) */
$pass_threshold = 75;
if (!empty($att['level_id'])) {
  $th = $conn->prepare("
    SELECT min_percent
    FROM level_thresholds
    WHERE applies_to='PB' AND level_id=?
    ORDER BY threshold_id DESC
    LIMIT 1
  ");
  $th->bind_param('i', $att['level_id']);
  $th->execute();
  $row = $th->get_result()->fetch_assoc();
  $th->close();
  if ($row && $row['min_percent'] !== null) $pass_threshold = (int)round((float)$row['min_percent']);
}
$did_pass = ($overall_pct >= $pass_threshold);

/* Check if may in-progress attempt pa para sa retake link */
$pbAidInProgress = 0;
$ip = $conn->prepare("
  SELECT attempt_id
  FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status='in_progress'
  ORDER BY started_at DESC, attempt_id DESC
  LIMIT 1
");
$ip->bind_param('i', $student_id);
$ip->execute();
$pbAidInProgress = (int)($ip->get_result()->fetch_assoc()['attempt_id'] ?? 0);
$ip->close();

$retakeHref = $pbAidInProgress > 0
  ? 'stories_pb_start.php?aid='.$pbAidInProgress.'&next=1'
  : 'stories_pb_start.php';

/* 5) Per-story breakdown (title + score) */
$rows = [];
$ps = $conn->prepare("
  SELECT s.title, ast.score, ast.max_score
  FROM attempt_stories ast
  JOIN stories s ON s.story_id = ast.story_id
  WHERE ast.attempt_id=?
  ORDER BY ast.sequence ASC, ast.attempt_story_id ASC
");
$ps->bind_param('i', $attempt_id);
$ps->execute();
$r = $ps->get_result();
while ($x = $r->fetch_assoc()) $rows[] = $x;
$ps->close();

/* 6) Previous results (latest 1–3 BEFORE this) */
$prev = [];
$pq = $conn->prepare("
  SELECT a.attempt_id, a.submitted_at, a.level_id, a.total_score, a.total_max, a.percent
  FROM assessment_attempts a
  WHERE a.student_id=? AND a.set_type='PB' AND a.status IN ('submitted','scored') AND a.attempt_id<>?
  ORDER BY a.submitted_at DESC, a.attempt_id DESC
  LIMIT 3
");
$pq->bind_param('ii', $student_id, $attempt_id);
$pq->execute();
$pr = $pq->get_result();
while ($p = $pr->fetch_assoc()) {
  $p['percent'] = ($p['total_max']>0)
    ? (int)round($p['total_score']/$p['total_max']*100)
    : (int)round((float)($p['percent'] ?? 0));
  $prev[] = $p;
}
$pq->close();

/* 7) UI */
$PAGE_TITLE  = 'Power Builder — Summary';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'pb';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
:root{
  --g:#003300; --acc:#ECA305; --bg:#f5f7f6; --card:#fff;
  --ink:#213421; --muted:#6b7c6b; --line:#e6efe6; --shadow:0 10px 28px rgba(0,0,0,.08);
}
.main-content{ width:calc(100% - 220px); margin-left:220px; background:var(--bg); }
@media (max-width:992px){ .main-content{ width:100%; margin-left:0; } }

.wrap{ max-width:1320px; margin:0 auto; padding:16px 24px; padding-top:12px; }

.hero{
  display:flex; align-items:flex-start; justify-content:space-between; gap:16px;
  margin:8px 0 16px; padding:18px 22px;
  background:linear-gradient(180deg,#fff,#fefefe);
  border:1px solid #eef2ee; border-radius:14px; box-shadow:var(--shadow);
}
.hero-main{ display:flex; flex-direction:column; gap:6px; }
.hero-title{
  margin:0; color:#003300; font-weight:900; letter-spacing:.2px;
  font-size:clamp(1.25rem,1.05rem + 1vw,1.7rem);
}
.hero-sub{ margin:0; color:var(--ink); font-size:1rem; font-weight:700; }
.hero-sub2{ margin:0; color:var(--muted); font-size:.95rem; }

.hero-right{
  display:flex; flex-direction:column; gap:6px; align-items:flex-end;
}

.pill{
  background:#eff3ef; color:#1b3a1b;
  border:1px solid #d9e3d9; border-radius:999px;
  padding:6px 10px; font-weight:700; font-size:.9rem;
}
.pill-status{
  background:#e6f6ea; border-color:#b2dfb5; color:#1b5e20;
}

.grid{
  display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px;
}
@media (max-width:992px){ .grid{ grid-template-columns:1fr 1fr; } }
@media (max-width:576px){ .grid{ grid-template-columns:1fr; } }

.card{
  background:var(--card); border:1px solid #eef2ee; border-radius:16px;
  box-shadow:0 8px 24px rgba(0,0,0,.06); padding:16px 18px;
}
.metric{ font-size:2rem; font-weight:900; color:#1b3a1b; }
.sub{ color:#6b7c6b; margin-top:4px; font-weight:700; }
.small{ font-size:.85rem; color:#6b7c6b; font-weight:600; }

.row{
  display:flex; align-items:center; gap:10px; justify-content:space-between;
  padding:12px 14px; border:1px solid #eef2ee; border-radius:12px;
  background:#fff; margin-top:10px;
}
.bar{ height:10px; background:#ececec; border-radius:999px; overflow:hidden; }
.bar>span{ display:block; height:100%; background:linear-gradient(90deg, var(--acc), #ffd37a); }

.actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
.btn{
  display:inline-flex; align-items:center; justify-content:center;
  padding:12px 20px; border:0; border-radius:12px;
  background:var(--g); color:#fff; font-weight:800; cursor:pointer;
}
.btn[disabled]{ opacity:.5; cursor:not-allowed; }
.btn-ghost{ background:#eef2ed; color:#1f3a1f; }

.section-title{ font-weight:900; color:#213421; margin:18px 0 8px; }
.list{ display:grid; gap:10px; }
.prev-card{
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 14px; border:1px solid #eef2ee; border-radius:12px; background:#fff;
}
.prev-meta{ color:#6b7c6b; font-size:.9rem; }
</style>

<div class="main-content">
  <div class="wrap">

    <!-- HEADER: same feel as SLT summary -->
    <section class="hero">
      <div class="hero-main">
        <h1 class="hero-title">Power Builder Test</h1>
        <p class="hero-sub">You’ve completed the Power Builder Test.</p>
        <p class="hero-sub2">Your results are ready. Here’s a quick summary.</p>
      </div>

      <div class="hero-right">
        <?php if (!empty($att['level_name'])): ?>
          <span class="pill">Color Category: <?= htmlspecialchars($att['level_name']) ?></span>
        <?php endif; ?>
        <span class="pill pill-status">Completed</span>
      </div>
    </section>

    <!-- TOP METRICS (4 cards) -->
    <div class="grid">
      <div class="card">
        <div class="metric"><?= (int)$overall_pct ?>%</div>
        <div class="sub">
          Overall Accuracy
          <span class="small"> (<?= (int)$sum_score ?>/<?= (int)$sum_max ?>)</span>
        </div>
      </div>

      <div class="card">
        <div class="metric"><?= fmt_hms($sum_secs) ?></div>
        <div class="sub">Total Reading Time</div>
      </div>

      <div class="card">
        <div class="metric"><?= $avg_wpm > 0 ? (int)$avg_wpm : '—' ?></div>
        <div class="sub">
          Average WPM
          <?php if ($min_wpm > 0 && $max_wpm > 0): ?>
            <div class="small">Range: <?= (int)$min_wpm ?>–<?= (int)$max_wpm ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="metric"><?= (int)$stories ?></div>
        <div class="sub">Stories Completed</div>
      </div>
    </div>

    <!-- PER STORY BREAKDOWN -->
    <div class="card" style="margin-top:14px;">
      <?php foreach ($rows as $i=>$r):
        $s = (int)($r['score'] ?? 0);
        $m = (int)($r['max_score'] ?? 0);
        $pct = ($m>0) ? (int)round($s/$m*100) : 0; ?>
        <div class="row">
          <div style="flex:1;">
            <div style="font-weight:800; color:#213421;">
              <?= htmlspecialchars($r['title'] ?: ('Story '.($i+1))) ?>
            </div>
            <div class="bar" style="margin-top:6px;">
              <span style="width:<?= $pct ?>%;"></span>
            </div>
          </div>
          <div style="min-width:120px; text-align:right; font-weight:800;">
            <?= $s ?>/<?= $m ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="actions">
        <a class="btn-ghost" href="index.php">Go to Dashboard</a>

        <?php if ($did_pass): ?>
          <a class="btn" href="stories_rb.php">Start Rate Builder</a>
        <?php else: ?>
          <a class="btn" href="<?= htmlspecialchars($retakeHref) ?>">Retake Power Builder</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- PREVIOUS RESULTS -->
    <?php if (!empty($prev)): ?>
      <div class="section-title">Previous Results</div>
      <div class="list">
        <?php foreach ($prev as $p): ?>
          <div class="prev-card">
            <div>
              <div style="font-weight:900; color:#213421;">
                Result: <?= (int)$p['percent'] ?>%
              </div>
              <div class="prev-meta">
                Submitted: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($p['submitted_at'] ?? ''))) ?>
              </div>
            </div>
            <div>
              <a class="btn-ghost" href="stories_pb_done.php?attempt_id=<?= (int)$p['attempt_id'] ?>">
                View
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
