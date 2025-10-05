<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$PAGE_TITLE  = 'Starting Level Assessment';
$ACTIVE_MENU = 'stories';
$ACTIVE_SUB  = 'slt';

/* ---------------- Flash helper ---------------- */
function flash_set($type,$msg){ $_SESSION['slt_flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['slt_flash']??null; unset($_SESSION['slt_flash']); return $f; }

/* ---------------- Data ------------------------ */
$sets = [];
$sql = "
  SELECT ss.*,
         /* count kept (di na natin ipapakita pero ok lang nandyan) */
         (SELECT COUNT(*) FROM stories s WHERE s.set_id = ss.set_id) AS story_count,

         /* ACTIVE / (legacy) PUBLISHED story title for this set */
         (SELECT s2.title
            FROM stories s2
           WHERE s2.set_id = ss.set_id
             AND (s2.status = 'active' OR s2.status = 'published')
        ORDER BY s2.updated_at DESC, s2.story_id DESC
           LIMIT 1) AS active_story_title

    FROM story_sets ss
   WHERE ss.set_type = 'SLT'
ORDER BY (ss.status = 'published') DESC, ss.sequence ASC, ss.updated_at DESC
";

$res = $conn->query($sql);
if ($res) while($r=$res->fetch_assoc()) $sets[] = $r;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
$flash = flash_pop();
?>

<style>
  /* --- Page theming --- */
  .page-wrap{ width:98%; margin:14px auto 24px; }
  .sub-bar{
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    background:#fff; padding:10px 12px; border-radius:10px; box-shadow: var(--shadow);
    border-left:6px solid var(--accent);
  }
  .sub-bar h3{ margin:0; color:var(--green); font-weight:800; letter-spacing:.02em; }
  .sub-bar .grow{ flex:1; }
  .search{ padding:9px 12px; border:1px solid #dfe5e8; border-radius:10px; min-width:220px; }

  .card{ background:#fff; border-radius:12px; box-shadow: var(--shadow); overflow:hidden; }

  /* ======= TABLE GRID (Items removed) =======
     Columns: # | Set | Stories | Status | Updated | Actions */
  .table-head{
    display:grid;
    grid-template-columns: 60px 1.2fr .8fr .9fr .9fr 240px;
    gap:10px; padding:12px 14px; background:rgba(0,0,0,.03); color:#265; font-weight:700;
  }
  .row{
    display:grid;
    grid-template-columns: 60px 1.2fr .8fr .9fr .9fr 240px;
    gap:10px; padding:12px 14px; border-top:1px solid #f0f2f4; align-items:center;
  }

  .table-wrap{ overflow-x:auto; }
  .muted{ color:#6b8b97; }

  /* Status pills */
  .status{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
  .st-draft{ background:#fff3cd; color:#856404; }
  .st-published{ background:#d4edda; color:#155724; }
  .st-archived{ background:#e2e3e5; color:#383d41; }

  /* Notices */
  .notice{ margin:10px 0; padding:10px 12px; border-radius:10px; }
  .notice.ok{ background:#eaf7ea; color:#1b5e20; border:1px solid #b9e6b9; }
  .notice.error{ background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }

  /* ======= Buttons ======= */
  .actions{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

.btn{
  display:inline-flex; align-items:center; gap:8px;
  padding:9px 14px; border-radius:10px;
  border:1px solid #dfe5e8; background:#fff; cursor:pointer; font-weight:600;
  transition: transform .05s ease, box-shadow .15s ease, filter .12s ease;
}
  .btn:hover{ box-shadow:0 2px 10px rgba(0,0,0,.08); }
  .btn:active{ transform: translateY(1px); }

  /* used for the "Add SLT Set" button on sub-bar */
  .btn-accent{ background:var(--accent); border-color:#d39b06; color:#1b1b1b; font-weight:700; }
  .btn-accent:hover{ filter:brightness(0.98); }

  /* row action buttons */
/* Row action buttons */
.btn-manage{
  background:#fff7ec;      /* light cream like Archive */
  border-color:#e5c7a8;
  color:#7a4b10;
}
.btn-publish{
  border-color:#bfe3c6;
  color:#155724;
  background:#eaf7ea;
}
.btn-archive{
  border-color:#bfe3c6;
  color:#155724;
  background:#eaf7ea;
}
  /* ========== Responsive ========== */
  /* md: hide "Updated", shrink actions */
  @media (max-width: 900px){
    .table-head, .row{
      grid-template-columns: 50px 1.4fr .7fr .9fr 200px; /* # | Set | Stories | Status | Actions */
    }
    .hide-md{ display:none !important; } /* apply on Updated column in markup */
  }

  /* sm: card rows, labels stack; actions full width */
  @media (max-width: 640px){
    .table-head{ display:none; }
    .row{
      grid-template-columns: 1fr 1fr;
      row-gap:8px;
    }
    .row > :nth-child(1){ grid-column:1/2; font-weight:700; } /* # */
    .row > :nth-child(2){ grid-column:2/3; }                 /* Set */
    .row > :nth-child(n+3){ grid-column:1/-1; }              /* rest */
    .actions{ justify-content:flex-start; }
  }

  /* === SLT page spacing override === */
  .slt-page{ padding-top:60px; }
  .slt-page .page-wrap{ margin:6px auto 18px; }

  /* ======= Modal + animation ======= */
  .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:2000; opacity:0; transition: opacity .18s ease-out; }
  .modal-backdrop.show{ display:flex; opacity:1; }

  .modal{ width:min(640px, 92vw); background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25); overflow:hidden;
          transform: translateY(8px) scale(.985); opacity:0; transition: transform .18s ease-out, opacity .18s ease-out; }
  .modal-backdrop.show .modal{ transform: translateY(0) scale(1); opacity:1; }

  .modal header{ background:var(--green); color:#fff; padding:12px 16px; font-weight:700; }
  .modal section{ padding:16px; display:grid; gap:10px; }
  .modal .control{ display:grid; gap:6px; }
  .modal input[type="text"], .modal textarea{ width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px; }
  .modal footer{ padding:12px 16px; display:flex; gap:10px; justify-content:flex-end; background:#fafafa; }
</style>

<div class="main-content slt-page">
  <div class="page-wrap">

    <?php if($flash): ?>
      <div class="notice <?= $flash['t']==='ok'?'ok':'error' ?>"><?= htmlspecialchars($flash['m']) ?></div>
    <?php endif; ?>

    <div class="sub-bar">
      <h3>SLT – Story Sets</h3>
      <div class="grow"></div>
      <input type="search" id="q" class="search" placeholder="Search title...">
      <button class="btn btn-accent" id="btnAdd"><i>＋</i> Add SLT Set</button>
    </div>

    <div class="card table-wrap" style="margin-top:12px;">
      <div class="table-head">
        <div>#</div>
        <div>Set</div>
        <div class="hide-md">Story Title</div>
        <div>Status</div>
        <div class="hide-md">Updated</div>
        <div>Actions</div>
      </div>

      <?php if(!$sets): ?>
        <div class="row">
          <div>—</div>
          <div class="muted">No SLT sets yet.</div>
          <div class="hide-md">0</div>
          <div><span class="status st-draft">draft</span></div>
          <div class="hide-md">—</div>
          <div class="actions">
            <button class="btn btn-manage" id="btnAdd2">＋ Add your first set</button>
          </div>
        </div>
      <?php endif; ?>

      <?php foreach($sets as $i => $s): ?>
          <div class="row slt-row">
            <div><?= $i+1 ?></div>

            <div>
              <div style="font-weight:700; color:#123;">
                <?= htmlspecialchars($s['title']) ?>
              </div>
              <?php if(!empty($s['notes'])): ?>
                <div class="muted" style="font-size:.9rem;"><?= nl2br(htmlspecialchars($s['notes'])) ?></div>
              <?php endif; ?>
            </div>

           <div>
  <?= !empty($s['active_story_title'])
        ? htmlspecialchars($s['active_story_title'])
        : '<span class="muted">—</span>' ?>
</div>
            <div>
              <?php
                $st = $s['status'];
                $cls = $st==='published'?'st-published':($st==='archived'?'st-archived':'st-draft');
              ?>
              <span class="status <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
            </div>

            <div class="hide-md"><?= htmlspecialchars($s['updated_at'] ?? '') ?></div>

            <div class="actions">
              <a class="btn btn-manage" href="slt_manage.php?set_id=<?= (int)$s['set_id'] ?>">📚 Manage</a>

              <?php if($st!=='published'): ?>
                <form method="post" action="slt_sets_action.php" class="js-status-form" style="display:inline">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="set_id" value="<?= (int)$s['set_id'] ?>">
                  <input type="hidden" name="status" value="published">
                  <button class="btn btn-publish">⬆ Publish</button>
                </form>
              <?php else: ?>
                <form method="post" action="slt_sets_action.php" class="js-status-form" style="display:inline">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="set_id" value="<?= (int)$s['set_id'] ?>">
                  <input type="hidden" name="status" value="archived">
                  <button class="btn btn-archive">🗂 Archive</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Add Set Modal -->
<div class="modal-backdrop" id="mAdd">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mAddTitle">
    <header id="mAddTitle">Add SLT Set</header>
    <form method="post" action="slt_sets_action.php" id="addSetForm">
      <section>
        <div class="control">
          <label>Title <span style="color:#c00">*</span></label>
          <input type="text" name="title" required placeholder="e.g., SLT – Batch 1">
        </div>
        <div class="control">
          <label>Notes (optional)</label>
          <textarea name="notes" rows="4" placeholder="Source, year level, remarks…"></textarea>
        </div>
      </section>
      <footer>
        <button type="button" class="btn" id="mAddClose">Cancel</button>
        <input type="hidden" name="action" value="add_set">
        <button class="btn btn-accent">Create Set</button>
      </footer>
    </form>
  </div>
</div>

<script>
  // client-side search filter
  const q = document.getElementById('q');
  q && q.addEventListener('input', () => {
    const term = q.value.toLowerCase();
    document.querySelectorAll('.slt-row').forEach(r=>{
      const text = r.innerText.toLowerCase();
      r.style.display = text.includes(term) ? '' : 'none';
    });
  });

  // modal open/close
  const m = document.getElementById('mAdd');
  const open = ()=> m.classList.add('show');
  const close = ()=> m.classList.remove('show');
  document.getElementById('btnAdd')?.addEventListener('click', open);
  document.getElementById('btnAdd2')?.addEventListener('click', open);
  document.getElementById('mAddClose')?.addEventListener('click', close);
  m?.addEventListener('click', (e)=>{ if(e.target===m) close(); });
</script>

<script>
  // Flash to SweetAlert
  <?php if (!empty($flash)): ?>
    Swal.fire({
      icon: '<?= $flash['t']==='ok'?'success':'error' ?>',
      title: <?= json_encode($flash['m']) ?>,
      confirmButtonColor: '#ECA305'
    });
  <?php endif; ?>

  // Confirm dialogs for Publish/Archive using SweetAlert
  document.querySelectorAll('.js-status-form').forEach(f=>{
    f.addEventListener('submit', function(e){
      e.preventDefault();
      const status = f.querySelector('input[name="status"]').value;
      const msg = status==='published' ? 'Publish this set?' : 'Archive this set? Students with history will be preserved.';
      Swal.fire({
        icon: 'question',
        title: msg,
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes'
      }).then(res=>{
        if(res.isConfirmed) f.submit();
      });
    });
  });
</script>
</body></html>
