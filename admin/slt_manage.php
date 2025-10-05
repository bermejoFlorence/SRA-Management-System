<?php
// admin/slt_manage.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$PAGE_TITLE  = 'SLT – Manage Stories';
$ACTIVE_MENU = 'stories';
$ACTIVE_SUB  = 'slt';

/* ---------- Guard: need a valid SLT set_id ---------- */
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;
if ($set_id <= 0) { header('Location: stories_sl.php'); exit; }

$set = null;
$stmt = $conn->prepare("SELECT * FROM story_sets WHERE set_id=? AND set_type='SLT' LIMIT 1");
$stmt->bind_param("i", $set_id);
$stmt->execute(); $res = $stmt->get_result();
if ($res && $res->num_rows) { $set = $res->fetch_assoc(); }
if (!$set) { header('Location: stories_sl.php'); exit; }

/* ---------- Flash helpers ---------- */
function flash_pop(){ $f=$_SESSION['slt_flash']??null; unset($_SESSION['slt_flash']); return $f; }
$flash = flash_pop();

/* ---------- Stories of this set ---------- */
$stories = [];
$q = "
  SELECT st.*,
    (SELECT COUNT(*) FROM story_items si WHERE si.story_id = st.story_id) AS item_count
  FROM stories st
  WHERE st.set_id=?
  ORDER BY (st.status='active') DESC, st.updated_at DESC
";

$stmt2 = $conn->prepare($q);
$stmt2->bind_param("i", $set_id);
$stmt2->execute(); $rs2 = $stmt2->get_result();
if ($rs2) while($row=$rs2->fetch_assoc()) $stories[] = $row;

/* next sequence default */
$nextSeq = 1 + (int)($stories ? max(array_column($stories,'sequence')) : 0);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
/* ====== Page layout ====== */
.main-content{ padding-top:60px; }
.page-wrap{ width:98%; margin:8px auto 22px; }

