<?php
// student/stories_sl_run.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$attemptId = isset($_GET['aid']) ? (int)$_GET['aid'] : 0;
if ($attemptId <= 0) {
  header('Location: stories_sl.php');
  exit;
}

$PAGE_TITLE  = 'Starting Level Test';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'slt';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
:root{
  --g:#003300; --acc:#ECA305; --acc-soft:rgba(236,163,5,.14);
  --bg:#f5f7f6; --card:#fff; --ink:#213421; --muted:#6b7c6b;
  --line:#e6efe6; --shadow:0 10px 28px rgba(0,0,0,.08);
}
.main-content{ width:calc(100% - 220px); margin-left:220px; background:var(--bg); }
@media (max-width:992px){ .main-content{ width:100%; margin-left:0; } }
.slt-wrap{ max-width:1320px; margin:0 auto; padding:16px 24px; padding-top:12px; }

/* Runner header (story title + timer) */
.run-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:8px 0 16px; padding:14px 18px;
  background:linear-gradient(180deg,#fff,#fefefe); border:1px solid #eef2ee;
  border-radius:14px; box-shadow:var(--shadow);
}
.run-title{ margin:0; font-weight:900; letter-spacing:.2px; color:var(--g);
  font-size:clamp(1.2rem,1rem + 1vw,1.6rem); }
.pill{ background:#eff3ef; color:#1b3a1b; border:1px solid #d9e3d9;
  border-radius:999px; padding:6px 10px; font-weight:700; font-size:.9rem; }
