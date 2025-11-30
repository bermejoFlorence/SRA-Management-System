<?php
// student/stories_rb_run.php — RB runner with SLT-style layout (Step 1: render & collect answers)
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);
$attemptId  = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($student_id <= 0 || $attemptId <= 0) {
  header('Location: stories_rb.php');
  exit;
}

$PAGE_TITLE  = 'Rate Builder';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'rb';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
:root{
  --g:#003300; --acc:#ECA305; --acc-soft:rgba(236,163,5,.14);
  --bg:#f5f7f6; --card:#fff; --ink:#213421; --muted:#6b7c6b;
  --line:#e6efe6; --shadow:0 10px 28px rgba(0,0,0,.08);
}
.main-content{ width:calc(100% - 220px); margin-left:220px; background:var(--bg); min-height:100vh; }
@media (max-width:992px){ .main-content{ width:100%; margin-left:0; } }
.rb-wrap{ max-width:1320px; margin:0 auto; padding:16px 24px; padding-top:12px; }

/* Runner header */
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
.run-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:8px 0 16px; padding:14px 18px;
  background:linear-gradient(180deg,#fff,#fefefe); border:1px solid #eef2ee;
  border-radius:14px; box-shadow:var(--shadow);
}
.run-title{ margin:0; font-weight:900; letter-spacing:.2px; color:var(--g);
  font-size:clamp(1.2rem,1rem + 1vw,1.6rem); }

/* ⬇⬇ NEW ⬇⬇ */
.run-left{
  display:flex;
  flex-direction:column;
  gap:2px;
}

.rb-story-author{
  font-size:.95rem;
  color:var(--muted);
}

/* optional: konting ibang look kapag no time limit */
.pill-muted{
  background:#f5f5f5;
  color:var(--muted);
}
/* ⬆⬆ NEW ⬆⬆ */

/* Cards */
.read-card, .quiz-card, .done-card{
  background:var(--card); border:1px solid #eef2ee; border-radius:16px;
  box-shadow:0 8px 24px rgba(0,0,0,.06); padding:clamp(16px,2.4vw,24px); margin:0 0 16px;
}

