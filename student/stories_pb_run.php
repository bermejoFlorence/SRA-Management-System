<?php
// student/stories_pb_run.php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$attemptId = (int)($_GET['aid'] ?? $_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) { header('Location: stories_pb.php'); exit; }

$PAGE_TITLE  = 'Power Builder — Story';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'pb';

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

/* Runner header (story title + pill) — copied from SLT for uniform look */
.run-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:8px 0 16px; padding:14px 18px;
  background:linear-gradient(180deg,#fff,#fefefe); border:1px solid #eef2ee;
  border-radius:14px; box-shadow:var(--shadow);
}
.run-head{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:8px 0 16px; padding:14px 18px;
  background:linear-gradient(180deg,#fff,#fefefe); border:1px solid #eef2ee;
  border-radius:14px; box-shadow:var(--shadow);
}

.run-head-main{
  display:flex;
  flex-direction:column;
  gap:4px;
}

.run-title{
  margin:0; font-weight:900; letter-spacing:.2px; color:var(--g);
  font-size:clamp(1.2rem,1rem + 1vw,1.6rem);
}

/* NEW: author line under title */
.run-author{
  margin:0;
  font-size:.95rem;
  color:var(--muted);
  font-weight:600;
}

.pill{ background:#eff3ef; color:#1b3a1b; border:1px solid #d9e3d9;
  border-radius:999px; padding:6px 10px; font-weight:700; font-size:.9rem; }

/* Cards shared with SLT */
.read-card, .quiz-card{
  background:var(--card); border:1px solid #eef2ee; border-radius:16px;
  box-shadow:0 8px 24px rgba(0,0,0,.06); padding:clamp(16px,2.4vw,24px); margin:0 0 16px;
}

/* Reading layout / typography — same as SLT */
.read-grid{
  display:grid; grid-template-columns:minmax(0, 2fr) minmax(240px, 1fr);
  gap:20px; align-items:start;
}
.read-passage{
  font-size:clamp(1.05rem, 0.95rem + 0.6vw, 1.35rem);
  line-height:1.7; letter-spacing:.2px; background:#fffefc;
  border:1px solid #efe9da; border-radius:14px; padding:clamp(16px, 2.2vw, 22px); color:#1f2a1f;
  max-width:65ch; height:min(70vh, 720px); overflow:auto;
}
.read-passage p{ margin:0 0 1em; }
.read-passage p:last-child{ margin-bottom:0; }
.read-note{ color:var(--muted); font-size:.95rem; margin-top:10px; }
.read-toolbar{ display:flex; align-items:center; gap:10px; justify-content:flex-end; margin:0 0 12px; }
.rt-btn{ border:1px solid #dfe6df; background:#fff; color:#1f3a1f; border-radius:10px; padding:8px 10px; cursor:pointer; font-weight:700; min-width:36px; }
.rt-btn:disabled{ opacity:.6; cursor:not-allowed; }

.theme-sepia .read-passage{ background:#fff7e6; border-color:#f0e2c6; color:#2a251a; }
.theme-dark  .read-passage{ background:#111; border-color:#222; color:#e7e7e7; }
.theme-dark  .read-note  { color:#a5b4a5; }

/* Image */
.read-img{ width:100%; border-radius:12px; border:1px solid var(--line); object-fit:cover; max-height:420px; background:#f5f5f5; }
.read-img.zoomable{ cursor:zoom-in; }
.lightbox{ position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:9999; }
.lightbox img{ max-width:95vw; max-height:92vh; border-radius:12px; }

/* Reading progress bar */
.read-progress{ height:6px; background:#eee; border-radius:999px; overflow:hidden; margin:8px 0 14px; }
.read-progress > span{ display:block; height:100%; width:0%; background:linear-gradient(90deg, var(--acc), #ffd37a); }

/* Quiz bits — same visual language as SLT */
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

/* Inputs for text / text_bank */
.fillin{ width:100%; max-width:520px; padding:10px 12px; border:1px solid #dfe6df; border-radius:10px; font-weight:600; }
.banksel{ padding:10px 12px; border:1px solid #dfe6df; border-radius:10px; font-weight:700; }

/* Footers */
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
/* ==== PB: sizing tweaks for header/instructions/questions ==== */

/* header pill: Well, Did You Read? — Questions … */
#quizView #qIndex.pill{
  font-size: clamp(1.02rem, 0.95rem + 0.75vw, 1.28rem);
  padding: 10px 16px;
  letter-spacing: .2px;
  font-weight: 900;
}

/* instructions line just below the progress bar */
#quizView #qNote{
  font-size: clamp(1rem, 0.95rem + 0.45vw, 1.18rem);
  font-weight: 700;
  color: #213421; /* darker green for emphasis */
  margin: 8px 0 10px;
}

/* question title (Q1 …) a bit smaller than before */
#quizView .qtext{
  font-size: clamp(1rem, 0.95rem + 0.40vw, 1.25rem);
  padding: 12px 16px 12px 48px;  /* slightly tighter left padding */
}

/* smaller circular Q-badge */
#quizView .qtext::before{
  width: 28px;
  height: 28px;
  font-size: .95rem;
}
/* Disable text selection for exam area (PB) */
#exam-content {
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

</style>

<div class="main-content" id="exam-content">
  <div class="slt-wrap">

    <!-- Header -->
<section class="run-head" aria-live="polite">
  <div class="run-head-main">
    <h1 id="storyTitle" class="run-title">Loading…</h1>
    <!-- NEW: author line (hidden by default) -->
    <p id="storyAuthor" class="run-author" style="display:none;">by …</p>
  </div>
  <div class="slt-meta">
    <!-- Itinago lang ang Story at elapsed para di na makita -->
    <span id="crumb" class="pill" style="display:none;">Story</span>
    <span id="elapsed" class="pill" title="Elapsed time" style="display:none;">00:00</span>

    <!-- ITO NA LANG ANG MAKIKITA: time limit / countdown -->
    <span id="limit" class="pill" title="Time limit" style="display:none;"></span>
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

    <!-- Confirm start quiz -->
    <div id="confirmStart" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" style="display:none;">
      <div class="modal-card">
        <h3 id="confirmTitle">Start Quiz?</h3>
        <p class="modal-text">
          • <strong>You cannot return to the passage</strong> after you start the quiz.<br>
          • If you <strong>refresh or leave</strong>, this story restarts from the beginning.
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
        <span id="qIndex" class="pill">Questions</span>
      </div>
      <div class="progress" aria-hidden="true"><span id="bar" style="width:0%"></span></div>
      <div id="qNote" class="read-note" style="margin:8px 0 0;"></div>
      <div id="qList"></div>
      <div class="slt-footer">
        <button id="btnPrev" class="btn-ghost">Back</button>
        <button id="btnNext" class="btn" disabled>Next</button>
      </div>
      <div class="read-note">Use Back/Next to move between pages.</div>

    </section>

    <!-- Story complete modal -->
    <div id="storyDone" class="modal" role="dialog" aria-modal="true" style="display:none;">
      <div class="modal-card">
        <h3>Story complete</h3>
        <p class="modal-text">Your answers for this story have been saved.</p>
        <div class="modal-actions">
          <a href="stories_pb_start.php?aid=<?= (int)$attemptId ?>&next=1" id="storyNext" class="btn">Continue</a>
        </div>
      </div>
    </div>

    <!-- Lightbox -->
    <div id="lightbox" class="lightbox" role="dialog" aria-modal="true">
      <img id="lightboxImg" alt="Zoomed image">
    </div>

  </div>
</div>

<script>
  let canLeave = false;
function beforeUnloadHandler(e){
  if (!canLeave){
    e.preventDefault();
    e.returnValue = '';
  }
}
(() => {
  const attemptId = <?= json_encode($attemptId) ?>;

  /* ------------ STATE ------------ */
  let story = null;                 // { story_id, title, passage_html, image }
  let items = [];                   // full item list (READ first → LAW after), numbered 1..N
  let answers = {};                 // { item_id: value } value can be number|string
  let groups = [];        // [{ items:[…], meta:{title,note}, firstNo, lastNo }]
let gIdx = 0;           // current group index
let itemsAll = [];      // flat list for total N + global numbering
  let readingStart = null;          // stopwatch
  let metaPB = {};   // <-- dito natin ise-store ang directions galing sa pb_fetch
  let timeUpHandled = false;
  /* ------------ DOM ------------ */
  const $title   = document.getElementById('storyTitle');
  const $author  = document.getElementById('storyAuthor'); // NEW
  const $crumb   = document.getElementById('crumb');
  const $elapsed = document.getElementById('elapsed');

  const $readView = document.getElementById('readView');
  const $readPass = document.getElementById('readPassage');
  const $readImg  = document.getElementById('readImage');
  const $imgWrap  = document.getElementById('imgWrap');
  const $readProg = document.getElementById('readProg');
  const $btnStart = document.getElementById('btnStartQuiz');

  const $quizView = document.getElementById('quizView');
  const $qIndex   = document.getElementById('qIndex');
  const $bar      = document.getElementById('bar');
  const $qList    = document.getElementById('qList');
  const $btnPrev  = document.getElementById('btnPrev');
  const $btnNext  = document.getElementById('btnNext');

  const $confirm  = document.getElementById('confirmStart');
  const $confirmCancel  = document.getElementById('confirmCancel');
  const $confirmProceed = document.getElementById('confirmProceed');

  const $lightbox = document.getElementById('lightbox');
  const $lightImg = document.getElementById('lightboxImg');

  const $done = document.getElementById('storyDone');
  const $limit = document.getElementById('limit');
  let passageWordCount = 0;  // bilang ng salita sa passage (for WPM)
let readingSecs = 0;       // reading time lang (hindi kasama ang quiz)


  /* ------------ UTILS ------------ */
  const fmtClock = (s) => {
    const t = Math.max(0, Math.floor(s));
    const m = Math.floor((t/60)).toString().padStart(2,'0');
    const ss= (t%60).toString().padStart(2,'0');
    return `${m}:${ss}`;
  };
function escapeHTML(s){
  return (s==null?'':String(s))
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function wordCountFromHTML(html){
  const d = document.createElement('div');
  d.innerHTML = html || '';
  const txt = (d.textContent || '').trim();
  if (!txt) return 0;
  return txt.split(/\s+/).filter(Boolean).length;
}

  function startStopwatch(){
    const started = Date.now();
    setInterval(()=>{ $elapsed.textContent = fmtClock((Date.now()-started)/1000); }, 1000);
  }

  function readProgress(){
    const el = $readPass;
    const total = el.scrollHeight - el.clientHeight;
    const pct = total <= 0 ? 0 : Math.max(0, Math.min(100, (el.scrollTop/total)*100));
    $readProg.style.width = pct.toFixed(0) + '%';
  }

  function rankSection(code){
    // READ first (0), everything else after (1) — Learn About Words (vocab/wordstudy)
    return (code === 'read') ? 0 : 1;
  }

  function sortItems(raw){
    // sort by READ first, then by section, sub_label, then number/sequence
    return [...raw].sort((a,b)=>{
      const ra = rankSection(a.section_code || ''), rb = rankSection(b.section_code || '');
      if (ra !== rb) return ra - rb;
      const sa = (a.section_code || '').localeCompare(b.section_code || '');
      if (sa !== 0) return sa;
      const la = (a.sub_label || '').localeCompare(b.sub_label || '');
      if (la !== 0) return la;
      const na = (a.number || a.sequence || 0) - (b.number || b.sequence || 0);
      return na;
    }).map((x,i)=>({ ...x, __qno: i+1 })); // continuous numbering 1..N
  }

function currentGroup(){ return groups[gIdx] || { items:[], meta:{title:'',note:''}, firstNo:0, lastNo:0 }; }


function updateNextEnabled(){
  const g = currentGroup();
  let ok = true;
  for (const it of g.items){
    const v = answers[it.item_id];
    if (v == null || v === '') { ok = false; break; }
  }
  $btnNext.disabled = !ok;
}

function secOrder(s){
  // READ first (0), VOCAB next (1), WORDSTUDY last (2), then others
  if (s === 'read') return 0;
  if (s === 'vocab') return 1;
  if (s === 'wordstudy') return 2;
  return 9;
}

// 2a) sort + global renumber 1..N
function sortAndNumber(raw){
  const arr = [...raw].sort((a,b)=>{
    const r = secOrder(a.section_code||'') - secOrder(b.section_code||'');
    if (r) return r;
    const s = (a.section_code||'').localeCompare(b.section_code||'');
    if (s) return s;
    const l = (a.sub_label||'').localeCompare(b.sub_label||'');
    if (l) return l;
    return (a.number||a.sequence||0) - (b.number||b.sequence||0);
  });
  return arr.map((x,i)=>({ ...x, __qno: i+1 }));
}

// ==== DIRECTIONS helper ====
function getDirections(sectionCode, subLabel){
  const m = metaPB || {};
  if (sectionCode === 'read') {
    return (m.read && m.read.directions) || '';
  }
  if (sectionCode === 'vocab') {
    return (m.vocab && m.vocab[subLabel] && m.vocab[subLabel].directions) || '';
  }
  if (sectionCode === 'wordstudy') {
    return (m.wordstudy && m.wordstudy[subLabel] && m.wordstudy[subLabel].directions) || '';
  }
  return '';
}

function metaFor(first){
  const sec = first.section_code;
  const L   = first.sub_label || '';
  const dir = getDirections(sec, L);   // ← kunin mula sa meta

  if (sec === 'read') {
    return {
      title: 'Well, Did You Read?',
      note:  dir || 'Answer Yes or No based on the story.'
    };
  }
  if (sec === 'vocab') {
    return {
      title: `Vocabulary — Set ${L}`,
      note:  dir || 'Choose the best meaning for each word.'
    };
  }
  if (sec === 'wordstudy') {
    const banky = (first.item_type === 'text_bank' || first.item_type === 'bank');
    return {
      title: `Word Study — Set ${L}`,
      note:  dir || (banky ? 'Use the word bank to complete each sentence.'
                           : 'Type the correct word or part.')
    };
  }
  return { title: 'Questions', note: (dir || '') };
}

// 2c) build grouped pages from sorted items
function buildGroups(sorted){
  const out = [];
  let curKey = null, cur = [];
  for (const it of sorted){
    const key = `${it.section_code}|${it.sub_label||''}`;
    if (key !== curKey){
      if (cur.length) out.push(cur);
      cur = [];
      curKey = key;
    }
    cur.push(it);
  }
  if (cur.length) out.push(cur);

  // expand to objects with meta + range
  return out.map(g => {
    const m = metaFor(g[0]);
    const firstNo = g[0].__qno;
    const lastNo  = g[g.length-1].__qno;
    return { items: g, meta: m, firstNo, lastNo };
  });
}
// 🔁 REPLACE your current ensureBankWords with this version
function ensureBankWords(arr){
  arr.forEach(it=>{
    if (it.item_type === 'text_bank' || it.item_type === 'bank') {

      // 1) try: per-item answer_key.bank (kung meron)
      if (!Array.isArray(it.bank_words) || it.bank_words.length === 0){
        const ak = it.answer_key || {};
        if (ak && Array.isArray(ak.bank)) it.bank_words = [...ak.bank];
      }

      // 2) fallback: kunin sa metaPB per section + set label
      if ((!it.bank_words || it.bank_words.length === 0) && metaPB){
        const L = it.sub_label || '';
        let list = null;
        if (it.section_code === 'vocab') {
          list = metaPB.vocab && metaPB.vocab[L] && metaPB.vocab[L].bank;
        } else if (it.section_code === 'wordstudy') {
          list = metaPB.wordstudy && metaPB.wordstudy[L] && metaPB.wordstudy[L].bank;
        }
        if (Array.isArray(list)) it.bank_words = [...list];
      }
    }
  });
}
function applyAuthor(){
  if (!$author) return;
  // depende sa ibinabalik ng pb_fetch.php:
  const au = story?.author || story?.author_name || '';
  if (au){
    $author.textContent = 'by ' + au;
    $author.style.display = '';
  } else {
    $author.style.display = 'none';
  }
}

  /* ------------ RENDER: Reading ------------ */
  function showReading(){
    $title.textContent = story?.title || 'Story';
      applyAuthor();     
    $crumb.textContent = 'Story';
    $readPass.innerHTML = story?.passage_html || '';
    if (story?.image) { $imgWrap.style.display = ''; $readImg.src = story.image; }
    else { $imgWrap.style.display = 'none'; $readImg.removeAttribute('src'); }

    $readView.style.display = 'block';
    $quizView.style.display = 'none';
    readingStart = Date.now();
    $readPass.scrollTop = 0; readProgress();
  }

  /* ------------ RENDER: Quiz ------------ */
function renderGroup(){
  const g = currentGroup();
  const totalN = itemsAll.length;

  // Header line (pill) + progress
  $qList.innerHTML = '';
  $qIndex.textContent = `${g.meta.title} — Questions ${g.firstNo}–${g.lastNo} of ${totalN}`;
  $bar.style.width = `${(g.lastNo/totalN)*100}%`;
  document.getElementById('qNote').textContent = g.meta.note || '';

  // Build the questions for this group
  g.items.forEach(it=>{
    const qBlock = document.createElement('div');
    qBlock.className = 'qblock';
    qBlock.innerHTML = `<div class="qtext" data-qbadge="Q${it.__qno}">${it.question_text || it.question || ''}</div>`;
    const optsWrap = document.createElement('div');
    optsWrap.className = 'opts';

    const type = it.item_type;
    if (type === 'single' || type === 'ab' || type === 'tf' || type === 'yn') {
  const chs = Array.isArray(it.choices) ? it.choices : [];
  chs.forEach((c,k)=>{
    const label = (c.label != null) ? String(c.label) : String.fromCharCode(65+k);
    const text  = c.text ?? c.choice_text ?? c.label ?? '';
        const row   = document.createElement('label');
        row.className = 'opt';
        row.innerHTML = `
          <span class="letter">${label}</span>
          <input type="radio" name="q_${it.item_id}" value="${k}">
          <span>${text}</span>`;
        const radio = row.querySelector('input');
        if (answers[it.item_id] === k) { radio.checked = true; row.classList.add('selected'); }
        row.addEventListener('click', ()=>{
          optsWrap.querySelectorAll('.opt').forEach(x=>x.classList.remove('selected'));
          row.classList.add('selected');
          answers[it.item_id] = k;
          updateNextEnabled();
        });
        optsWrap.appendChild(row);
      });
    } else if (type === 'text'){
      const inp = document.createElement('input');
      inp.type = 'text'; inp.className = 'fillin';
      inp.value = (answers[it.item_id] || '');
      inp.placeholder = 'Type your answer…';
      inp.addEventListener('input', ()=>{
        answers[it.item_id] = inp.value.trim();
        updateNextEnabled();
      });
      optsWrap.appendChild(inp);
    } else if (type === 'text_bank' || type === 'bank'){
      const sel = document.createElement('select');
      sel.className = 'banksel';
      const bank = Array.isArray(it.bank_words) ? it.bank_words : [];
      sel.innerHTML = `<option value="">— choose —</option>` + bank.map(w=>`<option>${w}</option>`).join('');
      if (answers[it.item_id]) sel.value = answers[it.item_id];
      sel.addEventListener('change', ()=>{ answers[it.item_id] = sel.value; updateNextEnabled(); });
      optsWrap.appendChild(sel);
    } else {
      const inp = document.createElement('input');
      inp.type = 'text'; inp.className = 'fillin'; inp.placeholder = 'Your answer…';
      inp.addEventListener('input', ()=>{ answers[it.item_id] = inp.value.trim(); updateNextEnabled(); });
      optsWrap.appendChild(inp);
    }

    qBlock.appendChild(optsWrap);
    $qList.appendChild(qBlock);
  });

  // nav labels
  $btnPrev.disabled = (gIdx === 0);
  $btnNext.textContent = (gIdx >= groups.length - 1) ? 'Finish story' : 'Next page';

  updateNextEnabled();
}

  /* ------------ EVENTS ------------ */
  // reading controls
  let fontScale = parseFloat(localStorage.getItem('pb_fontScale') || '1.0');
  function applyFont(){ $readPass.style.fontSize = `calc(1em * ${fontScale})`; }
  document.getElementById('btnFontMinus').addEventListener('click', ()=>{
    fontScale = Math.max(0.85, fontScale - 0.05);
    localStorage.setItem('pb_fontScale', fontScale.toFixed(2)); applyFont();
  });
  document.getElementById('btnFontPlus').addEventListener('click', ()=>{
    fontScale = Math.min(1.4, fontScale + 0.05);
    localStorage.setItem('pb_fontScale', fontScale.toFixed(2)); applyFont();
  });
  function setTheme(name){
    document.body.classList.remove('theme-sepia','theme-dark');
    if (name==='sepia') document.body.classList.add('theme-sepia');
  }
  document.getElementById('btnThemeLight').addEventListener('click', ()=>setTheme('light'));
  document.getElementById('btnThemeSepia').addEventListener('click', ()=>setTheme('sepia'));

  $readPass.addEventListener('scroll', readProgress, {passive:true});
  window.addEventListener('resize', readProgress);

  // image lightbox
  $readImg.addEventListener('click', ()=>{
    if (!$readImg.src) return; $lightImg.src = $readImg.src; $lightbox.style.display='flex';
  });
  $lightbox.addEventListener('click', ()=>{ $lightbox.style.display='none'; $lightImg.removeAttribute('src'); });

  // start quiz confirm
  $btnStart.addEventListener('click', ()=>{ $confirm.style.display='flex'; });
  $confirmCancel.addEventListener('click', ()=>{ $confirm.style.display='none'; });
  $confirm.addEventListener('click', (e)=>{ if (e.target===$confirm) $confirm.style.display='none'; });
  $confirmProceed.addEventListener('click', ()=>{ $confirm.style.display='none'; goQuiz(); });

  // quiz nav
$btnPrev.addEventListener('click', ()=>{
  if (gIdx <= 0) return;
  gIdx -= 1;
  renderGroup();
  window.scrollTo({ top: 0, behavior: 'smooth' });
});
$btnNext.addEventListener('click', ()=>{
  if (gIdx < groups.length - 1){
    gIdx += 1;
    renderGroup();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }
 // finished this story; submit answers then show recap
submitAnswers();

});
function startCountdown(limitSecs){
  const secs = Number(limitSecs || 0);
  if (!$limit) return;

  // walang limit → display lang
  if (secs <= 0){
    $limit.style.display = '';
    $limit.textContent = 'No time limit';
    return;
  }

  let end = Date.now() + secs * 1000;
  $limit.style.display = '';

  const tick = () => {
    const left = Math.max(0, Math.round((end - Date.now()) / 1000));
    $limit.textContent = 'Time left ' + fmtClock(left);

    if (left === 0) {
      clearInterval(tid);
      onTimeUpPB();   // 🔔 dito na natin ia-auto-submit
    }
  };

  tick();
  const tid = setInterval(tick, 1000);
}
  // keyboard shortcuts for MC (A–D, 1–4)
  document.addEventListener('keydown', (e)=>{
    if ($quizView.style.display !== 'block') return;
    const radios = $qList.querySelectorAll('input[type=radio]');
    if (!radios.length) return;
    let n = null;
    if (['1','2','3','4'].includes(e.key)) n = Number(e.key)-1;
    const k = e.key?.toUpperCase();
    if (['A','B','C','D'].includes(k)) n = k.charCodeAt(0)-65;
    if (n!=null && radios[n]){
      radios[n].checked = true;
      // visually mark the wrapper
      const opt = radios[n].closest('.opt');
      opt?.parentElement?.querySelectorAll('.opt')?.forEach(x=>x.classList.remove('selected'));
      opt?.classList.add('selected');
      // find item_id via name
      const nm = radios[n].name; // q_<item_id>
      const itemId = Number(nm.slice(2));
      answers[itemId] = n;
      updateNextEnabled();
    } else if (e.key === 'Enter' && !$btnNext.disabled) {
      $btnNext.click();
    }
  });

function goQuiz(){
  // one-way reading: clear passage so refresh always starts over
  readingSecs = Math.max(0, Math.round((Date.now() - readingStart)/1000));
  $readPass.innerHTML = '';
  $readView.style.display = 'none';
  $quizView.style.display = 'block';
  gIdx = 0;                // ✅ current group
  renderGroup();           // ✅ render the group page
}
async function submitAnswers(){
  try{
    const payload = {
      attempt_id: attemptId,
      story_id: story?.story_id || (itemsAll[0]?.story_id ?? 0),
      answers: answers,                 // { item_id: value } – index/letter/string
      reading_secs: readingSecs,        // reading time lang
      passage_words: passageWordCount   // for WPM
    };

    const resp = await fetch('pb_submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (!res.ok) throw new Error(res.error || 'Submit failed');

    showSummary(res);
  } catch (err){
    alert('Submit failed: ' + err.message);
  }
}
async function onTimeUpPB(){
  if (timeUpHandled) return;
  timeUpHandled = true;

  // kung nasa reading pa at wala pang readingSecs, kuhanin na natin
  if (readingStart && readingSecs === 0) {
    readingSecs = Math.max(0, Math.round((Date.now() - readingStart)/1000));
  }

  alert('Time is up for this story. We will save any answers you have.');

  try {
    await submitAnswers();
  } catch (err) {
    alert('Submit failed after time up: ' + err.message);
  }
}

function showSummary(res){
  const correct = res.score?.correct ?? 0;
  const total   = res.score?.total ?? 0;
  const pct     = res.score?.percent ?? 0;

  const secs = res.reading?.secs ?? 0;
  const wpm  = res.reading?.wpm;
  const wpmPart = (wpm && Number.isFinite(wpm))
      ? `• WPM: ${wpm}`
      : `• WPM: N/A (${escapeHTML(res.reading?.note || '—')})`;
  const timePart = `• Reading time: ${fmtClock(secs)}`;

  const recap = Array.isArray(res.recap) ? res.recap : [];
  const listItems = recap.length
    ? recap.map(d =>
        `<li>Q${d.qno}: Your answer <b>${escapeHTML(d.your || '—')}</b>; ` +
        `correct <b>${escapeHTML(d.correct || '—')}</b></li>`
      ).join('')
    : `<li>Great job! All answers correct.</li>`;

  const html = `
    <h3>Story complete</h3>
    <p class="modal-text">Your answers for this story have been saved.</p>
    <p class="modal-text"><strong>Score: ${correct}/${total} (${pct}%)</strong> ${wpmPart} ${timePart}</p>
    <ul class="modal-text" style="margin-left:1em;">${listItems}</ul>
    <div class="modal-actions">
      <a href="stories_pb_start.php?aid=${encodeURIComponent(attemptId)}&next=1" class="btn">Continue</a>
    </div>
  `;

  const card = document.querySelector('#storyDone .modal-card');
  if (card) card.innerHTML = html;
  document.getElementById('storyDone').style.display = 'flex';
}

function showSummary(res){
  const correct = res.score?.correct ?? 0;
  const total   = res.score?.total ?? 0;
  const pct     = res.score?.percent ?? 0;

  const secs = res.reading?.secs ?? 0;
  const wpm  = res.reading?.wpm;
  const wpmPart  = (wpm && Number.isFinite(wpm)) ? `• WPM: ${wpm}` 
                                                 : `• WPM: N/A (${escapeHTML(res.reading?.note || '—')})`;
  const timePart = `• Reading time: ${fmtClock(secs)}`;

  const recap = Array.isArray(res.recap) ? res.recap : [];
  const listItems = recap.length
    ? recap.map(d =>
        `<li>Q${d.qno}: Your answer <b>${escapeHTML(d.your || '—')}</b>; correct <b>${escapeHTML(d.correct || '—')}</b></li>`
      ).join('')
    : `<li>Great job! All answers correct.</li>`;

  const html = `
    <h3>Story complete</h3>
    <p class="modal-text">Your answers for this story have been saved.</p>
    <p class="modal-text"><strong>Score: ${correct}/${total} (${pct}%)</strong> ${wpmPart} ${timePart}</p>
    <ul class="modal-text" style="margin-left:1em;">${listItems}</ul>
    <div class="modal-actions">
      <a id="storyNext" href="stories_pb_start.php?aid=${encodeURIComponent(attemptId)}&next=1" class="btn">Continue</a>
    </div>
  `;

  const card = document.querySelector('#storyDone .modal-card');
  if (card) card.innerHTML = html;
  document.getElementById('storyDone').style.display = 'flex';

  // ✅ payagan na ang navigation (alisin ang prompt)
  canLeave = true;
  window.removeEventListener('beforeunload', beforeUnloadHandler);

  // safety: kung ma-click ang Continue, siguradong walang prompt
  document.getElementById('storyNext')?.addEventListener('click', () => {
    canLeave = true;
    window.removeEventListener('beforeunload', beforeUnloadHandler);
  });
}
 /* ------------ BOOT ------------ */
  (async function init(){
    try{

       window.addEventListener('beforeunload', beforeUnloadHandler);
      const r = await fetch(`pb_fetch.php?attempt_id=${encodeURIComponent(attemptId)}`);
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'Fetch failed');

      story = data.story || {};
      passageWordCount = wordCountFromHTML(story.passage_html);
      metaPB = data.meta || {};   // <-- ito ang directions per section/set
      applyAuthor();   // NEW

let raw = Array.isArray(data.items) ? data.items : [];

// (optional) kunin ang word bank mula answer_key kung available
ensureBankWords(raw);
startCountdown(story.time_limit || 0);

// sort + global numbers
itemsAll = sortAndNumber(raw);

// group by section + set label
groups = buildGroups(itemsAll);

$title.textContent = story?.title || 'Story';
$crumb.textContent = 'Story';
startStopwatch();
applyFont();
showReading();

    } catch (err){
      alert('Cannot load this story: ' + err.message);
      location.href = 'stories_pb.php';
    }
  })();
})();
</script>
<script>
// Exam protection script – PB version
(function() {
  // 1) Disable right-click
  document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
  });

  // 2) Disable text selection (backup kahit may CSS na)
  document.addEventListener('selectstart', function(e) {
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea') return; // allow typing
    e.preventDefault();
  });

  // 3) Try to clear clipboard (for PrintScreen / copy)
  function tryClearClipboard() {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText('Screenshots and copying are not allowed during the test.')
        .catch(function() {});
    } else {
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

      // Ctrl+Shift+I / J / C (DevTools shortcuts)
      if (e.shiftKey && ['i','j','c'].includes(key)) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }
    }
  });

  // 5) Extra PrintScreen detection on keyup
  document.addEventListener('keyup', function(e) {
    const key = (e.key || '').toLowerCase();
    if (key === 'printscreen' || e.keyCode === 44) {
      e.preventDefault();
      tryClearClipboard();
      alert('Screenshots are not allowed during the test.');
    }
  });
})();
</script>

</body>
</html>
