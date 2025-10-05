  <?php
  // admin/stories_pb.php
  require_once __DIR__ . '/../includes/auth.php';
  require_role('admin', '../login.php#login');
  require_once __DIR__ . '/../db_connect.php';

  $PAGE_TITLE  = 'Power Builder Assessment';
  $ACTIVE_MENU = 'stories';
  $ACTIVE_SUB  = 'pb';
  $PB_STORY_TARGET = 15; // bawat color set dapat may 15 stories


  /* Flash helpers */
  function flash_set($type,$msg){ $_SESSION['pb_flash']=['t'=>$type,'m'=>$msg]; }
  function flash_pop(){ $f=$_SESSION['pb_flash']??null; unset($_SESSION['pb_flash']); return $f; }

  /* Levels (colors) for the modal */
  $levels = [];
$lvlSql = "
  SELECT DISTINCT
    l.level_id,
    l.name,
    l.color_hex,
    COALESCE(l.order_rank, 9999) AS ord
  FROM sra_levels l
  JOIN level_thresholds t
    ON t.level_id = l.level_id
   AND t.applies_to = 'SLT'
  ORDER BY ord ASC, l.name ASC
";

  if ($r = $conn->query($lvlSql)) while($row=$r->fetch_assoc()) $levels[] = $row;

  /* Data: PB sets + active story title */
  /* Data: PB sets + story count */
$sets = [];
$sql = "
  SELECT
      ss.*,
      lvl.name      AS level_name,
      lvl.color_hex AS color_hex,
      /* how many stories in this set */
      (SELECT COUNT(*) FROM stories s WHERE s.set_id = ss.set_id) AS story_count
  FROM story_sets ss
  LEFT JOIN sra_levels lvl ON lvl.level_id = ss.level_id
  WHERE ss.set_type = 'PB'
  ORDER BY COALESCE(lvl.order_rank, 9999) ASC,
          (ss.status='published') DESC,
          ss.updated_at DESC