.crumbs{ display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.crumbs a{ text-decoration:none; color:#0b3; }

.sub-bar{
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  background:#fff; padding:10px 12px; border-radius:10px; box-shadow: var(--shadow);
  border-left:6px solid var(--accent);
}
.sub-bar h3{ margin:0; color:var(--green); font-weight:800; }
.sub-bar .muted{ color:#6b8b97; font-size:.95rem; }
.sub-bar .grow{ flex:1; }

/* ====== Buttons ====== */
.btn{
  display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:10px;
  border:1px solid #dfe5e8; background:#fff; cursor:pointer; font-weight:600;
  transition: transform .05s ease, box-shadow .15s ease, filter .12s ease;
}
.btn:hover{ box-shadow:0 2px 10px rgba(0,0,0,.08); }
.btn:active{ transform: translateY(1px); }
.btn-accent{ background:var(--accent); border-color:#d39b06; color:#1b1b1b; font-weight:700; }
.btn-ghost{ background:transparent; border-color:rgba(0,0,0,.1); }

.btn-edit{ background:#eef3ff; border-color:#cad5fb; color:#203a8f; }
.btn-activate{ background:#eaf7ea; border-color:#bfe3c6; color:#155724; }
.btn-active{ background:#d4edda; border-color:#bfe3c6; color:#155724; pointer-events:none; opacity:.95; }
.btn-deactivate{ background:#f4f6f8; border-color:#dfe5e8; color:#334155; }

/* ====== Chips ====== */
.chip{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
.c-active{ background:#d4edda; color:#155724; }
.c-inactive{ background:#e2e3e5; color:#383d41; }
.c-draft{ background:#fff3cd; color:#856404; }
.c-pub{ background:#d4edda; color:#155724; }

/* ====== Table ====== */
.card{ background:#fff; border-radius:12px; box-shadow: var(--shadow); overflow:hidden; margin-top:12px; }

/* 6 columns: # | Title | Status | Questions | Updated | Actions */
.table-head{
  display:grid;
  grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 280px;
  gap:10px; padding:12px 14px;
  background:rgba(0,0,0,.03); color:#265; font-weight:700;
}
.row{
  display:grid;
  grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 280px;
  gap:10px; padding:12px 14px;
  border-top:1px solid #f0f2f4; align-items:center;
}

/* Tablet and down: itago ang Updated (.hide-md); keep Questions visible */
@media (max-width: 980px){
  .table-head, .row{ grid-template-columns: 50px 1.6fr .8fr .6fr 220px; }
  .hide-md{ display:none !important; }
}

/* Mobile: stacked */
@media (max-width: 640px){
  .table-head{ display:none; }
  .row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
  .row > :nth-child(1){ grid-column:1/2; font-weight:700; }
  .row > :nth-child(2){ grid-column:2/3; }
  .row > :nth-child(n+3){ grid-column:1/-1; }
}


/* ====== Modal ====== */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  display:none; align-items:center; justify-content:center; z-index:2000;
  opacity:0; transition:opacity .18s ease-out;
}
.modal-backdrop.show{ display:flex; opacity:1; }

.modal{
  width:min(920px,96vw);
  height:clamp(560px, 86vh, 900px);
  background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25);
  display:flex; flex-direction:column; overflow:hidden;
  transform: translateY(8px) scale(.985); opacity:0;
  transition: transform .18s ease-out, opacity .18s ease-out;
}
.modal-backdrop.show .modal{ transform: translateY(0) scale(1); opacity:1; }

.modal header{
  flex:0 0 auto; background:var(--green); color:#fff; padding:12px 16px; font-weight:700;
  display:flex; justify-content:space-between; align-items:center;
}
/* Make header X both crisp and visible */
#mClose,#qClose{
  color:#fff !important; border-color:rgba(255,255,255,.35) !important;
  background:rgba(255,255,255,.06) !important;
}
#mClose:hover,#qClose:hover{ background:rgba(255,255,255,.18) !important; color:#fff !important; box-shadow:none !important; }

/* form scaffolding: body scrolls, footer fixed */
.modal form{ display:flex; flex-direction:column; flex:1 1 auto; min-height:0; }
.modal form section{
  flex:1 1 auto; min-height:0; overflow:auto; padding:16px;
  display:grid; gap:12px; grid-template-columns: 1fr;
}
.modal form footer{
  flex:0 0 auto; padding:12px 16px; display:flex; gap:10px; justify-content:flex-end;
  background:#fafafa; border-top:1px solid #eee;
}

/* Controls */
.modal label{ font-weight:600; color:#123; }
.modal input[type="text"], .modal input[type="number"], .modal select, .modal textarea{
  width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px;
}
.modal textarea{ height:260px; max-height:40vh; overflow:auto; resize:vertical; font-family:inherit; }
#imgPreview{ max-width:100%; max-height:28vh; height:auto; border-radius:8px; border:1px solid #e5e7eb; }

/* Top-right back button */
.top-actions{ display:flex; justify-content:flex-end; align-items:center; margin: 6px 0 10px; }
.btn-back{ background:#eaf7ea; border-color:#bfe3c6; color:#155724; font-weight:700; gap:10px; }
.btn-back:hover{ filter:brightness(.985); box-shadow:0 2px 10px rgba(0,0,0,.06); }
.btn-back i{
  display:inline-flex; width:20px; height:20px; align-items:center; justify-content:center;
  border-radius:999px; background:#d4edda; font-style:normal;
}
@media (max-width:640px){
  .top-actions{ margin-top:4px; }
  .top-actions .btn-back{ width:100%; justify-content:center; }
}

/* --- info callout (Add/Edit Story & Questions note) --- */
.note-callout{
  background:#f5faf2; border:1px solid #d7ead0; color:#184e27;
  padding:10px 12px; border-radius:10px; font-size:.95rem; display:flex; gap:8px; align-items:flex-start;
}
.note-callout b{ font-weight:700; }
.note-callout i{ font-style:normal; font-weight:700; }
.note-top{ margin:6px 0 8px; }
.note-compact{ padding:8px 10px; font-size:.93rem; line-height:1.25; }

/* ====== Questions modal (batch editor layout) ====== */
.qs-count{ color:#123; font-weight:700; font-size:.95rem; margin:6px 0 10px; }

.item-block{
  border:1px solid #eef2f4; border-radius:12px; padding:12px; margin:10px 0; background:#fcfcfc;
}
.item-head{ display:flex; align-items:center; gap:12px; }
.item-num{
  width:34px; height:34px; border-radius:999px; display:flex; align-items:center; justify-content:center;
  font-weight:800; background:#eef7ff; border:1px solid #cfe3ff; color:#1b3a7a;
}
.item-head input[type="text"]{ flex:1; }
.correct-select{ display:flex; align-items:center; gap:6px; white-space:nowrap; }
.two-col{ display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px; }
.choice-line{ display:flex; align-items:center; gap:8px; }
.choice-line .letter{ width:18px; text-align:center; font-weight:700; }
.center-actions{ display:flex; justify-content:center; margin-top:10px; }
.item-remove{ margin-left:8px; }

/* Add Items button — force pill, avoid big circle */
.btn-additems{
  width:auto !important; height:auto !important; min-width:unset !important; min-height:unset !important;
  line-height:normal !important; aspect-ratio:auto !important;
  padding:10px 18px !important; border-radius:999px !important;
  box-shadow:0 2px 8px rgba(0,0,0,.15) !important;
}
.btn-additems:hover{ filter:brightness(.98); }

/* (Legacy list styles kept if needed later) */
.qs-head, .qs-row{
  display:grid; grid-template-columns: 60px 1.6fr 1fr .8fr 180px;
  gap:10px; padding:12px 14px; align-items:center;
}
.qs-head{ background:rgba(0,0,0,.03); font-weight:700; color:#265; }
.qs-row{ border-top:1px solid #f0f2f4; }
.qs-empty{ padding:16px; color:#6b8b97; }
.badge{ display:inline-block; padding:2px 8px; border-radius:999px; background:#eef3ff; border:1px solid #cad5fb; color:#203a8f; font-size:.8rem; }
.badge-warn{ background:#fff3cd; border-color:#ffe69c; color:#7a5c00; }

/* Shake animation kapag nag-click sa labas / Esc */
.modal.shake{
  animation: modalShake .35s ease;
}
@keyframes modalShake{
  0%,100%{ transform: translateY(0) scale(1) translateX(0); }
  20%    { transform: translateY(0) scale(1) translateX(-8px); }
  40%    { transform: translateY(0) scale(1) translateX(8px); }
  60%    { transform: translateY(0) scale(1) translateX(-6px); }
  80%    { transform: translateY(0) scale(1) translateX(6px); }
}
/* Actions column: keep buttons on one line */
.actions-inline{
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;     /* <-- stop wrapping */
  white-space: nowrap;   /* keep button labels in one line */
}
.actions-inline form{ display:inline; }      /* forms behave like inline */
.actions-inline .btn{ padding:8px 12px; }    /* a bit tighter */

/* Mas maluwag ng kaunti ang last column para kasya ang 3 buttons */
.table-head{ grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 320px; }
.row       { grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 320px; }

/* Sa tablet pababa, pwede nating ibalik ang wrap para 'di sumagad */
@media (max-width: 980px){
  .actions-inline{ flex-wrap: wrap; }
}

</style>

<div class="main-content">
  <div class="page-wrap">
    <div class="top-actions">
  <a class="btn btn-back" href="stories_sl.php" title="Back to SLT Sets">
    <i>↩</i><span>Back to SLT Sets</span>
  </a>
</div>


    <div class="sub-bar">
      <h3><?= htmlspecialchars($set['title']) ?></h3>
      <span class="chip <?= $set['status']==='published'?'c-pub':'c-draft' ?>"><?= htmlspecialchars($set['status']) ?></span>
      <span class="muted">Set ID: <?= (int)$set_id ?></span>
      <div class="grow"></div>
      <button class="btn btn-accent" id="btnAdd"><i>＋</i> Add Story</button>
    </div>

    <div class="card table-wrap">
      <div class="table-head">
        <div>#</div>
        <div>Story Title</div>
        <div>Total Questions</div>
        <div>Status</div>
        <div class="hide-md">Updated</div>
        <div>Actions</div>
      </div>


      <?php if(!$stories): ?>
        <div class="row">
          <div>—</div>
          <div class="muted">No stories yet. Click “Add Story”.</div>
          <div><span class="chip c-draft">draft</span></div>
          <div class="hide-md">—</div>
          <div><button class="btn btn-accent" id="btnAdd2">Add your first story</button></div>
        </div>

      <?php endif; ?>

 <?php foreach($stories as $i=>$st): ?>
<?php
  $stStatus = strtolower($st['status'] ?? '');
  $isActive = in_array($stStatus, ['active','published'], true);
?>
<div class="row">
  <!-- # -->
  <div><?= $i+1 ?></div>

  <!-- Story Title -->
  <div>
    <div style="font-weight:700;"><?= htmlspecialchars($st['title']) ?></div>
    <?php if(!empty($st['time_limit_seconds'])): ?>
  <div class="muted" style="font-size:.9rem;">
    Time limit: <?= (int)floor($st['time_limit_seconds']/60) ?> min
  </div>
<?php endif; ?>
  </div>

  <!-- Total Questions -->
  <div><?= (int)($st['item_count'] ?? 0) ?></div>

  <!-- Status -->
  <div>
    <span class="chip <?= $isActive ? 'c-active' : 'c-inactive' ?>">
      <?= $isActive ? 'active' : 'inactive' ?>
    </span>
  </div>

  <!-- Updated (hidden on tablet-down via .hide-md) -->
  <div class="hide-md"><?= htmlspecialchars($st['updated_at'] ?? '') ?></div>

  <!-- Actions -->
<div class="actions-inline">

    <!-- Edit -->
    <button
  class="btn btn-edit js-edit"
  data-story='<?= json_encode([
    'story_id'           => (int)$st['story_id'],
    'title'              => $st['title'],
    'status'             => $st['status'],
    'image_path'         => $st['image_path'] ?? null,
    'time_limit_seconds' => isset($st['time_limit_seconds']) ? (int)$st['time_limit_seconds'] : null,
  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
  data-pass="<?= htmlspecialchars($st['passage_html']) ?>"
>✏️ Edit</button>


    <!-- Questions -->
    <button
  class="btn js-questions"
  data-story-id="<?= (int)$st['story_id'] ?>"
  data-story-title="<?= htmlspecialchars($st['title']) ?>"
  data-time-limit-seconds="<?= (int)($st['time_limit_seconds'] ?? 0) ?>"
>❓ Questions</button>


    <!-- Toggle -->
    <?php if(!$isActive): ?>
      <form method="post" action="slt_stories_action.php" class="js-activate" style="display:inline;">
        <input type="hidden" name="action" value="set_active">
        <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
        <input type="hidden" name="story_id" value="<?= (int)$st['story_id'] ?>">
        <button class="btn btn-activate">⭐ Set Active</button>
      </form>
    <?php else: ?>
      <form method="post" action="slt_stories_action.php" class="js-deactivate" style="display:inline;">
        <input type="hidden" name="action" value="set_inactive">
        <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
        <input type="hidden" name="story_id" value="<?= (int)$st['story_id'] ?>">
        <button class="btn btn-deactivate">⏸ Set Inactive</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Add/Edit Story Modal -->
<div class="modal-backdrop" id="mStory">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
    <header>
      <span id="mTitle">Add Story</span>
      <button type="button" class="btn btn-ghost" id="mClose" aria-label="Close modal">✖</button>
    </header>

    <form method="post" action="slt_stories_action.php" id="storyForm" enctype="multipart/form-data">
      <section>
          <div class="note-callout">
    
    <div>
      <b> <i>ℹ️</i>Note:</b> Please create and save the story first. After saving, open “Questions” to add items and set the answer keys.
    </div>
  </div>
      <div>
        <label for="fldQuizLimit">Story Time Limit (minutes)</label>
        <input type="number" min="0" step="1" name="time_limit_min" id="fldQuizLimit" placeholder="e.g., 10">
        <div class="muted" style="font-size:.9rem;">
          Applies to the whole story (reading + quiz). Leave blank or 0 = no limit.
        </div>
      </div>
        <div class="grid">
          <div>
            <label for="fldTitle">Title <span style="color:#c00">*</span></label>
            <input type="text" name="title" id="fldTitle" required placeholder="e.g., Story A – The 999">
          </div>
          <div>
            <label for="fldStatus">Status</label>
            <select name="status" id="fldStatus">
              <option value="inactive">inactive</option>
              <option value="active">active</option>
            </select>
          </div>
        </div>

        <div>
          <label for="fldPassage">Passage</label>
          <textarea name="passage_html" id="fldPassage" placeholder="Paste the passage text here…"></textarea>
          <div class="muted" id="wordInfo">Words: 0</div>
        </div>

        <div>
          <label for="fldImage">Cover image (optional)</label>
          <input type="file" name="cover_image" id="fldImage" accept="image/*">
          <div class="muted" style="font-size:.85rem;">JPG, PNG, WebP, o GIF — hanggang 3 MB.</div>

          <!-- Preview + remove (unique IDs, one set only) -->
          <img id="imgPreview" alt="Preview"
               style="display:none; max-width:220px; margin-top:8px; border-radius:8px; border:1px solid #e5e7eb;">
          <button type="button" class="btn" id="btnClearImg" style="display:none; margin-top:6px;">Remove image</button>
        </div>
      </section>
      
      <footer>
        <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
        <input type="hidden" name="action" id="fldAction" value="add_story">
        <input type="hidden" name="story_id" id="fldStoryId" value="">
        <button type="button" class="btn" id="btnCancel">Cancel</button>
        <button class="btn btn-accent" id="btnSave">Save Story</button>
      </footer>
    </form>
  </div>
</div>

<!-- Questions Modal (same size as Edit) -->
<div class="modal-backdrop" id="mQuestions">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="qTitle">
    <header>
      <span id="qTitle">Questions — </span>
      <button type="button" class="btn btn-ghost" id="qClose" aria-label="Close modal">✖</button>
    </header>

    <form id="qForm" method="post" action="slt_questions_action.php">
     <section>
  <!-- NOTE (English, nasa itaas) -->
<div class="note-callout note-top note-compact">
  <i>ℹ️</i>
  <div>
    <b>Reminder:</b> Add the questions and their answer keys here.
    Click <b>Add Items</b> to add more questions.
  </div>
</div>


  <!-- COUNT (no background) -->
  <div class="qs-count" id="qItemsBadge">Total number of Questions: 0</div>

  <!-- Items render area -->
  <div id="qItemsWrap"></div>

  <!-- Centered Add button -->
  <div class="center-actions">
    <button type="button" class="btn btn-accent btn-additems" id="btnAddItem">＋ Add Items</button>
  </div>
</section>



    <footer>
    <input type="hidden" name="action" value="batch_upsert">
    <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
    <input type="hidden" id="qStoryId" name="story_id" value="">
    <input type="hidden" id="qDeleteIds" name="delete_ids" value="">
    <button type="button" class="btn" id="qCancel">Close</button>
    <button type="submit" class="btn btn-accent" id="qSaveAll" disabled>Save Changes</button>
  </footer>

    </form>
  </div>
</div>
<script>
  <?php if (!empty($flash)): ?>
    Swal.fire({
      icon: '<?= $flash['t']==='ok'?'success':'error' ?>',
      title: <?= json_encode($flash['m']) ?>,
      confirmButtonColor: '#ECA305'
    });
  <?php endif; ?>

  // ------- Modal wiring -------
  const m = document.getElementById('mStory');
  const open  = () => m?.classList.add('show');
  const close = () => m?.classList.remove('show');

  // fields
  const fm         = document.getElementById('storyForm');
  const fldTitle   = document.getElementById('fldTitle');
  const fldStatus  = document.getElementById('fldStatus');
  const fldPass    = document.getElementById('fldPassage');
  const fldAction  = document.getElementById('fldAction');
  const fldStoryId = document.getElementById('fldStoryId');
  const wordInfo   = document.getElementById('wordInfo');
  const mTitle     = document.getElementById('mTitle');

  // image fields
  const fldImage   = document.getElementById('fldImage');
  const imgPreview = document.getElementById('imgPreview');
  const btnClearImg= document.getElementById('btnClearImg');
  const fldQuizLimit = document.getElementById('fldQuizLimit');

  document.getElementById('mClose')?.addEventListener('click', close);
  document.getElementById('btnCancel')?.addEventListener('click', close);
function nudge(backdrop){
  const box = backdrop?.querySelector('.modal');
  if(!box) return;
  box.classList.remove('shake'); // restart animation
  void box.offsetWidth;          // reflow
  box.classList.add('shake');
}
m?.addEventListener('click', (e) => {
  if (e.target === m) nudge(m);  // wag i-close; i-shake lang
});
// Optional: Esc key mag-nu-nudge din
document.addEventListener('keydown', (e)=>{
  if(e.key === 'Escape' && m?.classList.contains('show')) nudge(m);
});


  // word counter
  function uiWordCount(txt){
    const plain = (txt || '').replace(/<[^>]*>/g,' ').trim();
    const arr = plain ? plain.split(/\s+/).filter(Boolean) : [];
    return arr.length;
  }
  function refreshWords(){ if (wordInfo) wordInfo.textContent = 'Words: ' + uiWordCount(fldPass.value); }
  fldPass?.addEventListener('input', refreshWords);

  // image preview helpers
  function clearImage(){
    if (fldImage) fldImage.value = '';
    if (imgPreview){
      imgPreview.src = '';
      imgPreview.style.display = 'none';
    }
    if (btnClearImg) btnClearImg.style.display = 'none';
  }
  function setPreview(src){
    if (!imgPreview) return;
    imgPreview.src = src;
    imgPreview.style.display = 'block';
    if (btnClearImg) btnClearImg.style.display = 'inline-flex';
  }
  fldImage?.addEventListener('change', () => {
    const f = fldImage.files?.[0];
    if (!f){ clearImage(); return; }
    const reader = new FileReader();
    reader.onload = e => setPreview(e.target.result);
    reader.readAsDataURL(f);
  });
  btnClearImg?.addEventListener('click', clearImage);

// Add mode
function toAdd(){
  mTitle.textContent = 'Add Story';
  fldAction.value = 'add_story';
  fldStoryId.value = '';
  fldTitle.value = '';
  fldStatus.value = 'inactive';
  fldPass.value = '';
  fldQuizLimit.value = '';
  clearImage();       // <-- important for image
  refreshWords();
}

  document.getElementById('btnAdd')?.addEventListener('click', () => { toAdd(); open(); });
  document.getElementById('btnAdd2')?.addEventListener('click', () => { toAdd(); open(); });

// Edit mode
document.querySelectorAll('.js-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    const data = JSON.parse(btn.getAttribute('data-story') || '{}');

    mTitle.textContent = 'Edit Story';
    fldAction.value = 'update_story';
    fldStoryId.value = data.story_id ?? '';
    fldTitle.value   = data.title ?? '';
    fldStatus.value  = data.status ?? 'inactive';
    fldPass.value    = btn.getAttribute('data-pass') || '';
    // time limit (minutes)
const secs = (data.time_limit_seconds ?? null);
fldQuizLimit.value = (secs && secs > 0) ? Math.floor(secs / 60) : '';

    // --- image preview (normalize path) ---
    clearImage();                                // linisin muna preview
    let img = data.image_path || '';
    if (img && img.startsWith('/uploads/')) {
      // prefix app base (hal. "/sra") para di mag-404
      img = '<?= rtrim(str_replace("\\","/", dirname(dirname($_SERVER["SCRIPT_NAME"]))), "/") ?>' + img;
    }
    if (img) setPreview(img);                    // i-preview kung meron

    refreshWords();
    open();
  });
});


  // Set Active confirm
  document.querySelectorAll('.js-activate').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      Swal.fire({
        icon: 'question',
        title: 'Set this story as active?',
        text: 'Only one story in this set can be active. Others will be marked inactive.',
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes, set active'
      }).then(res => { if (res.isConfirmed) form.submit(); });
    });
  });

  // Set Inactive confirm
  document.querySelectorAll('.js-deactivate').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      Swal.fire({
        icon: 'warning',
        title: 'Set this story as inactive?',
        text: 'Students will no longer see this story in SLT.',
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes, set inactive'
      }).then(res => { if (res.isConfirmed) form.submit(); });
    });
  });
</script>
<script>
// ===== Questions modal open/close wiring =====
const mQ = document.getElementById('mQuestions');
const qOpen  = () => mQ?.classList.add('show');
const qClose = () => mQ?.classList.remove('show');

document.getElementById('qClose')?.addEventListener('click', qClose);
document.getElementById('qCancel')?.addEventListener('click', qClose);
mQ?.addEventListener('click', (e)=>{
  if (e.target === mQ) nudge(mQ);  // wag i-close; i-shake lang
});
document.addEventListener('keydown', (e)=>{
  if(e.key === 'Escape' && mQ?.classList.contains('show')) nudge(mQ);
});

</script>
<script>
// ===== Batch editor for Questions (load + edit + delete + add) =====
const qItemsWrap  = document.getElementById('qItemsWrap');
const qItemsBadge = document.getElementById('qItemsBadge');
const btnAddItem  = document.getElementById('btnAddItem');
const qSaveAll    = document.getElementById('qSaveAll');
const qDeleteIds  = document.getElementById('qDeleteIds');

let existingCount = 0;
const deletedIds  = new Set();

function escapeQuotes(s){ return (s||'').replace(/"/g,'&quot;'); }

function setCountBadge(){
  const visible = qItemsWrap.querySelectorAll('.item-block').length;
  qItemsBadge.textContent = 'Total number of Questions: ' + visible;
}

function renumber(){
  const blocks = [...qItemsWrap.querySelectorAll('.item-block')];
  blocks.forEach((b,i)=>{
    b.querySelector('.item-num').textContent = i+1;
    b.querySelector('input[name="number[]"]').value = i+1;
  });
  setCountBadge();
  qSaveAll.disabled = (blocks.length === 0 && deletedIds.size === 0);
}

function makeItem(data = {}) {
  const wrap = document.createElement('div');
  wrap.className = 'item-block';

  const id     = data.item_id ? parseInt(data.item_id,10) : 0;
  const q      = data.question || '';
  const A      = (data.choices?.A) || '';
  const B      = (data.choices?.B) || '';
  const C      = (data.choices?.C) || '';
  const D      = (data.choices?.D) || '';
  const key    = (data.key || 'A').toUpperCase();
  const points = data.points ? parseInt(data.points,10) : 1;

  wrap.innerHTML = `
    <input type="hidden" name="item_id[]" value="${id}">
    <input type="hidden" name="number[]" value="0">
    <div class="item-head">
      <div class="item-num">1</div>
      <input type="text" class="q-text" name="question_text[]" placeholder="Type the question here…" required value="${escapeQuotes(q)}">
      <div class="correct-select">
        <label>Correct Answer:</label>
        <select class="q-correct" name="correct_letter[]">
          ${['A','B','C','D'].map(L=>`<option value="${L}" ${L===key?'selected':''}>${L}</option>`).join('')}
        </select>
      </div>
      <button type="button" class="btn item-remove" title="Delete">🗑</button>
    </div>
    <div class="two-col">
      <div class="choice-line"><span class="letter">a.</span><input type="text" name="choice_a[]" value="${escapeQuotes(A)}" required></div>
      <div class="choice-line"><span class="letter">c.</span><input type="text" name="choice_c[]" value="${escapeQuotes(C)}" required></div>
      <div class="choice-line"><span class="letter">b.</span><input type="text" name="choice_b[]" value="${escapeQuotes(B)}" required></div>
      <div class="choice-line"><span class="letter">d.</span><input type="text" name="choice_d[]" value="${escapeQuotes(D)}" required></div>
    </div>
    <input type="hidden" name="points[]" value="${points}">
  `;

  // delete handler
  wrap.querySelector('.item-remove').onclick = ()=>{
    const hid = wrap.querySelector('input[name="item_id[]"]');
    const existingId = parseInt(hid.value||'0',10);
    if (existingId > 0) deletedIds.add(existingId);
    wrap.remove();
    renumber();
    qSaveAll.disabled = false;
  };

  // any change enables Save
  wrap.querySelectorAll('input,select,textarea').forEach(el=>{
    el.addEventListener('input', ()=> qSaveAll.disabled = false);
    el.addEventListener('change', ()=> qSaveAll.disabled = false);
  });

  return wrap;
}

// Add new item
btnAddItem?.addEventListener('click', ()=>{
  qItemsWrap.appendChild(makeItem());
  qSaveAll.disabled = false;
  renumber();
});

// Load existing questions for edit
async function loadExistingForEdit(setId, storyId){
  deletedIds.clear();
  try{
    const resp = await fetch(`slt_questions_fetch.php?set_id=${encodeURIComponent(setId)}&story_id=${encodeURIComponent(storyId)}`, {credentials:'same-origin'});
    const data = await resp.json();
    const items = (data.ok ? (data.items||[]) : []);
    existingCount = items.length;
    qItemsWrap.innerHTML = '';
    items.forEach(it => qItemsWrap.appendChild(makeItem(it)));
    renumber();
  }catch(err){
    existingCount = 0; qItemsWrap.innerHTML = ''; renumber();
    Swal.fire({icon:'error', title:'Load failed', text:String(err), confirmButtonColor:'#ECA305'});
  }
}

// Open modal -> load then show (no blank state)
document.querySelectorAll('.js-questions').forEach(btn => {
  btn.addEventListener('click', async () => {   // <-- async handler
    const sid   = btn.getAttribute('data-story-id');
    const title = btn.getAttribute('data-story-title') || 'Untitled';
    document.getElementById('qTitle').textContent = 'Questions — ' + title;
    document.getElementById('qStoryId').value = sid;

    // HUWAG mag-pre-clear dito para walang “0 + Add Items” na flash
    await loadExistingForEdit(<?= (int)$set_id ?>, sid);  // fetch & render items
    qOpen();                                              // open AFTER load
  });
});

document.querySelectorAll('.js-questions').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const secs = parseInt(btn.getAttribute('data-time-limit-seconds')||'0',10);
    const mins = secs>0 ? Math.floor(secs/60) : 0;
    const note = document.querySelector('#mQuestions .note-callout .time-note');
    if (!note && mins>0) {
      const nc = document.querySelector('#mQuestions .note-callout > div');
      const span = document.createElement('div');
      span.className = 'time-note';
      span.textContent = `Story time limit: ${mins} minute(s) (reading + quiz).`;
      nc.appendChild(span);
    }
    // ...
  });
});

// Submit: attach deletions + validate
document.getElementById('qForm')?.addEventListener('submit', (e)=>{
  document.getElementById('qDeleteIds').value = Array.from(deletedIds).join(',');

  const blocks = qItemsWrap.querySelectorAll('.item-block');
  if (blocks.length === 0 && deletedIds.size > 0) return; // delete-only ok

  for (const b of blocks){
    const q  = b.querySelector('.q-text')?.value.trim();
    const a  = b.querySelector('input[name="choice_a[]"]')?.value.trim();
    const bb = b.querySelector('input[name="choice_b[]"]')?.value.trim();
    const c  = b.querySelector('input[name="choice_c[]"]')?.value.trim();
    const d  = b.querySelector('input[name="choice_d[]"]')?.value.trim();
    const corr = b.querySelector('.q-correct')?.value;
    const map = {A:a,B:bb,C:c,D:d};
    const nonEmpty = [a,bb,c,d].filter(x=>x).length;
    if (!q || nonEmpty < 2 || !corr || !map[corr]){
      e.preventDefault();
      Swal.fire({icon:'error', title:'Please complete each item', text:'Question, at least 2 choices, and a valid answer key are required.', confirmButtonColor:'#ECA305'});
      return;
    }
  }
});
</script>

</body></html>
