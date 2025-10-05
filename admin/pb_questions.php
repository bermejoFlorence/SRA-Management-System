<?php
//admin/pb_questions.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$set_id   = isset($_GET['set_id'])   ? (int)$_GET['set_id']   : 0;
$story_id = isset($_GET['story_id']) ? (int)$_GET['story_id'] : 0;
if ($set_id <= 0 || $story_id <= 0) { header('Location: stories_pb.php'); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* Fetch story for title + optional saved notes JSON */
$story = ['title' => 'Story', 'notes' => null];
if ($stmt = $conn->prepare("SELECT title, notes FROM stories WHERE story_id=? AND set_id=? LIMIT 1")) {
  $stmt->bind_param('ii', $story_id, $set_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $story = $row;
  $stmt->close();
}
$story_title = $story['title'] ?? 'Story';
$notes_json  = $story['notes'] ?? null;

$PAGE_TITLE  = 'PB – Questions';
$ACTIVE_MENU = 'stories';
$ACTIVE_SUB  = 'pb';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
/* ===== Frame ===== */
.qpage{ padding-top:60px; }
.qpage .page-wrap{ width:min(1280px, 96vw); margin:10px auto 22px; }

/* ===== Header ===== */
.q-head{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin:6px 0 12px; }
.q-head h3{ margin:0; color:#0d442a; font-weight:800; letter-spacing:.02em; }
.back-pill{
  display:inline-flex; align-items:center; gap:10px; padding:10px 16px; border-radius:12px;
  background:#eaf7ea; border:1px solid #bfe3c6; color:#0d442a; font-weight:800; text-decoration:none;
}
.back-pill i{ width:20px; height:20px; display:inline-grid; place-items:center; background:#d4edda; border-radius:999px; }

/* ===== Tabs ===== */
.tabs-row{
  display:flex; align-items:center; justify-content:flex-start; gap:8px;
  background:#fff; padding:10px 12px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,.06);
  border-left:6px solid #ECA305; margin-bottom:12px;
}
.tab-btn{
  border:1px solid #dfe5e8; background:#fff; color:#143; padding:8px 14px; border-radius:999px; cursor:pointer; font-weight:700;
}
.tab-btn:hover{ box-shadow:0 1px 6px rgba(0,0,0,.06); }
.tab-btn.active{ background:#0d442a; border-color:#0d442a; color:#fff; }

/* ===== Generic panes/sections ===== */
.pane{ display:none; }
.pane.show{ display:block; }

.section-bar{
  background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.06); padding:12px; margin-bottom:10px;
  display:grid; gap:12px;
}
.section-row{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.section-row b{ color:#0d2c20; }

/* Pretty select (pill) */
.select-pill{ position:relative; display:inline-block; }
.select-pill select{
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
  border:1px solid #dfe5e8; background:#fff; color:#0d2c20; padding:9px 36px 9px 14px;
  border-radius:999px; font-weight:800; letter-spacing:.01em;
}
.select-pill:after{
  content:""; position:absolute; right:12px; top:50%; transform:translateY(-30%);
  border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid #0d2c20;
}

/* Subtitles inside Learn About Words */
.sub-h2{ margin:2px 0 0; font-size:1.05rem; font-weight:900; color:#0d2c20; }
.subnote{ font-size:.9rem; color:#5c7480; }

/* Directions */
.directions textarea{
  width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:12px; min-height:64px; resize:vertical;
}

/* ===== Word bank ===== */
.bank-wrap{ display:flex; gap:8px; flex-wrap:wrap; }
.bank-chip{ background:#eef3ff; border:1px solid #cad5fb; padding:6px 10px; border-radius:999px; font-weight:700; }
.bank-chip .x{ margin-left:6px; cursor:pointer; }
.bank-row{ display:grid; gap:6px; }
.warn{ color:#9a3e00; background:#fff7e6; border:1px solid #ffecb3; padding:8px 10px; border-radius:10px; }

/* ===== Items ===== */
.card{ background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.06); overflow:hidden; }
.items-wrap{ padding:10px; }

.item-card{
  border:1px solid #eef2f4; border-radius:12px; padding:12px; margin:10px 0; background:#fafcfd;
}
.item-top{ display:flex; align-items:center; gap:12px; }
.num-badge{
  width:34px; height:34px; border-radius:999px; display:grid; place-items:center; font-weight:800;
  background:#eef7ff; border:1px solid #cfe3ff; color:#1b3a7a;
}
.item-top input[type="text"]{
  flex:1; padding:10px 12px; border:1px solid #dfe5e8; border-radius:12px; font-weight:600;
}
.item-top .right{ display:flex; gap:8px; }
.btn-del{
  background:#fdecea; border:1px solid #f5c6cb; color:#b71c1c; padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:700;
}

/* Correct answer + choices */
.answer-bar{ display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap; }
.answer-bar b{ color:#0d2c20; }
.mini-select{ position:relative; display:inline-block; }
.mini-select select{
  appearance:none; border:1px solid #dfe5e8; background:#fff; padding:8px 34px 8px 12px; border-radius:10px; font-weight:700;
}
.mini-select:after{
  content:""; position:absolute; right:10px; top:50%; transform:translateY(-30%);
  border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid #0d2c20;
}

/* Grid choices */
.choice-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:10px; }
.choice-col{ display:flex; align-items:center; gap:12px; }
.choice-col .letter{ font-weight:800; color:#0d2c20; min-width:18px; text-align:right; }
.choice-col input[type="text"]{ flex:1; padding:10px 12px; border:1px solid #dfe5e8; border-radius:12px; }

/* Fill-in accepted answers */
.tokens{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
.token{ background:#eef3ff; border:1px solid #cad5fb; padding:6px 10px; border-radius:999px; font-weight:700; }
.token .x{ margin-left:6px; cursor:pointer; }
.tag-note{ font-size:.9rem; color:#6b8b97; }

/* Add item */
.add-wrap{ display:flex; justify-content:center; margin:16px 0 6px; }
.btn-add{
  background:#ECA305; color:#1b1b1b; border:1px solid #d39b06; padding:12px 18px; border-radius:999px;
  font-weight:800; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.15);
}

/* Set controls */
.set-row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.btn{ border:1px solid #dfe5e8; background:#fff; padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:700; }
.btn-danger{ background:#fdecea; border-color:#f5c6cb; color:#b71c1c; }

/* Footer (save) */
.footer-bar{
  position:sticky; bottom:0; z-index:10; background:#fff; box-shadow:0 -4px 18px rgba(0,0,0,.05);
  padding:10px 12px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eee;
}
.badge-dot{ width:10px; height:10px; border-radius:999px; display:inline-block; margin-right:8px; background:#aaa; }
.badge-dot.dirty{ background:#eab308; }
.btn-save{
  background:#ECA305; border:1px solid #d39b06; color:#1b1b1b; padding:10px 16px; border-radius:12px; font-weight:800; cursor:pointer;
}

/* Responsive tweaks */
/* Responsive tweaks */
@media (max-width: 820px){
  .choice-grid{ grid-template-columns: 1fr; }
}

/* Hide/show Word Bank row via class */
.bank-row.hidden { display: none !important; }
/* ===== Image attach (Directions) ===== */
.attach-row { display:grid; gap:10px; }
.attach-controls{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.attach-controls .mini-select select{
  appearance:none; border:1px solid #dfe5e8; background:#fff; padding:8px 34px 8px 12px; border-radius:10px; font-weight:700;
}
.attach-controls input[type="text"]{
  padding:8px 12px; border:1px solid #dfe5e8; border-radius:10px; min-width:220px;
}

.dropzone{
  border:2px dashed #cfd8dc; border-radius:12px; padding:14px;
  display:flex; align-items:center; gap:10px; background:#fbfdff;
}
.dropzone.dragover{ background:#eef7ff; border-color:#b3d2ff; }
.btn-ghost{ border:1px solid #dfe5e8; background:#fff; padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:700; }

.thumb{ display:flex; align-items:center; gap:12px; border:1px solid #eef2f4; background:#fafcfd; border-radius:12px; padding:10px; }
.thumb img{ width:80px; height:80px; object-fit:cover; border-radius:10px; border:1px solid #e3e7ea; }
.thumb-meta{ font-size:.92rem; color:#335; }
.thumb-actions{ margin-left:auto; display:flex; gap:8px; }
.hidden{ display:none !important; }
.pb-dir     { margin: 8px 0 10px; color:#334; }
.pb-dir-img { margin: 10px 0 14px; text-align:center; }
.pb-dir-img img { max-width:100%; height:auto; border:1px solid #e6e9ee; border-radius:8px; }

</style>

<div class="main-content qpage">
  <div class="page-wrap">

    <!-- Header -->
    <div class="q-head">
      <h3>Questions — <?= htmlspecialchars($story_title) ?></h3>
      <a class="back-pill" href="pb_manage.php?set_id=<?= (int)$set_id ?>"><i>↩</i>Back to PB Stories</a>
    </div>

    <!-- Tabs -->
    <div class="tabs-row">
      <button class="tab-btn active" data-tab="read">Well Did You Read?</button>
      <button class="tab-btn"        data-tab="learn">Learn About Words</button>
    </div>

    <!-- ===== Pane: Read ===== -->
    <section id="pane-read" class="pane show">
      <div class="section-bar">
        <div class="section-row">
          <b>Default type:</b>
          <span class="select-pill">
            <select id="cfg-read-type">
  <option value="">— Select question type —</option>
  <option value="single">Multiple Choice (A–D)</option>
  <option value="ab">Two choices (A/B)</option>
  <option value="bank">Word Bank</option>
</select>
          </span>
        </div>
        <div class="directions">
          <label style="font-weight:800; color:#0d2c20;">Directions / Instructions (shown to students)</label>
          <textarea id="dir-read" placeholder="Example: Answer the questions below. Choose the best answer."></textarea>
        </div>
        <div class="bank-row hidden" data-bank-for="read:">
  <div><b>Word Bank:</b></div>
  <div class="bank-wrap" id="bank-read-"></div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <input type="text" class="bank-inp" data-key="read:" placeholder="Add word…">
    <button class="btn bank-add" data-key="read:">＋ Add</button>
    <button class="btn" data-bankclear="read:">Clear</button>
  </div>
</div>

        <div class="tag-note">Select a type to start. We’ll insert <b>item #1</b> automatically. Use the button below to add more.</div>
      </div>

      <div class="card">
        <div class="items-wrap" id="items-read"></div>
        <div class="add-wrap"><button class="btn-add" data-key="read:">＋ Add Item</button></div>
      </div>
    </section>

    <!-- ===== Pane: Learn About Words ===== -->
    <section id="pane-learn" class="pane">
      <!-- Vocabulary -->
      <div class="section-bar">
        <div class="sub-h2">Vocabulary</div>
        <div class="set-row">
          <span class="select-pill">
            <select id="pick-vocab">
              <option value="">— Select Set (A–E) —</option>
            </select>
          </span>
          <button class="btn" id="btn-add-vocab">＋ Add Set</button>
          <span class="subnote">Add a Set letter (unused). Each Set has its own Default type, Directions, and Word Bank.</span>
        </div>
      </div>
      <div id="learn-vocab-sets"></div>

      <!-- Word Study -->
      <div class="section-bar" style="margin-top:12px;">
        <div class="sub-h2">Word Study</div>
        <div class="set-row">
          <span class="select-pill">
            <select id="pick-ws">
              <option value="">— Select Set (A–E) —</option>
            </select>
          </span>
          <button class="btn" id="btn-add-ws">＋ Add Set</button>
          <span class="subnote">A letter can be used only once across Vocabulary & Word Study.</span>
        </div>
      </div>
      <div id="learn-ws-sets"></div>
    </section>

    <!-- Sticky footer -->
    <div class="footer-bar">
      <div><span class="badge-dot" id="dirtyDot"></span><span id="dirtyText" class="tag-note">All changes saved</span></div>
      <button class="btn-save" id="btnSave">💾 Save</button>
      <button class="btn-save" id="btnPublish">⬆ Publish to Items</button>

    </div>

  </div>
</div>

<script>
/* ========= Setup / state ========= */
const SET_ID   = <?= (int)$set_id ?>;
const STORY_ID = <?= (int)$story_id ?>;
const INITIAL  = <?= json_encode($notes_json ? json_decode($notes_json,true) : null) ?> || {};

const LETTERS = ['A','B','C','D','E'];

const STATE = {
  sections: {
    read: { default_type:'', directions:'' }
  },
  // dynamic Sets
  learnSets: { vocab:[], wordstudy:[] },
  // per set config: { default_type:'', directions:'', bank:[] }
  setConfigs: {},
  // items
  items: { 'read:': [] },

  activeTab: 'read',
  dirty: false
};

/* ========= Helpers ========= */
function markDirty(on=true){
  STATE.dirty = on;
  document.getElementById('dirtyDot')?.classList.toggle('dirty', on);
  const dt = document.getElementById('dirtyText');
  if (dt) dt.textContent = on ? 'Unsaved changes' : 'All changes saved';
}
window.addEventListener('beforeunload', (e)=>{ if(STATE.dirty){ e.preventDefault(); e.returnValue=''; }});

function ensureNumbers(list){ list.forEach((it,i)=> it.number = i+1); }

function nextBase(type){
  const base = { item_id:null, item_type:type, number:0, question_text:'', choices:[], answer_key:null, normalize:['lower','trim'] };
  if (type==='single'){
    base.choices = [{label:'A',text:''},{label:'B',text:''},{label:'C',text:''},{label:'D',text:''}];
    base.answer_key = 'A';
  } else if (type==='ab'){
    base.choices = [{label:'A',text:''},{label:'B',text:''}];
    base.answer_key = 'A';
  } else if (type==='bank'){
    base.answer_key = ''; // piliin mula sa Word Bank
  }
  return base;
}
function fmtBytes(n){
  if (!n || n<=0) return '0 B';
  const u = ['B','KB','MB','GB']; let i=0; while(n>=1024&&i<u.length-1){ n/=1024;i++; }
  return n.toFixed(i?1:0)+' '+u[i];
}
const TEMP_PREVIEW = { read: { url:null } };
function ensurePreviewBucket(key){
  if(!TEMP_PREVIEW[key]) TEMP_PREVIEW[key] = { url:null };
}


function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
function usedLetters(){ return new Set([ ...STATE.learnSets.vocab, ...STATE.learnSets.wordstudy ]); }
function availableLetters(){
  const used = usedLetters(); return LETTERS.filter(L=>!used.has(L));
}
function updateSetPickers(){
  const fill = (sel) => {
    if (!sel) return;
    const av = availableLetters();
    sel.innerHTML = '<option value="">— Select Set (A–E) —</option>' + av.map(L=>`<option value="${L}">${L}</option>`).join('');
    sel.disabled = av.length === 0;
  };
  fill(document.getElementById('pick-vocab'));
  fill(document.getElementById('pick-ws'));
}

/* ========= Legacy type migration ========= */
function convertLegacyTypes(){
  Object.keys(STATE.items).forEach(k=>{
    (STATE.items[k]||[]).forEach(it=>{
      if (it && (it.item_type==='tf' || it.item_type==='yn')){
        const orig = it.item_type; // tf/yn
        it.item_type = 'ab';
        const pair = (orig==='tf') ? ['True','False'] : ['Yes','No'];
        it.choices = [{label:'A',text: pair[0]},{label:'B',text: pair[1]}];
        it.answer_key = (it.answer_key==='A' || it.answer_key==='B') ? it.answer_key : 'A';
      }
      if (it && it.item_type==='text'){
        // gawing bank na walang answer para mapili mo na lang
        it.item_type = 'bank';
        it.answer_key = it.answer_key || '';
      }
    });
  });
}

/* ========= Hydrate from INITIAL ========= */
(function hydrateFromInitial(){
  if (INITIAL.sections?.read){
    STATE.sections.read = { ...STATE.sections.read, ...INITIAL.sections.read };
  }
  const srcItems = INITIAL.items || {};
  Object.keys(srcItems).forEach(k=>{
    if (k==='read:'){ STATE.items[k] = srcItems[k] || []; return; }
    const m = k.match(/^(vocab|wordstudy):([A-E])$/);
    if (m){
      const block = m[1], L = m[2];
      if (!STATE.learnSets[block].includes(L)) STATE.learnSets[block].push(L);
      STATE.items[k] = srcItems[k] || [];
    }
  });
  const initCfg = INITIAL.setConfigs || {};
  Object.keys(initCfg).forEach(k=>{
    const v = initCfg[k] || {};
    STATE.setConfigs[k] = { default_type:'', directions:'', bank:[], ...v };
  });

  // ensure READ bank container
  STATE.setConfigs['read:'] = { bank: [], ...(STATE.setConfigs['read:'] || {}) };

  // convert any legacy types present in INITIAL
  convertLegacyTypes();
})();

/* ========= Tabs ========= */
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    STATE.activeTab = btn.dataset.tab;
    document.querySelectorAll('.pane').forEach(p=>p.classList.remove('show'));
    document.getElementById('pane-'+STATE.activeTab)?.classList.add('show');
    renderActive();
  });
});
/* ========= READ: Image attach UI ========= */
const dz      = document.getElementById('read-dropzone');
const pickBtn = document.getElementById('read-pick-btn');
const fileInp = document.getElementById('read-img-input');
const thumb   = document.getElementById('read-thumb');
const tImg    = document.getElementById('read-thumb-img');
const tName   = document.getElementById('read-file-name');
const tSize   = document.getElementById('read-file-size');
const btnRep  = document.getElementById('read-replace-btn');
const btnRem  = document.getElementById('read-remove-btn');
const selPos  = document.getElementById('read-img-pos');
const altInp  = document.getElementById('read-img-alt');

function renderReadImage(){
  const meta = STATE.sections.read.image || {};
  // hydrate controls
  if (selPos) selPos.value = meta.position || 'below';
  if (altInp) altInp.value = meta.alt || '';

  const hasLocal = !!TEMP_PREVIEW.read.url;
  const hasMeta  = meta && meta.name;

  if (hasLocal || hasMeta){
    if (thumb) thumb.classList.remove('hidden');
    if (tImg)  tImg.src = TEMP_PREVIEW.read.url || '';
    if (tName) tName.textContent = meta.name || '';
    if (tSize) tSize.textContent = [meta.type || '', fmtBytes(meta.size || 0)].filter(Boolean).join(' • ');
  } else {
    if (thumb) thumb.classList.add('hidden');
  }
}
const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

async function acceptFile(file){
  if (!file) return;
  if (!/^image\//.test(file.type)) return alert('Please select an image file.');
  if (file.size > 4*1024*1024) return alert('File too large (max ~4MB).');

  // upload to server
  const fd = new FormData();
  fd.append('file', file);

  let up;
  try{
    const res = await fetch(`pb_upload.php?story_id=${STORY_ID}`, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF }, // do NOT set Content-Type manually
      body: fd
    });
    up = await res.json();
    if (!res.ok || !up.ok) throw new Error(up?.error || 'Upload failed');
  } catch(e){
    return alert('Upload error: ' + e.message);
  }

  // store metadata + URL sa state
  STATE.sections.read.image = {
    position: selPos?.value || 'below',
    alt: altInp?.value || '',
    name: up.name || file.name,
    type: up.type || file.type,
    size: up.size || file.size,
    url:  up.url  || ''
  };

  // preview use server URL (mas ok kaysa objectURL)
  TEMP_PREVIEW.read.url = up.url || null;

  renderReadImage();
  markDirty();
}

function renderSetImage(key){
  const kp   = key.replace(':','-');
  const meta = (STATE.setConfigs[key]?.image) || {};
  const hasLocal = !!(TEMP_PREVIEW[key]?.url);
  const hasMeta  = !!meta.name;

  const thumb  = document.getElementById(`thumb-${kp}`);
  const tImg   = document.getElementById(`thumb-img-${kp}`);
  const tName  = document.getElementById(`file-name-${kp}`);
  const tSize  = document.getElementById(`file-size-${kp}`);
  const posSel = document.getElementById(`img-pos-${kp}`);
  const altInp = document.getElementById(`img-alt-${kp}`);

  if (posSel) posSel.value = meta.position || 'below';
  if (altInp) altInp.value = meta.alt || '';

  if (hasLocal || hasMeta){
    thumb?.classList.remove('hidden');
    if (tImg)  tImg.src = TEMP_PREVIEW[key]?.url || '';
    if (tName) tName.textContent = meta.name || '';
    if (tSize) tSize.textContent = [meta.type || '', fmtBytes(meta.size || 0)].filter(Boolean).join(' • ');
  } else {
    thumb?.classList.add('hidden');
  }
}

function acceptSetFile(key, file){
  if (!file) return;
  if (!/^image\//.test(file.type)) return alert('Please select an image file.');
  if (file.size > 4*1024*1024) return alert('File too large (max ~4MB).');

  ensurePreviewBucket(key);
  const cfg = (STATE.setConfigs[key] ||= {});
  cfg.image = {
    position: (document.getElementById(`img-pos-${key.replace(':','-')}`)?.value) || 'below',
    alt:      (document.getElementById(`img-alt-${key.replace(':','-')}`)?.value) || '',
    name: file.name, type: file.type, size: file.size
  };

  if (TEMP_PREVIEW[key]?.url) URL.revokeObjectURL(TEMP_PREVIEW[key].url);
  TEMP_PREVIEW[key].url = URL.createObjectURL(file);

  renderSetImage(key);
  markDirty();
}

function wireLearnImageControls(key){
  const kp = key.replace(':','-');
  ensurePreviewBucket(key);

  const pick = document.getElementById(`pick-${kp}`);
  const input= document.getElementById(`img-input-${kp}`);
  const rep  = document.getElementById(`replace-${kp}`);
  const rem  = document.getElementById(`remove-${kp}`);
  const dz   = document.getElementById(`dz-${kp}`);
  const pos  = document.getElementById(`img-pos-${kp}`);
  const alt  = document.getElementById(`img-alt-${kp}`);

  pick?.addEventListener('click', ()=> input?.click());
  rep?.addEventListener('click',  ()=> input?.click());
  input?.addEventListener('change', e=> acceptSetFile(key, e.target.files?.[0]));

  ['dragenter','dragover'].forEach(ev=> dz?.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.add('dragover'); }));
  ['dragleave','drop'    ].forEach(ev=> dz?.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.remove('dragover'); }));
  dz?.addEventListener('drop', e=> acceptSetFile(key, e.dataTransfer?.files?.[0]));

  rem?.addEventListener('click', ()=>{
    const cfg = (STATE.setConfigs[key] ||= {});
    cfg.image = { position: pos?.value || 'below', alt:'', name:'', type:'', size:0 };
    if (TEMP_PREVIEW[key]?.url){ URL.revokeObjectURL(TEMP_PREVIEW[key].url); TEMP_PREVIEW[key].url=null; }
    renderSetImage(key);
    markDirty();
  });

  pos?.addEventListener('change', ()=>{ const c=(STATE.setConfigs[key] ||= {}); (c.image ||= {}).position = pos.value; markDirty(); });
  alt?.addEventListener('input',  ()=>{ const c=(STATE.setConfigs[key] ||= {}); (c.image ||= {}).alt      = alt.value; markDirty(); });

  // initial paint
  renderSetImage(key);
}
function renderLearnImageUI(key, kp, root){
  const meta = (STATE.setConfigs[key].image || {});
  const selPos = root.querySelector(`#img-pos-${kp}`);
  const altInp = root.querySelector(`#img-alt-${kp}`);
  const thumb  = root.querySelector(`#thumb-${kp}`);
  const tImg   = root.querySelector(`#thumb-img-${kp}`);
  const tName  = root.querySelector(`#file-name-${kp}`);
  const tSize  = root.querySelector(`#file-size-${kp}`);

  if (selPos) selPos.value = meta.position || 'below';
  if (altInp) altInp.value = meta.alt || '';

  const hasUrl = !!meta.url;
  if (hasUrl) {
    thumb?.classList.remove('hidden');
    if (tImg)  tImg.src = meta.url;
    if (tName) tName.textContent = meta.name || '';
    if (tSize) tSize.textContent = [meta.type || '', fmtBytes(meta.size || 0)].filter(Boolean).join(' • ');
  } else {
    thumb?.classList.add('hidden');
  }
}

function wireLearnImageHandlers(key, kp, root){
  const selPos = root.querySelector(`#img-pos-${kp}`);
  const altInp = root.querySelector(`#img-alt-${kp}`);
  const dz     = root.querySelector(`#dz-${kp}`);
  const fileIn = root.querySelector(`#img-input-${kp}`);
  const pick   = root.querySelector(`#pick-${kp}`);
  const repBtn = root.querySelector(`#replace-${kp}`);
  const remBtn = root.querySelector(`#remove-${kp}`);

  // initial paint
  renderLearnImageUI(key, kp, root);

  async function acceptSetFile(file){
    if (!file) return;
    if (!/^image\//.test(file.type)) return alert('Please select an image file.');
    if (file.size > 4*1024*1024) return alert('File too large (max 4MB).');

    const fd = new FormData();
    fd.append('file', file);

    let up;
    try{
      const res = await fetch(`pb_upload.php?story_id=${STORY_ID}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        body: fd
      });
      up = await res.json();
      if (!res.ok || !up.ok) throw new Error(up?.error || 'Upload failed');
    }catch(e){
      return alert('Upload error: ' + e.message);
    }

    STATE.setConfigs[key].image = {
      position: selPos?.value || 'below',
      alt: altInp?.value || '',
      name: up.name || file.name,
      type: up.type || file.type,
      size: up.size || file.size,
      url:  up.url  || ''
    };
    renderLearnImageUI(key, kp, root);
    markDirty();
  }

  pick?.addEventListener('click', ()=> fileIn?.click());
  repBtn?.addEventListener('click', ()=> fileIn?.click());
  fileIn?.addEventListener('change', (e)=> acceptSetFile(e.target.files?.[0]));

  ['dragenter','dragover'].forEach(ev=>dz?.addEventListener(ev, (e)=>{ e.preventDefault(); dz.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev=>dz?.addEventListener(ev, (e)=>{ e.preventDefault(); dz.classList.remove('dragover'); }));
  dz?.addEventListener('drop', (e)=>{ const f = e.dataTransfer?.files?.[0]; acceptSetFile(f); });

  remBtn?.addEventListener('click', ()=>{
    STATE.setConfigs[key].image = { position: selPos?.value || 'below', alt: '', name:'', type:'', size:0, url:'' };
    renderLearnImageUI(key, kp, root);
    markDirty();
  });

  selPos?.addEventListener('change', ()=>{
    STATE.setConfigs[key].image = STATE.setConfigs[key].image || {};
    STATE.setConfigs[key].image.position = selPos.value;
    markDirty();
  });
  altInp?.addEventListener('input', ()=>{
    STATE.setConfigs[key].image = STATE.setConfigs[key].image || {};
    STATE.setConfigs[key].image.alt = altInp.value;
    markDirty();
  });
}

pickBtn?.addEventListener('click', ()=> fileInp?.click());
btnRep?.addEventListener('click', ()=> fileInp?.click());
fileInp?.addEventListener('change', (e)=> acceptFile(e.target.files?.[0]));

['dragenter','dragover'].forEach(ev=>dz?.addEventListener(ev, (e)=>{ e.preventDefault(); dz.classList.add('dragover'); }));
['dragleave','drop'].forEach(ev=>dz?.addEventListener(ev, (e)=>{ e.preventDefault(); dz.classList.remove('dragover'); }));
dz?.addEventListener('drop', (e)=>{
  const f = e.dataTransfer?.files?.[0]; acceptFile(f);
});

btnRem?.addEventListener('click', ()=>{
  // clear meta + preview url
  STATE.sections.read.image = { position: selPos?.value || 'below', alt: '', name:'', type:'', size:0 };
  if (TEMP_PREVIEW.read.url){ URL.revokeObjectURL(TEMP_PREVIEW.read.url); TEMP_PREVIEW.read.url=null; }
  renderReadImage();
  markDirty();
});
selPos?.addEventListener('change', ()=>{
  STATE.sections.read.image = STATE.sections.read.image || {};
  STATE.sections.read.image.position = selPos.value;
  markDirty();
});
altInp?.addEventListener('input', ()=>{
  STATE.sections.read.image = STATE.sections.read.image || {};
  STATE.sections.read.image.alt = altInp.value;
  markDirty();
});

// first paint
renderReadImage();

/* ========= READ controls ========= */
const cfgRead = document.getElementById('cfg-read-type');
const dirRead = document.getElementById('dir-read');
const bankRowRead = document.querySelector('[data-bank-for="read:"]');

// (1) rewrite options ng READ select para siguradong tama
if (cfgRead){
  cfgRead.innerHTML = `
    <option value="">— Select question type —</option>
    <option value="single">Multiple Choice (A–D)</option>
    <option value="ab">Two choices (A/B)</option>
    <option value="bank">Word Bank</option>
  `;
}

// (2) set initial values from STATE
if (cfgRead) cfgRead.value = STATE.sections.read.default_type || '';
if (dirRead) dirRead.value = STATE.sections.read.directions || '';
if (bankRowRead) bankRowRead.classList.toggle('hidden', (cfgRead?.value || '') !== 'bank');

// (3) change handler (isa lang)
cfgRead?.addEventListener('change', ()=>{
  const t = cfgRead.value;
  STATE.sections.read.default_type = t;

  // show/hide Word Bank row sa READ
  if (bankRowRead) bankRowRead.classList.toggle('hidden', t !== 'bank');

  const L = STATE.items['read:'];
  if (!t){ renderActive(); return; }
  if (L.length===0) L.push(nextBase(t));
  else {
    for (let i=0;i<L.length;i++){
      const q=L[i].question_text;
      L[i]=nextBase(t);
      L[i].question_text=q;
    }
  }
  ensureNumbers(L);
  renderActive();
  markDirty();
});

dirRead?.addEventListener('input', ()=>{
  STATE.sections.read.directions = dirRead.value;
  markDirty();
});

// READ Word Bank buttons
document.querySelector('.bank-add[data-key="read:"]')?.addEventListener('click', ()=>{
  const input = document.querySelector('.bank-inp[data-key="read:"]');
  const v = (input?.value || '').trim(); if(!v) return;
  const cfg = (STATE.setConfigs['read:'] ||= { bank:[] });
  cfg.bank.push(v); input.value='';
  renderBankChips('read:'); refreshAllBankSelects('read:'); markDirty();
});
document.querySelector('[data-bankclear="read:"]')?.addEventListener('click', ()=>{
  const cfg = (STATE.setConfigs['read:'] ||= { bank:[] });
  cfg.bank = [];
  renderBankChips('read:'); refreshAllBankSelects('read:'); markDirty();
});

// ensure may container sa STATE para sa READ bank
STATE.setConfigs['read:'] = { bank: [], ...(STATE.setConfigs['read:'] || {}) };
// unang render ng chips
renderBankChips('read:');

/* ========= Learn About Words: add/remove sets ========= */
document.getElementById('btn-add-vocab')?.addEventListener('click', ()=>{
  const L = document.getElementById('pick-vocab')?.value;
  if (!L) return;
  if (usedLetters().has(L)) return alert('That Set is already used.');
  STATE.learnSets.vocab.push(L);
  STATE.setConfigs['vocab:'+L] = { default_type:'', directions:'', bank:[] };
  STATE.items['vocab:'+L] = STATE.items['vocab:'+L] || [];
  updateSetPickers(); renderLearn(); markDirty();
});
document.getElementById('btn-add-ws')?.addEventListener('click', ()=>{
  const L = document.getElementById('pick-ws')?.value;
  if (!L) return;
  if (usedLetters().has(L)) return alert('That Set is already used.');
  STATE.learnSets.wordstudy.push(L);
  STATE.setConfigs['wordstudy:'+L] = { default_type:'', directions:'', bank:[] };
  STATE.items['wordstudy:'+L] = STATE.items['wordstudy:'+L] || [];
  updateSetPickers(); renderLearn(); markDirty();
});

/* ========= Add item buttons (general) ========= */
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.btn-add[data-key]');
  if (!btn) return;

  const key = btn.getAttribute('data-key');
  let def = '';

  if (key === 'read:') {
    def = STATE.sections.read.default_type || '';
    if (def === 'bank') {
      const cfg = STATE.setConfigs['read:'] || { bank:[] };
      if (!cfg.bank || cfg.bank.length === 0) {
        if (!confirm('Word Bank is empty. Add items anyway?')) return;
      }
    }
  } else {
    const cfg = STATE.setConfigs[key] || {};
    def = cfg.default_type || '';
    if (def === 'bank' && (!cfg.bank || cfg.bank.length === 0)) {
      if (!confirm('Word Bank is empty. Add items anyway?')) return;
    }
  }

  if (!def) { alert('Please choose a Default type first.'); return; }

  const list = (STATE.items[key] ||= []);
  list.push(nextBase(def));
  ensureNumbers(list);
  renderActive();
  markDirty();
});

/* ========= Rendering ========= */
function renderActive(){
  if (STATE.activeTab==='read'){
    const mount = document.getElementById('items-read');
    paintList('read:', mount, 0);
    return;
  }
  if (STATE.activeTab==='learn'){
    renderLearn(); return;
  }
}

function renderLearn(){
  const vWrap = document.getElementById('learn-vocab-sets'); if (vWrap) vWrap.innerHTML='';
  const wWrap = document.getElementById('learn-ws-sets');    if (wWrap) wWrap.innerHTML='';

  const present = LETTERS.filter(L => STATE.learnSets.vocab.includes(L) || STATE.learnSets.wordstudy.includes(L));

  let base = (STATE.items['read:']||[]).length;

  present.forEach(L=>{
    const block = STATE.learnSets.vocab.includes(L) ? 'vocab' : 'wordstudy';
    const key = block+':'+L;
    const cfg = (STATE.setConfigs[key] ||= { default_type:'', directions:'', bank:[] });

    const setBar = document.createElement('div');
    setBar.className = 'section-bar';
    const kp = key.replace(':','-');   // ex: "vocab:A" -> "vocab-A"
    setBar.innerHTML = `
      <div class="section-row" style="justify-content:space-between;">
        <div><b>${block==='vocab'?'Vocabulary':'Word Study'} — Set ${L}</b></div>
        <div><button class="btn btn-danger" data-delset="${key}">🗑 Remove Set</button></div>
      </div>

      <div class="section-row">
        <b>Default type:</b>
        <span class="select-pill">
          <select class="cfg-type" data-key="${key}">
            <option value="">— Select question type —</option>
            <option value="single">Multiple Choice (A–D)</option>
            <option value="ab">Two choices (A/B)</option>
            <option value="bank">Word Bank</option>
          </select>
        </span>
      </div>

      <div class="directions">
  <label style="font-weight:800; color:#0d2c20;">Directions / Instructions</label>
  <textarea class="cfg-dir" data-key="${key}" placeholder="Write directions for Set ${L}…"></textarea>

  <!-- LEARN: Optional image for directions (unique IDs per set) -->

      <div class="bank-row" data-bank-for="${key}">
        <div><b>Word Bank:</b></div>
        <div class="bank-wrap" id="bank-${key.replace(':','-')}"></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <input type="text" class="bank-inp" data-key="${key}" placeholder="Add word…">
            <button class="btn bank-add" data-key="${key}">＋ Add</button>
            <button class="btn" data-bankclear="${key}">Clear</button>
        </div>
      </div>

      <div class="card">
        <div class="items-wrap" id="items-${key.replace(':','-')}"></div>
        <div class="add-wrap"><button class="btn-add" data-key="${key}">＋ Add Item</button></div>
      </div>
    `;
    (block==='vocab'?vWrap:wWrap).appendChild(setBar);
wireLearnImageControls(key);

    const bankRow = setBar.querySelector(`[data-bank-for="${key}"]`);
    bankRow.classList.toggle('hidden', (cfg.default_type || '') !== 'bank');

    const sel = setBar.querySelector('.cfg-type'); sel.value = cfg.default_type || '';
    sel.addEventListener('change', ()=>{
      cfg.default_type = sel.value;
      bankRow.classList.toggle('hidden', cfg.default_type !== 'bank');
      const list = STATE.items[key] || [];
      if (!cfg.default_type){ paintSetList(key, base); markDirty(); return; }
      if (list.length===0) list.push(nextBase(cfg.default_type));
      else for (let i=0;i<list.length;i++){ const q=list[i].question_text; list[i]=nextBase(cfg.default_type); list[i].question_text=q; }
      ensureNumbers(list); paintSetList(key, base); markDirty();
    });

    const ta = setBar.querySelector('.cfg-dir'); ta.value = cfg.directions || '';
    ta.addEventListener('input', ()=>{ cfg.directions = ta.value; markDirty(); });

    // bank paint + controls
    renderBankChips(key);
    setBar.querySelector('.bank-add').addEventListener('click', ()=>{
      const input = setBar.querySelector('.bank-inp');
      const v = (input.value||'').trim(); if(!v) return;
      cfg.bank = cfg.bank || []; cfg.bank.push(v); input.value='';
      renderBankChips(key); refreshAllBankSelects(key); markDirty();
    });
    setBar.querySelector(`[data-bankclear="${key}"]`).addEventListener('click', ()=>{
      cfg.bank = []; renderBankChips(key); refreshAllBankSelects(key); markDirty();
    });

    // delete set
    setBar.querySelector('[data-delset]').addEventListener('click', ()=>{
      const arr = STATE.learnSets[block]; STATE.learnSets[block] = arr.filter(x=>x!==L);
      delete STATE.setConfigs[key]; delete STATE.items[key];
      updateSetPickers(); renderLearn(); markDirty();
    });

    // render list + advance base
    paintSetList(key, base);
    base += (STATE.items[key]?.length || 0);
  });

  updateSetPickers();
}

function renderBankChips(key){
  const cfg = STATE.setConfigs[key] || { bank:[] };
  const box = document.getElementById('bank-'+key.replace(':','-'));
  if (!box) return;
  box.innerHTML = '';
  (cfg.bank||[]).forEach((w,i)=>{
    const chip = document.createElement('span');
    chip.className = 'bank-chip';
    chip.innerHTML = `${escapeHtml(w)} <span class="x">✖</span>`;
    chip.querySelector('.x').onclick = ()=>{ cfg.bank.splice(i,1); renderBankChips(key); refreshAllBankSelects(key); markDirty(); };
    box.appendChild(chip);
  });
}
function refreshAllBankSelects(key){
  const mount = document.getElementById('items-'+key.replace(':','-'));
  if (!mount) return;
  mount.querySelectorAll('select.bankSel').forEach(sel=>{
    const itIndex = parseInt(sel.getAttribute('data-idx'),10);
    const list = STATE.items[key] || [];
    const it = list[itIndex];
    fillBankSelectOptions(sel, key, it);
  });
}
function fillBankSelectOptions(selectEl, key, it){
  const cfg = STATE.setConfigs[key] || { bank:[] };
  const bank = cfg.bank || [];
  const current = it?.answer_key || '';
  selectEl.innerHTML = `<option value="">— choose —</option>` + bank.map(w=>`<option value="${w}">${w}</option>`).join('');
  selectEl.value = current || '';
}

function paintSetList(key, baseOffset){
  const mount = document.getElementById('items-'+key.replace(':','-'));
  if (!mount) return;
  mount.innerHTML = '';
  const list = STATE.items[key] || [];
  ensureNumbers(list);
  list.forEach((it,idx)=>{
    const displayNum = baseOffset + idx + 1;
    mount.appendChild(makeItemCard(it, list, idx, displayNum, key));
  });
}

/* Generic painter for simple list */
function paintList(key, mount, baseOffset=0){
  if (!mount) return;
  mount.innerHTML = '';
  const list = STATE.items[key] || [];
  ensureNumbers(list);
  list.forEach((it,idx)=>{
    const displayNum = baseOffset + idx + 1;
    mount.appendChild(makeItemCard(it, list, idx, displayNum, key));
  });
}

/* Item card (shared); key is set key or 'read:' */
function makeItemCard(it, list, idx, dispNum, key){
  const wrap = document.createElement('div');
  wrap.className = 'item-card';

  const top = document.createElement('div');
  top.className = 'item-top';
  top.innerHTML = `
    <div class="num-badge">${dispNum || 1}</div>
    <input type="text" class="q-input" placeholder="Type the question here…" value="${escapeHtml(it.question_text||'')}">
    <div class="right"><button type="button" class="btn-del">🗑 Delete</button></div>
  `;
  wrap.appendChild(top);

  top.querySelector('.q-input').addEventListener('input', e=>{ it.question_text = e.target.value; markDirty(); });
  top.querySelector('.btn-del').addEventListener('click', ()=>{ list.splice(idx,1); ensureNumbers(list); renderActive(); markDirty(); });

  const zone = document.createElement('div');

  if (it.item_type === 'single'){
    const bar = document.createElement('div');
    bar.className = 'answer-bar';
    bar.innerHTML = `<b>Correct Answer:</b>
      <span class="mini-select"><select class="corrSel">
        <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
      </select></span>`;
    zone.appendChild(bar);

    const grid = document.createElement('div'); grid.className = 'choice-grid';
    ['A','B','C','D'].forEach((L,i)=>{
      const row = document.createElement('div'); row.className = 'choice-col';
      row.innerHTML = `<span class="letter">${L.toLowerCase()}.</span>
        <input type="text" class="ch" data-i="${i}" placeholder="">`;
      row.querySelector('.ch').value = it.choices[i]?.text || '';
      row.querySelector('.ch').addEventListener('input', e=>{ it.choices[i].text = e.target.value; markDirty(); });
      grid.appendChild(row);
    });
    bar.querySelector('.corrSel').value = it.answer_key || 'A';
    bar.querySelector('.corrSel').addEventListener('change', e=>{ it.answer_key = e.target.value; markDirty(); });
    zone.appendChild(grid);
  }
  else if (it.item_type === 'ab'){
    if (!it.choices?.length) it.choices = [{label:'A',text:''},{label:'B',text:''}];
    const bar = document.createElement('div'); bar.className='answer-bar';
    bar.innerHTML = `<b>Correct Answer:</b>
      <span class="mini-select"><select class="corrSel">
        <option value="A">A</option><option value="B">B</option>
      </select></span>`;
    bar.querySelector('.corrSel').value = it.answer_key || 'A';
    bar.querySelector('.corrSel').addEventListener('change', e=>{ it.answer_key = e.target.value; markDirty(); });
    zone.appendChild(bar);

    const grid = document.createElement('div'); grid.className='choice-grid';
    [0,1].forEach(i=>{
      const row = document.createElement('div'); row.className='choice-col';
      row.innerHTML = `<span class="letter">${i===0?'a':'b'}.</span>
        <input type="text" class="ch" data-i="${i}" placeholder="">`;
      row.querySelector('.ch').value = it.choices[i]?.text || '';
      row.querySelector('.ch').addEventListener('input', e=>{ it.choices[i].text = e.target.value; markDirty(); });
      grid.appendChild(row);
    });
    zone.appendChild(grid);
  }
  else if (it.item_type === 'bank'){
    const cfg = STATE.setConfigs[key] || { bank:[] };
    const bank = cfg.bank || [];
    const bar = document.createElement('div'); bar.className='answer-bar';
    if (bank.length === 0){
      bar.innerHTML = `<div class="warn">No words in the Word Bank yet. Add words above to enable selection.</div>`;
      zone.appendChild(bar);
    } else {
      bar.innerHTML = `<b>Correct Answer:</b>
        <span class="mini-select"><select class="bankSel" data-idx="${idx}"></select></span>`;
      zone.appendChild(bar);
      const sel = bar.querySelector('.bankSel');
      fillBankSelectOptions(sel, key, it);
      sel.addEventListener('change', e=>{ it.answer_key = e.target.value || ''; markDirty(); });
    }
  }

  wrap.appendChild(zone);
  return wrap;
}

/* ========= Save (wire backend later) ========= */
function buildPayload(){
  const sections    = JSON.parse(JSON.stringify(STATE.sections));
  const setConfigs  = JSON.parse(JSON.stringify(STATE.setConfigs));
  return {
    set_id: SET_ID,
    story_id: STORY_ID,
    sections,
    setConfigs,
    items: STATE.items
  };
}



async function doSave(){
  const payload = buildPayload();
  try {
    const res = await fetch('pb_questions_action.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': <?= json_encode($_SESSION['csrf_token']) ?>
      },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Save failed');

    markDirty(false);
    if (window.Swal) {
      Swal.fire({ icon:'success', title:'Questions saved', text:(data.count ?? 0) + ' items saved', confirmButtonColor:'#ECA305' });
    } else {
      alert('Saved ' + (data.count ?? 0) + ' items.');
    }
  } catch (err){
    if (window.Swal) {
      Swal.fire({ icon:'error', title:'Save error', text: err.message, confirmButtonColor:'#ECA305' });
    } else {
      alert('Save error: ' + err.message);
    }
  }
}
document.getElementById('btnSave')?.addEventListener('click', doSave);

/* ===== Prefill from DB when notes don't have items (first run / migrated stories) ===== */
async function prefillFromDBIfEmpty(){
  const anyItems = Object.keys(STATE.items || {}).some(k => (STATE.items[k] || []).length > 0);
  if (anyItems) { renderActive(); return; }

  try {
    const res = await fetch(
      `pb_questions_fetch.php?set_id=${SET_ID}&story_id=${STORY_ID}&for=editor`,
      { credentials: 'same-origin' }
    );
    const j = await res.json();
    if (j.ok && j.editor) {
      // READ sections (default type + directions)
      STATE.sections.read = {
        ...STATE.sections.read,
        ...(j.editor.sections?.read || {})
      };

      // per-set configs (directions + bank)
      Object.assign(STATE.setConfigs, j.editor.setConfigs || {});
      STATE.setConfigs['read:'] = { bank: [], ...(STATE.setConfigs['read:'] || {}) };

      // buckets -> items
      Object.keys(j.editor.items || {}).forEach(k => {
        STATE.items[k] = j.editor.items[k];
      });

      // convert legacy sa data na galing server
      convertLegacyTypes();

      // rebuild learnSets from buckets
      STATE.learnSets.vocab = [];
      STATE.learnSets.wordstudy = [];
      Object.keys(STATE.items).forEach(k => {
        const m = k.match(/^(vocab|wordstudy):([A-E])$/);
        if (m) {
          const block = m[1], L = m[2];
          if (!STATE.learnSets[block].includes(L)) STATE.learnSets[block].push(L);
        }
      });

      // hydrate READ controls + bank row toggle
      if (cfgRead)  cfgRead.value  = STATE.sections.read.default_type || '';
      if (dirRead)  dirRead.value  = STATE.sections.read.directions || '';
      if (bankRowRead) bankRowRead.classList.toggle('hidden', (cfgRead?.value || '') !== 'bank');
      renderBankChips('read:');
    }
  } catch (e) {
    console.warn('prefill error:', e);
  }

  renderActive();
}
document.getElementById('btnPublish')?.addEventListener('click', async ()=>{
  // make sure latest editor changes are saved (optional but recommended)
  await doSave();

  try {
    const res = await fetch('pb_questions_publish.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ set_id: SET_ID, story_id: STORY_ID })
    });
    const j = await res.json();
    if (!res.ok || !j.ok) throw new Error(j.error || 'Publish failed');

    if (window.Swal) {
      Swal.fire({
        icon:'success',
        title:'Published!',
        text:`${j.inserted_items} items / ${j.inserted_choices} choices`,
        confirmButtonColor:'#ECA305'
      });
    } else {
      alert('Published: ' + j.inserted_items + ' items.');
    }
  } catch (err) {
    if (window.Swal) {
      Swal.fire({ icon:'error', title:'Publish error', text:String(err), confirmButtonColor:'#ECA305' });
    } else {
      alert('Publish error: ' + String(err));
    }
  }
});

/* ========= Boot ========= */
updateSetPickers();
prefillFromDBIfEmpty();   // renderActive() is called at the end of this

</script>

</body></html>