";
$res = $conn->query($sql);
if ($res) while($r=$res->fetch_assoc()) $sets[] = $r;


  require_once __DIR__ . '/includes/header.php';
  require_once __DIR__ . '/includes/sidebar.php';
  $flash = flash_pop();

  ?>
  <style>
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

    /* Columns: # | Color | Story Title | Status | Updated | Actions */
    .table-head{
      display:grid;
      grid-template-columns: 60px .9fr 1.3fr .9fr .9fr 240px;
      gap:10px; padding:12px 14px; background:rgba(0,0,0,.03); color:#265; font-weight:700;
    }
    .row{
      display:grid;
      grid-template-columns: 60px .9fr 1.3fr .9fr .9fr 240px;
      gap:10px; padding:12px 14px; border-top:1px solid #f0f2f4; align-items:center;
    }
    .table-wrap{ overflow-x:auto; }
    .muted{ color:#6b8b97; }

    .status{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }
    .st-draft{ background:#fff3cd; color:#856404; }
    .st-published{ background:#d4edda; color:#155724; }
    .st-archived{ background:#e2e3e5; color:#383d41; }

    .actions{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn{
      display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:10px;
      border:1px solid #dfe5e8; background:#fff; cursor:pointer; font-weight:600;
      transition: transform .05s ease, box-shadow .15s ease, filter .12s ease;
    }
    .btn:hover{ box-shadow:0 2px 10px rgba(0,0,0,.08); }
    .btn:active{ transform: translateY(1px); }
    .btn-accent{ background:var(--accent); border-color:#d39b06; color:#1b1b1b; font-weight:700; }
    .btn-manage{ background:#fff7ec; border-color:#e5c7a8; color:#7a4b10; }
    .btn-publish{ border-color:#bfe3c6; color:#155724; background:#eaf7ea; }
    .btn-archive{ border-color:#bfe3c6; color:#155724; background:#eaf7ea; }

    .lvl-badge{ display:inline-flex; align-items:center; gap:8px; }
    .lvl-dot{ width:12px; height:12px; border-radius:999px; border:1px solid rgba(0,0,0,.12); }

    @media (max-width: 900px){
      .table-head, .row{ grid-template-columns: 50px .9fr 1.3fr .9fr 200px; }
      .hide-md{ display:none !important; }
    }
    @media (max-width: 640px){
      .table-head{ display:none; }
      .row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
      .row > :nth-child(1){ grid-column:1/2; font-weight:700; }
      .row > :nth-child(2){ grid-column:2/3; }
      .row > :nth-child(n+3){ grid-column:1/-1; }
      .actions{ justify-content:flex-start; }
    }

    .notice{ margin:10px 0; padding:10px 12px; border-radius:10px; }
    .notice.ok{ background:#eaf7ea; color:#1b5e20; border:1px solid #b9e6b9; }
    .notice.error{ background:#fdecea; color:#b71c1c; border:1px solid #f5c6cb; }

    .pb-page{ padding-top:60px; }
    .pb-page .page-wrap{ margin:6px auto 18px; }

    /* Modal */
    .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:2000; opacity:0; transition:opacity .18s ease-out; }
    .modal-backdrop.show{ display:flex; opacity:1; }
    .modal{ width:min(640px, 92vw); background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25); overflow:hidden;
            transform: translateY(8px) scale(.985); opacity:0; transition: transform .18s ease-out, opacity .18s ease-out; }
    .modal-backdrop.show .modal{ transform: translateY(0) scale(1); opacity:1; }
    .modal header{ background:var(--green); color:#fff; padding:12px 16px; font-weight:700; display:flex; justify-content:space-between; align-items:center; }
    .modal section{ padding:16px; display:grid; gap:10px; }
    .modal .control{ display:grid; gap:6px; }
    .modal input[type="text"], .modal textarea, .modal select{ width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px; }
    .modal footer{ padding:12px 16px; display:flex; gap:10px; justify-content:flex-end; background:#fafafa; }
  </style>

  <div class="main-content pb-page">
    <div class="page-wrap">

      <div class="sub-bar">
        <h3>PB – Story Sets (by Color)</h3>
        <div class="grow"></div>
        <input type="search" id="q" class="search" placeholder="Search color or title...">
        <button class="btn btn-accent" id="btnAddPB"><i>＋</i> Add PB Set</button>
      </div>

      <div class="card table-wrap" style="margin-top:12px;">
        <div class="table-head">
          <div>#</div>
          <div>Color</div>
           <div>Stories</div>  
          <div>Status</div>
          <div class="hide-md">Updated</div>
          <div>Actions</div>
        </div>

        <?php if(!$sets): ?>
          <div class="row">
            <div>—</div>
            <div class="muted">No PB sets yet.</div>
            <div class="muted">—</div>
            <div><span class="status st-draft">draft</span></div>
            <div class="hide-md">—</div>
            <div class="actions">
              <button class="btn btn-accent" id="btnAddPB2">＋ Add your first set</button>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach($sets as $i => $s): ?>
          <div class="row pb-row">
            <div><?= $i+1 ?></div>

            <div class="lvl-badge">
              <span class="lvl-dot" style="background:<?= htmlspecialchars($s['color_hex'] ?? '#ddd') ?>"></span>
              <strong><?= htmlspecialchars($s['level_name'] ?? '—') ?></strong>
            </div>

            <div>
  <?= (int)($s['story_count'] ?? 0) ?> / <?= (int)($PB_STORY_TARGET ?? 15) ?>
</div>

            <div>
              <?php
                $st  = $s['status'];
                $cls = $st==='published'?'st-published':($st==='archived'?'st-archived':'st-draft');
              ?>
              <span class="status <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
            </div>

            <div class="hide-md"><?= htmlspecialchars($s['updated_at'] ?? '') ?></div>

            <div class="actions">
              <a class="btn btn-manage" href="pb_manage.php?set_id=<?= (int)$s['set_id'] ?>">📚 Manage</a>

              <?php if($st!=='published'): ?>
                <form method="post" action="pb_sets_action.php" class="js-status-form" style="display:inline">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="set_id" value="<?= (int)$s['set_id'] ?>">
                  <input type="hidden" name="status" value="published">
                  <button class="btn btn-publish">⬆ Publish</button>
                </form>
              <?php else: ?>
                <form method="post" action="pb_sets_action.php" class="js-status-form" style="display:inline">
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

  <!-- Add PB Set Modal -->
  <div class="modal-backdrop" id="mAddPB">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mAddPBTitle">
      <header id="mAddPBTitle">Add PB Set</header>
      <form method="post" action="pb_sets_action.php" id="addPBForm">
        <section>
          <div class="control">
            <label>Level / Color <span style="color:#c00">*</span></label>
            <select name="level_id" id="pbLevel" required>
              <option value="">— choose color —</option>
              <?php foreach($levels as $lv): ?>
                <option value="<?= (int)$lv['level_id'] ?>"
                        data-name="<?= htmlspecialchars($lv['name']) ?>">
                  <?= htmlspecialchars($lv['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="control">
            <label>Title <span style="color:#c00">*</span></label>
            <input type="text" name="title" id="pbTitle" required placeholder="e.g., PB – Yellow">
          </div>

          <div class="control">
            <label>Notes (optional)</label>
            <textarea name="notes" rows="4" placeholder="Source, year level, remarks…"></textarea>
          </div>
        </section>
        <footer>
          <button type="button" class="btn" id="pbClose">Cancel</button>
          <input type="hidden" name="action" value="add_set">
          <button class="btn btn-accent">Create Set</button>
        </footer>
      </form>
    </div>
  </div>

  <script>
    // client-side search
    const q = document.getElementById('q');
    q && q.addEventListener('input', () => {
      const term = q.value.toLowerCase();
      document.querySelectorAll('.pb-row').forEach(r=>{
        const text = r.innerText.toLowerCase();
        r.style.display = text.includes(term) ? '' : 'none';
      });
    });

    // SweetAlert confirms for publish/archive
    document.querySelectorAll('.js-status-form').forEach(f=>{
      f.addEventListener('submit', function(e){
        e.preventDefault();
        const status = f.querySelector('input[name="status"]').value;
        const msg = status==='published' ? 'Publish this set?' : 'Archive this set?';
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

    // Modal open/close
    const mPB   = document.getElementById('mAddPB');
    const openPB  = ()=> mPB.classList.add('show');
    const closePB = ()=> mPB.classList.remove('show');
    document.getElementById('btnAddPB')?.addEventListener('click', openPB);
    document.getElementById('btnAddPB2')?.addEventListener('click', openPB);
    document.getElementById('pbClose')?.addEventListener('click', closePB);
    mPB?.addEventListener('click', (e)=>{ if(e.target===mPB) closePB(); });

    // Auto-fill Title from selected level
    const pbLevel = document.getElementById('pbLevel');
    const pbTitle = document.getElementById('pbTitle');
    pbLevel?.addEventListener('change', ()=>{
      const opt = pbLevel.selectedOptions[0];
      if(opt && opt.dataset.name && (!pbTitle.value || pbTitle.value.startsWith('PB – '))){
        pbTitle.value = 'PB – ' + opt.dataset.name;
      }
    });
  </script>
  
<?php /* Flash → SweetAlert */ ?>
<script>
<?php if (!empty($flash)): ?>
  Swal.fire({
    icon: '<?= $flash['t'] === 'ok' ? 'success' : 'error' ?>',
    title: <?= json_encode($flash['m']) ?>,
    confirmButtonColor: '#ECA305'
  });
<?php endif; ?>
</script>

  </body></html>
