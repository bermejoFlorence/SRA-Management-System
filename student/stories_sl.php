<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

require_once __DIR__ . '/includes/progress_gates.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);

/* --- DYNAMIC ESTIMATED TIME: based sa SLT stories --- */
$estLabel = null;

// 1) Hanapin ang latest published SLT set (pwede mong palitan logic kung may “current set” ka)
$stmt = $conn->prepare("
  SELECT set_id
  FROM story_sets
  WHERE set_type = 'SLT' AND status = 'published'
  ORDER BY updated_at DESC, set_id DESC
  LIMIT 1
");
$stmt->execute();
$res = $stmt->get_result();
$activeSetId = 0;
if ($row = $res->fetch_assoc()) {
  $activeSetId = (int)$row['set_id'];
}
$stmt->close();

// 2) Kung may nahanap na set, kunin ang total time ng active stories sa set na iyon
// 2) Kung may nahanap na set, kunin ang time limit ng UNANG active story
//    (same logic as slt_fetch → base sa unang story na may non-zero limit)
if ($activeSetId > 0) {
  $stmt = $conn->prepare("
    SELECT COALESCE(time_limit_seconds,0) AS limit_secs
    FROM stories
    WHERE set_id = ?
      AND status = 'active'
      AND COALESCE(time_limit_seconds,0) > 0
    ORDER BY sequence ASC, story_id ASC
    LIMIT 1
  ");
  $stmt->bind_param('i', $activeSetId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    $limitSecs = (int)$row['limit_secs'];
    if ($limitSecs > 0) {
      $mins = (int)ceil($limitSecs / 60);
      $estLabel = $mins . ' minute' . ($mins > 1 ? 's' : '');
    }
  }
  $stmt->close();
}


/* --- GUARD: kapag tapos na ang SLT, huwag nang ipakita ang intro --- */
$stmt = $conn->prepare("
  SELECT attempt_id, status
  FROM assessment_attempts
  WHERE student_id = ? AND set_type = 'SLT'
  ORDER BY submitted_at DESC, started_at DESC, attempt_id DESC
  LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$last = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($last && in_array($last['status'], ['submitted', 'scored'])) {
  header('Location: stories_sl_run.php?aid=' . (int)$last['attempt_id'] . '&view=completed');
  exit;
}
/* --- END GUARD --- */

$PAGE_TITLE  = 'Starting Level Test';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'slt';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
/* ================= THEME (student SLT page) ================= */
:root{
  --g: #003300;           /* brand green */
  --acc: #ECA305;         /* brand gold */
  --acc-soft: rgba(236,163,5,.14);
  --bg: #f5f7f6;          /* app page background */
  --card: #ffffff;
  --ink: #213421;         /* dark text */
  --muted: #6b7c6b;
  --line: #e6efe6;        /* subtle borders */
  --shadow: 0 10px 28px rgba(0,0,0,.08);
}

/* Layout wrapper beside 220px sidebar */
.main-content{
  width: calc(100% - 220px);
  margin-left: 220px;
  background: var(--bg);
}
@media (max-width: 992px){
  .main-content{ width: 100%; margin-left: 0; }
}

/* Page container */
.slt-wrap{
  max-width: 1320px;
  margin: 0 auto;
  padding: 16px 24px;
  padding-top: 0;
}

/* ---------------- HERO ---------------- */
.slt-hero{
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  margin: 12px 0 16px; padding: 24px 28px;
  background:
    radial-gradient(1100px 180px at 18% -20%, var(--acc-soft), transparent 60%),
    linear-gradient(180deg, #fff, #fefefe);
  border: 1px solid #eef2ee;
  border-radius: 16px;
  box-shadow: var(--shadow);
}
.slt-hero h1{
  margin: 0 0 4px;
  color: var(--g);
  font-weight: 900;
  letter-spacing: .2px;
  font-size: clamp(1.4rem, 1.1rem + 1.2vw, 2rem);
}
.slt-hero p{ margin: 0; color: var(--ink); opacity: .85; font-size: clamp(.95rem, .9rem + .2vw, 1rem); }
@media (max-width: 640px){ .slt-hero{ flex-direction: column; align-items: flex-start; } }

/* ---------------- CARDS ---------------- */
.slt-card{
  background: var(--card);
  border: 1px solid #eef2ee;
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(0,0,0,.06);
  padding: clamp(16px, 2.4vw, 24px);
  margin: 0 0 16px;
}

/* “Before you start” callout (green base + gold accent) */
.slt-callout{ padding: 0; overflow: hidden; }
.callout-head{
  display: flex; align-items: center; gap: 12px;
  padding: 18px 20px;
  background:
    radial-gradient(900px 140px at 0% -25%, var(--acc-soft), transparent 60%),
    linear-gradient(180deg, #ffffff, #fcfdfc);
  border-bottom: 1px solid var(--line);
}
.callout-head .icon{
  width: 42px; height: 42px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,51,0,.08); color: var(--g);
}
.callout-head .kicker{
  display: block; font-size: .72rem; letter-spacing: .3px;
  text-transform: uppercase; color: var(--muted); margin-bottom: 2px;
}
.callout-head h3{
  margin: 0; color: var(--g); font-weight: 900;
  font-size: clamp(1.05rem, .95rem + .4vw, 1.25rem);
}
.callout-body{ padding: 18px 20px 16px; }

/* Checklist */
.rulelist{ list-style: none; margin: 10px 0 12px; padding: 0; }
.rulelist.check li{
  position: relative;
  margin: 8px 0; padding: 10px 12px 10px 44px;
  background: #fff; border: 1px solid var(--line); border-radius: 12px;
  line-height: 1.55; color: var(--ink);
}
.rulelist.check li::before{
  content:"\f00c"; font-family:"Font Awesome 6 Free"; font-weight:900;
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  width:22px; height:22px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  background: var(--g); color:#fff; font-size:.7rem;
  box-shadow: 0 0 0 2px rgba(0,51,0,.08);
}

/* Acknowledgement row (shift from yellow to greenish neutral) */
.ack{
  display:flex; align-items:center; gap:10px;
  background: rgba(0,51,0,.05);
  border: 1px dashed rgba(0,51,0,.18);
  color: var(--ink);
  padding: 10px 12px; border-radius: 10px; margin: 12px 0 10px;
}
.ack input{ transform: translateY(1px); }

/* Actions */
.actions{ display:flex; align-items:center; flex-wrap:wrap; gap: 12px; }
.actions .btn{ min-width: 160px; }

/* ---------------- BUTTONS ---------------- */
.btn{
  display:inline-flex; align-items:center; justify-content:center;
  padding: 12px 20px; border: 0; border-radius: 12px;
  background: var(--g); color: #fff; font-weight: 800; cursor: pointer;
  transition: filter .15s ease, transform .06s ease;
}
.btn:hover:not(:disabled){ filter: brightness(1.06); }
.btn:active:not(:disabled){ transform: translateY(1px); }
.btn:disabled{ background: #9aa89f; cursor: not-allowed; }

.btn-ghost{
  background: #eef2ed; color: #1f3a1f;
}

/* ---------------- TEST UI ---------------- */
.slt-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
  margin-bottom:12px;
}
.slt-meta{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.pill{
  background:#eff3ef; color:#1b3a1b; border:1px solid #d9e3d9; border-radius:999px;
  padding:6px 10px; font-weight:700; font-size:.9rem;
}

.progress{
  width:100%; height:12px; border-radius:999px; overflow:hidden; background:#eaeaea;
}
.progress > span{ display:block; height:100%; background:linear-gradient(90deg, var(--acc), #ffd37a); }

.passage{
  background:#f9faf9; border:1px solid #e6eee6; border-radius:12px; padding:12px; margin-bottom:10px;
}
.qtext{ font-weight:700; margin:10px 0; color: var(--ink); }

.opts{ display:grid; gap:10px; }
.opt{
  background:#fff; border:1.5px solid #dfe6df; border-radius:12px; padding:12px;
  line-height:1.45; cursor:pointer;
}
.opt:focus-within{ outline:2px solid #acc9ac; }
.opt input{ margin-right:10px; transform:translateY(2px); }
.opt.selected{ border-color:var(--g); box-shadow:0 0 0 2px rgba(0,51,0,.1) inset; }

/* Sticky footer */
.slt-footer{
  position: sticky; bottom: 8px;
  display:flex; justify-content:flex-end; gap:10px; margin-top:12px;
}
.slt-footer .btn{ min-width:160px; }

/* Completion */
.done{ text-align:center; padding:36px 16px; }
.done h2{ margin:0 0 8px; color: var(--g); font-size:clamp(1.1rem,1rem + .8vw,1.6rem); }
.done p{ margin:0 0 16px; opacity:.9; }

/* Helpers */
.muted{ opacity:.75; font-size:.9rem; color: var(--muted); }

/* Desktop refinements */
@media (min-width: 1200px){
  .progress{ height: 14px; }
  .opt{ padding: 14px 16px; }
  .slt-footer{ bottom: 10px; }
}

/* No horizontal jiggle on wide screens */
html{ scrollbar-gutter: auto !important; }
html, body{ overflow-x: hidden; }
</style>


<div class="main-content">
  <div class="slt-wrap">

    <!-- HERO -->
    <section class="slt-hero">
      <div>
        <h1>Starting Level Test</h1>
        <p>Let’s begin your reading assessment to determine your starting point.</p>
      </div>
      <!-- <button id="btnScrollToStart" class="btn-ghost">Jump to Start</button> -->
    </section>

    <!-- INSTRUCTIONS (shown until Start) -->
<section id="sltIntro" class="slt-card slt-callout" role="region" aria-labelledby="sltRulesTitle">
  <div class="callout-head">
    <div class="icon"><i class="fas fa-clipboard-check"></i></div>
    <div class="titles">
      <span class="kicker">Please read</span>
      <h3 id="sltRulesTitle">Before you start</h3>
    </div>
  </div>

  <div class="callout-body">
    <ul class="rulelist check">
      <li><strong>Continuous Test</strong> — Once you start, you must finish in one sitting.</li>
      <li><strong>No Pause, No Exit</strong> — Closing the page will end the attempt and it cannot be resumed.</li>
      <li><strong>One-Way Reading</strong> — A story/passage can only be read once. After you move forward, you cannot go back to re-read it.</li>
      <li><strong>Automatic Assignment</strong> — Your starting level is determined automatically when you finish.</li>
        <li><strong>Stable Internet</strong> — Make sure you have a reliable connection. Refreshing the page or losing your connection will end the attempt and it cannot be resumed.</li>
    </ul>

    <form action="stories_sl_start.php" method="post" id="sltStartForm">
  <label class="ack" for="ack">
    <input type="checkbox" name="ack" id="ack" value="1">
    I have read and understood the instructions.
  </label>

  <div class="actions">
    <button id="btnStart" type="submit" class="btn" disabled>Start Test</button>
    <span class="muted">Estimated time: <?= htmlspecialchars($estLabel ?? '10–15 minutes') ?></span>
  </div>
</form>

  </div>
</section>

  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const ack = document.getElementById('ack');
  const btn = document.getElementById('btnStart');

  // Guard against missing nodes (prevents "Cannot read properties of null")
  if (!ack || !btn) return;

  function refresh() {
    // require: acknowledged + online
    btn.disabled = !(ack.checked && navigator.onLine);
  }

  ack.addEventListener('change', refresh);
  window.addEventListener('online',  refresh);
  window.addEventListener('offline', refresh);

  refresh(); // set initial state
});
</script>


</body>
</html>
