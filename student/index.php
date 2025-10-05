<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$PAGE_TITLE  = 'Science Research Dashboard';
$ACTIVE_MENU = 'dashboard';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

/* ---------------- Helpers ---------------- */
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
function clamp($v, $min=0, $max=100){ $v = (float)$v; if($v<$min) $v=$min; if($v>$max) $v=$max; return $v; }

/* ---------------- Data ---------------- */
$studentId   = (int)($_SESSION['user_id'] ?? 0);
$studentName = htmlspecialchars($_SESSION['full_name'] ?? 'Student');

/* ----- Current level (id + name + color) ----- */
$level = null;
if ($studentId > 0) {
  $stmt = $conn->prepare("
    SELECT L.level_id, L.name, L.color_hex
      FROM student_level SL
      JOIN sra_levels L ON L.level_id = SL.level_id
     WHERE SL.student_id = ? AND SL.is_current = 1
     LIMIT 1
  ");
  $stmt->bind_param('i', $studentId);
  $stmt->execute();
  $level = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}
$levelId      = isset($level['level_id']) ? (int)$level['level_id'] : null;
$levelName    = $level['name']  ?? null;
$isFirstTimer = empty($levelName);

/* ----- Totals per level (published only) ----- */
$pbPublishedTotal = 0;
$rbPublishedTotal = 0;

if ($levelId) {
  // PB total
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute();
    $r = $stmt->get_result();
    $pbPublishedTotal = (int)($r->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }

  // RB total
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='RB' AND ss.level_id=? AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute();
    $r = $stmt->get_result();
    $rbPublishedTotal = (int)($r->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }
}

$pbDen = max(1, $pbPublishedTotal);  // iwas divide-by-zero
$rbDen = max(1, $rbPublishedTotal);

/* ----- Dynamic attempt-based progress: PB & RB ----- */

/** Find in-progress attempt IDs (PB/RB) */
$pbAidInProgress = (int)(scalar(
  $conn,
  "SELECT attempt_id
     FROM assessment_attempts
    WHERE student_id=? AND set_type='PB' AND status='in_progress'
    ORDER BY started_at DESC, attempt_id DESC
    LIMIT 1",
  [$studentId], 'i'
) ?? 0);

$rbAidInProgress = (int)(scalar(
  $conn,
  "SELECT attempt_id
     FROM assessment_attempts
    WHERE student_id=? AND set_type='RB' AND status='in_progress'
    ORDER BY started_at DESC, attempt_id DESC
    LIMIT 1",
  [$studentId], 'i'
) ?? 0);

/** Helper to get attempt progress (done/total) for a given attempt_id */
$attemptProgress = function(mysqli $c, int $aid): array {
  if ($aid <= 0) return [0,0];
  $total = (int)(scalar($c, "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=?", [$aid], 'i') ?? 0);
  $done  = (int)(scalar($c, "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=? AND score IS NOT NULL", [$aid], 'i') ?? 0);
  return [$done, $total];
};

/** PB progress numbers to SHOW on the card */
$pbProgDone  = 0;
$pbProgTotal = (int)$pbPublishedTotal; // fallback

if ($pbAidInProgress > 0) {
  // live in-progress
  [$pbProgDone, $pbProgTotal] = $attemptProgress($conn, $pbAidInProgress);
} else {
  // fallback to latest submitted/scored PB attempt (most recent)
  $pbLastAttempt = (int)(scalar(
    $conn,
    "SELECT attempt_id
       FROM assessment_attempts
      WHERE student_id=? AND set_type='PB' AND status IN ('submitted','scored')
      ORDER BY submitted_at DESC, attempt_id DESC
      LIMIT 1",
    [$studentId], 'i'
  ) ?? 0);

  if ($pbLastAttempt > 0) {
    [$pbProgDone, $pbProgTotal] = $attemptProgress($conn, $pbLastAttempt);
  } else {
    $pbProgDone = 0;
    $pbProgTotal = (int)$pbPublishedTotal;
  }
}

/** RB progress numbers to SHOW on the card */
$rbProgDone  = 0;
$rbProgTotal = (int)$rbPublishedTotal; // fallback

if ($rbAidInProgress > 0) {
  [$rbProgDone, $rbProgTotal] = $attemptProgress($conn, $rbAidInProgress);
} else {
  $rbLastAttempt = (int)(scalar(
    $conn,
    "SELECT attempt_id
       FROM assessment_attempts
      WHERE student_id=? AND set_type='RB' AND status IN ('submitted','scored')
      ORDER BY submitted_at DESC, attempt_id DESC
      LIMIT 1",
    [$studentId], 'i'
  ) ?? 0);

  if ($rbLastAttempt > 0) {
    [$rbProgDone, $rbProgTotal] = $attemptProgress($conn, $rbLastAttempt);
  } else {
    $rbProgDone  = 0;
    $rbProgTotal = (int)$rbPublishedTotal;
  }
}

/** Fractions for overall progress */
$pbFrac = ($pbProgTotal > 0) ? $pbProgDone / $pbProgTotal : 0.0;
$rbFrac = ($rbProgTotal > 0) ? $rbProgDone / $rbProgTotal : 0.0;

if ($levelId) {
  // PB
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute(); $r=$stmt->get_result();
    $pbPublishedTotal = (int)($r->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }
  // RB
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='RB' AND ss.level_id=? AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute(); $r=$stmt->get_result();
    $rbPublishedTotal = (int)($r->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }
}

$pbDen = max(1, $pbPublishedTotal);   // iwas /0 sa calc
$rbDen = max(1, $rbPublishedTotal);


/* ----- SLT done? (any submitted SLT attempt) ----- */
$sltDone = (int)(scalar(
  $conn,
  "SELECT COUNT(*) FROM assessment_attempts
    WHERE student_id = ? AND set_type = 'SLT' AND status = 'submitted' LIMIT 1",
  [$studentId],'i'
) ?? 0) > 0;

/* ----- PB/RB: completed stories (distinct story_id) ----- */
$pbCompleted = (int)(scalar(
  $conn,
  "SELECT COUNT(DISTINCT s.story_id)
     FROM attempt_stories s
     JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
    WHERE a.student_id = ? AND a.set_type = 'PB' AND a.status = 'submitted'",
  [$studentId],'i'
) ?? 0);

$rbCompleted = (int)(scalar(
  $conn,
  "SELECT COUNT(DISTINCT s.story_id)
     FROM attempt_stories s
     JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
    WHERE a.student_id = ? AND a.set_type = 'RB' AND a.status = 'submitted'",
  [$studentId],'i'
) ?? 0);
/* ----- Thresholds / gates (match stories_rb.php) ----- */
$PB_OVERALL_PASS = 75.0;  // unlock RB if PB overall >= 75
$RB_OVERALL_PASS = 85.0;  // unlock Certificate if RB overall >= 85 (as per announcement)

/* per-story thresholds (default 75 if not set) */
$pbPassThreshold = (float)(scalar(
  $conn, "SELECT min_percent FROM level_thresholds WHERE applies_to='PB' AND level_id=? LIMIT 1",
  [$levelId],'i'
) ?? 75.0);
$rbPassThreshold = (float)(scalar(
  $conn, "SELECT min_percent FROM level_thresholds WHERE applies_to='RB' AND level_id=? LIMIT 1",
  [$levelId],'i'
) ?? 75.0);

/* scaled required passes (8/15 of what’s actually published) */
$requiredPBPass = $pbPublishedTotal ? max(1, (int)ceil($pbPublishedTotal * (8/15))) : 8;
$requiredRBPass = $rbPublishedTotal ? max(1, (int)ceil($rbPublishedTotal * (8/15))) : 8;

/* latest overall % per test */
$pbOverallPercent = (float)(scalar($conn, "
  SELECT percent FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status='submitted'
  ORDER BY submitted_at DESC, attempt_id DESC LIMIT 1",
  [$studentId],'i'
) ?? 0.0);

$rbOverallPercent = (float)(scalar($conn, "
  SELECT percent FROM assessment_attempts
  WHERE student_id=? AND set_type='RB' AND status='submitted'
  ORDER BY submitted_at DESC, attempt_id DESC LIMIT 1",
  [$studentId],'i'
) ?? 0.0);

/* passed story counts */
$pbPassed = (int)(scalar($conn, "
  SELECT COUNT(DISTINCT s.story_id)
  FROM attempt_stories s
  JOIN assessment_attempts a ON a.attempt_id=s.attempt_id
  WHERE a.student_id=? AND a.set_type='PB' AND a.status='submitted'
    AND s.percent >= ?",
  [$studentId, $pbPassThreshold], 'id'
) ?? 0);

$rbPassed = (int)(scalar($conn, "
  SELECT COUNT(DISTINCT s.story_id)
  FROM attempt_stories s
  JOIN assessment_attempts a ON a.attempt_id=s.attempt_id
  WHERE a.student_id=? AND a.set_type='RB' AND a.status='submitted'
    AND s.percent >= ?",
  [$studentId, $rbPassThreshold], 'id'
) ?? 0);

/* final gates (now identical to stories_rb.php) */
$pbUnlocked   = $sltDone;
$rbUnlocked   = ($pbAidInProgress > 0)                   // may in-progress RB → unlocked
             || ($pbOverallPercent >= $PB_OVERALL_PASS)  // OR PB overall pass
             || ($pbPassed >= $requiredPBPass);          // OR scaled passed stories

$certUnlocked = ($rbOverallPercent >= $RB_OVERALL_PASS)  // RB overall pass (e.g., 85%)
             || ($rbPassed >= $requiredRBPass);          // OR scaled passed stories


/* ----- Overall progress (10% SLT + 45% PB + 45% RB) ----- */
/* Use COMPLETED stories so the bar moves even if a story didn't pass */
/* ----- Overall progress (10% SLT + 45% PB + 45% RB) — now attempt-based dynamic ----- */
$overall = 0.0;
$overall += $sltDone ? 10 : 0;
$overall += clamp($pbFrac, 0, 1) * 45;  // PB fraction (done/total) from A)
$overall += clamp($rbFrac, 0, 1) * 45;  // RB fraction (done/total) from A)
$overall = clamp(round($overall, 1));
$remaining = clamp(100 - $overall);


/* ----- Color pill style ----- */
function hex_to_rgb(string $hex): array {
  $h = ltrim($hex, '#'); if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  $int = hexdec($h); return [($int>>16)&255, ($int>>8)&255, $int&255];
}
function level_color_hex(?string $name, ?string $hexFromDb): ?string {
  if ($hexFromDb) return $hexFromDb;
  if (!$name) return null;
  $map = ['red'=>'#D32F2F','orange'=>'#EF6C00','yellow'=>'#F9A825','blue'=>'#1565C0','green'=>'#2E7D32'];
  return $map[strtolower(trim($name))] ?? null;
}
$lvHex = level_color_hex($levelName ?? null, $level['color_hex'] ?? null);
$levelPillStyle = '';
if ($lvHex) { [$r,$g,$b] = hex_to_rgb($lvHex); $levelPillStyle = "background: rgba($r,$g,$b,.12); border:1px solid $lvHex; color:#1b3a1b;"; }

/* ----- Next step label + CTA ----- */
if ($isFirstTimer) {
  $nextStepLabel = 'Start your Starting Level Test';
  $nextStepHref  = 'stories.php?start=slt';
  $ctaText = 'Start SLT'; $ctaHref = 'stories_sl.php';
} elseif ($pbCompleted < $pbDen) {
  $nextStepLabel = 'Continue Power Builder – Story ' . ($pbCompleted + 1);
  $ctaText       = ($pbCompleted === 0) ? 'Start Power Builder' : 'Continue Power Builder';
  $ctaHref       = 'stories_pb.php';  // <-- landing/instructions page
} elseif ($rbCompleted < $rbDen) {
  $nextStepLabel = 'Continue Rate Builder – Story ' . ($rbCompleted + 1);
  $ctaText = ($rbCompleted === 0) ? 'Start Rate Builder' : 'Continue Rate Builder';
  $ctaHref = 'stories_rb.php'; // same pattern as PB
}
 elseif ($certUnlocked) {
  $nextStepLabel = 'Congratulations! You can download your certificate.';
  $nextStepHref  = 'certificates.php';
  $ctaText = 'Download Certificate'; $ctaHref = 'certificates.php';
} else {
  $nextStepLabel = 'You’re all set! Review your results.';
  $nextStepHref  = 'results.php';
  $ctaText = 'View Results'; $ctaHref = 'results.php';
}

?>

<style>
/* ================= Page-scoped styles (responsive) ================= */

/* Fluid typography & spacing */
:root{
  --g: #003300;
  --acc: #ECA305;
  --card-shadow: 0 2px 14px rgba(0,0,0,.06);
}
.main-content{
  /* remove the 1280px cap that causes a big right gap */
  max-width: none;
  width: calc(100% - 220px);   /* fill everything to the right of the 220px sidebar */
  margin-left: 220px;
}
@media (max-width: 992px){
  .main-content{
    width: 100%;
    margin-left: 0;
  }
}

/* ---------- Status strip (Welcome + KPIs) ---------- */
.status-strip{
  display:flex; flex-wrap:wrap;
  align-items:center; gap:16px;
  background:#fff;
  border-bottom:2px solid #e0e0e0;
  padding: clamp(12px, 2vw, 20px);
  margin: 0 0 12px 0;
}
.status-strip .welcome{
  min-width: 220px; margin-right:auto;
}
.status-strip .hi{
  font-size: clamp(1rem, 1.2rem + .2vw, 1.25rem);
  font-weight: 800; color: var(--g);
  line-height: 1.2;
}
.status-strip .sub{
  font-size: clamp(.9rem, .95rem + .15vw, 1rem);
  opacity:.85;
}

/* KPI group becomes horizontally scrollable *only if needed* on very small screens */
.kpis{
  display:flex; flex-wrap:wrap; gap:12px;
}
@media (max-width: 480px){
  .kpis{ overflow-x:auto; padding-bottom:4px; }
  .kpis::-webkit-scrollbar{ height: 6px; }
}
.kpi{
  background:#fff; border:1px solid #e9e9e9; border-radius:12px;
  padding:12px 14px; min-width: 180px;
  box-shadow: var(--card-shadow);
  flex: 1 1 180px;
}
.kpi-label{
  font-size:.8rem; opacity:.8; margin-bottom:6px;
}
.kpi-value{
  font-weight:800; white-space:nowrap;
}
.badge-level{
  display:inline-block; padding:6px 12px; border-radius:999px;
  background:rgba(0,128,0,.1); color:var(--g); border:1px solid rgba(0,128,0,.2);
}

.kpi-progress{
  position:relative; width: 100%; max-width: 220px;
  height: 12px; border-radius: 999px; background:#eee; overflow:hidden;
}
.kpi-progress-bar{ height:100%; background: linear-gradient(90deg, var(--acc), #ffd37a); }
.kpi-note{ font-size:.8rem; margin-top:6px; text-align:right; }

/* ---------- Banner (retain your existing HTML) ---------- */
.banner{
  display:flex; align-items:center; gap: clamp(12px, 3vw, 24px);
  background:#fff; padding: clamp(12px, 2.2vw, 20px);
  border-bottom:2px solid #e0e0e0;
}
.banner img{
  width: clamp(200px, 40vw, 320px);
  max-width: 100%;
  border-radius: 12px;
}
.quote{
  font-style: italic;
  font-size: clamp(1.1rem, 1rem + .6vw, 1.6rem);
  line-height: 1.5; color:#2e2e2e;
  padding: clamp(8px, 2vw, 16px);
  background:#f9f9f9; border-left:6px solid var(--g);
  border-radius:8px;
}
@media (max-width: 992px){
  .banner{ flex-direction:column; text-align:center; }
  .quote{ text-align: center; border-left-width:0; border-top:6px solid var(--g); }
}

/* ---------- Next step CTA ---------- */
.next-card{
  display:flex; align-items:center; justify-content:space-between; gap: 12px;
  background:#fcfcfc; border:1px solid #ebebeb; border-left:6px solid var(--acc);
  padding: clamp(12px, 2vw, 18px); margin: clamp(12px, 2vw, 18px) clamp(12px, 2vw, 20px) 0;
  border-radius:12px; box-shadow: var(--card-shadow);
}
.next-title{ font-weight:900; color:var(--g); margin-bottom:4px; font-size: clamp(1rem, 1rem + .3vw, 1.2rem); }
.next-sub{ opacity:.9; font-size: clamp(.9rem, .95rem + .2vw, 1rem); }
.btn-primary{
  display:inline-block; padding:12px 18px; border-radius:10px;
  background:var(--g); color:#fff; text-decoration:none; font-weight:800;
  transition: transform .06s ease, filter .2s ease;
}
.btn-primary:active{ transform: translateY(1px); }
.btn-primary:hover{ filter:brightness(1.06); }
@media (max-width: 600px){
  .next-card{ flex-direction:column; align-items:flex-start; }
  .btn-primary{ width: 100%; text-align:center; }
}

/* ---------- Main grid (modules) ---------- */
.dashboard-grid{
  display:grid; gap: clamp(12px, 2vw, 20px);
  padding: clamp(12px, 2vw, 20px);
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}
.card-box{
  background:#fff; padding: clamp(14px, 2vw, 20px);
  border-radius:12px; box-shadow: var(--card-shadow);
  display:flex; flex-direction:column; gap:10px;
}
.card-box h4{
  margin:0; color:var(--g);
  border-bottom:2px solid var(--g); padding-bottom:10px;
  font-size: clamp(1rem, 1rem + .2vw, 1.1rem);
}
.card-box p{ margin:0; font-size: clamp(.9rem, .95rem + .15vw, 1rem); }
.card-box button{
  padding:12px 14px; border:none; background:var(--g);
  color:#fff; border-radius:10px; cursor:pointer; font-weight:800;
  transition: transform .06s ease, filter .2s ease;
}
.card-box button:active{ transform: translateY(1px); }
.card-box button:hover{ filter: brightness(1.06); }
@media (max-width: 480px){
  .card-box button{ width: 100%; }
}

/* ---------- Info grid (Announcements + Tips) ---------- */
.info-grid{
  display:grid; gap: clamp(12px, 2vw, 20px);
  padding: 0 clamp(12px, 2vw, 20px) clamp(24px, 3vw, 32px);
  grid-template-columns: repeat(2, 1fr);
}
.info-card{
  background:#fff; border-radius:12px; box-shadow: var(--card-shadow);
  padding: clamp(14px, 2vw, 20px);
}
.info-card h3{
  margin:0 0 10px; color:var(--g);
  border-bottom:2px solid var(--g); padding-bottom:8px;
  font-size: clamp(1rem, 1rem + .2vw, 1.1rem);
}
.info-card ul{ margin:10px 0 0 18px; }

@media (max-width: 900px){
  .info-grid{ grid-template-columns: 1fr; }
}

/* Reduce motion for users who prefer it */
@media (prefers-reduced-motion: reduce){
  .btn-primary, .card-box button{ transition: none; }
}
/* Remove the reserved scrollbar gutter that creates a blank strip on the right */
html { scrollbar-gutter: auto !important; }

/* Avoid accidental horizontal overflow showing side gaps */
html, body { overflow-x: hidden; }

/* On mobile, make key sections bleed edge-to-edge so walang mukhang border */
@media (max-width: 992px){
  .status-strip,
  .banner,
  .next-card {
    margin-left: 0;
    margin-right: 0;
    border-left-width: 0;
    border-right-width: 0;
    border-radius: 0;
  }
}
body, .main-content { border: 0 !important; }
.next-card {
   background: linear-gradient(90deg,#fdf6e3,#fff);
}
.next-title { font-size: 1.3rem; }
/* ----- Flow & arrows ----- */
.flow{
  display:flex; align-items:stretch; gap:12px; flex-wrap:wrap;
  padding: clamp(12px, 2vw, 20px);
}
.flow .card-box{ flex: 1 1 260px; }
.flow-arrow{
  align-self:center;
  font-size: clamp(1.2rem, 1rem + .6vw, 1.6rem);
  color: var(--g); opacity: .7;
}

/* Hide arrows when stacked on small screens */
@media (max-width: 900px){
  .flow-arrow{ display:none; }
}

/* ----- Locked look & feel ----- */
.card-box.locked{
  opacity: .55;
  filter: grayscale(.15);
  /* wala nang pointer-events:none; para gumana ang title tooltip */
}
.card-box.locked button{
  background: #9aa09a !important;
  cursor: not-allowed;
  filter: none !important;
}

</style>

<div class="main-content">

  <!-- Welcome & KPIs -->
  <section class="status-strip">
    <div class="welcome">
      <div class="hi">Hi, <?= $studentName; ?>!</div>
      <div class="sub"><?= $isFirstTimer ? 'Let’s find your starting level first.' : 'Ready to improve your reading today?'; ?></div>
    </div>

    <div class="kpis" role="list">
      <div class="kpi" role="listitem" aria-label="Current Level">
        <div class="kpi-label">Color Category</div>
       <div class="kpi-value">
  <?php if ($isFirstTimer): ?>
    <span class="badge-level" style="background:#f3f5f3;border:1px solid #dfe6df;color:#1b3a1b;">
      Not set yet
    </span>
  <?php else: ?>
    <span class="badge-level" style="<?= htmlspecialchars($levelPillStyle) ?>">
      <?= htmlspecialchars($levelName) ?>
    </span>
  <?php endif; ?>
</div>

      </div>

      <div class="kpi" role="listitem" aria-label="Overall Progress">
        <div class="kpi-label">Overall Progress</div>
        <div class="kpi-progress" aria-hidden="true">
          <div class="kpi-progress-bar" style="width: <?= (float)$overall; ?>%"></div>
        </div>
        <div class="kpi-note"><?= (float)$overall; ?>%</div>
      </div>

      <div class="kpi" role="listitem" aria-label="To Finish">
        <div class="kpi-label">To Finish</div>
        <div class="kpi-value"><?= (float)$remaining; ?>%</div>
      </div>
    </div>
  </section>

  <!-- Banner with quote (kept)
  <div class="banner">
    <img src="assets/picture2.jpg" alt="picture2">
    <div class="quote">
      “The more that you read, the more things you will know.
      The more that you learn, the more places you’ll go.”<br> — Dr. Seuss
    </div>
  </div> -->

  <!-- Next Step -->
  <section class="next-card">
    <div>
      <div class="next-title"><?= $isFirstTimer ? 'Start your journey' : 'Continue where you left off'; ?></div>
      <div class="next-sub"><?= htmlspecialchars($nextStepLabel); ?></div>
    </div>
    <a class="btn-primary" href="<?= htmlspecialchars($ctaHref); ?>">
  <?= htmlspecialchars($ctaText); ?>
</a>
  </section>

<!-- Core Modules (with flow + arrows + locking) -->
<div class="flow">
  <!-- SLT -->
  <div class="card-box">
    <h4>📘 Starting Level Test</h4>
    <p>Take the test to determine your reading level.</p>
    <button type="button" onclick="location.href='stories_sl.php'">
      <?= $sltDone ? 'Retake / Review' : 'Take Test' ?>
    </button>
  </div>

  <i class="fas fa-arrow-right flow-arrow" aria-hidden="true"></i>

  <!-- PB (locked until SLT is done) -->
  <div class="card-box <?= $pbUnlocked ? '' : 'locked' ?>"
       title="<?= $pbUnlocked ? '' : 'Unlocks after you finish the Starting Level Test' ?>">
    <h4>📈 Power Builder Assessment</h4>
  <p style="opacity:.8;margin-top:-6px;">
  Progress: <?= (int)$pbProgDone; ?>/<?= (int)$pbProgTotal; ?>
</p>


    <p>Take the test to improve your comprehension skills.</p>

<?php if ($pbUnlocked): ?>
  <?php $pbBtnText = ($pbProgDone === 0) ? 'Start' : 'Continue'; ?>
  <button type="button" onclick="location.href='stories_pb.php'"><?= $pbBtnText; ?></button>
<?php else: ?>
  <button type="button" disabled aria-disabled="true">Locked</button>
<?php endif; ?>



  </div>

  <i class="fas fa-arrow-right flow-arrow" aria-hidden="true"></i>

  <!-- RB (locked until PB >= threshold) -->
  <div class="card-box <?= $rbUnlocked ? '' : 'locked' ?>"
       title="<?= $rbUnlocked ? '' : 'Unlocks after you pass ' . PB_PASS_REQUIRED . ' Power Builder stories' ?>">
    <h4>📚 Rate Builder Assessment</h4>
    <p>Rate your Builder Assessment.</p>
  <p style="opacity:.8;margin-top:-6px;">
  <?php if ($rbProgTotal > 0): ?>
    Progress: <?= (int)$rbProgDone; ?>/<?= (int)$rbProgTotal; ?>
  <?php else: ?>
    Stories Available: 0
  <?php endif; ?>
</p>

<?php if ($rbUnlocked): ?>
  <?php $rbBtnText = ($rbProgDone === 0) ? 'Start' : 'Continue'; ?>
<button type="button" onclick="location.href='stories_rb.php'"><?= $rbBtnText; ?></button>

<?php else: ?>
  <button type="button" disabled aria-disabled="true">Locked</button>
<?php endif; ?>

  </div>

  <i class="fas fa-arrow-right flow-arrow" aria-hidden="true"></i>

  <!-- Certificate (locked until RB >= threshold) -->
  <div class="card-box <?= $certUnlocked ? '' : 'locked' ?>"
       title="<?= $certUnlocked ? '' : 'Unlocks after you pass ' . RB_PASS_REQUIRED . ' Rate Builder stories' ?>">
    <h4>🎓 Certificate</h4>
    <p>Download your certificate once you finish Rate Builder.</p>

    <?php if ($certUnlocked): ?>
      <button type="button" onclick="location.href='certificates.php'">View Certificate</button>
    <?php else: ?>
      <button type="button" disabled aria-disabled="true">Locked</button>
    <?php endif; ?>
  </div>
</div>


  <!-- Announcements + Reading Tips -->
  <section class="info-grid">
    <div class="info-card">
      <h3>🔔 Announcements</h3>
      <ul>
        <li>All students are required to complete the SRA Starting Level Test by <strong>August 15, 2025</strong>.</li>
        <li>The Power Builder Assessment will open on <strong>August 20, 2025</strong>.</li>
        <li>Students who achieve 85%+ in Rate Builder receive a Certificate.</li>
        <li>Log in 15 minutes before your scheduled test to avoid delays.</li>
      </ul>
    </div>
    <div class="info-card">
      <h3>💡 Reading Tips</h3>
      <ul>
        <li>Preview the story first—scan headings and pictures.</li>
        <li>Set a purpose: “What do I want to learn here?”</li>
        <li>Underline key ideas and summarize each paragraph.</li>
        <li>Time yourself weekly to track WPM growth.</li>
      </ul>
    </div>
  </section>
</div>

</body>
</html>
