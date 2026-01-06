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
    if ($res = $stmt->get_result()){
      $row = $res->fetch_row(); $val = $row ? $row[0] : null; $res->free();
    }
  }
  $stmt->close();
  return $val;
}
function clamp($v, $min=0, $max=100){
  $v = (float)$v; if($v<$min) $v=$min; if($v>$max)$v=$max; return $v;
}

/* ---------------- Data ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$studentId   = (int)($_SESSION['user_id'] ?? 0);
$studentName = htmlspecialchars($_SESSION['full_name'] ?? 'Student');

/* ----- Assessment validation status (pending / validated / invalid) ----- */
$validationStatus = null;
$validationReason = null;

if ($studentId > 0) {
  if ($stmt = $conn->prepare("
        SELECT status, reason
          FROM assessment_validation
         WHERE student_id = ?
         LIMIT 1
  ")) {
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $validationStatus = $row['status'] ?? null;   // pending | validated | invalid
      $validationReason = $row['reason'] ?? null;
    }
    $stmt->close();
  }
}


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

/* ----- SLT done? (any finalized SLT attempt) ----- */
$sltDone = (int)(scalar(
  $conn,
  "SELECT COUNT(*)
     FROM assessment_attempts
    WHERE student_id=? AND set_type='SLT' AND status IN ('submitted','scored')
    LIMIT 1",
  [$studentId],'i'
) ?? 0) > 0;

/* IMPORTANT: ang tunay na first-timer ay 'di pa nakapag-SLT' */
$isFirstTimer = !$sltDone;

/* ----- Published story totals (once) ----- */
$pbPublishedTotal = 0;
$rbPublishedTotal = 0;