/* Reading layout / typography */
.read-grid{ display:grid; grid-template-columns:minmax(0, 2fr) minmax(240px, 1fr); gap:20px; align-items:start; }
.read-passage{
  font-size:clamp(1.05rem, 0.95rem + 0.6vw, 1.35rem);
  line-height:1.7; letter-spacing:.2px; background:#fffefc;
  border:1px solid #efe9da; border-radius:14px; padding:clamp(16px, 2.2vw, 22px);
  color:#1f2a1f; max-width:65ch;
}
.read-passage p{ margin:0 0 1em; } .read-passage p:last-child{ margin-bottom:0; }
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
.rb-meta{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px;}
.progress{ width:100%; height:10px; border-radius:999px; overflow:hidden; background:#ececec; }
.progress > span{ display:block; height:100%; background:linear-gradient(90deg, var(--acc), #ffd37a); }
.qtext{
  position:relative; margin:16px 0 14px; padding:14px 16px 14px 56px;
  font-weight:900; font-size:clamp(1.05rem, 1rem + 0.4vw, 1.3rem); color:#1b3a1b;
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
.rb-footer{ position:sticky; bottom:8px; display:flex; justify-content:flex-end; gap:10px; margin-top:12px; }
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
</style>

<div class="main-content">
  <div class="rb-wrap">

    <!-- Runner header -->
   <section class="run-head" aria-live="polite">
  <div class="run-left">
    <h1 id="storyTitle" class="run-title">Loading…</h1>
    <div id="storyAuthor" class="rb-story-author" style="display:none;"></div>
  </div>
  <div class="rb-meta">
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

    <!-- Confirm start quiz -->
    <div id="confirmStart" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" style="display:none;">
      <div class="modal-card">
        <h3 id="confirmTitle">Start Quiz?</h3>
        <p class="modal-text">
          • <strong>You cannot return to the passage</strong> after you start the quiz.<br>
          • If you <strong>leave, refresh, or lose your connection</strong>, your answers may not be recorded.
        </p>
        <div class="modal-actions">
          <button id="confirmCancel" class="btn-ghost">Cancel</button>
          <button id="confirmProceed" class="btn">Start the quiz</button>
        </div>
      </div>
    </div>

    <!-- Quiz view -->
    <section id="quizView" class="quiz-card" style="display:none;" aria-live="polite">
      <div class="rb-meta">
        <span id="qIndex" class="pill">Questions</span>
      </div>
      <div class="progress" aria-hidden="true"><span id="bar" style="width:0%"></span></div>
      <div id="qContainer"></div>
      <div class="rb-footer">
        <button id="btnPrev" class="btn-ghost">Back</button>
        <button id="btnNext" class="btn" disabled>Next</button>
      </div>
      <div style="color:#6b7c6b; font-size:.9rem;">No back button is available to re-open the passage.</div>
    </section>

    <!-- Done view (attempt finished) -->
    <section id="doneView" class="done-card" style="display:none;">
      <h2>Rate Builder attempt complete 🎉</h2>
      <p>Your answers for all stories have been submitted.</p>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="stories_rb.php" class="btn">Back to RB Overview</a>
        <a href="index.php" class="btn-ghost">Go to Dashboard</a>
      </div>
    </section>

        <!-- Story complete modal (uniform with SLT) -->
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
        <!-- Time-up modal (uniform name with SLT) -->
    <div id="timeUpModal" class="modal" style="display:none;" role="dialog" aria-modal="true">
      <div class="modal-card">
        <h3>Time is up</h3>
        <p class="modal-text">We’re saving your answers for this story. Please wait…</p>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const attemptId = <?= json_encode($attemptId) ?>;

  /* ----- State ----- */
  let story = null;        // { attempt_story_id, story_id, title, passage_html, image, time_limit }
  let items = [];          // [{ item_id, number, question, choices[{label,text}], item_type }]
  let answers = {};        // { item_id: "A" | "B" | ... }
  let readingStart = null;
  let fontScale = parseFloat(localStorage.getItem('rb_fontScale') || '1.0');
  let theme = localStorage.getItem('rb_theme') || 'light';

  // chunking like SLT
  const CHUNK_SIZE = 5;
  let qIdx = 0;

  // timer (per-story)
  let timerHandle = null;
  let quizDeadline = null;
  let timeAlreadyUp = false; // NEW: para once lang tumakbo ang time-up logic
  /* ----- DOM ----- */
  const $title   = document.getElementById('storyTitle');
  const $author  = document.getElementById('storyAuthor');   // NEW
  const $crumb   = document.getElementById('crumb');
  const $elapsed = document.getElementById('elapsed');

  const $readView = document.getElementById('readView');
  const $readPassage = document.getElementById('readPassage');
  const $readImage = document.getElementById('readImage');
  const $imgWrap   = document.getElementById('imgWrap');
  const $readProg  = document.getElementById('readProg');

  const $btnStartQuiz = document.getElementById('btnStartQuiz');
  const $confirm = document.getElementById('confirmStart');
  const $confirmCancel = document.getElementById('confirmCancel');
  const $confirmProceed= document.getElementById('confirmProceed');

  const $quizView = document.getElementById('quizView');
  const $qIndex   = document.getElementById('qIndex');
  const $bar      = document.getElementById('bar');
  const $qContainer = document.getElementById('qContainer');
  const $btnPrev  = document.getElementById('btnPrev');
  const $btnNext  = document.getElementById('btnNext');

  const $doneView = document.getElementById('doneView');

  const $lightbox = document.getElementById('lightbox');
  const $lightboxImg = document.getElementById('lightboxImg');

  const $btnFontMinus = document.getElementById('btnFontMinus');
  const $btnFontPlus  = document.getElementById('btnFontPlus');
  const $btnThemeLight= document.getElementById('btnThemeLight');
  const $btnThemeSepia= document.getElementById('btnThemeSepia');

  const $storyDone  = document.getElementById('storyDone');
  const $storyNext  = document.getElementById('storyNext');   // NEW
  const $timeUpModal= document.getElementById('timeUpModal'); // NEW

  function escapeHtml(s){ return String(s ?? '').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

  /* ----- Utils ----- */
  const fmt = (sec) => {
    sec = Math.max(0, Math.floor(sec));
    const m = String(Math.floor(sec/60)).padStart(2,'0');
    const s = String(sec%60).padStart(2,'0');
    return `${m}:${s}`;
  };

function setLimitLabel(){
  if (!$crumb) return;
  const secs = Number(story?.time_limit || 0);

  if (!secs) {
    $crumb.textContent = 'No time limit';
    $crumb.classList.add('pill-muted');
  } else {
    $crumb.textContent = 'Time limit: ' + fmt(secs);
    $crumb.classList.remove('pill-muted');
  }
}
function refreshHeader(){
  $title.textContent = story?.title || 'Story';

  if ($author){
    const name = story?.author || '';
    if (name){
      $author.textContent = 'by ' + name;
      $author.style.display = '';
    } else {
      $author.textContent = '';
      $author.style.display = 'none';
    }
  }

  setLimitLabel();
}


  function updateReadProgress(){
    if ($readView.style.display !== 'block') { $readProg.style.width = '0%'; return; }
    const el = $readPassage;
    const total = el.scrollHeight - el.clientHeight;
    const pct = total <= 0 ? 0 : Math.max(0, Math.min(100, (el.scrollTop/total)*100));
    $readProg.style.width = pct.toFixed(0) + '%';
  }

  function applyFontScale(){
    $readPassage.style.fontSize = `calc(1em * ${fontScale})`;
    document.getElementById('btnFontMinus').disabled = fontScale <= 0.85;
    document.getElementById('btnFontPlus').disabled  = fontScale >= 1.40;
  }
  function setTheme(name){
    document.body.classList.remove('theme-sepia','theme-dark');
    theme = name;
    if (name === 'sepia') document.body.classList.add('theme-sepia');
    localStorage.setItem('rb_theme', theme);
  }
async function onTimeUpRB(){
  if (timeAlreadyUp) return;
  timeAlreadyUp = true;

  // compute reading time kung nasa reading pa siya
  ensureReadingSeconds();

  try {
    if ($timeUpModal) $timeUpModal.style.display = 'flex';

    // gamitin existing submitStoryAndNotify, pero hindi natin kailangan
    // hintayin ang "Continue" ng user – gagawa tayo ng separate silent save
    const flat = Object.entries(answers).map(([itemId, letter]) => ({
      item_id: Number(itemId),
      choice_label: String(letter || '').toUpperCase()
    }));

    const r = await fetch('rb_submit_story.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        attempt_id: <?= json_encode($attemptId) ?>,
        attempt_story_id: story?.attempt_story_id ?? null,
        story_id: story?.story_id,
        answers: flat,
        reading_seconds: Number(window.__rbLastReadingSecs || 0)
      })
    });
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');

    // tapos na yung story – kunin next, or done na
    if ($timeUpModal) $timeUpModal.style.display = 'none';

    // try load next unsubmitted story; kapag wala na, RB attempt complete
    await loadNextStory();
  } catch (e) {
    if ($timeUpModal) $timeUpModal.style.display = 'none';
    alert('Time is up but we could not save your work.\nPlease contact your teacher.\n\n' + e.message);
    // fallback: balik overview
    window.location.href = 'stories_rb.php';
  }
}

  function startCountdown(seconds){
    clearInterval(timerHandle);
    quizDeadline = null;
    timeAlreadyUp = false; // reset every story

    if (!seconds || seconds <= 0){
      $elapsed.title = 'Elapsed time'; // stopwatch mode
      const t0 = Date.now();
      timerHandle = setInterval(()=>{
        $elapsed.textContent = fmt((Date.now()-t0)/1000);
      }, 1000);
      return;
    }

    $elapsed.title = 'Remaining time';
    quizDeadline = Math.floor(Date.now()/1000) + seconds;
    timerHandle = setInterval(()=>{
      const left = quizDeadline - Math.floor(Date.now()/1000);
      $elapsed.classList.toggle('warn',   left <= 120 && left > 30);
      $elapsed.classList.toggle('danger', left <= 30);
      $elapsed.textContent = fmt(left);

      if (left <= 0){
        clearInterval(timerHandle);
        onTimeUpRB();   // ⬅️ IMBES NA $btnNext.click()
      }
    }, 300);
  }

function msToClock(sec){
  sec = Math.max(0, Math.floor(sec));
  const m = String(Math.floor(sec/60)).padStart(2,'0');
  const s = String(sec%60).padStart(2,'0');
  return `${m}:${s}`;
}
function ensureReadingSeconds(){
  if (typeof window.__rbLastReadingSecs === 'number') return;
  if (readingStart){
    const secs = Math.floor((Date.now() - readingStart)/1000);
    window.__rbLastReadingSecs = secs;
  } else {
    window.__rbLastReadingSecs = 0;
  }
}
  /* ----- Chunk helpers (like SLT) ----- */
  function chunkInfo() {
    const total = items.length;
    const start = Math.floor(qIdx / CHUNK_SIZE) * CHUNK_SIZE;
    const end   = Math.min(start + CHUNK_SIZE, total);
    const currentChunk = Math.floor(qIdx / CHUNK_SIZE) + 1;
    const totalChunks  = Math.ceil(total / CHUNK_SIZE);
    return { total, start, end, currentChunk, totalChunks };
  }
  function updateNextEnabled(){
    const { start, end } = chunkInfo();
    let ok = true;
    for (let i = start; i < end; i++){
      const it = items[i]; if (!it) continue;
      if (answers[it.item_id] == null){ ok = false; break; }
    }
    $btnNext.disabled = !ok;
  }

  /* ----- Rendering ----- */
  function showReading(){
    $title.textContent = story?.title || 'Story';

    // Trusted admin HTML
    $readPassage.innerHTML = story?.passage_html || '';
    if (story?.image){ $imgWrap.style.display=''; $readImage.src = story.image; }
    else { $imgWrap.style.display='none'; $readImage.removeAttribute('src'); }

    $readView.style.display = 'block';
    $quizView.style.display = 'none';
    $doneView.style.display = 'none';

    setTheme(theme); applyFontScale();
    $readPassage.scrollTop = 0; updateReadProgress();
    readingStart = Date.now();

  }

  function renderChunk(){
    const { total, start, end, currentChunk, totalChunks } = chunkInfo();
    const firstNo = start + 1, lastNo = end;

    $qContainer.innerHTML = '';
    $qIndex.textContent = `Set ${currentChunk}/${totalChunks} — Questions ${firstNo}–${lastNo} of ${total}`;
    $bar.style.width = `${(end/total)*100}%`;

    for (let i = start; i < end; i++){
      const it = items[i]; if (!it) continue;
      const qBadge = `Q${it.number || (i+1)}`;
      const block = document.createElement('div');
      block.className = 'qblock';
      block.innerHTML = `
        <div class="qtext" data-qbadge="${qBadge}">${it.question || ''}</div>
        <div class="opts"></div>`;
      const $opts = block.querySelector('.opts');

      // choices: array of {label,text}
      (Array.isArray(it.choices) ? it.choices : []).forEach((ch, k) => {
        const letter = (ch.label || String.fromCharCode(65+k)).toUpperCase();
        const text   = ch.text || ch.label || '';
        const wrap = document.createElement('label');
        wrap.className = 'opt';
        wrap.innerHTML = `
          <span class="letter">${letter}</span>
          <input type="radio" name="q_${it.item_id}" value="${letter}" aria-label="Choice ${letter}">
          <span>${text}</span>`;
        const inp = wrap.querySelector('input');
        if (answers[it.item_id] === letter) { inp.checked = true; wrap.classList.add('selected'); }
        wrap.addEventListener('click', () => {
          $opts.querySelectorAll('.opt').forEach(x=>x.classList.remove('selected'));
          wrap.classList.add('selected');
          answers[it.item_id] = letter;
          updateNextEnabled();
        });
        $opts.appendChild(wrap);
      });

      $qContainer.appendChild(block);
    }

    const onLast = (end >= total);
    $btnPrev.disabled = (start === 0);
    $btnNext.textContent = onLast ? 'Finish story' : 'Next set';
    updateNextEnabled();
  }
async function submitStoryAndNotify(){
  // flatten answers as letters (A/B/C/…)
  const flat = Object.entries(answers).map(([itemId, letter]) => ({
    item_id: Number(itemId),
    choice_label: String(letter || '').toUpperCase()
  }));

  const readingSeconds = Number(window.__rbLastReadingSecs || 0);

  const r = await fetch('rb_submit_story.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      attempt_id: <?= json_encode($attemptId) ?>,
      attempt_story_id: story?.attempt_story_id ?? null,
      story_id: story?.story_id,
      answers: flat,
      reading_seconds: readingSeconds
    })
  });
  const data = await r.json();
  if (!data.ok) throw new Error(data.error || 'Save failed');

  // Build summary (uniform with SLT style)
  const total = Number(data.total ?? 0);
  const score = Number(data.score ?? 0);
  const pct   = total ? Math.round((score/total)*100) : 0;
  const readS = Number(data.read_secs ?? readingSeconds ?? 0);

  let wpmLine = 'WPM: N/A (reading too short)';
  if (data.wpm != null && data.wpm !== false) {
    wpmLine = `WPM: ${Number(data.wpm)}`;
  }

  const $sum = document.getElementById('storySummary');
  $sum.innerHTML = `
    Score: <b>${score}/${total}</b> (${pct}%) • ${wpmLine} • Reading time: ${msToClock(readS)}
  `;

  const $det = document.getElementById('storySummaryDetails');
  $det.innerHTML = '';
  const wrong = Array.isArray(data.wrong_items) ? data.wrong_items : [];
  if (wrong.length) {
    const ul = document.createElement('ul');
    ul.style.margin = '8px 0 0';
    ul.style.paddingLeft = '18px';
    wrong.forEach(w => {
      const qno  = w.q_no ?? '?';
      const pick = (w.picked_label || '—').toString().toUpperCase();
      const corr = (w.correct_label || '—').toString().toUpperCase();
      const li = document.createElement('li');
      li.innerHTML = `Q${qno}: Your answer <b>${pick}</b>; correct <b>${corr}</b>`;
      ul.appendChild(li);
    });
    $det.appendChild(ul);
  } else if (score === total && total > 0) {
    $det.textContent = 'Great job! All correct.';
  }

  // show modal
  $storyDone.style.display = 'flex';
}

