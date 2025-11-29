<?php
// admin/rb_manage.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* flash helpers (RB namespace) */
function flash_set($type,$msg){ $_SESSION['rb_flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['rb_flash']??null; unset($_SESSION['rb_flash']); return $f; }

/* ---------- Guard: need a valid RB set_id ---------- */
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;
if ($set_id <= 0) { header('Location: stories_rb.php'); exit; }

/* ---------- Fetch RB set ---------- */
$set = null;
$sqlSet = "
  SELECT ss.*, lvl.name AS level_name, lvl.color_hex
  FROM story_sets ss
  LEFT JOIN sra_levels lvl ON lvl.level_id = ss.level_id
  WHERE ss.set_id = ? AND ss.set_type = 'RB'
  LIMIT 1
";
if ($stmt = $conn->prepare($sqlSet)) {
  $stmt->bind_param('i',$set_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $set = $res->fetch_assoc();
  $stmt->close();
}
if (!$set) { flash_set('err','RB set not found.'); header('Location: stories_rb.php'); exit; }

/* ---------- Stories of this set ---------- */
$stories = [];
$sqlStories = "
  SELECT
    s.*,
    (SELECT COUNT(*) FROM story_items si WHERE si.story_id = s.story_id) AS item_count
  FROM stories s
  WHERE s.set_id = ?
  ORDER BY (s.status='published') DESC, s.updated_at DESC, s.story_id DESC
";
if ($stmt = $conn->prepare($sqlStories)) {
  $stmt->bind_param('i',$set_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row=$res->fetch_assoc()) $stories[] = $row;
  $stmt->close();
}

$PAGE_TITLE  = 'RB – Manage Stories';
$ACTIVE_MENU = 'stories';
$ACTIVE_SUB  = 'rb';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
$flash = flash_pop();
?>
<style>
  /* ====== Page layout (same as PB/SLT manage) ====== */
  .main-content{ padding-top:60px; }
  .page-wrap{ width:98%; margin:8px auto 22px; }

  /* Top-right back button container */
  .top-actions{
    display:flex; justify-content:flex-end; align-items:center;
    padding-bottom:1px; margin:6px 0 10px;
  }

  /* Buttons */
  .btn{
    display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:10px;
    border:1px solid #dfe5e8; background:#fff; cursor:pointer; font-weight:600;
    transition: transform .05s ease, box-shadow .15s ease, filter .12s ease;
    text-decoration:none; color:inherit;
  }
  .btn:hover{ box-shadow:0 2px 10px rgba(0,0,0,.08); }
  .btn:active{ transform: translateY(1px); }
  .btn-accent{ background:var(--accent); border-color:#d39b06; color:#1b1b1b; font-weight:700; }
  .btn-ghost{ background:transparent; border-color:rgba(0,0,0,.1); }

  .btn-back{
    background:#eaf7ea; border-color:#bfe3c6; color:#155724; font-weight:700; gap:10px;
  }
  .btn-back:hover{ filter:brightness(.985); box-shadow:0 2px 10px rgba(0,0,0,.06); }
  .btn-back i{
    display:inline-flex; width:20px; height:20px; align-items:center; justify-content:center;
    border-radius:999px; background:#d4edda; font-style:normal;
  }

  /* Sub-bar */
  .sub-bar{
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    background:#fff; padding:10px 12px; border-radius:10px; box-shadow: var(--shadow);
    border-left:6px solid var(--accent);
  }
  .sub-bar h3{ margin:0; color:var(--green); font-weight:800; }
  .sub-bar .muted{ color:#6b8b97; font-size:.95rem; }
  .sub-bar .grow{ flex:1; }

  /* Chips */
  .chip{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
  .c-pub{ background:#d4edda; color:#155724; }
  .c-arch{ background:#e2e3e5; color:#383d41; }
  .c-draft{ background:#fff3cd; color:#856404; }

  /* Table */
  .card{ background:#fff; border-radius:12px; box-shadow: var(--shadow); overflow:hidden; margin-top:12px; }
.table-head{
  display:grid;
  grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 280px; /* + Total Questions */
  gap:10px; padding:12px 14px; background:rgba(0,0,0,.03); color:#265; font-weight:700;
}
.row{
  display:grid;
  grid-template-columns: 60px 1.6fr .8fr .8fr .9fr 280px; /* + Total Questions */
  gap:10px; padding:12px 14px; border-top:1px solid #f0f2f4; align-items:center;
}
  .table-wrap{ overflow-x:auto; }
  .muted{ color:#6b8b97; }

  .status{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
  .st-draft{ background:#fff3cd; color:#856404; }
  .st-published{ background:#d4edda; color:#155724; }
  .st-archived{ background:#e2e3e5; color:#383d41; }

  .actions{ display:flex; gap:8px; flex-wrap:wrap; }
  .btn-edit{ background:#fff7ec; border-color:#e5c7a8; color:#7a4b10; }
  .btn-publish{ border-color:#bfe3c6; color:#155724; background:#eaf7ea; }
  .btn-archive{ border-color:#bfe3c6; color:#155724; background:#eaf7ea; }

  @media (max-width: 980px){
    .table-head, .row{ grid-template-columns: 50px 1.6fr .8fr 220px; }
    .hide-md{ display:none !important; }
  }
  @media (max-width: 640px){
    .top-actions{ margin-top:4px; }
    .top-actions .btn-back{ width:100%; justify-content:center; }
    .table-head{ display:none; }
    .row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
    .row > :nth-child(1){ grid-column:1/2; font-weight:700; }
    .row > :nth-child(2){ grid-column:2/3; }
    .row > :nth-child(n+3){ grid-column:1/-1; }
  }

  /* Bigger modal (SLT-like) */
  .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:2000; opacity:0; transition:opacity .18s ease-out; }
  .modal-backdrop.show{ display:flex; opacity:1; }
  .modal{
    width:min(920px,96vw); height:clamp(560px, 86vh, 900px);
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
  .modal header #rbCloseX{ color:#fff; border-color:rgba(255,255,255,.28); background:transparent; }
  .modal header #rbCloseX:hover{ background:rgba(255,255,255,.14); color:#fff; box-shadow:none; }

  .modal form{ display:flex; flex-direction:column; flex:1 1 auto; min-height:0; }
  .modal form section{
    flex:1 1 auto; min-height:0; overflow:auto;
    padding:16px; display:grid; gap:12px; grid-template-columns: 1fr;
  }
  .modal form footer{
    flex:0 0 auto; padding:12px 16px; display:flex; gap:10px; justify-content:flex-end;
    background:#fafafa; border-top:1px solid #eee;
  }
  .modal label{ font-weight:600; color:#123; }
  .modal input[type="text"], .modal select, .modal textarea{
    width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px;
  }
  .modal textarea{ height:260px; max-height:40vh; overflow:auto; resize:vertical; font-family:inherit; }

  #rbImgPreview{ max-width:100%; max-height:28vh; height:auto; border-radius:8px; border:1px solid #e5e7eb; }

  /* --- Questions UI (RB) --- */
.note-callout{
  background:#f5faf2; border:1px solid #d7ead0; color:#184e27;
  padding:10px 12px; border-radius:10px; font-size:.95rem;
  display:flex; gap:8px; align-items:flex-start;
}
.note-callout b{ font-weight:700; }
.note-compact{ padding:8px 10px; font-size:.93rem; line-height:1.25; }
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

.btn-additems{
  padding:10px 18px; border-radius:999px;
  box-shadow:0 2px 8px rgba(0,0,0,.15);
}
.btn-additems:hover{ filter:brightness(.98); }

/* Shake effect: hindi magsasara pag backdrop click/ESC, mag-sha-shake lang */
.modal.shake{ animation: rbModalShake .35s ease; }
@keyframes rbModalShake{
  0%,100%{ transform: translateY(0) scale(1) translateX(0); }
  20%    { transform: translateX(-8px); }
  40%    { transform: translateX( 8px); }
  60%    { transform: translateX(-6px); }
  80%    { transform: translateX( 6px); }
}
/* One-line actions row */
.actions-inline{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:nowrap;     /* keep in one line */
  white-space:nowrap;   /* avoid line breaks */
}
.actions-inline form{ display:inline; } /* para hindi mag line-break ang form */
.actions-inline .btn{ border-radius:999px; padding:8px 12px; font-weight:700; }

</style>

<div class="main-content">
  <div class="page-wrap">

    <?php if($flash): ?>
      <script>
        Swal.fire({
          icon: '<?= $flash['t']==='ok'?'success':'error' ?>',
          title: <?= json_encode($flash['m']) ?>,
          confirmButtonColor: '#ECA305'
        });
      </script>
    <?php endif; ?>

    <!-- Top-right back -->
    <div class="top-actions">
      <a class="btn btn-back" href="stories_rb.php" title="Back to RB Sets">
        <i>↩</i><span>Back to RB Sets</span>
      </a>
    </div>

    <!-- Sub-bar -->
    <div class="sub-bar">
      <h3><?= htmlspecialchars($set['title'] ?? 'RB Set') ?></h3>
      <?php
        $set_status = strtolower($set['status'] ?? 'draft');
        $chip = $set_status==='published' ? 'c-pub' : ($set_status==='archived' ? 'c-arch' : 'c-draft');
      ?>
      <span class="chip <?= $chip ?>"><?= htmlspecialchars($set['status'] ?? 'draft') ?></span>
      <span class="muted">Set ID: <?= (int)$set_id ?></span>
      <div class="grow"></div>
      <button class="btn btn-accent" id="btnAddStory">＋ Add Story</button>
    </div>

    <!-- Table -->
    <div class="card table-wrap">
      <div class="table-head">
        <div>#</div>
        <div>Story Title</div>
         <div>Total Questions</div>   <!-- NEW -->
        <div>Status</div>
        <div class="hide-md">Updated</div>
        <div>Actions</div>
      </div>

      <?php if(!$stories): ?>
        <div class="row">
          <div>—</div>
          <div class="muted">No stories yet. Click “Add Story”.</div>
            <div>0</div> <!-- Total Questions -->
          <div><span class="status st-draft">draft</span></div>
          <div class="hide-md">—</div>
          <div><button class="btn btn-accent" id="btnAddStory2">＋ Add your first story</button></div>
        </div>
      <?php endif; ?>

     <?php foreach($stories as $i=>$s): ?>
  <?php
    $st  = strtolower($s['status'] ?? 'draft');
    $cls = $st==='published' ? 'st-published' : ($st==='archived' ? 'st-archived' : 'st-draft');
    $itemCount = (int)($s['item_count'] ?? 0);
  ?>
  <div class="row">
    <div><?= $i+1 ?></div>
    <div style="font-weight:700; color:#123;"><?= htmlspecialchars($s['title'] ?? '—') ?></div>
    <div><?= $itemCount ?></div>
    <div><span class="status <?= $cls ?>"><?= htmlspecialchars($s['status'] ?? 'draft') ?></span></div>
    <div class="hide-md"><?= htmlspecialchars($s['updated_at'] ?? '') ?></div>

    <!-- ONE-LINE ACTIONS -->
    <div class="actions-inline">
      <!-- Edit -->
      <button
        class="btn btn-edit js-rb-edit"
        data-story='<?= json_encode([
         'story_id'           => (int)$s['story_id'],
'title'              => $s['title'] ?? '',
'author'             => $s['author'] ?? '',   // ← ADD THIS LINE
'status'             => $s['status'] ?? 'draft',
'image_path'         => $s['image_path'] ?? null,
'time_limit_seconds' => isset($s['time_limit_seconds']) ? (int)$s['time_limit_seconds'] : 0,

        ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
        data-pass="<?= htmlspecialchars($s['passage_html'] ?? '') ?>"
      >✏ Edit</button>

      <!-- Questions -->
      <button
        class="btn btn-questions js-rb-questions"
        data-story-id="<?= (int)$s['story_id'] ?>"
        data-story-title="<?= htmlspecialchars($s['title'] ?? 'Untitled') ?>"
      >❓ Questions</button>

      <!-- Publish / Archive -->
      <?php if(($s['status'] ?? 'draft')!=='published'): ?>
        <form method="post" action="rb_stories_action.php" class="js-status-form">
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
          <input type="hidden" name="story_id" value="<?= (int)$s['story_id'] ?>">
          <input type="hidden" name="status" value="published">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
          <button class="btn btn-publish">⬆ Publish</button>
        </form>
      <?php else: ?>
        <form method="post" action="rb_stories_action.php" class="js-status-form">
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
          <input type="hidden" name="story_id" value="<?= (int)$s['story_id'] ?>">
          <input type="hidden" name="status" value="archived">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
          <button class="btn btn-archive">🗂 Archive</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Add/Edit Story Modal -->
<div class="modal-backdrop" id="mRBStory">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mRBTitle">
    <header>
      <span id="mRBTitle">Add Story</span>
      <button type="button" class="btn btn-ghost" id="rbCloseX" aria-label="Close modal">✖</button>
    </header>

    <form method="post" action="rb_stories_action.php" id="rbStoryForm" enctype="multipart/form-data">
      <section>
        <div>
          <label for="rbTitle">Title <span style="color:#c00">*</span></label>
          <input type="text" name="title" id="rbTitle" required placeholder="e.g., Story A – The 999">
        </div>
        <div>
  <label for="rbTimeLimit">Story Time Limit (minutes)</label>
  <input type="number" name="time_limit_minutes" id="rbTimeLimit" min="0" step="1" placeholder="e.g., 30">
  <div class="muted" style="font-size:.95rem;">
    Applies to the whole story (reading + quiz). Leave blank or 0 = no limit.
  </div>
</div>


        <div>
          <label for="rbStatus">Status</label>
          <select name="status" id="rbStatus">
            <option value="draft">draft</option>
            <option value="published">published</option>
          </select>
        </div>

        <div>
          <label for="rbPassage">Passage</label>
          <textarea name="passage_html" id="rbPassage" placeholder="Paste the passage text here…"></textarea>
          <div class="muted" id="rbWordInfo">Words: 0</div>
        </div>
            <div>
      <label for="rbAuthor">Author (optional)</label>
      <input type="text" name="author" id="rbAuthor" placeholder="e.g., By Juan Dela Cruz">
    </div>

        <div>
          <label for="rbCover">Cover image (optional)</label>
          <input type="file" name="cover_image" id="rbCover" accept="image/*">
          <div class="muted" style="font-size:.85rem;">JPG, PNG, WebP, or GIF — up to 3 MB.</div>

          <img id="rbImgPreview" alt="Preview" style="display:none; margin-top:8px;">
          <button type="button" class="btn" id="rbClearImg" style="display:none; margin-top:6px;">Remove image</button>
        </div>
      </section>

      <input type="hidden" name="action" id="rbAction" value="add_story">
      <input type="hidden" name="story_id" id="rbStoryId" value="">
      <input type="hidden" name="remove_image" id="rbRemoveImage" value="0">
      <input type="hidden" name="set_id" value="<?= $set_id ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">

      <footer>
        <button type="button" class="btn btn-ghost" id="rbCancel">Cancel</button>
        <button class="btn btn-accent">Save Story</button>
      </footer>
    </form>
  </div>
</div>
<!-- RB Questions Modal -->
<div class="modal-backdrop" id="mRBQuestions">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rbQTitle">
    <header>
      <span id="rbQTitle">Questions — </span>
      <button type="button" class="btn btn-ghost" id="rbQClose" aria-label="Close modal">✖</button>
    </header>

    <form id="rbQForm" method="post" action="rb_questions_action.php">
      <section>
        <!-- Note -->
        <div class="note-callout note-compact">
          <b>ℹ️ Reminder:</b>&nbsp;Add the questions and their answer keys here.
          Click <b>Add Items</b> to add more questions.
        </div>

        <!-- Count -->
        <div class="qs-count" id="rbQCount">Total number of Questions: 0</div>

        <!-- Items render target -->
        <div id="rbQWrap"></div>

        <!-- Add button -->
        <div class="center-actions">
          <button type="button" class="btn btn-accent btn-additems" id="rbQAdd">＋ Add Items</button>
        </div>
      </section>

      <footer>
        <input type="hidden" name="action" value="batch_upsert">
        <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
        <input type="hidden" id="rbQStoryId"  name="story_id"  value="">
        <input type="hidden" id="rbQDeleteIds" name="delete_ids" value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
        <button type="button" class="btn" id="rbQCancel">Close</button>
        <button type="submit" class="btn btn-accent" id="rbQSave" disabled>Save Changes</button>
      </footer>
    </form>
  </div>
</div>

<script>
  // open/close modal
const m = document.getElementById('mRBStory');
const open  = () => m?.classList.add('show');
const close = () => m?.classList.remove('show');

// close only via X at Cancel
document.getElementById('rbCancel') ?.addEventListener('click', close);
document.getElementById('rbCloseX')?.addEventListener('click', close);

// backdrop / ESC => shake only (do not close)
function rbNudgeStory(){
  const box = m?.querySelector('.modal');
  if(!box) return;
  box.classList.remove('shake');   // restart animation
  void box.offsetWidth;            // reflow
  box.classList.add('shake');
}
m?.addEventListener('click', (e)=>{ if (e.target === m) rbNudgeStory(); });
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && m?.classList.contains('show')) rbNudgeStory();
});

// keep your existing “Add Story” open hooks (they call rbToAdd() then open)
document.getElementById('btnAddStory') ?.addEventListener('click', ()=>{ rbToAdd(); open(); });
document.getElementById('btnAddStory2')?.addEventListener('click', ()=>{ rbToAdd(); open(); });
  // SweetAlert for publish/archive
  document.querySelectorAll('.js-status-form').forEach(f=>{
    f.addEventListener('submit', function(e){
      e.preventDefault();
      const status = f.querySelector('input[name="status"]').value;
      const msg = status==='published' ? 'Publish this story?' : 'Archive this story?';
      Swal.fire({
        icon: 'question',
        title: msg,
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes'
      }).then(res=>{ if(res.isConfirmed) f.submit(); });
    });
  });

  // Word counter
  function rbWordCount(txt){
    const plain = (txt || '').replace(/<[^>]*>/g,' ').trim();
    const arr = plain ? plain.split(/\s+/).filter(Boolean) : [];
    return arr.length;
  }
  const rbPassage  = document.getElementById('rbPassage');
  const rbWordInfo = document.getElementById('rbWordInfo');
  function rbRefreshWords(){ if (rbWordInfo) rbWordInfo.textContent = 'Words: ' + rbWordCount(rbPassage?.value || ''); }
  rbPassage?.addEventListener('input', rbRefreshWords);

  // Image preview
  const rbCover    = document.getElementById('rbCover');
  const rbPreview  = document.getElementById('rbImgPreview');
  const rbClearImg = document.getElementById('rbClearImg');

  function rbClearPreview(){
    if (rbCover) rbCover.value = '';
    if (rbPreview){ rbPreview.src=''; rbPreview.style.display='none'; }
    if (rbClearImg) rbClearImg.style.display='none';
  }
  function rbSetPreview(src){
    if (!rbPreview) return;
    rbPreview.src = src;
    rbPreview.style.display = 'block';
    if (rbClearImg) rbClearImg.style.display='inline-flex';
  }
  rbCover?.addEventListener('change', ()=>{
    const f = rbCover.files?.[0];
    if (!f){ rbClearPreview(); return; }
    const reader = new FileReader();
    reader.onload = e => rbSetPreview(e.target.result);
    reader.readAsDataURL(f);
  });
  rbClearImg?.addEventListener('click', ()=>{
    rbClearPreview();
    document.getElementById('rbRemoveImage').value = '1';
  });
function rbToAdd(){
  document.getElementById('mRBTitle').textContent = 'Add Story';
  document.getElementById('rbAction').value = 'add_story';
  document.getElementById('rbStoryId').value = '';
  document.getElementById('rbRemoveImage').value = '0';

  document.getElementById('rbTitle').value = '';
  const rbStatus = document.getElementById('rbStatus');
  if (rbStatus) rbStatus.value = 'draft';

  const rbTime = document.getElementById('rbTimeLimit');
  if (rbTime) rbTime.value = '';   // blank = no limit

   const rbAuthor = document.getElementById('rbAuthor');   // NEW
  if (rbAuthor) rbAuthor.value = '';
    
  if (rbPassage) rbPassage.value = '';
  rbClearPreview();
  rbRefreshWords();
}

  // ADD mode reset
  function rbToAdd(){
    document.getElementById('mRBTitle').textContent = 'Add Story';
    document.getElementById('rbAction').value = 'add_story';
    document.getElementById('rbStoryId').value = '';
    document.getElementById('rbRemoveImage').value = '0';

    document.getElementById('rbTitle').value = '';
    const rbStatus = document.getElementById('rbStatus');
    if (rbStatus) rbStatus.value = 'draft';
    if (rbPassage) rbPassage.value = '';
    rbClearPreview();
    rbRefreshWords();
  }
  document.getElementById('btnAddStory')?.addEventListener('click', ()=>{ rbToAdd(); m.classList.add('show'); });
  document.getElementById('btnAddStory2')?.addEventListener('click', ()=>{ rbToAdd(); m.classList.add('show'); });

  // EDIT mode
document.querySelectorAll('.js-rb-edit').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const data = JSON.parse(btn.getAttribute('data-story') || '{}');
    const pass = btn.getAttribute('data-pass') || '';

    document.getElementById('mRBTitle').textContent = 'Edit Story';
    document.getElementById('rbAction').value  = 'update_story';
    document.getElementById('rbStoryId').value = data.story_id ?? '';
    document.getElementById('rbRemoveImage').value = '0';

    document.getElementById('rbTitle').value  = data.title ?? '';

        const rbAuthor = document.getElementById('rbAuthor');
    if (rbAuthor) rbAuthor.value = data.author || '';

    const statusVal = (data.status || 'draft').toLowerCase();
    const normalized = (statusVal === 'active') ? 'published' : statusVal;
    const rbStatus = document.getElementById('rbStatus');
    if (rbStatus) rbStatus.value = normalized;

const rbTime = document.getElementById('rbTimeLimit');
if (rbTime) {
  const secs = parseInt((data.time_limit_seconds ?? 0), 10) || 0;
  rbTime.value = secs > 0 ? Math.floor(secs / 60) : ''; // minutes; blank if none
}


    if (rbPassage) rbPassage.value = pass;

    rbClearPreview();
    let img = data.image_path || '';
    if (img) rbSetPreview(img);

    rbRefreshWords();
    m.classList.add('show');
  });
});

</script>

<script>
// ===== RB Questions: open/close + shake =====
const mRBQ    = document.getElementById('mRBQuestions');
const rbQOpen = () => mRBQ?.classList.add('show');
const rbQClose= () => mRBQ?.classList.remove('show');

document.getElementById('rbQClose') ?.addEventListener('click', rbQClose);
document.getElementById('rbQCancel')?.addEventListener('click', rbQClose);

// shake helper
function rbNudge(backdrop){
  const box = backdrop?.querySelector('.modal');
  if(!box) return;
  box.classList.remove('shake'); void box.offsetWidth; box.classList.add('shake');
}
// backdrop/ESC => shake only
mRBQ?.addEventListener('click', (e)=>{ if(e.target===mRBQ) rbNudge(mRBQ); });
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && mRBQ?.classList.contains('show')) rbNudge(mRBQ); });

// Elements
const rbQWrap   = document.getElementById('rbQWrap');
const rbQCount  = document.getElementById('rbQCount');
const rbQAdd    = document.getElementById('rbQAdd');
const rbQSave   = document.getElementById('rbQSave');
const rbQDelInp = document.getElementById('rbQDeleteIds');
const deletedRB = new Set();

// helpers
const escQ = s => (s||'').replace(/"/g,'&quot;');
function setRBCount(){
  const n = rbQWrap.querySelectorAll('.item-block').length;
  rbQCount.textContent = 'Total number of Questions: ' + n;
  rbQSave.disabled = (n===0 && deletedRB.size===0);
}
function renumRB(){
  [...rbQWrap.querySelectorAll('.item-block')].forEach((b,i)=>{
    b.querySelector('.item-num').textContent = i+1;
    b.querySelector('input[name="number[]"]').value = i+1;
  });
  setRBCount();
}

function makeRBItem(data={}){
  const wrap = document.createElement('div');
  wrap.className = 'item-block';

  const id     = data.item_id ? parseInt(data.item_id,10) : 0;
  const q      = data.question || '';
  const A      = (data.choices?.A)||'';
  const B      = (data.choices?.B)||'';
  const C      = (data.choices?.C)||'';
  const D      = (data.choices?.D)||'';
  const key    = (data.key || 'A').toUpperCase();
  const points = data.points ? parseInt(data.points,10) : 1;

  wrap.innerHTML = `
    <input type="hidden" name="item_id[]" value="${id}">
    <input type="hidden" name="number[]" value="0">
    <div class="item-head">
      <div class="item-num">1</div>
      <input type="text" class="q-text" name="question_text[]" placeholder="Type the question here…" required value="${escQ(q)}">
      <div class="correct-select">
        <label>Correct Answer:</label>
        <select class="q-correct" name="correct_letter[]">
          ${['A','B','C','D'].map(L=>`<option value="${L}" ${L===key?'selected':''}>${L}</option>`).join('')}
        </select>
      </div>
      <button type="button" class="btn item-remove" title="Delete">🗑</button>
    </div>
    <div class="two-col">
      <div class="choice-line"><span class="letter">a.</span><input type="text" name="choice_a[]" value="${escQ(A)}" required></div>
      <div class="choice-line"><span class="letter">c.</span><input type="text" name="choice_c[]" value="${escQ(C)}" required></div>
      <div class="choice-line"><span class="letter">b.</span><input type="text" name="choice_b[]" value="${escQ(B)}" required></div>
      <div class="choice-line"><span class="letter">d.</span><input type="text" name="choice_d[]" value="${escQ(D)}" required></div>
    </div>
    <input type="hidden" name="points[]" value="${points}">
  `;

  // delete
  wrap.querySelector('.item-remove').onclick = ()=>{
    const ex = parseInt(wrap.querySelector('input[name="item_id[]"]').value||'0',10);
    if (ex>0) deletedRB.add(ex);
    wrap.remove();
    rbQSave.disabled = false;
    renumRB();
  };

  // any edit -> enable save
  wrap.querySelectorAll('input,select,textarea').forEach(el=>{
    el.addEventListener('input', ()=> rbQSave.disabled = false);
    el.addEventListener('change', ()=> rbQSave.disabled = false);
  });

  return wrap;
}

rbQAdd?.addEventListener('click', ()=>{
  rbQWrap.appendChild(makeRBItem());
  rbQSave.disabled = false;
  renumRB();
});


// Validate + attach deletions on submit
document.getElementById('rbQForm')?.addEventListener('submit', (e)=>{
  rbQDelInp.value = Array.from(deletedRB).join(',');

  const blocks = rbQWrap.querySelectorAll('.item-block');
  if (blocks.length===0 && deletedRB.size>0) return; // delete-only is OK

  for (const b of blocks){
    const q  = b.querySelector('.q-text')?.value.trim();
    const a  = b.querySelector('input[name="choice_a[]"]')?.value.trim();
    const bb = b.querySelector('input[name="choice_b[]"]')?.value.trim();
    const c  = b.querySelector('input[name="choice_c[]"]')?.value.trim();
    const d  = b.querySelector('input[name="choice_d[]"]')?.value.trim();
    const corr = b.querySelector('.q-correct')?.value;
    const map = {A:a,B:bb,C:c,D:d};
    const nonEmpty = [a,bb,c,d].filter(Boolean).length;
    if (!q || nonEmpty<2 || !corr || !map[corr]){
      e.preventDefault();
      Swal.fire({
        icon:'error',
        title:'Please complete each item',
        text:'Question, at least 2 choices, and a valid answer key are required.',
        confirmButtonColor:'#ECA305'
      });
      return;
    }
  }
});
</script>
<script>
// 3.a loader
async function loadRBItems(setId, storyId){
  try{
    const resp = await fetch(
      `rb_questions_fetch.php?set_id=${encodeURIComponent(setId)}&story_id=${encodeURIComponent(storyId)}`,
      { credentials:'same-origin' }
    );
    const text = await resp.text();

    let data;
    try { data = JSON.parse(text); }
    catch(e){
      // Server returned HTML (warning/redirect). Show raw snippet so we see the real error.
      throw new Error(text);
    }

    if (!data.ok) throw new Error(data.error || 'Server returned an error.');

    rbQWrap.innerHTML = '';
    deletedRB.clear();
    rbQSave.disabled = true;

    (data.items || []).forEach(it => rbQWrap.appendChild(makeRBItem(it)));
    renumRB();
  }catch(err){
    Swal.fire({
      icon:'error',
      title:'Load failed',
      // show a snippet to help during debugging
      html: String(err).slice(0, 1000),
      confirmButtonColor:'#ECA305'
    });
    rbQWrap.innerHTML = '';
    renumRB();
  }
}

// 3.b modify open handler (REPLACE your current .js-rb-questions handler body)
document.querySelectorAll('.js-rb-questions').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const sid   = btn.getAttribute('data-story-id');
    const title = btn.getAttribute('data-story-title') || 'Untitled';
    document.getElementById('rbQTitle').textContent = 'Questions — ' + title;
    document.getElementById('rbQStoryId').value = sid;

    // load existing from server, then open
    loadRBItems(<?= (int)$set_id ?>, sid);
    rbQOpen();
  });
});
</script>

</body></html>