if ($levelId) {
  // PB totals
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
  // RB totals
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
$pbDen = max(1, $pbPublishedTotal);
$rbDen = max(1, $rbPublishedTotal);

/* ----- COMPLETED stories (must have score; attempt finalized) ----- */
$pbCompleted = (int)(scalar(
  $conn,
  "SELECT COUNT(DISTINCT s.story_id)
     FROM attempt_stories s
     JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
    WHERE a.student_id = ?
      AND a.set_type = 'PB'
      AND a.status IN ('in_progress','submitted','scored')
      AND s.score IS NOT NULL",
  [$studentId],'i'
) ?? 0);

$rbCompleted = (int)(scalar(
  $conn,
  "SELECT COUNT(DISTINCT s.story_id)
     FROM attempt_stories s
     JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
    WHERE a.student_id = ?
      AND a.set_type = 'RB'
      AND a.status IN ('in_progress','submitted','scored')
      AND s.score IS NOT NULL",
  [$studentId],'i'
) ?? 0);

/* ===== Completion vs Published (for progress & gates) ===== */
$pbCardDone  = min($pbCompleted, $pbPublishedTotal);
$pbCardTotal = (int)$pbPublishedTotal;

$rbCardDone  = min($rbCompleted, $rbPublishedTotal);
$rbCardTotal = (int)$rbPublishedTotal;

/* Fractions for overall progress now use COMPLETION */
$pbFrac = ($pbCardTotal > 0) ? ($pbCardDone / $pbCardTotal) : 0.0;
$rbFrac = ($rbCardTotal > 0) ? ($rbCardDone / $rbCardTotal) : 0.0;

/* Gates: unlock when ALL published stories are completed (scored) */
$pbUnlocked   = $sltDone;  // PB still gated by SLT
$rbUnlocked   = ($pbCardTotal > 0 && $pbCardDone >= $pbCardTotal);
$certUnlocked = ($rbCardTotal > 0 && $rbCardDone >= $rbCardTotal);

/* ----- Certificate state based on RB completion + validation ----- */
$certState = 'locked'; // locked | pending | validated | invalid

if ($certUnlocked) {
  if ($validationStatus === 'validated') {
    $certState = 'validated';
  } elseif ($validationStatus === 'invalid') {
    $certState = 'invalid';
  } else {
    // null or 'pending'
    $certState = 'pending';
  }
}

/* Message to show inside Certificate card */
$certMessage = 'Download your certificate once you finish Rate Builder.';
if ($certState === 'pending') {
  $certMessage = 'You have completed all assessments. Your certificate is under review. Please allow 1–2 working days for validation.';
} elseif ($certState === 'validated') {
  $certMessage = 'Your results have been approved. You may now view and download your certificate.';
} elseif ($certState === 'invalid') {
  $certMessage = 'Your results were not approved. Please contact your SRA coordinator during office hours for assistance.';
}

/* Overall progress (10% SLT + 45% PB + 45% RB) */
$overall   = 0.0;
$overall  += $sltDone ? 10 : 0;
$overall  += clamp($pbFrac, 0, 1) * 45;
$overall  += clamp($rbFrac, 0, 1) * 45;
$overall   = clamp(round($overall, 1));
$remaining = clamp(100 - $overall);

/* ----- Color pill style ----- */
function hex_to_rgb(string $hex): array {
  $h = ltrim($hex, '#');
  if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  $int = hexdec($h);
  return [($int>>16)&255, ($int>>8)&255, $int&255];
}
function level_color_hex(?string $name, ?string $hexFromDb): ?string {
  if ($hexFromDb) return $hexFromDb;
  if (!$name) return null;
  $map = [
    'red'=>'#D32F2F','orange'=>'#EF6C00','yellow'=>'#F9A825',
    'blue'=>'#1565C0','green'=>'#2E7D32','purple'=>'#7E57C2'
  ];
  return $map[strtolower(trim($name))] ?? null;
}
$lvHex = level_color_hex($levelName ?? null, $level['color_hex'] ?? null);
$levelPillStyle = '';
if ($lvHex) { [$r,$g,$b]=hex_to_rgb($lvHex); $levelPillStyle = "background:rgba($r,$g,$b,.12);border:1px solid $lvHex;color:#1b3a1b;"; }

/* ===== Next step (completion-based) ===== */
$nextStepLabel = 'You’re all set! Review your results.';
$ctaText = 'View Results';
$ctaHref = 'results.php';
$ctaEnabled = true; // para ma-disable natin kapag pending/invalid

if ($isFirstTimer) {

  $nextStepLabel = 'Start your Starting Level Test';
  $ctaText = 'Start SLT';
  $ctaHref = 'stories_sl.php';
  $ctaEnabled = true;

} elseif ($pbCardTotal > 0 && $pbCardDone < $pbCardTotal) {

  $nextStepLabel = ($pbCardDone === 0)
    ? 'Start Power Builder'
    : ('Continue Power Builder – Story ' . ($pbCardDone + 1));
  $ctaText = ($pbCardDone === 0) ? 'Start Power Builder' : 'Continue Power Builder';
  $ctaHref = 'stories_pb.php';
  $ctaEnabled = true;

} elseif ($rbCardTotal > 0 && $rbCardDone < $rbCardTotal) {

  $nextStepLabel = ($rbCardDone === 0)
    ? 'Start Rate Builder'
    : ('Continue Rate Builder – Story ' . ($rbCardDone + 1));
  $ctaText = ($rbCardDone === 0) ? 'Start Rate Builder' : 'Continue Rate Builder';
  $ctaHref = 'stories_rb.php';
  $ctaEnabled = true;

} elseif ($certUnlocked) {

  if ($certState === 'validated') {
    // ✅ Approved – pwedeng mag-download
    $nextStepLabel = 'Congratulations! Your certificate has been approved. You can now download it.';
    $ctaText = 'Download Certificate';
    $ctaHref = 'certificates.php';
    $ctaEnabled = true;

  } elseif ($certState === 'pending') {
    // ⏳ Under review
    $nextStepLabel = 'You’ve completed all tests. Your results are now under review. Please allow 1–2 working days for approval.';
    $ctaText = 'Certificate under review';
    $ctaHref = '#';
    $ctaEnabled = false;

  } elseif ($certState === 'invalid') {
    // ❌ Not approved
    $nextStepLabel = 'Your results were not approved. Please contact your SRA coordinator during office hours for assistance.';
    $ctaText = 'Contact your coordinator';
    $ctaHref = '#';
    $ctaEnabled = false;
  }
}

/* ---------- Answer distribution (correct / wrong / skipped) ---------- */function fetch_answer_distribution(mysqli $conn, int $studentId, string $setType): array {
  $sql = "
    SELECT
      SUM(CASE WHEN aa.is_correct = 1  THEN 1 ELSE 0 END) AS correct_cnt,
      SUM(CASE WHEN aa.is_correct = 0  THEN 1 ELSE 0 END) AS wrong_cnt,
      SUM(CASE WHEN aa.is_correct IS NULL THEN 1 ELSE 0 END) AS skipped_cnt
    FROM assessment_attempts a
    JOIN attempt_stories ats
      ON ats.attempt_id = a.attempt_id
    JOIN attempt_answers aa
      ON aa.attempt_story_id = ats.attempt_story_id
   WHERE a.student_id = ?
     AND a.set_type   = ?
     AND a.status IN ('in_progress','submitted','scored')
  ";

  $correct = $wrong = $skipped = 0;
  if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('is', $studentId, $setType);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $correct = (int)($row['correct_cnt'] ?? 0);
      $wrong   = (int)($row['wrong_cnt'] ?? 0);
      $skipped = (int)($row['skipped_cnt'] ?? 0);
    }
    $stmt->close();
  }
  return ['correct' => $correct, 'wrong' => $wrong, 'skipped' => $skipped];
}

