<?php
// admin/pb_manage.php
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


function flash_set($type,$msg){ $_SESSION['pb_flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['pb_flash']??null; unset($_SESSION['pb_flash']); return $f; }

/* ---------- Guard: need a valid PB set_id ---------- */
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;
if ($set_id <= 0) { header('Location: stories_pb.php'); exit; }

/* ---------- Fetch PB set ---------- */
$set = null;
$sqlSet = "
  SELECT ss.*, lvl.name AS level_name, lvl.color_hex
  FROM story_sets ss
  LEFT JOIN sra_levels lvl ON lvl.level_id = ss.level_id
  WHERE ss.set_id = ? AND ss.set_type = 'PB'
  LIMIT 1
";
if ($stmt = $conn->prepare($sqlSet)) {
  $stmt->bind_param('i',$set_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $set = $res->fetch_assoc();
  $stmt->close();
}
if (!$set) { flash_set('err','PB set not found.'); header('Location: stories_pb.php'); exit; }

/* ---------- Stories of this set ---------- */
$stories = [];
$sqlStories = "
  SELECT s.*,
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

$PAGE_TITLE  = 'PB – Manage Stories';
$ACTIVE_MENU = 'stories';
$ACTIVE_SUB  = 'pb';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
$flash = flash_pop();
?>
<style>
  /* ====== Page layout (match SLT manage) ====== */
  .main-content{ padding-top:60px; }
  .page-wrap{ width:98%; margin:8px auto 22px; }

  /* Top-right back button container */
  .top-actions{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    padding-bottom: 1px;
      margin: 6px 0 10px;   
  }

  /* Buttons (shared look) */
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

  /* Back pill (green), same vibe as SLT */
  .btn-back{
    background:#eaf7ea;          /* light green */
    border-color:#bfe3c6;
    color:#155724;
    font-weight:700;
    gap:10px;
  }
  .btn-back:hover{
    filter:brightness(.985);
    box-shadow:0 2px 10px rgba(0,0,0,.06);
  }
  .btn-back i{
    display:inline-flex;
    width:20px; height:20px;
    align-items:center; justify-content:center;
    border-radius:999px;
    background:#d4edda;          /* badge feel */
    font-style:normal;
  }

  /* Sub-bar (title line) */
  .sub-bar{
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    background:#fff; padding:10px 12px; border-radius:10px; box-shadow: var(--shadow);
    border-left:6px solid var(--accent);
  }
  .sub-bar h3{ margin:0; color:var(--green); font-weight:800; }
  .sub-bar .muted{ color:#6b8b97; font-size:.95rem; }
  .sub-bar .grow{ flex:1; }

  /* Chips (status pills) */
  .chip{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
  .c-pub{ background:#d4edda; color:#155724; }
  .c-arch{ background:#e2e3e5; color:#383d41; }
  .c-draft{ background:#fff3cd; color:#856404; }

/* Table */
.card{ background:#fff; border-radius:12px; box-shadow: var(--shadow); overflow:hidden; margin-top:12px; }

/* 6 columns: # | Title | Total Questions | Status | Updated | Actions */
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
.table-wrap{ overflow-x:auto; }
.muted{ color:#6b8b97; }

/* pills */
.status{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
.st-draft{ background:#fff3cd; color:#856404; }
.st-published{ background:#d4edda; color:#155724; }
.st-archived{ background:#e2e3e5; color:#383d41; }

/* actions in one line (wrap only if maliit ang screen) */
.actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

@media (max-width: 980px){
  /* itago ang 'Updated' column pero i-keep ang 'Total Questions' */
  .table-head, .row{ grid-template-columns: 50px 1.6fr .8fr .6fr 220px; }
  .hide-md{ display:none !important; }
}
@media (max-width: 640px){
  .table-head{ display:none; }
  .row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
  .row > :nth-child(1){ grid-column:1/2; font-weight:700; }
  .row > :nth-child(2){ grid-column:2/3; }
  .row > :nth-child(n+3){ grid-column:1/-1; }
}

  /* Modal (kept your original sizes) */
  .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:2000; opacity:0; transition:opacity .18s ease-out; }
  .modal-backdrop.show{ display:flex; opacity:1; }
  .modal{ width:min(640px, 92vw); background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25); overflow:hidden;
          transform: translateY(8px) scale(.985); opacity:0; transition: transform .18s ease-out, opacity .18s ease-out; }
  .modal-backdrop.show .modal{ transform: translateY(0) scale(1); opacity:1; }
  .modal header{ background:var(--green); color:#fff; padding:12px 16px; font-weight:700; display:flex; justify-content:space-between; align-items:center; }
  .modal section{ padding:16px; display:grid; gap:10px; }
  .modal .control{ display:grid; gap:6px; }
  .modal input[type="text"], .modal textarea{ width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px; }
  .modal footer{ padding:12px 16px; display:flex; gap:10px; justify-content:flex-end; background:#fafafa; }
  /* tighten space above the Back pill */

@media (max-width:640px){
  .top-actions{ margin-top: 2px; }  /* keep it tight on mobile too */
}
/* Bigger, SLT-like modal */
.modal{
  width:min(920px,96vw);
  height:clamp(560px, 86vh, 900px);
  background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25);
  display:flex; flex-direction:column; overflow:hidden;
  transform: translateY(8px) scale(.985); opacity:0;
  transition: transform .18s ease-out, opacity .18s ease-out;
}
.modal header{
  flex:0 0 auto;
  background:var(--green); color:#fff; padding:12px 16px; font-weight:700;
  display:flex; justify-content:space-between; align-items:center;
}
.modal header #pbCloseX{ color:#fff; border-color:rgba(255,255,255,.28); background:transparent; }
.modal header #pbCloseX:hover{ background:rgba(255,255,255,.14); color:#fff; box-shadow:none; }

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

#pbImgPreview{
  max-width:100%; max-height:28vh; height:auto; border-radius:8px; border:1px solid #e5e7eb;
}
/* === PB Questions modal styles (same vibe as SLT) === */
.note-callout{background:#f5faf2;border:1px solid #d7ead0;color:#184e27;padding:10px 12px;border-radius:10px;font-size:.95rem;display:flex;gap:8px;align-items:flex-start}
.note-callout b{font-weight:700}.note-callout i{font-style:normal;font-weight:700}
.note-top{margin:6px 0 8px}.note-compact{padding:8px 10px;font-size:.93rem;line-height:1.25}
.qs-count{color:#123;font-weight:700;font-size:.95rem;margin:6px 0 10px}
.item-block{border:1px solid #eef2f4;border-radius:12px;padding:12px;margin:10px 0;background:#fcfcfc}
.item-head{display:flex;align-items:center;gap:12px}
.item-num{width:34px;height:34px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:800;background:#eef7ff;border:1px solid #cfe3ff;color:#1b3a7a}
.item-head input[type="text"]{flex:1}
.correct-select{display:flex;align-items:center;gap:6px;white-space:nowrap}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
.choice-line{display:flex;align-items:center;gap:8px}
.choice-line .letter{width:18px;text-align:center;font-weight:700}
.center-actions{display:flex;justify-content:center;margin-top:10px}
.item-remove{margin-left:8px}
.btn-additems{padding:10px 18px;border-radius:999px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.btn-additems:hover{filter:brightness(.98)}
/* One-line actions row */
.actions-inline{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:nowrap;    /* keep in one line */
  white-space:nowrap;
}
.actions-inline form{ display:inline; }

/* Pill look */
.actions-inline .btn{
  border-radius:999px;
  padding:8px 12px;
  font-weight:700;
}

/* Edit (light blue) */
.btn-edit{
  background:#eef3ff;
  border-color:#cad5fb;
  color:#203a8f;
}

/* Questions (white w/ subtle border, red-ish label) – keep if you show Questions here */
.btn-questions{
  background:#ffffff;
  border-color:#e6e9ee;
  color:#7a1a00;
}

/* PB actions */
.btn-publish{            /* green, like Start Level Test */
  background:#eaf7ea;
  border-color:#bfe3c6;
  color:#155724;
}
.btn-archive{            /* soft gray */
  background:#f4f6f8;
  border-color:#dfe5e8;
  color:#334155;
}
/* Shake animation kapag nag-click sa labas / ESC */
.modal.shake{
  animation: pbModalShake .35s ease;
}
@keyframes pbModalShake{
  0%,100%{ transform: translateY(0) scale(1) translateX(0); }
  20%    { transform: translateY(0) scale(1) translateX(-8px); }
  40%    { transform: translateY(0) scale(1) translateX(8px); }
  60%    { transform: translateY(0) scale(1) translateX(-6px); }
  80%    { transform: translateY(0) scale(1) translateX(6px); }
}

/* (optional) mas visible ang X sa green header */
#pbCloseX{
  color:#fff !important;
  border-color:rgba(255,255,255,.35) !important;
  background:rgba(255,255,255,.06) !important;
}
#pbCloseX:hover{
  background:rgba(255,255,255,.18) !important;
  color:#fff !important;
  box-shadow:none !important;
}
/* Give the last column room to grow and a wider minimum */
.table-head,
.row{
  display:grid;
  grid-template-columns: 60px 1.6fr .8fr .8fr .9fr minmax(320px, 1fr);
  gap:10px;
}

/* Let buttons wrap instead of forcing one line */
.actions-inline{
  display:flex;
  gap:8px 10px;
  align-items:center;
  flex-wrap:wrap;      /* allow wrap */
  white-space:normal;  /* allow text to wrap */
}

/* Prevent other columns from forcing overflow */
.row > div { min-width: 0; }
@media (max-width: 980px){
  .table-head, .row{
    grid-template-columns: 50px 1.6fr .8fr .6fr minmax(260px, 1fr);
  }
}
/* PBQ preview modal: make the body scrollable */
#mPBQ .modal { display:flex; flex-direction:column; height:clamp(560px,86vh,900px); }
#mPBQ .modal > section { flex:1 1 auto; min-height:0; overflow:auto; padding:16px; }

/* Group headers + list spacing */
#pbQList { display:grid; gap:14px; }
.pbq-cat { margin:4px 0 6px; font-weight:800; color:#123; font-size:1rem; }
.pbq-group { border:1px solid #eef2f4; border-radius:12px; padding:10px; background:#fff; display:grid; gap:10px; }

/* little badge for the correct choice */
.badge-corr{
  display:inline-block; font-size:.75rem; padding:2px 6px; border-radius:999px;
  background:#eaf7ea; color:#155724; border:1px solid #bfe3c6;
}
.qchoice-correct{ font-weight:700; }
/* Scrollable body for PB Questions preview modal */
#mPBQ .modal { display:flex; flex-direction:column; height:clamp(560px,86vh,900px); }
#mPBQ .modal > section { flex:1 1 auto; min-height:0; overflow:auto; padding:16px; }

/* Category header style */
.pbq-cat {
  position: sticky; top: 0;
  background: #f8fafc; border: 1px solid #e5e7eb;
  padding: 8px 12px; margin: 8px 0 6px; border-radius: 10px;
  font-weight: 800; color:#0f172a;
}
.badge-corr {
  display:inline-block; margin-left:6px; padding:2px 8px; border-radius:999px;
  background:#dcfce7; color:#14532d; font-weight:700; font-size:.75rem;
}
.qchoice-correct { font-weight:700; }
/* Directions block in Questions preview */
.dir-note{
  margin:6px 0 10px;
  padding:10px 12px;
  background:#f5faf2;
  border:1px solid #d7ead0;
  border-radius:10px;
  color:#184e27;
  font-size:.95rem;
  white-space:pre-wrap;
}
/* Correct answer pill */
.ans-pill{
  margin-left:auto;                  /* tumulak sa far right */
  align-self:flex-start;
  padding:4px 8px;
  border-radius:999px;
  background:#eef7ff;
  border:1px solid #cfe3ff;
  color:#1b3a7a;
  font-weight:800;
  font-size:.85rem;
  white-space:nowrap;
}
/* Numbered paragraphs (preview + actual read view) */
.pb-num { counter-reset: para; }
.pb-num p{
  position:relative;
  margin:0 0 .5em;
  padding-left:1.4em;           /* space for the number */
}
.pb-num p::before{
  counter-increment: para;
  content: counter(para);
  position:absolute;
  left:0;
  top:-.20em;                   /* slight “superscript” look */
  font-size:.75em;
  font-weight:800;
  color:#0f172a;
}
.badge-corr { display: none !important; }

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

    <!-- Top-right back (same position/feel as SLT) -->
    <div class="top-actions">
      <a class="btn btn-back" href="stories_pb.php" title="Back to PB Sets">
        <i>↩</i><span>Back to PB Sets</span>
      </a>
    </div>

    <!-- Sub-bar header (title + set status + set id + Add Story at right) -->
    <div class="sub-bar">
      <h3><?= htmlspecialchars($set['title'] ?? 'PB Set') ?></h3>
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
          <div>Total Questions</div>
        <div>Status</div>
        <div class="hide-md">Updated</div>
        <div>Actions</div>
      </div>

      <?php if(!$stories): ?>
        <div class="row">
          <div>—</div>
          <div class="muted">No stories yet. Click “Add Story”.</div>
          <div><span class="status st-draft">draft</span></div>
          <div class="hide-md">—</div>
          <div><button class="btn btn-accent" id="btnAddStory2">＋ Add your first story</button></div>
        </div>
      <?php endif; ?>

      <?php foreach($stories as $i=>$s): ?>
  <?php
    $st = strtolower($s['status'] ?? 'draft');
    $cls = $st==='published' ? 'st-published' : ($st==='archived' ? 'st-archived' : 'st-draft');
    $itemCount = (int)($s['item_count'] ?? 0);
  ?>
  <div class="row">
    <!-- # -->
    <div><?= $i+1 ?></div>

    <!-- Title -->
    <div>
      <div style="font-weight:700; color:#123;"><?= htmlspecialchars($s['title'] ?? '—') ?></div>
    </div>

    <!-- Total Questions -->
    <div><?= $itemCount ?></div>

    <!-- Status -->
    <div><span class="status <?= $cls ?>"><?= htmlspecialchars($s['status'] ?? 'draft') ?></span></div>

    <!-- Updated -->
    <div class="hide-md"><?= htmlspecialchars($s['updated_at'] ?? '') ?></div>

    <!-- Actions (one line) -->
   <div class="actions-inline">

  <!-- Edit -->
 <?php
$rawImg = $s['image_path'] ?? '';
$imgUrl = $rawImg;

if ($rawImg && !preg_match('#^https?://#', $rawImg)) {
  // "/sra" mula sa "/sra/admin/pb_manage.php"
  $appRoot = preg_replace('#/admin$#','', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));

  if (strpos($rawImg, '/uploads/') === 0) {
    // ex: "/uploads/…" -> "/sra/uploads/…"
    $imgUrl = $appRoot . $rawImg;
  } elseif (strpos($rawImg, 'uploads/') === 0 || strpos($rawImg, './uploads/') === 0) {
    // ex: "uploads/…" -> "/sra/uploads/…"
    $imgUrl = $appRoot . '/' . ltrim($rawImg, './');
  } else {
    // fallback
    $imgUrl = '../' . ltrim($rawImg, './');
  }
}
?>
<button
  class="btn btn-edit js-pb-edit"
  data-story='<?= json_encode([
    "story_id"   => (int)$s["story_id"],
    "title"      => $s["title"] ?? "",
    "status"     => $s["status"] ?? "draft",
    "image_path" => $imgUrl ?: null,   // <— normalized URL
    "time_limit_seconds" => isset($s["time_limit_seconds"]) ? (int)$s["time_limit_seconds"] : 0,
  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
  data-pass="<?= htmlspecialchars($s['passage_html'] ?? '') ?>"
>✏ Edit</button>


  <!-- (Optional) Questions button for PB, if you're showing it here -->
<button
  type="button"
  class="btn btn-questions js-pb-qpreview"
  data-story-id="<?= (int)$s['story_id'] ?>"
  data-story-title="<?= htmlspecialchars($s['title'] ?? 'Untitled') ?>"
>
  ❓ Questions
</button>

  <!-- Publish / Archive (PB) -->
  <?php if(($s['status'] ?? 'draft') !== 'published'): ?>
    <form method="post" action="pb_stories_action.php" class="js-status-form">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
      <input type="hidden" name="story_id" value="<?= (int)$s['story_id'] ?>">
      <input type="hidden" name="status" value="published">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
      <button class="btn btn-publish">⬆ Publish</button>
    </form>
  <?php else: ?>
    <form method="post" action="pb_stories_action.php" class="js-status-form">
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

<!-- Add/Edit Story Modal (SLT-like) -->
<div class="modal-backdrop" id="mAddStory">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mAddStoryTitle">
    <header>
      <span id="mAddStoryTitle">Add Story</span>
      <button type="button" class="btn btn-ghost" id="pbCloseX" aria-label="Close modal">✖</button>
    </header>

    <form method="post" action="pb_stories_action.php" id="addStoryForm" enctype="multipart/form-data">
      <section>
        <div>
          <label for="pbTitle">Title <span style="color:#c00">*</span></label>
          <input type="text" name="title" id="pbTitle" required placeholder="e.g., Story A – The 999">
        </div>

        <!-- NOTE: PB uses draft/published/archived. Keep PB-friendly options here. -->
        <div>
          <label for="pbStatus">Status</label>
          <select name="status" id="pbStatus">
            <option value="draft">draft</option>
            <option value="published">published</option>
          </select>
        </div>
       <div>
  <label for="pbTimeLimit">Time limit (minutes)</label>
  <input type="number" name="time_limit_minutes" id="pbTimeLimit" min="0" step="1"
         placeholder="e.g., 10 for 10 minutes">
  <div class="muted" style="font-size:.85rem;">Leave blank or 0 for no time limit.</div>
</div>
        <div>
          <label for="pbPassage">Passage</label>
          <div class="note-callout note-compact">Paragraphs are auto-numbered on student view. Live preview:</div>
<div id="pbPassagePreview" class="pb-num"
     style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;max-height:200px;overflow:auto;background:#fff"></div>

          <textarea name="passage_html" id="pbPassage" placeholder="Paste the passage text here…"></textarea>
          <div class="muted" id="pbWordInfo">Words: 0</div>
        </div>

        <div>
          <label for="pbCover">Cover image (optional)</label>
          <input type="file" name="cover_image" id="pbCover" accept="image/*">
          <div class="muted" style="font-size:.85rem;">JPG, PNG, WebP, o GIF — hanggang 3 MB.</div>

          <img id="pbImgPreview" alt="Preview" style="display:none; margin-top:8px;">
          <button type="button" class="btn" id="pbClearImg" style="display:none; margin-top:6px;">Remove image</button>
        </div>
      </section>
        <input type="hidden" name="action" id="pbAction" value="add_story">
<input type="hidden" name="story_id" id="pbStoryId" value="">
<input type="hidden" name="remove_image" id="pbRemoveImage" value="0">
<input type="hidden" name="set_id" value="<?= $set_id ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">

      <footer>
        <button type="button" class="btn btn-ghost" id="mAddClose">Cancel</button>
        <button class="btn btn-accent">Save Story</button>
      </footer>
    </form>
  </div>
</div>

<!-- PB Questions Modal -->
<!-- PB Questions Preview Modal -->
<div class="modal-backdrop" id="mPBQ">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="pbQTitle">
    <header>
      <span id="pbQTitle">Questions — </span>
      <button type="button" class="btn btn-ghost" id="pbQCloseX" aria-label="Close">✖</button>
    </header>

    <section>
      <div class="qs-count" id="pbQCount">Total number of Questions: 0</div>
      <div id="pbQList"></div>
    </section>

    <footer>
      <a class="btn btn-edit" id="pbQEditLink" href="#" target="_self">✏ Edit / Add</a>
      <button type="button" class="btn" id="pbQCloseBtn">Close</button>
    </footer>
  </div>
</div>

<script>
(function(){
  // ---------- CONFIG ----------
  var SET_ID = <?= (int)$set_id ?>;

  // ---------- Helpers ----------
  function on(el, ev, h){ if(el) el.addEventListener(ev, h); }
  function nudge(backdrop){
    if(!backdrop) return;
    var box = backdrop.querySelector('.modal');
    if(!box) return;
    box.classList.remove('shake'); void box.offsetWidth; box.classList.add('shake');
  }
  function getQueryParam(url, name){
    name = name.replace(/[\[\]]/g,'\\$&');
    var regex = new RegExp('[?&]'+name+'(=([^&#]*)|&|#|$)');
    var results = regex.exec(url);
    if(!results || !results[2]) return null;
    return decodeURIComponent(results[2].replace(/\+/g,' '));
  }

  // =========================================================
  // =============== Add/Edit Story MODAL ====================
  // =========================================================
  var mAdd = document.getElementById('mAddStory');
  function openAdd(){ if(mAdd) mAdd.classList.add('show'); }
  function closeAdd(){ if(mAdd) mAdd.classList.remove('show'); }

  on(document.getElementById('pbCloseX'), 'click', closeAdd);
  on(document.getElementById('mAddClose'), 'click', closeAdd);
  if(mAdd){ mAdd.addEventListener('click', function(e){ if(e.target===mAdd) nudge(mAdd); }); }
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && mAdd && mAdd.classList.contains('show')) nudge(mAdd); });

  // SweetAlert confirm for publish/archive
  document.querySelectorAll('.js-status-form').forEach(function(f){
    f.addEventListener('submit', function(e){
      e.preventDefault();
      var status = f.querySelector('input[name="status"]').value;
      var msg = (status==='published') ? 'Publish this story?' : 'Archive this story?';
      Swal.fire({
        icon: 'question',
        title: msg,
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes'
      }).then(function(res){ if(res.isConfirmed) f.submit(); });
    });
  });

  <?php if (!empty($flash)): ?>
  Swal.fire({
    icon: '<?= $flash['t']==='ok'?'success':'error' ?>',
    title: <?= json_encode($flash['m']) ?>,
    confirmButtonColor: '#ECA305'
  });
  <?php endif; ?>

  // Word counter
  function pbWordCount(txt){
    var plain = (txt || '').replace(/<[^>]*>/g,' ').trim();
    var arr = plain ? plain.split(/\s+/).filter(Boolean) : [];
    return arr.length;
  }
  var pbPassage  = document.getElementById('pbPassage');
  var pbWordInfo = document.getElementById('pbWordInfo');
  function pbRefreshWords(){
    if (pbWordInfo) pbWordInfo.textContent = 'Words: ' + pbWordCount(pbPassage ? pbPassage.value : '');
  }
  on(pbPassage,'input',pbRefreshWords);

  // Image preview
  var pbCover    = document.getElementById('pbCover');
  var pbPreview  = document.getElementById('pbImgPreview');
  var pbClearImg = document.getElementById('pbClearImg');

  function pbClearPreview(){
    if (pbCover) pbCover.value = '';
    if (pbPreview){ pbPreview.src=''; pbPreview.style.display='none'; }
    if (pbClearImg) pbClearImg.style.display='none';
  }
  function pbSetPreview(src){
    if (!pbPreview) return;
    pbPreview.src = src;
    pbPreview.style.display = 'block';
    if (pbClearImg) pbClearImg.style.display='inline-flex';
  }
  on(pbCover,'change',function(){
    var f = pbCover && pbCover.files ? pbCover.files[0] : null;
    if (!f){ pbClearPreview(); return; }
    var reader = new FileReader();
    reader.onload = function(e){ pbSetPreview(e.target.result); };
    reader.readAsDataURL(f);
  });
  on(pbClearImg,'click',pbClearPreview);

  // ONE definition of pbToAdd
  function pbToAdd(){
    document.getElementById('mAddStoryTitle').textContent = 'Add Story';
    document.getElementById('pbAction').value  = 'add_story';
    document.getElementById('pbStoryId').value = '';
    document.getElementById('pbRemoveImage').value = '0';
    document.getElementById('pbTitle').value   = '';
    var pbStatus = document.getElementById('pbStatus');
    if (pbStatus) pbStatus.value = 'draft';
    if (pbPassage) pbPassage.value = '';
     var pbTL = document.getElementById('pbTimeLimit');
  if (pbTL) pbTL.value = '';
  pbClearPreview();
  pbRefreshWords();
  }
  on(document.getElementById('btnAddStory'),  'click', function(){ pbToAdd(); openAdd(); });
  on(document.getElementById('btnAddStory2'), 'click', function(){ pbToAdd(); openAdd(); });

  // EDIT mode (prefill)
  document.querySelectorAll('.js-pb-edit').forEach(function(btn){
    btn.addEventListener('click', function(){
      var data = JSON.parse(btn.getAttribute('data-story') || '{}');
      var pass = btn.getAttribute('data-pass') || '';

      document.getElementById('mAddStoryTitle').textContent = 'Edit Story';
      document.getElementById('pbAction').value  = 'update_story';
      document.getElementById('pbStoryId').value = data.story_id || '';
      document.getElementById('pbRemoveImage').value = '0';
      document.getElementById('pbTitle').value  = data.title || '';

      var statusVal = (data.status || 'draft').toLowerCase();
      var normalized = (statusVal === 'active') ? 'published' : statusVal;
      var pbStatus = document.getElementById('pbStatus');
      if (pbStatus) pbStatus.value = normalized;

      if (pbPassage) pbPassage.value = pass;
      var pbTL = document.getElementById('pbTimeLimit');
if (pbTL) {
  var tlim = parseInt(data.time_limit_seconds || 0, 10); // seconds from DB
  var mins = tlim > 0 ? Math.round(tlim / 60) : '';       // convert to minutes
  pbTL.value = mins;
}
pbClearPreview();
var img = data.image_path || '';

// gawing absolute-from-root (/uploads/...) o idagdag ang ../ kapag relative lang
if (img) {
  if (!/^https?:\/\//i.test(img) && img.charAt(0) !== '/') {
    img = '../' + img.replace(/^\.\//, '');
  }
  pbSetPreview(img);
}
      pbRefreshWords();
      openAdd();
    });
  });

  on(document.getElementById('pbClearImg'),'click',function(){
    document.getElementById('pbRemoveImage').value = '1';
  });

  // =========================================================
  // ============ PB Questions PREVIEW MODAL =================
  // =========================================================
  var mPBQ     = document.getElementById('mPBQ');           // <div id="mPBQ">…</div>
  var pbQList  = document.getElementById('pbQList');        // container ng items
  var pbQCount = document.getElementById('pbQCount');       // "Total number of Questions: N"
  var pbQEdit  = document.getElementById('pbQEditLink');    // <a> to pb_questions.php
  var pbQTitle = document.getElementById('pbQTitle');       // "Questions — {title}"

  function pbQOpen(){ if(mPBQ) mPBQ.classList.add('show'); }
  function pbQClose(){ if(mPBQ) mPBQ.classList.remove('show'); }

  on(document.getElementById('pbQCloseX'),'click',pbQClose);
  on(document.getElementById('pbQCloseBtn'),'click',pbQClose);
  if(mPBQ){ mPBQ.addEventListener('click', function(e){ if(e.target===mPBQ) nudge(mPBQ); }); }
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && mPBQ && mPBQ.classList.contains('show')) nudge(mPBQ); });

  function loadPBPreview(setId, storyId){
    pbQList.innerHTML = '<div class="muted">Loading…</div>';
    fetch('pb_questions_fetch.php?set_id='+encodeURIComponent(setId)+'&story_id='+encodeURIComponent(storyId), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){ window.renderPBPreview(j.ok ? (j.items||[]) : []); })
      .catch(function(){ pbQList.innerHTML = '<div class="warn">Failed to load questions.</div>'; });
  }

  function getTitleFromRow(btn){
    var row = btn.closest ? btn.closest('.row') : null;
    if(!row) return 'Untitled';
    var cell = row.children && row.children[1] ? row.children[1] : null; // 2nd grid column
    var titleDiv = cell ? cell.querySelector('div') : null;
    var txt = titleDiv ? titleDiv.textContent : '';
    txt = (txt || '').trim();
    return txt || 'Untitled';
  }

  // Intercept "Questions" buttons (anchors with .btn-questions)
  document.querySelectorAll('.btn-questions').forEach(function(a){
    a.addEventListener('click', function(e){
      // open preview modal instead of navigating
      e.preventDefault();

      // storyId: use data attr if present; else parse from href
      var sid = a.getAttribute('data-story-id') || getQueryParam(a.getAttribute('href')||'', 'story_id') || '';
      var title = a.getAttribute('data-story-title') || getTitleFromRow(a);

      if (pbQTitle) pbQTitle.textContent = 'Questions — ' + title;
      if (pbQEdit)  pbQEdit.href = a.getAttribute('href') || ('pb_questions.php?set_id='+SET_ID+'&story_id='+encodeURIComponent(sid));

      if (sid) loadPBPreview(SET_ID, sid);
      pbQOpen();
    });
  });

})(); 
</script>
<script>
(function () {
  /* --- Minimal formatter for preview:
       - supports **bold** → <b>…</b>
       - keeps only <b>/<strong>/<i>/<em>/<u>/<br> tags
  ----------------------------------------------------------------- */
  function sanitizeLite(html) {
    var d = document.createElement('div');
    d.innerHTML = (html || '');
    var nodes = d.querySelectorAll('*');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (['B', 'STRONG', 'I', 'EM', 'U', 'BR'].indexOf(el.tagName) === -1) {
        el.replaceWith(document.createTextNode(el.textContent));
      }
    }
    return d.innerHTML;
  }
  function fmt(s) {
    var withBold = (s || '').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
    return sanitizeLite(withBold);
  }

  /* --- Helper: pick category from an item (several possible keys) --- */
  function getCat(it) {
    var c = (it.category || it.section || it.group || it.topic || it.bucket || '').toString().trim();
    return c || 'Uncategorized';
  }

  /* --- Optional custom order for known categories ------------------- */
  var CATEGORY_ORDER = [
    'Well, Did You Read?',
    'Learn About Words',
    'About the Story',
    'Think and Write',
    'Word Work'
  ];
  var CAT_ORDER_MAP = {};
  for (var i = 0; i < CATEGORY_ORDER.length; i++) {
    CAT_ORDER_MAP[CATEGORY_ORDER[i].toLowerCase()] = i;
  }
  function sortKeysWithOrder(keys) {
    return keys.sort(function (a, b) {
      var ia = CAT_ORDER_MAP[a.toLowerCase()];
      var ib = CAT_ORDER_MAP[b.toLowerCase()];
      if (ia === undefined && ib === undefined) return a.localeCompare(b);
      if (ia === undefined) return 1;
      if (ib === undefined) return -1;
      return ia - ib;
    });
  }
function getAnswerText(it){
  var t = (it.type || '').toLowerCase();

  // Letter-only for choice-based types
  if (t === 'single' || t === 'ab' || t === 'tf' || t === 'yn'){
    var letter = ((it.key || it.answer_key || it.answer_label || '') + '').toUpperCase();
    return letter || '';
  }

  // Text-entry (accepted list)
  if (t === 'text'){
    var arr = Array.isArray(it.answers) ? it.answers
            : (Array.isArray(it.accepted) ? it.accepted : []);
    return arr.join(' / ');
  }

  // Word bank: show the word itself
  if (t === 'bank' || t === 'text_bank'){
    return it.key || it.answer || it.answer_word || '';
  }

  return '';
}

  /* --- Grouped renderer: writes into #pbQList and #pbQCount ---------- */
function renderPBPreview(items) {
  var list = document.getElementById('pbQList');
  var countEl = document.getElementById('pbQCount');
  if (!list) return;

  list.innerHTML = '';

  var total = Array.isArray(items) ? items.length : 0;
  if (countEl) countEl.textContent = 'Total number of Questions: ' + total;

  if (!total) {
    list.innerHTML = '<div class="muted">No questions yet.</div>';
    return;
  }

  // Group by category (title already computed by backend)
  var groups = {};
  items.forEach(function (it) {
    var cat = getCat(it);
    (groups[cat] = groups[cat] || []).push(it);
  });

  var cats = sortKeysWithOrder(Object.keys(groups));

  cats.forEach(function (catName) {
    var group = document.createElement('div');
    group.className = 'pbq-group';

  var h = document.createElement('div');
h.className = 'pbq-cat';
h.textContent = catName + ' (' + groups[catName].length + ')';
group.appendChild(h);

// Get directions + image meta from the FIRST item of the group
var dirText   = '';
var dirImgUrl = '';
var dirImgAlt = '';
var dirImgPos = 'below';

if (groups[catName] && groups[catName].length) {
  var first = groups[catName][0];
  dirText   = (first.directions || first.dir || '');
  dirImgUrl = (first.dir_image_url || '');
  dirImgAlt = (first.dir_image_alt || '');
  dirImgPos = (first.dir_image_pos || 'below');
}

// IMAGE ABOVE directions?
if (dirImgUrl && dirImgPos === 'above') {
  var imgTop = document.createElement('div');
  imgTop.className = 'dir-img';
  imgTop.innerHTML = '<img src="' + dirImgUrl + '" alt="' + (dirImgAlt || '') + '">';
  group.appendChild(imgTop);
}

// Directions text
if (dirText) {
  var d = document.createElement('div');
  d.className = 'dir-note';
  d.textContent = dirText;
  group.appendChild(d);
}

// IMAGE BELOW directions?
if (dirImgUrl && dirImgPos !== 'above') {
  var imgBot = document.createElement('div');
  imgBot.className = 'dir-img';
  imgBot.innerHTML = '<img src="' + dirImgUrl + '" alt="' + (dirImgAlt || '') + '">';
  group.appendChild(imgBot);
}


    groups[catName].forEach(function (it, idx) {
      try {
       var type = (it.type || '').toLowerCase();
var ansLetter = ((it.key || it.answer_key || '') + '').toUpperCase(); // <-- letter lang
var C = it.choices || {};

        var wrap = document.createElement('div');
        wrap.className = 'item-block';

        // Header (number + question)
        var header = document.createElement('div');
        header.className = 'item-head';
        header.style.alignItems = 'flex-start';

        var num = document.createElement('div');
        num.className = 'item-num';
        num.textContent = (idx + 1);

        var q = document.createElement('div');
        q.style.fontWeight = '700';
        q.style.flex = '1';
        q.className = 'qtext';
        q.innerHTML = fmt(it.question || '');

        header.appendChild(num);
        header.appendChild(q);

        var ansText = getAnswerText(it);
        var ansPill = document.createElement('div');
        ansPill.className = 'ans-pill';
        ansPill.textContent = 'Answer: ' + (ansText || '—');
        header.appendChild(ansPill);

        wrap.appendChild(header);

        // Choices or special display per type
        var letters = (C && typeof C === 'object') ? Object.keys(C).filter(function (L) {
          return (C[L] || '').toString().trim() !== '';
        }) : [];

        if (letters.length) {
          var grid = document.createElement('div');
          grid.className = 'two-col';
          grid.style.marginTop = '8px';

          letters.forEach(function (L) {
            var row = document.createElement('div');
            row.className = 'choice-line';

            var letter = document.createElement('span');
            letter.className = 'letter';
            letter.textContent = L.toLowerCase() + '.';

            var text = document.createElement('div');
            text.style.flex = '1';
            text.className = 'ctext' + (L === ansLetter ? ' qchoice-correct' : '');
            text.setAttribute('data-letter', L);
            text.innerHTML = fmt(C[L] || '');

            row.appendChild(letter);
            row.appendChild(text);

            if (L === ansLetter) {
  var badge = document.createElement('span');
  badge.className = 'badge-corr';
  badge.textContent = 'correct';
  row.appendChild(badge);
}
            grid.appendChild(row);
          });

          wrap.appendChild(grid);
        } else if (type === 'text') {
          var extra = document.createElement('div');
          extra.className = 'tag-note';
          var answers = Array.isArray(it.answers) ? it.answers : [];
          extra.innerHTML = '<b>Accepted answers:</b> ' + (answers.length
            ? answers.map(sanitizeLite).join(', ')
            : '<i>none</i>');
          wrap.appendChild(extra);
        } else if (type === 'bank' || type === 'text_bank') {
          var extra2 = document.createElement('div');
          extra2.className = 'tag-note';
          var corr = it.key || it.answer || '';
          extra2.innerHTML = '<b>Correct word:</b> ' + (corr ? sanitizeLite(corr) : '<i>—</i>');
          wrap.appendChild(extra2);

          if (Array.isArray(it.bankWords) && it.bankWords.length) {
            var bw = document.createElement('div');
            bw.className = 'tag-note';
            bw.style.marginTop = '6px';
            bw.innerHTML = '<b>Word Bank:</b> ' + it.bankWords.map(sanitizeLite).join(', ');
            wrap.appendChild(bw);
          }
        }

        group.appendChild(wrap);
      } catch (err) {
        console.error('Render PB item failed:', err, it);
        var warn = document.createElement('div');
        warn.className = 'muted';
        warn.textContent = '⚠️ Failed to render one question.';
        group.appendChild(warn);
      }
    });

    list.appendChild(group);
  });
}

  // Expose for your loader code: window.renderPBPreview(items)
  window.renderPBPreview = renderPBPreview;
})();
</script>

<script>
(function(){
  var pbPassage = document.getElementById('pbPassage');
  var pbPreview = document.getElementById('pbPassagePreview');

  function esc(s){return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));}

  function renderPassagePreview(){
    if(!pbPreview) return;
    var raw = pbPassage ? pbPassage.value : '';

    // If admin pasted HTML with <p>, keep it; if plain text, split into paragraphs.
    if (/<\s*p[\s>]/i.test(raw)) {
      pbPreview.innerHTML = raw;
    } else {
      var parts = (raw||'').split(/\r?\n\s*\r?\n|\r?\n/).filter(Boolean);
      pbPreview.innerHTML = parts.map(t => `<p>${esc(t.trim())}</p>`).join('');
    }
  }

  // hook up events + call during add/edit
  if (pbPassage){
    pbPassage.addEventListener('input', renderPassagePreview);
    renderPassagePreview();
  }

  // when switching to ADD mode
  function pbToAddPatch(){
    if (pbPassage) pbPassage.value = '';
    renderPassagePreview();
  }
  // run our patch when the real pbToAdd runs
  document.getElementById('btnAddStory')?.addEventListener('click', pbToAddPatch);
  document.getElementById('btnAddStory2')?.addEventListener('click', pbToAddPatch);

  // when EDIT fills the textarea, call preview again
  document.querySelectorAll('.js-pb-edit').forEach(btn=>{
    btn.addEventListener('click', ()=> setTimeout(renderPassagePreview, 0));
  });
})();
</script>


</body></html>