async function apiStartQuiz(){
  const payload = {
    attempt_id: attemptId,
    attempt_story_id: story?.attempt_story_id ?? null,
    story_id: story?.story_id ?? null
  };
  const r = await fetch('rb_quiz_start.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload),
    credentials:'same-origin'
  });
  const data = await r.json();
  if (!data.ok) throw new Error(data.error || 'Failed to start quiz.');
  // data.time_left is in SECONDS (null/0 => unlimited)
  return Number(data.time_left || 0);
}
function goQuiz(secondsLeft, options = {}){
  const skipTimer = options.skipTimer === true;

  // finalize reading time (reading-only duration)
  if (readingStart){
    const secs = Math.floor((Date.now() - readingStart)/1000);
    window.__rbLastReadingSecs = secs;
  } else {
    window.__rbLastReadingSecs = 0;
  }

  // one-way: clear passage and proceed
  $readPassage.innerHTML = '';
  $readView.style.display = 'none';
  $quizView.style.display  = 'block';
  $doneView.style.display  = 'none';

  // secondsLeft: authoritative from server
  if (!skipTimer){
    startCountdown(Number(secondsLeft || 0));
  }

  qIdx = 0;
  renderChunk();
}
  /* ----- Load one story (RB fetch returns first unsubmitted) ----- */
  async function loadNextStory(){
    // reset state
      story = null; items = []; answers = {}; qIdx = 0;
    clearInterval(timerHandle);
    $elapsed.textContent = '--:--';
    timeAlreadyUp = false;

    try{
      const r = await fetch(`rb_fetch.php?attempt_id=${encodeURIComponent(attemptId)}`, { credentials:'same-origin' });
      const data = await r.json();
        if (!data || data.ok !== true){
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.location.replace(`stories_rb_done.php?attempt_id=${attemptId}`);
        return;
  throw new Error(data && data.error ? data.error : 'Load failed');
}
story = data.story || null;
items = Array.isArray(data.items) ? data.items : [];

// refreshHeader(); // kung nagawa mo na ito sa previous step
$title.textContent = story?.title || 'Story';
// setLimitLabel(); // kung pinalitan na natin si setCrumb dati

if (story?.quiz_started) {
  // REFRESH SA GITNA NG QUIZ → gamitin remaining time galing server
  const left = Number(story.time_left || story.time_limit || 0);
  goQuiz(left); // may timer sa loob nito
} else {
  // BAGONG STORY → start full countdown agad (reading + quiz)
  startCountdown(Number(story?.time_limit || 0));
  showReading();
}


    }catch(e){
      alert('Could not load story: ' + e.message);
      location.href = 'stories_rb.php';
    }
  }

  /* ----- Events ----- */
  // reading controls
  document.getElementById('btnFontMinus').addEventListener('click', () => {
    fontScale = Math.max(0.85, (fontScale - 0.05)); localStorage.setItem('rb_fontScale', fontScale.toFixed(2)); applyFontScale();
  });
  document.getElementById('btnFontPlus') .addEventListener('click', () => {
    fontScale = Math.min(1.40, (fontScale + 0.05)); localStorage.setItem('rb_fontScale', fontScale.toFixed(2)); applyFontScale();
  });
  document.getElementById('btnThemeLight').addEventListener('click', () => setTheme('light'));
  document.getElementById('btnThemeSepia').addEventListener('click', () => setTheme('sepia'));
  $readPassage.addEventListener('scroll', updateReadProgress, { passive:true });
  window.addEventListener('resize', updateReadProgress);

  // image lightbox
  $readImage.addEventListener('click', () => {
    if (!$readImage.src) return;
    $lightboxImg.src = $readImage.src;
    $lightbox.style.display = 'flex';
  });
  $lightbox.addEventListener('click', () => { $lightbox.style.display = 'none'; $lightboxImg.removeAttribute('src'); });

  // confirm start
  $btnStartQuiz.addEventListener('click', () => { $confirm.style.display = 'flex'; });
  $confirmCancel.addEventListener('click', () => { $confirm.style.display = 'none'; });
  $confirm.addEventListener('click', (e) => { if (e.target === $confirm) $confirm.style.display = 'none'; });