function to_percent_dist(array $dist): array {
  $total = max(0, ($dist['correct'] ?? 0) + ($dist['wrong'] ?? 0) + ($dist['skipped'] ?? 0));
  if ($total <= 0) {
    return ['correct'=>0,'wrong'=>0,'skipped'=>0,'total'=>0];
  }
  $c = round($dist['correct'] / $total * 100);
  $w = round($dist['wrong']   / $total * 100);
  $s = 100 - $c - $w; // para siguradong 100% total
  return ['correct'=>$c,'wrong'=>$w,'skipped'=>$s,'total'=>$total];
}

$distSLTRaw = fetch_answer_distribution($conn, $studentId, 'SLT');
$distPBRaw  = fetch_answer_distribution($conn, $studentId, 'PB');
$distBRRaw  = fetch_answer_distribution($conn, $studentId, 'RB');

$distSLT = to_percent_dist($distSLTRaw);
$distPB  = to_percent_dist($distPBRaw);
$distRB  = to_percent_dist($distBRRaw);

/* ---------- Completion percentages for bar chart ---------- */
$sltCompletionPct = $sltDone ? 100 : 0;
$pbCompletionPct  = ($pbCardTotal > 0) ? (int)round(($pbCardDone / $pbCardTotal) * 100) : 0;
$rbCompletionPct  = ($rbCardTotal > 0) ? (int)round(($rbCardDone / $rbCardTotal) * 100) : 0;

$pbLabelText = $pbCardTotal > 0 ? ($pbCardDone . ' / ' . $pbCardTotal . ' stories') : '0 / 0 stories';
$rbLabelText = $rbCardTotal > 0 ? ($rbCardDone . ' / ' . $rbCardTotal . ' stories') : '0 / 0 stories';
$sltLabelText = $sltDone ? '1 / 1 test' : '0 / 1 test';

/* ===== (Optional) Debug panel: open page with ?debug=1 to see values ===== */
if (isset($_GET['debug'])) {
  echo '<div style="margin:12px 16px;padding:12px;border:1px solid #ddd;border-left:6px solid #1565C0;background:#f8fbff;border-radius:8px;">';
  echo '<h4 style="margin:0 0 8px;">Debug (visible because ?debug=1)</h4><pre style="white-space:pre-wrap;font-size:12px;line-height:1.35;margin:0;">';
  $debug = [
    'studentId'=>$studentId,'levelId'=>$levelId,'levelName'=>$levelName,'sltDone'=>$sltDone,
    'PB_publishedTotal'=>$pbPublishedTotal,'PB_completed'=>$pbCompleted,
    'PB_cardDone/Total'=>"$pbCardDone/$pbCardTotal",'PB_unlocked'=>$pbUnlocked,
    'RB_publishedTotal'=>$rbPublishedTotal,'RB_completed'=>$rbCompleted,
    'RB_cardDone/Total'=>"$rbCardDone/$rbCardTotal",'RB_unlocked'=>$rbUnlocked,
    'Cert_unlocked'=>$certUnlocked,'overall'=>$overall,'remaining'=>$remaining,
    'distSLT'=>$distSLT,'distPB'=>$distPB,'distRB'=>$distRB,
    'completionPct'=>[
      'SLT'=>$sltCompletionPct,
      'PB'=>$pbCompletionPct,
      'RB'=>$rbCompletionPct
    ]
  ];
  print_r($debug);
  echo "</pre></div>";
}