.pill.warn   { background:#fff5e6; border-color:#f4d58f; color:#5a4200; }
.pill.danger { background:#fdeaea; border-color:#f3b1b1; color:#7a0d0d; }

/* Cards */
.read-card, .quiz-card, .done-card{
  background:var(--card); border:1px solid #eef2ee; border-radius:16px;
  box-shadow:0 8px 24px rgba(0,0,0,.06); padding:clamp(16px,2.4vw,24px); margin:0 0 16px;
}

/* Reading layout / typography */
.read-grid{
  display:grid; grid-template-columns:minmax(0, 2fr) minmax(240px, 1fr);
  gap:20px; align-items:start;
}
.read-passage{
  font-size:clamp(1.05rem, 0.95rem + 0.6vw, 1.35rem);
  line-height:1.7;
  letter-spacing:.2px;
  background:#fffefc;
  border:1px solid #efe9da; border-radius:14px;
  padding:clamp(16px, 2.2vw, 22px);
  color:#1f2a1f;
  max-width:65ch;
}
.read-passage p{ margin:0 0 1em; }
.read-passage p:last-child{ margin-bottom:0; }
.read-note{ color:var(--muted); font-size:.95rem; margin-top:10px; }
.read-toolbar{ display:flex; align-items:center; gap:10px; justify-content:flex-end; margin:0 0 12px; }
.rt-btn{ border:1px solid #dfe6df; background:#fff; color:#1f3a1f; border-radius:10px; padding:8px 10px; cursor:pointer; font-weight:700; min-width:36px; }
.rt-btn:disabled{ opacity:.6; cursor:not-allowed; }

/* Themes */
.theme-sepia .read-passage{ background:#fff7e6; border-color:#f0e2c6; color:#2a251a; }
.theme-dark  .read-passage{ background:#111; border-color:#222; color:#e7e7e7; }
.theme-dark  .read-note  { color:#a5b4a5; }

/* Image */
.read-img{ width:100%; border-radius:12px; border:1px solid var(--line); object-fit:cover; max-height:420px; background:#f5f5f5; }
.read-img.zoomable{ cursor:zoom-in; }
.lightbox{ position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:9999; }
.lightbox img{ max-width:95vw; max-height:92vh; border-radius:12px; }

/* Reading progress */
.read-progress{ height:6px; background:#eee; border-radius:999px; overflow:hidden; margin:8px 0 14px; }
.read-progress > span{ display:block; height:100%; width:0%; background:linear-gradient(90deg, var(--acc), #ffd37a); }

/* Quiz */
.slt-meta{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px;}
.progress{ width:100%; height:10px; border-radius:999px; overflow:hidden; background:#ececec; }
.progress > span{ display:block; height:100%; background:linear-gradient(90deg, var(--acc), #ffd37a); }
.qtext{
  position:relative; margin:16px 0 14px; padding:14px 16px 14px 56px;
  font-weight:900; font-size:clamp(1.15rem, 1rem + 0.6vw, 1.6rem); color:#1b3a1b;
  background:#fffef6; border:1px solid #f1e6b3; border-radius:12px; box-shadow:inset 0 0 0 3px rgba(236,163,5,.12);
}
.qtext::before{
  content: attr(data-qbadge);
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  width:32px; height:32px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center;
  background:var(--acc); color:#000; font-weight:900; border:1px solid #e3c97a;
}
.opts{ display:grid; gap:10px; margin-top:8px; }
.opt{ position:relative; padding:12px 12px 12px 44px; background:#fff; border:1.5px solid #dfe6df; border-radius:12px; line-height:1.45; cursor:pointer; }
.opt .letter{ position:absolute; left:12px; top:50%; transform:translateY(-50%); width:26px; height:26px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:.9rem; border:1.5px solid #cfd9cf; background:#f9faf9; color:#1f3a1f; }
.opt:focus-within{ outline:2px solid #acc9ac; }
.opt input{ margin-right:10px; transform:translateY(2px); }
.opt.selected{ border-color:var(--g); box-shadow:0 0 0 2px rgba(0,51,0,.1) inset; }
.qblock{ padding:12px 0; border-top:1px dashed #e6efe6; }
.qblock:first-child{ border-top:0; }
.slt-footer{ position:sticky; bottom:8px; display:flex; justify-content:flex-end; gap:10px; margin-top:12px; }
.btn{ display:inline-flex; align-items:center; justify-content:center; padding:12px 20px; border:0; border-radius:12px; background:var(--g); color:#fff; font-weight:800; cursor:pointer; transition:filter .15s ease, transform .06s ease; }
.btn:hover:not(:disabled){ filter:brightness(1.06); }
.btn:disabled{ background:#9aa89f; cursor:not-allowed; }
.btn-ghost{ background:#eef2ed; color:#1f3a1f; }

/* Modals */
.modal{ position:fixed; inset:0; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; z-index:10000; }
.modal-card{ width:min(560px,90vw); background:#fff; border:1px solid #eef2ee; border-radius:16px; box-shadow:var(--shadow); padding:20px; }
.modal-card h3{ margin:0 0 8px; color:#2b422b; font-size:1.2rem; }
.modal-text{ color:#213421; line-height:1.6; margin:0 0 16px; }
.modal-actions{ display:flex; gap:10px; justify-content:flex-end; }

/* analyzing overlay bits */
.analyze-steps{ display:grid; gap:8px; margin:12px 0 4px; }
.analyze-step{ display:flex; align-items:center; gap:10px; color:#213421; }
.analyze-step .dot{ width:10px; height:10px; border-radius:999px; background:#cfd8cf; }
.analyze-step.done .dot{ background:#2e7d32; }
.analyze-note{ color:#6b7c6b; font-size:.9rem; }

.spinner{
  width:34px; height:34px; border-radius:50%;
  border:3px solid #e6efe6; border-top-color:#2e7d32; 
  animation: spin 1s linear infinite;
}
@keyframes spin{ to{ transform:rotate(360deg);} }
.reveal-badge{
  display:inline-flex; align-items:center; gap:10px;
  padding:10px 14px; border:1px solid #dfe6df; border-radius:12px;
  background:#f9fbf9; font-weight:800;
}
.rev-actions{ display:flex; gap:10px; justify-content:center; }
.btn-ghost.small{ padding:8px 12px; font-weight:700; }

/* --- Completed analytics --- */
.stats-grid{
  display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
  gap:12px; margin:18px 0 8px;
}
@media (max-width:1200px){ .stats-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:520px){ .stats-grid{ grid-template-columns:1fr; } }

.stat-card{
  background:var(--card); border:1px solid #eef2ee; border-radius:14px;
  padding:14px 16px; box-shadow:var(--shadow);
}
.stat-title{ color:#6b7c6b; font-size:.9rem; margin:0 0 6px; }
.stat-value{ font-weight:900; font-size:1.4rem; color:#1b3a1b; }

.meter{ height:8px; background:#ececec; border-radius:999px; overflow:hidden; margin-top:10px; }
.meter > span{ display:block; height:100%; width:0; background:linear-gradient(90deg,var(--acc),#ffd37a); }

.per-story{ margin-top:12px; }
.story-row{
  display:flex; gap:12px; align-items:center; padding:10px 12px;
  border:1px solid #eef2ee; border-radius:12px; background:#fff; margin-top:8px;
}
.story-title{ flex:1; font-weight:700; color:#203520; min-width:180px; }
.story-meter{ flex:2; min-width:160px; }
.story-score{ width:110px; text-align:right; font-weight:800; }
.story-wpm{ width:100px; text-align:right; color:#203520; }

@media (max-width:600px){
  .story-row{ flex-wrap:wrap; }
  .story-score,.story-wpm{ width:auto; text-align:left; }
  .story-meter{ width:100%; flex-basis:100%; }
}
.color-line{ color:#203520; font-weight:700; }
.color-badge{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border:1px solid #e6efe6; border-radius:999px;
  background:#fff; font-weight:800;
}
/* Optional color classes if you want preset looks */
.color-red    { background:#fdeaea; border-color:#f3b1b1; color:#7a0d0d; }
.color-orange { background:#fff5e6; border-color:#f4d58f; color:#5a4200; }
.color-yellow { background:#fffbe6; border-color:#f1e6b3; color:#5a4b00; }
.color-blue   { background:#eaf3ff; border-color:#b3d3f1; color:#0b3d78; }
.color-green  { background:#ecf6ec; border-color:#cfe8cf; color:#1b5e20; }

.pb-btn{
  background: var(--g);
  color:#fff;
  border-radius:14px;
  padding:12px 18px;
  display:inline-flex; align-items:center; font-weight:800;
  box-shadow:0 6px 18px rgba(0,0,0,.1);
  transition: transform .06s ease, filter .15s ease, box-shadow .15s ease;
}
.pb-btn:hover{ filter:brightness(1.06); box-shadow:0 8px 22px rgba(0,0,0,.12); }
.pb-btn:active{ transform: translateY(1px); }
.pb-btn[aria-disabled="true"]{ background:#9aa89f; pointer-events:none; }

@media (max-width:520px){
  .pb-btn{ width:100%; justify-content:center; }
}
/* Start Power Builder – fixed brand gold look */
.pb-btn{
  --pb-bg: var(--acc);                /* #ECA305 */
  --pb-text: #1b3a1b;                 /* deep green text for contrast */
  --pb-shadow: rgba(236,163,5,.35);

  background: var(--pb-bg);
  color: var(--pb-text);
  border: 1px solid #e3c97a;
  border-radius: 14px;
  padding: 12px 18px;
  display: inline-flex;
  align-items: center;
  font-weight: 800;
  box-shadow: 0 6px 18px var(--pb-shadow);
  transition: transform .06s ease, filter .15s ease, box-shadow .15s ease;
}
.pb-btn:hover{ filter: brightness(1.02); box-shadow: 0 8px 22px var(--pb-shadow); }
.pb-btn:active{ transform: translateY(1px); }
.pb-btn[aria-disabled="true"]{ background:#9aa89f; color:#fff; pointer-events:none; }
.btn-pb{
  display:inline-flex; align-items:center; gap:10px;
  padding:12px 20px;
  border-radius:9999px;                 /* pill */
  background:#ECA305;                   /* golden/orange */
  color:#0f3e0f;                        /* dark green text */
  font-weight:800; text-decoration:none;
  box-shadow:0 2px 10px rgba(0,0,0,.08);
  transition: filter .15s ease, transform .06s ease;
}
.btn-pb:hover{ filter:brightness(1.06); }
.btn-pb:active{ transform:translateY(1px); }
.btn-pb .arr{ font-weight:900; margin-left:2px; }
.btn-pb:focus-visible{
  outline:3px solid rgba(0,51,0,.25);
  outline-offset:3px;
}
/* Disable text selection for exam area */
#exam-content {
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

</style>

<div class="main-content">
  <div class="slt-wrap" id="exam-content">

    <!-- Runner header -->
    <section class="run-head" aria-live="polite">
      <h1 id="storyTitle" class="run-title">Loading…</h1>
      <div class="slt-meta">
        <span id="crumb" class="pill">Story –</span>
        <!-- NOTE: this is now a COUNTDOWN (or stopwatch if no limit) -->
        <span id="elapsed" class="pill" title="Remaining time">--:--</span>
      </div>
    </section>

    <!-- Reading view -->
    <section id="readView" class="read-card" style="display:none;">
      <div class="read-toolbar">
        <button id="btnThemeLight" class="rt-btn" title="Light theme">Light</button>
        <button id="btnThemeSepia" class="rt-btn" title="Sepia theme">Sepia</button>
        <span style="flex:1"></span>
        <button id="btnFontMinus" class="rt-btn" aria-label="Decrease text size">A−</button>
        <button id="btnFontPlus"  class="rt-btn" aria-label="Increase text size">A+</button>
      </div>

      <div class="read-progress" aria-hidden="true"><span id="readProg" style="width:0%"></span></div>

      <div class="read-grid">
        <div>
          <article id="readPassage" class="read-passage" aria-label="Reading passage"></article>
          <div class="read-note">You can only read the passage once. When you start the quiz, you can’t reopen it.</div>
        </div>
        <div id="imgWrap" style="display:none;">
          <img id="readImage" src="" alt="Story image" class="read-img zoomable">
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; margin-top:16px;">
        <button id="btnStartQuiz" class="btn">I’m ready for the quiz</button>
      </div>
    </section>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox" role="dialog" aria-modal="true">
      <img id="lightboxImg" alt="Zoomed image">
    </div>

    <!-- Story complete modal -->
    <div id="storyDone" class="modal" role="dialog" aria-modal="true" style="display:none;">
      <div class="modal-card">
        <h3>Story complete</h3>
        <p class="modal-text">
  Your answers for this story have been saved.
  <span id="storySummary" style="display:block;margin-top:6px;color:#6b7c6b;"></span>
</p>
<div id="storySummaryDetails" class="modal-text" style="margin-top:-8px;color:#213421;"></div>
        <div class="modal-actions">
          <button id="storyNext" class="btn" type="button">Continue</button>
        </div>
      </div>
    </div>

    <!-- Confirm start quiz -->
    <div id="confirmStart" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" style="display:none;">
      <div class="modal-card">
        <h3 id="confirmTitle">Start Quiz?</h3>
        <p class="modal-text">
          • <strong>You cannot return to the passage</strong> after you start the quiz.<br>
          • If you <strong>leave, refresh, or lose your connection</strong> during the quiz,
            your answers may not be recorded and the test will restart from the beginning.
        </p>
        <div class="modal-actions">
          <button id="confirmCancel" class="btn-ghost">Cancel</button>
          <button id="confirmProceed" class="btn">Start the quiz</button>
        </div>
      </div>
    </div>

    <!-- Quiz view -->
    <section id="quizView" class="quiz-card" style="display:none;" aria-live="polite">
      <div class="slt-meta">
        <span id="qIndex" class="pill">Question</span>
      </div>
      <div class="progress" aria-hidden="true"><span id="bar" style="width:0%"></span></div>
      <div id="qText" class="qtext"></div>
      <div id="options" class="opts"></div>
      <div class="slt-footer">
        <button id="btnPrev" class="btn-ghost">Back</button>
        <button id="btnNext" class="btn" disabled>Next</button>
      </div>
      <div style="color:#6b7c6b; font-size:.9rem;">No back button is available during the quiz.</div>
    </section>

    <!-- Done view -->
  <!-- Done view -->
<section id="doneView" class="done-card" style="display:none;">
  <div class="done">
    <h2>You’ve completed the Starting Level Test.</h2>

    <!-- NEW: color category line (under the heading) -->
    <p id="colorLine" class="color-line" style="margin:-6px 0 12px;">
      Your Color Category:
      <span id="colorBadge" class="color-badge">—</span>
    </p>

    <p>Your results are ready. Here’s a quick summary.</p>

    <!-- existing analytics containers (keep if you already have them) -->
    <div id="finalStats" class="stats-grid" aria-live="polite"></div>
    <div id="perStoryWrap" class="per-story"></div>

    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:14px;">
  <a href="index.php" class="btn">Go to Dashboard</a>

  <!-- Single anchor only, with id="pbLink" -->
  <a id="pbLink" href="stories_pb.php" class="btn-pb">
    <span>Start Power Builder</span>
    <span class="arr">→</span>
    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" style="margin-left:8px">
      <path d="M13 5l7 7-7 7M5 12h14"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"/>
    </svg>
  </a>
</div>

    </div>
  </div>
</section>

    <!-- Time-up modal -->
    <div id="timeUpModal" class="modal" style="display:none;" role="dialog" aria-modal="true">
      <div class="modal-card">
        <h3>Time is up</h3>
        <p class="modal-text">We’re saving your work and finishing the test.</p>
      </div>
    </div>

    <!-- Analyzing / Reveal overlay -->
<div id="analyzeOverlay" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="analyzeTitle">
  <div class="modal-card" style="width:min(640px,92vw);">
    <!-- State: analyzing -->
    <div id="analyzeState">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="spinner" aria-hidden="true"></div>
        <h3 id="analyzeTitle" style="margin:0;">Analyzing your results…</h3>
      </div>
      <div class="analyze-steps">
        <div id="step-save"   class="analyze-step"><span class="dot"></span><span>Saving answers</span></div>
        <div id="step-score"  class="analyze-step"><span class="dot"></span><span>Scoring the test</span></div>
        <div id="step-map"    class="analyze-step"><span class="dot"></span><span>Applying thresholds</span></div>
        <div id="step-assign" class="analyze-step"><span class="dot"></span><span>Assigning your starting level</span></div>
      </div>
      <div class="analyze-note">This takes a few seconds. Don’t close the page.</div>
    </div>

    <!-- State: reveal (hidden until ready) -->
    <div id="revealState" style="display:none;">
      <h3 style="margin:0 0 8px;">🎉 Congratulations!</h3>
      <p class="modal-text" id="revealLine">Your starting level is:</p>
      <div id="revealBadge" class="reveal-badge">Level</div>
      <div class="rev-actions" style="margin-top:16px;">
  <button id="revealSeeResults" class="btn" type="button">See your results</button>
  <a id="revealBackLink" href="index.php" class="btn-ghost small">Return to Dashboard</a>
</div>

    </div>
  </div>
</div>


  </div>
</div>

<script>
(() => {
  const attemptId = <?= json_encode($attemptId) ?>;

  /* ---------------- STATE ---------------- */
  let stories = [];
  let sIdx = 0;
  let qIdx = 0;
  let mode = 'read';
  let answers = {};
  let readingStart = null;
  const readingSeconds = {};

  // timer meta (from server)
  let timeMeta = null;           // {limit_seconds, started_ts, now_ts, deadline_ts, remaining_seconds}
  let driftSec = 0;              // serverNow - clientNow
  let timerHandle = null;
  let timeAlreadyUp = false;
  const perStoryResults = {}; 
  let finalAssign = null; // { levelId, levelName, colorHex, overallPct }


  /* ---------------- DOM ---------------- */
  const $title   = document.getElementById('storyTitle');
  const $crumb   = document.getElementById('crumb');
  const $elapsed = document.getElementById('elapsed');

  const $readView = document.getElementById('readView');
  const $readPassage = document.getElementById('readPassage');
  const $readImage = document.getElementById('readImage');
  const $imgWrap   = document.getElementById('imgWrap');
  const $btnStartQuiz = document.getElementById('btnStartQuiz');

  const $quizView = document.getElementById('quizView');
  const $qIndex   = document.getElementById('qIndex');
  const $qText    = document.getElementById('qText');
  const $opts     = document.getElementById('options');
  const $bar      = document.getElementById('bar');
  const $btnNext  = document.getElementById('btnNext');
  const $btnPrev  = document.getElementById('btnPrev');

  const $doneView = document.getElementById('doneView');

  const $btnFontMinus = document.getElementById('btnFontMinus');
  const $btnFontPlus  = document.getElementById('btnFontPlus');
  const $btnThemeLight= document.getElementById('btnThemeLight');
  const $btnThemeSepia= document.getElementById('btnThemeSepia');
  const $readProg     = document.getElementById('readProg');
  const $lightbox     = document.getElementById('lightbox');
    // ... existing DOM refs ...
  const $lightboxImg  = document.getElementById('lightboxImg');
  const $timeUpModal  = document.getElementById('timeUpModal');

  // --- analyzing / reveal overlay ---
  const $analyze        = document.getElementById('analyzeOverlay');
  const $analyzeState   = document.getElementById('analyzeState');
  const $revealState    = document.getElementById('revealState');
  const $revealBadge    = document.getElementById('revealBadge');
  const $revealLine     = document.getElementById('revealLine');
 const $revealSeeResults = document.getElementById('revealSeeResults');


  const steps = ['step-save','step-score','step-map','step-assign'];
  const mark  = id => document.getElementById(id)?.classList.add('done');
  const clear = id => document.getElementById(id)?.classList.remove('done');
  const params = new URLSearchParams(location.search);
const forceCompletedView = params.get('view') === 'completed';

  const resetAnalyzer = () => {
    steps.forEach(clear);
    $analyzeState.style.display = '';
    $revealState.style.display  = 'none';
  };

  const sleep = (ms)=>new Promise(r=>setTimeout(r,ms));
  
  /* ---------------- UTILS ---------------- */
  const fmtClock = (s) => {
    const t = Math.max(0, Math.floor(s));
    const h = Math.floor(t/3600);
    const m = Math.floor((t%3600)/60).toString().padStart(2,'0');
    const ss= (t%60).toString().padStart(2,'0');
    return h > 0 ? `${h}:${m}:${ss}` : `${m}:${ss}`;
  };

  function setCrumb() {
    $crumb.textContent = `Story ${sIdx+1} of ${stories.length}`;
  }

  // server-time-based remaining seconds
  const nowServer = () => Math.floor(Date.now()/1000 + driftSec);

  // COUNTDOWN or stopwatch
  function startGlobalTimer() {
    if (!timeMeta) return;

    // if no limit → show stopwatch (count up)
    if (!timeMeta.limit_seconds || timeMeta.limit_seconds <= 0 || !timeMeta.deadline_ts) {
      if (timerHandle) clearInterval(timerHandle);
      const startTs = timeMeta.started_ts || Math.floor(Date.now()/1000);
      timerHandle = setInterval(() => {
        const elapsed = nowServer() - startTs;
        $elapsed.classList.remove('warn','danger');
        $elapsed.title = 'Elapsed time';
        $elapsed.textContent = fmtClock(elapsed);
      }, 1000);
      return;
    }

    // with limit → countdown
    if (timerHandle) clearInterval(timerHandle);
    $elapsed.title = 'Remaining time';
    const tick = async () => {
      const remaining = timeMeta.deadline_ts - nowServer();
      // styles
      $elapsed.classList.toggle('warn', remaining <= 60*2 && remaining > 30);
      $elapsed.classList.toggle('danger', remaining <= 30);
      $elapsed.textContent = fmtClock(remaining);

      if (!timeAlreadyUp && remaining <= 0) {
        timeAlreadyUp = true;
        clearInterval(timerHandle);
        await onTimeUp();
      }
    };
    tick();
    timerHandle = setInterval(tick, 1000);
  }

  function onBeforeUnload(e){ e.preventDefault(); e.returnValue = ''; }

  /* ---------------- CHUNK HELPERS ---------------- */
  const CHUNK_SIZE = 5;

  function chunkInfo() {
    const st    = stories[sIdx];
    const items = st?.items || [];
    const total = items.length;
    const start = Math.floor(qIdx / CHUNK_SIZE) * CHUNK_SIZE;
    const end   = Math.min(start + CHUNK_SIZE, total);
    const idxInChunk = qIdx - start;
    const currentChunk = Math.floor(qIdx / CHUNK_SIZE) + 1;
    const totalChunks  = Math.ceil(total / CHUNK_SIZE);
    return { items, total, start, end, idxInChunk, currentChunk, totalChunks };
  }
  function updateNextEnabled() {
    const { items, start, end } = chunkInfo();
    let ok = true;
    for (let i = start; i < end; i++) {
      if (answers[items[i].item_id] == null) { ok = false; break; }
    }
    $btnNext.disabled = !ok;
  }

  /* ---------------- RENDERING ---------------- */
  // prefs
  let fontScale = parseFloat(localStorage.getItem('slt_fontScale') || '1.0');
  let theme     = localStorage.getItem('slt_theme') || 'light';

  function applyFontScale(){
    $readPassage.style.fontSize = `calc(1em * ${fontScale})`;
    document.getElementById('btnFontMinus').disabled = fontScale <= 0.85;
    document.getElementById('btnFontPlus').disabled  = fontScale >= 1.40;
  }
  function setTheme(name){
    document.body.classList.remove('theme-sepia','theme-dark');
    theme = name;
    if (name === 'sepia') document.body.classList.add('theme-sepia');
    localStorage.setItem('slt_theme', theme);
  }
  function updateReadProgress(){
    if ($readView.style.display !== 'block') { $readProg.style.width = '0%'; return; }
    const el = $readPassage;
    const total = el.scrollHeight - el.clientHeight;
    const pct = total <= 0 ? 0 : Math.max(0, Math.min(100, (el.scrollTop/total)*100));
    $readProg.style.width = pct.toFixed(0) + '%';
  }

  function showReading(){
    const st = stories[sIdx];
    mode = 'read';
    setCrumb();
    $title.textContent = st.title || 'Story';

    $readPassage.innerHTML = st.passage_html || '';
    if (st.image) { $imgWrap.style.display=''; $readImage.src = st.image; }
    else { $imgWrap.style.display='none'; $readImage.removeAttribute('src'); }

    $readView.style.display = 'block';
    $quizView.style.display = 'none';
    $doneView.style.display = 'none';

    setTheme(theme); applyFontScale();
    $readPassage.scrollTop = 0; updateReadProgress();
    readingStart = Date.now();
  }

  function showChunk(){
    const st = stories[sIdx];
    const { items, total, start, end, currentChunk, totalChunks } = chunkInfo();

    mode = 'quiz';
    setCrumb();
    $title.textContent = st.title || 'Story';
    $readView.style.display = 'none';
    $quizView.style.display = 'block';
    $doneView.style.display = 'none';

    const firstNum = start + 1, lastNum = end;
    $qIndex.textContent = `Set ${currentChunk}/${totalChunks} — Questions ${firstNum}–${lastNum} of ${total}`;
    $bar.style.width = `${(end/total)*100}%`;

    if ($qText) $qText.style.display = 'none';
    $opts.innerHTML = '';

    for (let i = start; i < end; i++) {
      const item = items[i]; if (!item) continue;
      const qtext = item.question ?? item.question_text ?? '';
      const prevPick = answers[item.item_id];
      const rawChoices = Array.isArray(item.choices) ? item.choices : [];
      const letterFor = (k) => String.fromCharCode(65 + k);

      const block = document.createElement('div');
      block.className = 'qblock';
      block.innerHTML = `
        <div class="qtext" data-qbadge="Q${i+1}">${qtext}</div>
        <div class="opts"></div>`;
      const $optsWrap = block.querySelector('.opts');

      rawChoices.forEach((choice, k) => {
        const text = (choice && (choice.text ?? choice.choice_text ?? choice.label)) ?? String(choice ?? '');
        const wrap = document.createElement('label');
        wrap.className = 'opt';
        wrap.innerHTML = `
          <span class="letter">${letterFor(k)}</span>
          <input type="radio" name="q_${item.item_id}" value="${k}" aria-label="Choice ${letterFor(k)}">
          <span>${text}</span>`;
        const inp = wrap.querySelector('input');
        if (prevPick === k) { inp.checked = true; wrap.classList.add('selected'); }
        wrap.addEventListener('click', () => {
          $optsWrap.querySelectorAll('.opt').forEach(x=>x.classList.remove('selected'));
          wrap.classList.add('selected');
          answers[item.item_id] = Number(inp.value);
          updateNextEnabled();
        });
        $optsWrap.appendChild(wrap);
      });

      $opts.appendChild(block);
    }

    const onLastSet = (end >= total);
    $btnPrev.disabled = (start === 0);
    $btnNext.textContent = onLastSet ? 'Finish story' : 'Next set';
    updateNextEnabled();
  }

  function renderCompletedAnalytics(){
  const $stats = document.getElementById('finalStats');
  const $list  = document.getElementById('perStoryWrap');
  if (!$stats || !$list) return;

  const rows = Object.values(perStoryResults);
  const totalQ = rows.reduce((s,r)=> s + (r.total||0), 0);
  const correct= rows.reduce((s,r)=> s + (r.score||0), 0);
  const pct    = totalQ ? Math.round((correct/totalQ)*100) : 0;
  const readAll= rows.reduce((s,r)=> s + (r.read_secs||0), 0);

  const wpms   = rows.map(r=> r.wpm).filter(v=> typeof v==='number' && isFinite(v));
  const avgWpm = wpms.length ? Math.round(wpms.reduce((s,v)=>s+v,0)/wpms.length) : '—';
  const minWpm = wpms.length ? Math.min(...wpms) : '—';
  const maxWpm = wpms.length ? Math.max(...wpms) : '—';

  $stats.innerHTML = `
    <div class="stat-card">
      <div class="stat-title">Overall Accuracy</div>
      <div class="stat-value">${correct}/${totalQ} (${pct}%)</div>
      <div class="meter" aria-hidden="true"><span style="width:${pct}%;"></span></div>
    </div>
    <div class="stat-card">
      <div class="stat-title">Total Reading Time</div>
      <div class="stat-value">${fmtClock(readAll)}</div>
    </div>
    <div class="stat-card">
      <div class="stat-title">Average WPM</div>
      <div class="stat-value">${avgWpm}</div>
      <div style="color:#6b7c6b; font-size:.9rem;">Range: ${minWpm}–${maxWpm}</div>
    </div>
    <div class="stat-card">
      <div class="stat-title">Stories Completed</div>
      <div class="stat-value">${rows.length}</div>
    </div>
  `;

  // per-story rows
  $list.innerHTML = rows.map(r => {
    const pct = r.total ? Math.round((r.score / r.total)*100) : 0;
    const wpmText = (r.wpm == null) ? '—' : r.wpm;
    return `
      <div class="story-row">
        <div class="story-title">${r.title}</div>
        <div class="story-meter">
          <div class="meter" aria-label="Accuracy ${pct}%"><span style="width:${pct}%"></span></div>
        </div>
        <div class="story-score">${r.score}/${r.total}</div>
        <div class="story-wpm">WPM: ${wpmText}</div>
      </div>
    `;
  }).join('');
}
  function hexToRgba(hex, alpha=0.14){
  if (!hex) return null;
  const m = hex.replace('#','').match(/^([0-9a-f]{6}|[0-9a-f]{3})$/i);
  if (!m) return null;
  let r,g,b;
  if (hex.length === 4){
    r = parseInt(hex[1]+hex[1],16);
    g = parseInt(hex[2]+hex[2],16);
    b = parseInt(hex[3]+hex[3],16);
  } else {
    r = parseInt(hex.slice(1,3),16);
    g = parseInt(hex.slice(3,5),16);
    b = parseInt(hex.slice(5,7),16);
  }
  return `rgba(${r},${g},${b},${alpha})`;
}

function applyColorCategory(assign){
  const badge = document.getElementById('colorBadge');
  if (!badge || !assign) return;

  const name = (assign.levelName || '—').trim();
  badge.textContent = name;

  // remove preset classes (if you added them in CSS)
  badge.classList.remove('color-red','color-orange','color-yellow','color-blue','color-green');

  // prefer colorHex from backend, else fallback by name
  if (assign.colorHex){
    const bg = hexToRgba(assign.colorHex, 0.16);
    badge.style.background   = bg || '';
    badge.style.borderColor  = assign.colorHex;
    // use darker text if background is light—keep default brand color
    badge.style.color        = '#1b3a1b';
  } else {
    const key = name.toLowerCase();
    if (key.includes('red'))    badge.classList.add('color-red');
    else if (key.includes('orange')) badge.classList.add('color-orange');
    else if (key.includes('yellow')) badge.classList.add('color-yellow');
    else if (key.includes('blue'))   badge.classList.add('color-blue');
    else if (key.includes('green'))  badge.classList.add('color-green');
  }
}

  /* ---------------- SAVE / SUBMIT ---------------- */
  async function saveCurrentStorySilently(){
    const st = stories[sIdx];
    const items = st.items || [];

    const flat = [];
    for (const it of items) {
      if (answers[it.item_id] != null) flat.push({ item_id: it.item_id, choice_index: answers[it.item_id] });
    }
    const readSecs = (readingSeconds[st.story_id] || 0) + Math.floor((Date.now() - (readingStart||Date.now()))/1000);
    const attemptStoryId = st.attempt_story_id ?? null;

    const r = await fetch('slt_submit_story.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        attempt_id: attemptId,
        attempt_story_id: attemptStoryId,
        story_id: st.story_id,
        answers: flat,
        reading_seconds: readSecs
      })
    });
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    // store computed reading time
    readingSeconds[st.story_id] = readSecs;

    perStoryResults[st.story_id] = {
  title: st.title || 'Story',
  score: Number(data.score ?? 0),
  total: Number(data.total ?? items.length ?? 0),
  pct:   (data.pct != null)
          ? Number(data.pct)
          : (items.length ? Math.round((Number(data.score ?? 0) / items.length)*100) : 0),
  wpm:   (data.wpm === null || data.wpm === undefined) ? null : Number(data.wpm),
  read_secs: Number(data.read_secs ?? readingSeconds[st.story_id] ?? 0),
};
    return data;
  }

async function submitStoryAndNotify(){
  const data = await saveCurrentStorySilently();

  // Fallbacks kung hindi ibinalik ng server ang ilan
  const st      = stories[sIdx] || {};
  const total   = data.total ?? (Array.isArray(st.items) ? st.items.length : 0);
  const score   = data.score ?? 0;
  const pct     = total > 0 ? Math.round((score/total)*100) : 0;
  const readSec = Number(data.read_secs ?? data.reading_seconds ?? 0);

  // WPM display
  let wpmText = 'WPM: —';
  if (data.wpm != null && data.wpm !== false) {
    const w = Number(data.wpm);
    // Optional: speed label
    const label = (w < 120) ? 'Slow' : (w <= 220 ? 'Average' : 'Fast');
    wpmText = `WPM: ${w} (${label})`;
  } else if (readSec < 15) {
    wpmText = `WPM: N/A (reading too short)`;
  }

  // Top line (compact)
  const $sum = document.getElementById('storySummary');
  $sum.innerHTML = `Score: <b>${score}/${total}</b> (${pct}%) • ${wpmText} • Reading time: ${fmtClock(readSec)}`;

  // Details: wrong items list (kung meron ibinalik ang server)
  const $det = document.getElementById('storySummaryDetails');
  $det.innerHTML = ''; // reset

  if (Array.isArray(data.wrong_items) && data.wrong_items.length) {
    const ul = document.createElement('ul');
    ul.style.margin = '8px 0 0';
    ul.style.paddingLeft = '18px';

    const letter = (i)=> String.fromCharCode(65 + Number(i || 0));

    data.wrong_items.forEach(w => {
      const li = document.createElement('li');
      const qno = w.q_no ?? (w.index != null ? (Number(w.index) + 1) : '?');
      li.innerHTML = `Q${qno}: Your answer <b>${letter(w.picked_index)}</b>; correct <b>${letter(w.correct_index)}</b>` +
                     (w.explain ? ` — <span style="color:#6b7c6b">${w.explain}</span>` : '');
      ul.appendChild(li);
    });
    $det.appendChild(ul);
  } else {
    $det.textContent = (score === total && total > 0) ? 'Great job! All correct.' : '';
  }

  // show modal
  const $done = document.getElementById('storyDone');
  $done.style.display = 'flex';

  return new Promise(resolve => {
    const $doneBtn = document.getElementById('storyNext');
    $doneBtn.onclick = () => { $done.style.display = 'none'; resolve(); };
  });
}

  async function submitAll(){
    const flat = [];
    for (const st of stories) {
      for (const it of (st.items || [])) {
        if (answers[it.item_id] == null) continue;
        flat.push({ item_id: it.item_id, choice_index: answers[it.item_id] });
      }
    }
    const payload = {
      attempt_id: attemptId,
      // do not trust client time for elapsed; backend can recompute if needed
      answers: flat,
      reading_seconds_by_story: readingSeconds
    };
    const r = await fetch('slt_submit.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await r.json();
    if(!data.ok) throw new Error(data.error || 'Submit error');
    return data;
  }

// --- Completed screen helper (so we can reuse) ---
function showCompleted(){
  // stop timer + hide time pill
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  if ($elapsed) $elapsed.style.display = 'none';

  $readView.style.display = 'none';
  $quizView.style.display = 'none';
  $doneView.style.display = 'block';
  $title.textContent = 'Starting Level Test';
  $crumb.textContent = 'Completed';

  renderCompletedAnalytics();
  applyColorCategory(finalAssign);   // NEW
  updatePbLink(finalAssign);         // NEW
  applyColorCategory(finalAssign)
  
  window.removeEventListener('beforeunload', onBeforeUnload);
  window.scrollTo({ top: 0, behavior: 'instant' });
}
function updatePbLink(assign){
  const a = document.getElementById('pbLink');
  if (!a) return;

  // disable habang wala pang assignment
  if (!assign || !assign.levelId){
    a.setAttribute('aria-disabled','true');
    return;
  }
  a.removeAttribute('aria-disabled');

  // update URL params
  const url = new URL(a.getAttribute('href'), location.origin);
  url.searchParams.set('level_id', String(assign.levelId));
  if (assign.levelName)  url.searchParams.set('level_name', assign.levelName);
  if (assign.overallPct != null) url.searchParams.set('slt_pct', String(Math.round(assign.overallPct)));
  if (assign.colorHex)   url.searchParams.set('color_hex', assign.colorHex);
  a.setAttribute('href', url.pathname + '?' + url.searchParams.toString());

}
// --- Analyze → Reveal → Continue flow ---
// --- Analyze → Reveal → Continue flow ---
async function runAnalyzeAndFinish({ fast=false } = {}) {
  // Fallback if the overlay isn’t present for any reason
  if (!$analyze) {
    await submitAll().catch(() => {});
    showCompleted();
    return;
  }

  resetAnalyzer();
  $analyze.style.display = 'flex';

  const delay = ms => new Promise(r => setTimeout(r, fast ? 120 : ms));

  // Fake steps (visual only) then finalize attempt
  mark('step-save');   await delay(650);
  mark('step-score');  await delay(650);
  mark('step-map');    await delay(650);

  let result = null;
  try {
    result = await submitAll();          // <-- finalize on the server
    mark('step-assign');
  } catch (e) {
    // If finalize fails, close overlay and fall back to completed view
    $analyze.style.display = 'none';
    alert('We had trouble finalizing your test. Your teacher can help if this persists.');
    showCompleted();
    return;
  }

  // 🔴 NEW: kung sinabi ng server na "allow_retake", huwag na mag-assign ng level
  //        → balik sa SLT page para mag-retake
  if (result && result.allow_retake) {
    $analyze.style.display = 'none';
    window.removeEventListener('beforeunload', onBeforeUnload);

    const msg = result.retry_message
      || 'Your attempt was not scored (not enough answers or time ran out).\n\nYou may retake the Starting Level Test.';
    alert(msg);

    // balik sa SLT landing page na may flag, para pwede kang magpakita ng notice doon
    window.location.href = 'stories_sl.php?retry=1';
    return;
  }

  // ✅ Normal path: may valid assignment galing backend
  finalAssign = {
    levelId:    result?.assigned_level_id ?? result?.level_id ?? null,
    levelName:  result?.assigned_level_name ?? result?.level_name ?? null,
    colorHex:   result?.assigned_color_hex ?? null,
    overallPct: result?.overall_pct ?? result?.summary?.percent ?? null,
  };

  // (optional) update reveal overlay badge text if needed
  if (finalAssign?.levelName) {
    $revealBadge.textContent = finalAssign.levelName;
    $revealLine.textContent  = `Congratulations! You’ll start in the ${finalAssign.levelName} category.`;
  }

  // Pull assigned level from server response (use whatever keys you return)
  const levelName  = result?.assigned_level_name || result?.level_name || result?.pb_level_name || null;
  const colorName  = result?.assigned_color_name || result?.color_name || null;

  $revealBadge.textContent = levelName ? levelName : 'Ready';
  $revealLine.textContent  = levelName
    ? `Congratulations! You’ll start in the ${levelName}${colorName ? ' ('+colorName+')' : ''} category.`
    : 'Your starting level is ready.';

  // Switch from “analyzing…” to reveal state
  $analyzeState.style.display = 'none';
  $revealState.style.display  = '';

  return new Promise(resolve => {
    const btn = $revealSeeResults || document.getElementById('revealSeeResults');
    if (btn) {
      btn.addEventListener('click', () => {
        $analyze.style.display = 'none';
        showCompleted();
        resolve();
      }, { once: true });
    } else {
      // safety fallback
      $analyze.style.display = 'none';
      showCompleted();
      resolve();
    }
  });
}

async function onTimeUp(){
  try{
    // Ipakita ang “Time is up” sandali habang nagsa-save
    $timeUpModal.style.display = 'flex';

    // Best-effort save ng current story bago mag-finalize
    if (stories.length && sIdx >= 0 && sIdx < stories.length) {
      await saveCurrentStorySilently().catch(()=>{});
    }

    // Isara ang time-up modal bago ang analyzing overlay
    $timeUpModal.style.display = 'none';

    // Mabilis na analyze → reveal → completed
    await runAnalyzeAndFinish({ fast:true });
  } catch (e) {
    // Fallback kung may error sa finalize/analyze
    $timeUpModal.style.display = 'none';
    showCompleted();
  }
}

  /* ---------------- EVENTS ---------------- */
  // Reading controls
  $btnFontMinus?.addEventListener('click', () => {
    fontScale = Math.max(0.85, (fontScale - 0.05));
    localStorage.setItem('slt_fontScale', fontScale.toFixed(2));
    applyFontScale();
  });
  $btnFontPlus?.addEventListener('click', () => {
    fontScale = Math.min(1.40, (fontScale + 0.05));
    localStorage.setItem('slt_fontScale', fontScale.toFixed(2));
    applyFontScale();
  });
  $btnThemeLight?.addEventListener('click', () => setTheme('light'));
  $btnThemeSepia?.addEventListener('click', () => setTheme('sepia'));

  $readPassage.addEventListener('scroll', updateReadProgress, { passive:true });
  window.addEventListener('resize', updateReadProgress);

  // Image lightbox
  $readImage.addEventListener('click', () => {
    if (!$readImage.src) return;
    $lightboxImg.src = $readImage.src;
    $lightbox.style.display = 'flex';
  });
  $lightbox.addEventListener('click', () => {
    $lightbox.style.display = 'none';
    $lightboxImg.removeAttribute('src');
  });

  // Confirm start quiz
  const $confirm = document.getElementById('confirmStart');
  const $confirmCancel = document.getElementById('confirmCancel');
  const $confirmProceed = document.getElementById('confirmProceed');

  $btnStartQuiz.addEventListener('click', () => { $confirm.style.display = 'flex'; $confirm.querySelector('.btn')?.focus(); });
  $confirmCancel.addEventListener('click', () => { $confirm.style.display = 'none'; });
  $confirmProceed.addEventListener('click', () => { $confirm.style.display = 'none'; goQuizFromReading(); });
  $confirm.addEventListener('click', (e) => { if (e.target === $confirm) $confirm.style.display = 'none'; });

  // Quiz navigation
  $btnNext.addEventListener('click', async () => {
    const { items, start, end } = chunkInfo();
    const total = items.length;
    const onLastSet = (end >= total);

    if (!onLastSet) {
      qIdx = end;
      showChunk();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    try { await submitStoryAndNotify(); }
    catch(e){ alert('Could not save this story. Please check your connection and try again.\n\n' + e.message); return; }

    if (sIdx < stories.length - 1) {
      sIdx++;
      showReading();
      window.scrollTo({ top: 0, behavior: 'instant' });
        } else {
  await runAnalyzeAndFinish();
}

  });

  $btnPrev.addEventListener('click', () => {
    const { start } = chunkInfo();
    if (start === 0) return;
    qIdx = Math.max(0, start - CHUNK_SIZE);
    showChunk();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // Keyboard shortcuts (quiz only): 1–4, A–D, Enter
  document.addEventListener('keydown', (e) => {
    if ($quizView.style.display !== 'block') return;

    let n = null;
    if (['1','2','3','4'].includes(e.key)) n = Number(e.key) - 1;
    const k = e.key?.toUpperCase();
    if (['A','B','C','D'].includes(k)) n = k.charCodeAt(0) - 65;

    if (n != null) {
      const radios = $opts.querySelectorAll('input[type=radio]');
      const inp = radios[n];
      if (inp) {
        inp.checked = true;
        $opts.querySelectorAll('.opt').forEach((x,i)=> x.classList.toggle('selected', i===n));
        $btnNext.disabled = false;
      }
    } else if (e.key === 'Enter' && !$btnNext.disabled) {
      $btnNext.click();
    }
  });

  function goQuizFromReading(){
    const st = stories[sIdx];
    const secs = Math.floor((Date.now() - (readingStart||Date.now()))/1000);
    readingSeconds[st.story_id] = (readingSeconds[st.story_id] || 0) + secs;

    // one-way reading
    $readPassage.innerHTML = '';

    qIdx = 0;
    showChunk();
  }

  /* ---------------- BOOT ---------------- */
  (async function init(){
    // if we are asked to show completed summary only
if (forceCompletedView) {
  try{
    // Fetch summary from server
    const rs = await fetch(`slt_attempt_summary.php?attempt_id=${encodeURIComponent(attemptId)}`);
    const sum = await rs.json();
    if (!sum.ok) throw new Error(sum.error || 'Summary error');

    // fill perStoryResults
    (sum.stories || []).forEach(r => {
      perStoryResults[r.story_id] = {
        title: r.title,
        score: r.score,
        total: r.total,
        pct:   r.total ? Math.round((r.score/r.total)*100) : 0,
        wpm:   r.wpm === null ? null : r.wpm,
        read_secs: r.read_secs || 0,
      };
    });

    // set final assignment
    finalAssign = {
      levelId:   sum.assigned_level_id || null,
      levelName: sum.assigned_level_name || null,
      colorHex:  sum.assigned_color_hex || null,
      overallPct: sum.overall_pct ?? null
    };

    // show completed analytics
    showCompleted();
    return; // stop normal flow
  } catch(e){
    alert('Cannot load SLT result: ' + e.message);
    location.href = 'stories_sl.php';
    return;
  }
}

    try{
      window.addEventListener('beforeunload', onBeforeUnload);

      const r = await fetch(`slt_fetch.php?attempt_id=${encodeURIComponent(attemptId)}`);
      if (!r.ok) throw new Error('Failed to fetch test data');
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Server error');

      stories = Array.isArray(data.stories) ? data.stories : [];
      if (!stories.length) throw new Error('No stories queued for this attempt');

      // time meta + drift
      timeMeta = data.time || null;
      if (timeMeta && typeof timeMeta.now_ts === 'number') {
        const clientNow = Math.floor(Date.now()/1000);
        driftSec = (timeMeta.now_ts - clientNow); // serverNow - clientNow
      }

      // if already expired (edge case), finish immediately
      if (timeMeta?.time_up === true) {
        await onTimeUp();
        return;
      }

      startGlobalTimer();

      sIdx = 0; qIdx = 0; mode = 'read';
      showReading();
    }catch(err){
      alert('Cannot load the test: ' + err.message);
      location.href = 'stories_sl.php';
    }
  })();
})();
</script>
<script>
// Exam protection script – applies only on pages where this is included
(function() {
  // 1) Disable right-click
  document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
  });

  // 2) Disable text selection globally (backup, in case CSS is bypassed)
  document.addEventListener('selectstart', function(e) {
    // hayaan gumana ang selection sa input/textarea kung meron ka
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea') return;
    e.preventDefault();
  });

  // 3) Try to clear clipboard (for PrintScreen / copy)
  function tryClearClipboard() {
    // Modern API
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText('Screenshots and copying are not allowed during the test.')
        .catch(function() {
          // ignore errors silently
        });
    } else {
      // Legacy fallback
      const inp = document.createElement('input');
      inp.value = '.';
      inp.style.position = 'fixed';
      inp.style.opacity = '0';
      document.body.appendChild(inp);
      inp.select();
      try { document.execCommand('copy'); } catch (err) {}
      document.body.removeChild(inp);
    }
  }

  // 4) Block important keys / shortcuts
  document.addEventListener('keydown', function(e) {
    const key = (e.key || '').toLowerCase();

    // F12 (DevTools)
    if (key === 'f12') {
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    // PrintScreen key (some browsers/OS)
    if (key === 'printscreen' || e.keyCode === 44) {
      e.preventDefault();
      e.stopPropagation();
      tryClearClipboard();
      alert('Screenshots are not allowed during the test.');
      return;
    }

    // Ctrl + something
    if (e.ctrlKey) {
      const blocked = ['c','x','s','p','u','a']; // copy, cut, save, print, view source, select all
      if (blocked.includes(key)) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      // Ctrl+Shift+I / J / C (DevTools)
      if (e.shiftKey && ['i','j','c'].includes(key)) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }
    }
  });

  // 5) Extra PrintScreen detection on keyup (sa ibang OS/browsers)
  document.addEventListener('keyup', function(e) {
    const key = (e.key || '').toLowerCase();
    if (key === 'printscreen' || e.keyCode === 44) {
      e.preventDefault();
      tryClearClipboard();
      alert('Screenshots are not allowed during the test.');
    }
  });

    // 6) Detect tab switching / leaving page – with logging
  document.addEventListener('visibilitychange', function() {
    const state = document.hidden ? 'hidden' : 'visible';

    // Log to server (best-effort, hindi nakaka-apekto sa exam kahit mag-fail)
    fetch('slt_tab_switch_log.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        attempt_id: <?= json_encode($attemptId ?? 0) ?>,
        state: state,
        ts: Date.now()
      })
    }).catch(() => {});

    if (document.hidden) {
      console.warn('User switched AWAY from the exam tab.');
    } else {
      console.log('User RETURNED to the exam tab.');
    }
  });

})();
</script>
</body>
</html>