$confirmProceed.addEventListener('click', () => {
  $confirm.style.display = 'none';
  // gamitin ang time limit galing sa rb_fetch (seconds; 0/undefined = walang limit)
  const left = Number(story?.time_limit || 0);
  goQuiz(left);
});

  // quiz nav
  $btnPrev.addEventListener('click', () => {
    const { start } = chunkInfo();
    if (start === 0) return;
    qIdx = Math.max(0, start - CHUNK_SIZE);
    renderChunk();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
$btnNext.addEventListener('click', async () => {
  const { total, end } = chunkInfo();
  const onLast = (end >= total);

  if (!onLast){
    qIdx = end;
    renderChunk();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  try {
    // this calls rb_submit_story.php and opens the “Story complete” modal
    await submitStoryAndNotify();
  } catch (e) {
    alert('Could not save this story. Please check your connection and try again.\n\n' + e.message);
    return;
  }
});

$storyNext.addEventListener('click', async () => {
  $storyDone.style.display = 'none';
  await loadNextStory(); // next unsubmitted story, or done
  window.scrollTo({ top: 0, behavior: 'instant' });
});
  // guard on refresh/leave during quiz
  function onBeforeUnload(e){
    if ($quizView.style.display === 'block'){ e.preventDefault(); e.returnValue = ''; }
  }
  window.addEventListener('beforeunload', onBeforeUnload);

  // keyboard shortcuts (A–D / 1–4) while in quiz
  document.addEventListener('keydown', (e) => {
    if ($quizView.style.display !== 'block') return;
    const letters = ['A','B','C','D'];
    let letter = null;
    if (['1','2','3','4'].includes(e.key)) letter = letters[Number(e.key)-1];
    const up = e.key?.toUpperCase();
    if (letters.includes(up)) letter = up;
    if (!letter) return;

    // choose the first visible question’s matching choice in the current chunk if present
    const radios = $qContainer.querySelectorAll(`input[type="radio"][value="${letter}"]`);
    if (radios[0]){
      const wrap = radios[0].closest('.opt');
      radios[0].checked = true;
      wrap.parentElement.querySelectorAll('.opt').forEach(x=>x.classList.remove('selected'));
      wrap.classList.add('selected');
      // store
      const qName = radios[0].getAttribute('name') || '';
      const itemId = Number(qName.replace(/^q_/, '')) || null;
      if (itemId) answers[itemId] = letter;
      updateNextEnabled();
    }
  });

  /* ----- Boot ----- */
  loadNextStory();
})();
</script>
<script>
// Exam protection script – Rate Builder (same as SLT, minus tab-logging for now)
(function() {
  // 1) Disable right-click
  document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
  });

  // 2) Disable text selection globally (backup, in case CSS is bypassed)
  document.addEventListener('selectstart', function(e) {
    const tag = (e.target.tagName || '').toLowerCase();
    // allow selection sa input/textarea
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

    // PrintScreen key
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

  // 5) Extra PrintScreen detection on keyup (ibang OS/browsers)
  document.addEventListener('keyup', function(e) {
    const key = (e.key || '').toLowerCase();
    if (key === 'printscreen' || e.keyCode === 44) {
      e.preventDefault();
      tryClearClipboard();
      alert('Screenshots are not allowed during the test.');
    }
  });

  // NOTE:
  // Yung pag-monitor ng tab switching (visibilitychange + fetch) ilalagay natin
  // sa STEP 3, para hiwalay na feature at hindi ka malito sa debugging.
})();
</script>

</body>
</html>