/* ---------- Active announcements for student dashboard ---------- */
$announcements = [];
try {
    $sql = "
        SELECT announcement_id, title, body, created_at
        FROM sra_announcements
        WHERE status = 'active'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $announcements[] = $row;
        }
        $res->free();
    }
} catch (Throwable $e) {
    // kung may error, tahimik lang tayo sa UI
    error_log('Announcements load error: ' . $e->getMessage());
    $announcements = [];
}
/* ---------- Top Overall Performers (STRICT: must have SLT+PB+RB) ---------- */
$topSy = null;
$top3 = [];

if ($studentId > 0) {
  // Use the student's own school_year as filter
  if ($stmt = $conn->prepare("SELECT school_year FROM users WHERE user_id=? AND role='student' LIMIT 1")) {
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $topSy = ($stmt->get_result()->fetch_row()[0] ?? null);
    $stmt->close();
  }

  if ($topSy) {
    // Best % per set_type, then average of (best SLT + best PB + best RB) / 3
    $sqlTop = "
      SELECT
        U.user_id,
        CONCAT_WS(' ', U.first_name, U.middle_name, U.last_name) AS full_name,
        U.course,
        U.year_level,
        U.section,
        ROUND((MAX(CASE WHEN A.set_type='SLT' THEN A.percent END)
            + MAX(CASE WHEN A.set_type='PB'  THEN A.percent END)
            + MAX(CASE WHEN A.set_type='RB'  THEN A.percent END)) / 3, 2) AS overall_pct
      FROM users U
      JOIN assessment_attempts A
        ON A.student_id = U.user_id
       AND A.status IN ('submitted','scored')
       AND A.percent IS NOT NULL
       AND A.set_type IN ('SLT','PB','RB')
      WHERE U.role='student'
        AND U.school_year = ?
      GROUP BY U.user_id, U.first_name, U.middle_name, U.last_name, U.course, U.year_level, U.section
      HAVING
        MAX(CASE WHEN A.set_type='SLT' THEN 1 ELSE 0 END) = 1
        AND MAX(CASE WHEN A.set_type='PB'  THEN 1 ELSE 0 END) = 1
        AND MAX(CASE WHEN A.set_type='RB'  THEN 1 ELSE 0 END) = 1
      ORDER BY overall_pct DESC, full_name ASC
      LIMIT 3
    ";

    if ($stmt = $conn->prepare($sqlTop)) {
      $stmt->bind_param('s', $topSy);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $top3[] = $r;
      }
      $stmt->close();
    }
  }
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
  margin-top: auto;          /* 👉 ito ang magpapantay sa buttons sa ibaba */
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

/* default UL para sa Reading Tips (may indent) */
.info-card ul{
  margin:10px 0 0 18px;
}

/* --- Announcements list (same feel as Reading Tips) --- */
.ann-list{
  list-style: disc;
  padding-left: 20px;      /* katulad ng normal <ul> */
  margin: 4px 0 0;
}

.ann-item{
  padding: 6px 0 10px;
  border-bottom: 1px dashed #e5e7eb;
}
.ann-item:last-child{
  border-bottom:none;
}

/* title: green + soft highlight */
.ann-title{
  display:inline-block;
  font-weight:600;
  color:#064d00;
  background:#fdf6e3;
  padding:3px 8px;
  border-radius:6px;
  margin-bottom:2px;
}

/* date (mas maliit nang kaunti) */
.ann-meta{
  font-size:0.85rem;
  color:#6b7280;
  margin:2px 0 4px;
}

/* body: same size as Reading Tips (1rem) */
.ann-body{
  font-size:1rem;
  color:#374151;
}

/* empty state */
.ann-empty{
  font-size:0.95rem;
  color:#6b7280;
  margin:8px 0 0;
}


@media (max-width: 900px){
  .info-grid{ grid-template-columns: 1fr; }
}

/* ---------- Analytics section (charts) ---------- */
.analytics-section{
  padding: 8px clamp(12px, 2vw, 20px) clamp(24px, 3vw, 32px);
}
.analytics-title{
  font-weight: 800;
  color: var(--g);
  font-size: clamp(1.1rem, 1.1rem + .2vw, 1.3rem);
  margin: 0 0 12px;
  display:flex;
  align-items:center;
  gap:8px;
}
.analytics-title span.icon{
  font-size:1.4rem;
}
.chart-row{
  display:grid;
  gap: clamp(12px, 2vw, 20px);
  grid-template-columns: repeat(3, 1fr);
  margin-bottom: clamp(18px, 3vw, 24px);
}
@media (max-width: 1100px){
  .chart-row{
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 768px){
  .chart-row{
    grid-template-columns: 1fr;
  }
}
.chart-card{
  background:#fff;
  border-radius:12px;
  box-shadow: var(--card-shadow);
  padding: clamp(12px, 2vw, 18px);
  display:flex;
  flex-direction:column;
  gap:8px;
}
.chart-card h4{
  margin:0;
  font-size: .95rem;
  font-weight: 700;
  color: var(--g);
}
.chart-card small{
  font-size:.8rem;
  opacity:.8;
}
.chart-wrapper{
  position:relative;
  width:100%;
  min-height:220px;
}
.chart-wrapper canvas{
  width:100%!important;
  height:100%!important;
}

/* Completion bar chart */
.completion-card{
  background:#fff;
  border-radius:12px;
  box-shadow: var(--card-shadow);
  padding: clamp(14px, 2vw, 20px);
}
.completion-card h4{
  margin:0 0 4px;
  color:var(--g);
  font-size: clamp(1rem, 1rem + .2vw, 1.1rem);
}
.completion-card p{
  margin:0 0 12px;
  font-size:.85rem;
  opacity:.85;
}
.completion-wrapper{
  position:relative;
  width:100%;
  min-height:260px;
}
.completion-wrapper canvas{
  width:100%!important;
  height:100%!important;
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
}
.card-box.locked button{
  background: #9aa09a !important;
  cursor: not-allowed;
  filter: none !important;
}
/* ---------- Top Performers (Student dashboard) ---------- */
.top-performers{
  margin: 0 clamp(12px, 2vw, 20px) clamp(12px, 2vw, 16px);
  background:#fff;
  border:1px solid #eaeaea;
  border-radius:12px;
  box-shadow: var(--card-shadow);
  padding: clamp(14px, 2vw, 18px);
}
.top-performers .tp-title{
  display:flex; align-items:center; gap:10px;
  font-weight:900; color:var(--g);
  font-size: clamp(1.05rem, 1rem + .25vw, 1.25rem);
  margin:0;
}
.top-performers .tp-sub{
  margin:.35rem 0 0;
  color:#555;
  font-size:.92rem;
}
.tp-grid{
  display:grid;
  grid-template-columns: repeat(3, minmax(220px, 1fr));
  gap: 14px;
  margin-top: 14px;
}
@media (max-width: 1100px){ .tp-grid{ grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px){ .tp-grid{ grid-template-columns: 1fr; } }

.tp-card{
  border:1px solid #ededed;
  border-radius:14px;
  padding: 14px;
  background: linear-gradient(180deg,#ffffff, #fafafa);
  position:relative;
  overflow:hidden;
}
.tp-rank{
  position:absolute; top:10px; right:10px;
  width:32px; height:32px; border-radius:999px;
  display:flex; align-items:center; justify-content:center;
  font-weight:900;
  background:#0b3d0b; color:#fff;
  font-size:.9rem;
  opacity:.9;
}
.tp-avatar{
  width:56px; height:56px; border-radius:999px;
  display:flex; align-items:center; justify-content:center;
  border:1px solid #e7e7e7;
  background:#f3f4f6;
  color:#1f2937;
  font-size: 1.25rem;
}
.tp-name{
  margin:10px 0 2px;
  font-weight:900;
  color:#0f172a;
  font-size: 1.05rem;
}
.tp-meta{
  margin:0;
  color:#374151;
  font-size:.9rem;
  line-height:1.35;
}
.tp-score{
  margin-top:12px;
  display:flex; align-items:baseline; gap:10px;
  border-top:1px dashed #e5e7eb;
  padding-top:10px;
}
.tp-score .label{ font-size:.78rem; letter-spacing:.08em; text-transform:uppercase; color:#6b7280; }
.tp-score .val{ font-weight:900; font-size: 1.35rem; color: var(--g); }
.tp-empty{
  margin-top:10px;
  color:#6b7280;
  font-size:.95rem;
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
              <?= htmlspecialchars($levelName ?? '—') ?>
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

  <!-- Next Step -->
  <section class="next-card">
    <div>
      <div class="next-title"><?= $isFirstTimer ? 'Start your journey' : 'Continue where you left off'; ?></div>
      <div class="next-sub"><?= htmlspecialchars($nextStepLabel); ?></div>
    </div>
  <?php if ($ctaEnabled && $ctaHref): ?>
  <a class="btn-primary" href="<?= htmlspecialchars($ctaHref); ?>">
    <?= htmlspecialchars($ctaText); ?>
  </a>
<?php else: ?>
  <button class="btn-primary" type="button" disabled aria-disabled="true" style="cursor:not-allowed;opacity:.75;">
    <?= htmlspecialchars($ctaText); ?>
  </button>
<?php endif; ?>
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
        Progress: <?= (int)$pbCardDone; ?>/<?= (int)$pbCardTotal; ?>
      </p>
      <p>Take the test to improve your comprehension skills.</p>

      <?php if ($pbUnlocked): ?>
        <?php $pbBtnText = ($pbCardDone === 0) ? 'Start' : 'Continue'; ?>
        <button type="button" onclick="location.href='stories_pb.php'"><?= $pbBtnText; ?></button>
      <?php else: ?>
        <button type="button" disabled aria-disabled="true">Locked</button>
      <?php endif; ?>
    </div>

    <i class="fas fa-arrow-right flow-arrow" aria-hidden="true"></i>

    <!-- RB (unlocks when ALL PB published are completed) -->
    <div class="card-box <?= $rbUnlocked ? '' : 'locked' ?>"

         title="<?= $rbUnlocked ? '' : 'Unlocks after you finish all published Power Builder stories' ?>">
      <h4>📚 Rate Builder Assessment</h4>
      <p>Rate your Builder Assessment.</p>
      <p style="opacity:.8;margin-top:-6px;">
        <?php if ($rbCardTotal > 0): ?>
          Progress: <?= (int)$rbCardDone; ?>/<?= (int)$rbCardTotal; ?>
        <?php else: ?>
          Stories Available: 0
        <?php endif; ?>
      </p>

      <?php if ($rbUnlocked): ?>
        <?php $rbBtnText = ($rbCardDone === 0) ? 'Start' : 'Continue'; ?>
        <button type="button" onclick="location.href='stories_rb.php'"><?= $rbBtnText; ?></button>
      <?php else: ?>
        <button type="button" disabled aria-disabled="true">Locked</button>
      <?php endif; ?>
    </div>

    <i class="fas fa-arrow-right flow-arrow" aria-hidden="true"></i>
<!-- Certificate (now depends on validation status) -->
<?php
  $certTitle = 'Unlocks after you finish all published Rate Builder stories';
  if ($certState === 'pending') {
    $certTitle = 'Completed – waiting for teacher validation (1–2 working days).';
  } elseif ($certState === 'validated') {
    $certTitle = 'Approved – you can now view your certificate.';
  } elseif ($certState === 'invalid') {
    $certTitle = 'Results not approved – please contact your coordinator.';
  }
?>
<div class="card-box <?= $certState === 'locked' ? 'locked' : '' ?>"
     title="<?= htmlspecialchars($certTitle); ?>">
  <h4>🎓 Certificate</h4>
  <p><?= htmlspecialchars($certMessage); ?></p>

  <?php if ($certState === 'validated'): ?>
    <!-- ✅ Approved -->
    <button type="button" onclick="location.href='certificates.php'">View Certificate</button>

  <?php elseif ($certState === 'pending'): ?>
    <!-- ⏳ Under review -->
    <button type="button" disabled aria-disabled="true">Under review</button>

  <?php elseif ($certState === 'invalid'): ?>
    <!-- ❌ Not approved -->
    <button type="button" disabled aria-disabled="true">Not available</button>

  <?php else: ?>
    <!-- 🔒 RB not finished yet -->
    <button type="button" disabled aria-disabled="true">Locked</button>
  <?php endif; ?>
</div>

  </div>

  <!-- Announcements + Reading Tips -->
  <!-- Announcements + Reading Tips -->
  <section class="info-grid">
    <!-- Dynamic Announcements -->
    <div class="info-card">
      <h3>🔔 Announcements</h3>

      <?php if (empty($announcements)): ?>
        <p class="ann-empty">
          No announcements at the moment. Please check again later.
        </p>
      <?php else: ?>
        <ul class="ann-list">
          <?php foreach ($announcements as $a): ?>
            <li class="ann-item">
              <div class="ann-title">
                <?= htmlspecialchars($a['title']); ?>
              </div>
              <div class="ann-meta">
                <?= date('M d, Y', strtotime($a['created_at'])); ?>
              </div>
              <div class="ann-body">
                <?= nl2br(htmlspecialchars($a['body'])); ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Reading Tips (static pa rin) -->
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

  <!-- Analytics section (Pie + Bar charts) -->

  <!-- Top Overall Performers (STRICT: SLT+PB+RB required) -->
<section class="top-performers">
  <h3 class="tp-title">🏆 Top Overall Performers (Overall %)</h3>

  <?php if (!$topSy): ?>
    <p class="tp-sub">School year is not set for your account.</p>

  <?php else: ?>
    <p class="tp-sub">
      Top 3 students by <strong>overall average percentage</strong> (best SLT + best PB + best RB) — filtered by <strong>SY <?= htmlspecialchars($topSy) ?></strong>.
    </p>

    <?php if (empty($top3)): ?>
      <div class="tp-empty">
        No qualified students yet. (STRICT: requires completed SLT, PB, and RB results.)
      </div>
    <?php else: ?>
      <div class="tp-grid">
        <?php foreach ($top3 as $idx => $r): ?>
          <?php
            $rank = $idx + 1;
            $nm   = trim((string)($r['full_name'] ?? 'Student'));
            $crs  = trim((string)($r['course'] ?? '—'));
            $yr   = (int)($r['year_level'] ?? 0);
            $sec  = trim((string)($r['section'] ?? '—'));
            $pct  = number_format((float)($r['overall_pct'] ?? 0), 2);
          ?>
          <div class="tp-card">
            <div class="tp-rank"><?= $rank ?></div>

            <div class="tp-avatar" aria-hidden="true">
              <i class="fa-solid fa-user"></i>
            </div>

            <div class="tp-name"><?= htmlspecialchars($nm) ?></div>
            <p class="tp-meta">
              <strong>Course:</strong> <?= htmlspecialchars($crs) ?><br>
              <strong>Year/Section:</strong> <?= $yr ? htmlspecialchars((string)$yr) : '—' ?> - <?= htmlspecialchars($sec) ?>
            </p>

            <div class="tp-score">
              <div class="label">Overall Percentage</div>
              <div class="val"><?= $pct ?>%</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

  <section class="analytics-section">
    <h2 class="analytics-title">
      <span class="icon">📊</span>
      Assessment Performance Overview
    </h2>

    <!-- Row 1: 3 pie charts -->
    <div class="chart-row">
      <div class="chart-card">
        <h4>Starting Level Test</h4>
        <small>
          <?php if ($distSLT['total'] > 0): ?>
            Based on <?= (int)$distSLT['total']; ?> answered items.
          <?php else: ?>
            No SLT results yet.
          <?php endif; ?>
        </small>
        <div class="chart-wrapper">
          <canvas id="pieSLT"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h4>Power Builder Test</h4>
        <small>
          <?php if ($distPB['total'] > 0): ?>
            Based on <?= (int)$distPB['total']; ?> answered items.
          <?php else: ?>
            No Power Builder results yet.
          <?php endif; ?>
        </small>
        <div class="chart-wrapper">
          <canvas id="piePB"></canvas>
        </div>
      </div>

      <div class="chart-card">
        <h4>Rate Builder Test</h4>
        <small>
          <?php if ($distRB['total'] > 0): ?>
            Based on <?= (int)$distRB['total']; ?> answered items.
          <?php else: ?>
            No Rate Builder results yet.
          <?php endif; ?>
        </small>
        <div class="chart-wrapper">
          <canvas id="pieRB"></canvas>
        </div>
      </div>
    </div>

    <!-- Row 2: completion bar chart -->
    <div class="completion-card">
      <h4>Completion by Assessment</h4>
      <p>This shows how many activities you have finished for each stage.</p>
      <div class="completion-wrapper">
        <canvas id="completionBar"></canvas>
      </div>
    </div>
  </section>

</div>

<!-- Charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  // --------- Data from PHP ---------
  const distSLT = {
    correct: <?= (int)$distSLT['correct']; ?>,
    wrong:   <?= (int)$distSLT['wrong']; ?>,
    skipped: <?= (int)$distSLT['skipped']; ?>
  };
  const distPB = {
    correct: <?= (int)$distPB['correct']; ?>,
    wrong:   <?= (int)$distPB['wrong']; ?>,
    skipped: <?= (int)$distPB['skipped']; ?>
  };
  const distRB = {
    correct: <?= (int)$distRB['correct']; ?>,
    wrong:   <?= (int)$distRB['wrong']; ?>,
    skipped: <?= (int)$distRB['skipped']; ?>
  };

  const piePercentSLT = [<?= (int)$distSLT['correct']; ?>, <?= (int)$distSLT['wrong']; ?>, <?= (int)$distSLT['skipped']; ?>];
  const piePercentPB  = [<?= (int)$distPB['correct']; ?>,  <?= (int)$distPB['wrong']; ?>,  <?= (int)$distPB['skipped']; ?>];
  const piePercentRB  = [<?= (int)$distRB['correct']; ?>,  <?= (int)$distRB['wrong']; ?>,  <?= (int)$distRB['skipped']; ?>];

  const completionPercents = [
    <?= (int)$sltCompletionPct; ?>,
    <?= (int)$pbCompletionPct; ?>,
    <?= (int)$rbCompletionPct; ?>
  ];
  const completionLabelsDetail = [
    '<?= addslashes($sltLabelText); ?>',
    '<?= addslashes($pbLabelText); ?>',
    '<?= addslashes($rbLabelText); ?>'
  ];

  const correctColor = '#2E7D32';
  const wrongColor   = '#D32F2F';
  const skippedColor = '#9E9E9E';

  function makePieChart(ctxId, percentArr) {
    const ctx = document.getElementById(ctxId);
    if (!ctx) return;
    new Chart(ctx, {
      type: 'pie',
      data: {
        labels: ['Correct', 'Wrong', 'Skipped'],
        datasets: [{
          data: percentArr,
          backgroundColor: [correctColor, wrongColor, skippedColor]
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              usePointStyle: true,
              boxWidth: 8
            }
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const label = context.label || '';
                const value = context.parsed || 0;
                return `${label}: ${value}%`;
              }
            }
          }
        }
      }
    });
  }

  makePieChart('pieSLT', piePercentSLT);
  makePieChart('piePB',  piePercentPB);
  makePieChart('pieRB',  piePercentRB);

  // Completion bar chart
  const ctxBar = document.getElementById('completionBar');
if (ctxBar) {
  new Chart(ctxBar, {
    type: 'bar',
    data: {
      labels: ['Starting Level Test', 'Power Builder', 'Rate Builder'],
      datasets: [{
        label: 'Completion (%)',
        data: completionPercents,
        backgroundColor: ['#2E7D32', '#ECA305', '#1565C0'],
        borderRadius: 8
      }]
    },
    options: {
      responsive: true,
      indexAxis: 'y',   // ⭐ ito ang nagpapahiga
      scales: {
        x: {
          beginAtZero: true,
          max: 100,
          ticks: {
            stepSize: 20,
            callback: (val) => val + '%'
          },
          title: {
            display: true,
            text: 'Completion (%)'
          }
        },
        y: {
          title: {
            display: false
          }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (context) => {
              const idx = context.dataIndex;
              const pct = context.parsed.x || 0;
              const detail = completionLabelsDetail[idx] || '';
              return `${pct}%  (${detail})`;
            }
          }
        }
      }
    }
  });
}

</script>

</body>
</html>
