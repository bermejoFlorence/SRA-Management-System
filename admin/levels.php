<?php
require_once __DIR__ . '/../includes/auth.php';    // <-- ito ang nagse-session_start()
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Admin Levels';
$ACTIVE_MENU = 'levels';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

require_once __DIR__ . '/../db_connect.php';       // <-- DITO ilagay simula ng snippet

/* --- PB Levels / Colors --- */
$levels = [];
$sqlLv = "SELECT level_id, code, name, color_hex, COALESCE(order_rank,9999) AS order_rank
          FROM sra_levels
          ORDER BY order_rank ASC, name ASC";
if ($res = $conn->query($sqlLv)) {
  while($row = $res->fetch_assoc()) $levels[] = $row;
}

/* --- SLT → PB Thresholds --- */
$rules = [];
$sqlRl = "SELECT t.threshold_id, t.min_percent, t.max_percent,
       JSON_UNQUOTE(JSON_EXTRACT(t.other_rules,'$.notes')) AS other_rules,
       l.level_id, l.name AS level_name, l.color_hex
FROM level_thresholds t
JOIN sra_levels l ON l.level_id = t.level_id
WHERE t.applies_to='SLT'
ORDER BY t.min_percent ASC, t.max_percent ASC, l.order_rank ASC, l.name ASC;";
if ($res = $conn->query($sqlRl)) {
  while($row = $res->fetch_assoc()) $rules[] = $row;
}

// make sure session exists (safe guard)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function levels_flash_pop(){
  $f = $_SESSION['levels_flash'] ?? null;
  unset($_SESSION['levels_flash']);
  return $f;
}
$flash = levels_flash_pop();


?>


<style>
/* ===== Page frame ===== */
.levels-page{ padding-top:60px; }
.levels-page .page-wrap{ width:98%; margin:10px auto 22px; }

/* ===== Title + Tabs row ===== */
.page-head{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; margin:6px 0 10px;
}
.page-head h3{ margin:0; color:var(--green); font-weight:800; letter-spacing:.02em; }

.tabs-row{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; background:#fff; padding:10px 12px; border-radius:10px;
  box-shadow: var(--shadow); border-left:6px solid var(--accent);
  margin-bottom:12px;
}
.tabs{ display:flex; gap:8px; flex-wrap:wrap; }
.tab-btn{
  border:1px solid #dfe5e8; background:#fff; color:#143;
  padding:8px 14px; border-radius:999px; cursor:pointer; font-weight:700;
}
.tab-btn:hover{ box-shadow:0 1px 6px rgba(0,0,0,.06); }
.tab-btn.active{ background:var(--green); border-color:var(--green); color:#fff; }

.tab-actions{ display:flex; gap:8px; flex-wrap:wrap; }

/* ===== Buttons ===== */
.btn{
  display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:10px;
  border:1px solid #dfe5e8; background:#fff; cursor:pointer; font-weight:600;
  transition: transform .05s ease, box-shadow .15s ease, filter .12s ease;
}
.btn:hover{ box-shadow:0 2px 10px rgba(0,0,0,.08); }
.btn:active{ transform: translateY(1px); }
.btn-accent{ background:var(--accent); border-color:#d39b06; color:#1b1b1b; font-weight:700; }
.btn-light{ background:#f6f8fa; }
.btn-danger{ background:#fdecea; border-color:#f5c6cb; color:#b71c1c; }

/* ===== Cards / tables ===== */
.card{ background:#fff; border-radius:12px; box-shadow: var(--shadow); overflow:hidden; }
.table-wrap{ overflow-x:auto; }
.muted{ color:#6b8b97; }

/* Levels table: # | Color | Name | Code | Order | Actions */
.levels-head, .levels-row{
  display:grid; gap:10px; align-items:center;
  grid-template-columns: 60px .9fr 1.2fr .9fr .7fr 220px;
}
.levels-head{ padding:12px 14px; background:rgba(0,0,0,.03); color:#265; font-weight:700; }
.levels-row{ padding:12px 14px; border-top:1px solid #f0f2f4; }

/* Thresholds table: # | Min% | Max% | Assign Color | Notes | Actions */
.rules-head, .rules-row{
  display:grid; gap:10px; align-items:center;
  grid-template-columns: 60px .6fr .6fr 1.2fr 1.2fr 200px;
}
.rules-head{ padding:12px 14px; background:rgba(0,0,0,.03); color:#265; font-weight:700; }
.rules-row{ padding:12px 14px; border-top:1px solid #f0f2f4; }

/* Color chip / swatch */
.swatch{ display:inline-flex; align-items:center; gap:10px; }
.swatch .box{
  width:22px; height:22px; border-radius:6px; border:1px solid rgba(0,0,0,.08);
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.15);
}
.chip{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:.85rem; font-weight:600; }

/* Content panes */
.pane{ display:none; }
.pane.show{ display:block; }

/* Responsive */
@media (max-width: 900px){
  .tabs-row{ flex-direction:column; align-items:stretch; }
  .tab-actions{ justify-content:flex-end; }
}
@media (max-width: 980px){
  .levels-head, .levels-row{ grid-template-columns: 50px 1fr 1.2fr .8fr 190px; }
  .levels-head > :nth-child(5), .levels-row > :nth-child(5){ display:none; } /* hide Order on md */
  .rules-head, .rules-row{ grid-template-columns: 50px .7fr .7fr 1fr 1fr 180px; }
}
@media (max-width: 640px){
  .levels-head{ display:none; }
  .levels-row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
  .levels-row > :nth-child(1){ grid-column:1/2; font-weight:700; }
  .levels-row > :nth-child(2){ grid-column:2/3; }
  .levels-row > :nth-child(n+3){ grid-column:1/-1; }

  .rules-head{ display:none; }
  .rules-row{ grid-template-columns: 1fr 1fr; row-gap:8px; }
  .rules-row > :nth-child(1){ grid-column:1/2; font-weight:700; }
  .rules-row > :nth-child(2){ grid-column:2/3; }
  .rules-row > :nth-child(n+3){ grid-column:1/-1; }
}

/* ===== Modals ===== */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45); display:none;
  align-items:center; justify-content:center; z-index:2000; opacity:0; transition:opacity .18s ease-out;
}
.modal-backdrop.show{ display:flex; opacity:1; }
.modal{
  width:min(740px,96vw); background:#fff; border-radius:14px; box-shadow:0 20px 80px rgba(0,0,0,.25);
  overflow:hidden; transform: translateY(8px) scale(.985); opacity:0;
  transition: transform .18s ease-out, opacity .18s ease-out;
  display:flex; flex-direction:column; max-height:92vh;
}
.modal-backdrop.show .modal{ transform: translateY(0) scale(1); opacity:1; }
.modal header{
  background:var(--green); color:#fff; padding:12px 16px; font-weight:700;
  display:flex; justify-content:space-between; align-items:center;
}
.modal section{ padding:16px; display:grid; gap:12px; overflow:auto; }
.modal .grid-2{ display:grid; gap:12px; grid-template-columns: 1fr 1fr; }
.modal label{ font-weight:600; color:#123; }
.modal input[type="text"], .modal input[type="number"], .modal select, .modal textarea{
  width:100%; padding:10px 12px; border:1px solid #dfe5e8; border-radius:10px;
}
.modal footer{
  padding:12px 16px; display:flex; gap:10px; justify-content:flex-end;
  background:#fafafa; border-top:1px solid #eee;
}
.inline{ display:flex; align-items:center; gap:12px; }
.w-120{ width:120px; }
</style>

<div class="main-content levels-page">
  <div class="page-wrap">

    <!-- Title -->
    <div class="page-head">
      <h3>Levels &amp; Thresholds</h3>
      <div></div>
    </div>

    <!-- Tabs row -->
    <div class="tabs-row">
      <div class="tabs">
        <button class="tab-btn active" id="tabLevelsBtn">PB Levels / Colors</button>
        <button class="tab-btn" id="tabRulesBtn">SLT → PB Thresholds</button>
      </div>
      <div class="tab-actions">
        <button class="btn btn-accent" id="btnAddLevel">＋ Add Level</button>
        <button class="btn btn-accent" id="btnAddRule" style="display:none;">＋ Add Rule</button>
      </div>
    </div>

    <!-- ===== Pane: PB Levels / Colors ===== -->
    <section id="pane-levels" class="pane show">
      <div class="card table-wrap">
        <div class="levels-head">
          <div>#</div>
          <div>Color</div>
          <div>Name</div>
          <div>Code</div>
          <div>Order</div>
          <div>Actions</div>
        </div>

        <?php $levels = $levels ?? []; ?>
        <?php if(empty($levels)): ?>
          <div class="levels-row">
            <div>—</div>
            <div class="muted">No PB levels/colors yet.</div>
            <div class="muted">—</div>
            <div class="muted">—</div>
            <div class="muted">—</div>
            <div><button class="btn btn-accent" id="btnAddLevel2">＋ Add your first level</button></div>
          </div>
        <?php else: ?>
          <?php foreach($levels as $i=>$lv): ?>
            <?php
              $hex  = htmlspecialchars($lv['color_hex'] ?? '#cccccc');
              $name = htmlspecialchars($lv['name'] ?? '');
              $code = htmlspecialchars($lv['code'] ?? '');
              $ord  = (int)($lv['order_rank'] ?? 0);
            ?>
            <div class="levels-row">
              <div><?= $i+1 ?></div>
              <div class="swatch">
                <span class="box" style="background:<?= $hex ?>"></span>
                <span class="muted"><?= $hex ?></span>
              </div>
              <div style="font-weight:700;"><?= $name ?></div>
              <div><?= $code ?></div>
              <div><?= $ord ?></div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-light js-edit-level"
                  data-level='<?= json_encode([
                    'level_id' => (int)($lv['level_id']??0),
                    'name'     => $lv['name'] ?? '',
                    'code'     => $lv['code'] ?? '',
                    'color_hex'=> $lv['color_hex'] ?? '#cccccc',
                    'order'    => (int)($lv['order_rank'] ?? 0),
                  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
                >✏️ Edit</button>
                <button class="btn btn-danger js-del-level" data-id="<?= (int)($lv['level_id']??0) ?>">🗑 Delete</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===== Pane: SLT → PB Thresholds ===== -->
    <section id="pane-rules" class="pane">
      <div class="card table-wrap">
        <div class="rules-head">
          <div>#</div>
          <div>Min %</div>
          <div>Max %</div>
          <div>Assign PB Color</div>
          <div>Notes</div>
          <div>Actions</div>
        </div>

        <?php $rules = $rules ?? []; ?>
        <?php if(empty($rules)): ?>
          <div class="rules-row">
            <div>—</div>
            <div class="muted">0</div>
            <div class="muted">0</div>
            <div class="muted">—</div>
            <div class="muted">No rules yet. Click “Add Rule”.</div>
            <div><button class="btn btn-accent" id="btnAddRule2">＋ Add Rule</button></div>
          </div>
        <?php else: ?>
          <?php foreach($rules as $i=>$r): ?>
            <div class="rules-row">
              <div><?= $i+1 ?></div>
              <div><?= (float)($r['min_percent'] ?? 0) ?></div>
              <div><?= (float)($r['max_percent'] ?? 0) ?></div>
              <div>
                <span class="chip" style="background:<?= htmlspecialchars($r['color_hex']??'#d4edda') ?>; color:#123;">
                  <?= htmlspecialchars($r['level_name']??'—') ?>
                </span>
              </div>
              <div class="muted"><?= htmlspecialchars($r['other_rules'] ?? '') ?></div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-light js-edit-rule"
                  data-rule='<?= json_encode([
                    'threshold_id'=>(int)($r['threshold_id']??0),
                    'min_percent' =>(float)($r['min_percent']??0),
                    'max_percent' =>(float)($r['max_percent']??0),
                    'level_id'    =>(int)($r['level_id']??0),
                    'notes'       =>$r['other_rules']??'',
                  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'
                >✏️ Edit</button>
                <button class="btn btn-danger js-del-rule" data-id="<?= (int)($r['threshold_id']??0) ?>">🗑 Delete</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

  </div>

  <!-- ===== Modal: Add/Edit Level ===== -->
  <div class="modal-backdrop" id="mLevel">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mLevelTitle">
      <header>
        <span id="mLevelTitle">Add Level</span>
        <button type="button" class="btn btn-light" id="mLevelClose">✖</button>
      </header>
      <form method="post" action="levels_action.php" id="levelForm">
        <section>
          <div class="grid-2">
            <div>
              <label>Name <span style="color:#c00">*</span></label>
              <input type="text" name="name" id="lvName" required placeholder="e.g., Yellow">
            </div>
            <div>
              <label>Code <span style="color:#c00">*</span></label>
              <input type="text" name="code" id="lvCode" required placeholder="e.g., YLW">
            </div>
          </div>
          <div class="inline">
            <div class="swatch">
              <span class="box" id="lvSwatch" style="background:#ffeb3b"></span>
              <label for="lvHex" class="muted">Color</label>
            </div>
            <input type="text" name="color_hex" id="lvHex" class="w-120" value="#ffeb3b" placeholder="#RRGGBB">
            <input type="color" id="lvPicker" value="#ffeb3b">
            <div style="flex:1"></div>
            <label class="muted">Order</label>
            <input type="number" name="order_rank" id="lvOrder" class="w-120" value="0" min="0">
          </div>
        </section>
        <footer>
          <input type="hidden" name="action" id="lvAction" value="add_level">
          <input type="hidden" name="level_id" id="lvId" value="">
          <button type="button" class="btn" id="btnLevelCancel">Cancel</button>
          <button class="btn btn-accent" id="btnLevelSave">Save Level</button>
        </footer>
      </form>
    </div>
  </div>

  <!-- ===== Modal: Add/Edit Rule ===== -->
  <div class="modal-backdrop" id="mRule">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mRuleTitle">
      <header>
        <span id="mRuleTitle">Add Rule (SLT → PB)</span>
        <button type="button" class="btn btn-light" id="mRuleClose">✖</button>
      </header>
      <form method="post" action="levels_action.php" id="ruleForm">
        <section>
          <div class="grid-2">
            <div>
              <label>Min %</label>
              <input type="number" name="min_percent" id="rlMin" min="0" max="100" step="0.01" value="0">
            </div>
            <div>
              <label>Max %</label>
              <input type="number" name="max_percent" id="rlMax" min="0" max="100" step="0.01" value="100">
            </div>
          </div>
          <div>
            <label>Assign PB Color</label>
            <select name="level_id" id="rlLevel">
              <option value="">— choose PB color —</option>
              <?php if(!empty($levels)): foreach($levels as $lv): ?>
                <option value="<?= (int)($lv['level_id']??0) ?>"><?= htmlspecialchars($lv['name']??'') ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div>
            <label>Notes (optional)</label>
            <textarea name="notes" id="rlNotes" rows="3" placeholder="Any remarks…"></textarea>
          </div>
        </section>
        <footer>
          <input type="hidden" name="action" id="rlAction" value="add_rule">
          <input type="hidden" name="threshold_id" id="rlId" value="">
          <button type="button" class="btn" id="btnRuleCancel">Cancel</button>
          <button class="btn btn-accent" id="btnRuleSave">Save Rule</button>
        </footer>
      </form>
    </div>
  </div>
</div>

<script>
// ===== Tabs
const tabLevelsBtn = document.getElementById('tabLevelsBtn');
const tabRulesBtn  = document.getElementById('tabRulesBtn');
const paneLevels   = document.getElementById('pane-levels');
const paneRules    = document.getElementById('pane-rules');
const btnAddLevel  = document.getElementById('btnAddLevel');
const btnAddRule   = document.getElementById('btnAddRule');

function showLevels(){
  tabLevelsBtn.classList.add('active');
  tabRulesBtn.classList.remove('active');
  paneLevels.classList.add('show');
  paneRules.classList.remove('show');
  btnAddLevel.style.display = '';
  btnAddRule.style.display  = 'none';
}
function showRules(){
  tabRulesBtn.classList.add('active');
  tabLevelsBtn.classList.remove('active');
  paneRules.classList.add('show');
  paneLevels.classList.remove('show');
  btnAddLevel.style.display = 'none';
  btnAddRule.style.display  = '';
}
tabLevelsBtn?.addEventListener('click', showLevels);
tabRulesBtn?.addEventListener('click', showRules);

// Optional: open rules via ?tab=rules
const q = new URLSearchParams(location.search);
if (q.get('tab') === 'rules') showRules();

// ===== Level modal
const mLevel = document.getElementById('mLevel');
const openLv = ()=> mLevel.classList.add('show');
const closeLv= ()=> mLevel.classList.remove('show');
document.getElementById('btnAddLevel')?.addEventListener('click', ()=>toAddLevel());
document.getElementById('btnAddLevel2')?.addEventListener('click', ()=>toAddLevel());
document.getElementById('mLevelClose')?.addEventListener('click', closeLv);
document.getElementById('btnLevelCancel')?.addEventListener('click', closeLv);
mLevel?.addEventListener('click', e=>{ if(e.target===mLevel) closeLv(); });

const lvTitle  = document.getElementById('mLevelTitle');
const lvAction = document.getElementById('lvAction');
const lvId     = document.getElementById('lvId');
const lvName   = document.getElementById('lvName');
const lvCode   = document.getElementById('lvCode');
const lvHex    = document.getElementById('lvHex');
const lvPicker = document.getElementById('lvPicker');
const lvSwatch = document.getElementById('lvSwatch');
const lvOrder  = document.getElementById('lvOrder');

function toAddLevel(){
  lvTitle.textContent = 'Add Level';
  lvAction.value = 'add_level';
  lvId.value=''; lvName.value=''; lvCode.value='';
  lvHex.value='#ffeb3b'; lvPicker.value='#ffeb3b'; lvSwatch.style.background = '#ffeb3b';
  lvOrder.value = 0;
  openLv();
}

document.querySelectorAll('.js-edit-level').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const data = JSON.parse(btn.getAttribute('data-level')||'{}');
    lvTitle.textContent = 'Edit Level';
    lvAction.value = 'update_level';
    lvId.value = data.level_id || '';
    lvName.value = data.name || '';
    lvCode.value = data.code || '';
    lvHex.value  = data.color_hex || '#cccccc';
    lvPicker.value = (data.color_hex || '#cccccc');
    lvSwatch.style.background = (data.color_hex || '#cccccc');
    lvOrder.value = data.order ?? 0;
    openLv();
  });
});
lvHex?.addEventListener('input', ()=>{ lvSwatch.style.background = lvHex.value || '#cccccc'; lvPicker.value = lvHex.value || '#cccccc'; });
lvPicker?.addEventListener('input', ()=>{ lvSwatch.style.background = lvPicker.value || '#cccccc'; lvHex.value = lvPicker.value || '#cccccc'; });

// ===== Rule modal
const mRule = document.getElementById('mRule');
const openRl = ()=> mRule.classList.add('show');
const closeRl= ()=> mRule.classList.remove('show');
document.getElementById('btnAddRule')?.addEventListener('click', ()=>toAddRule());
document.getElementById('btnAddRule2')?.addEventListener('click', ()=>toAddRule());
document.getElementById('mRuleClose')?.addEventListener('click', closeRl);
document.getElementById('btnRuleCancel')?.addEventListener('click', closeRl);
mRule?.addEventListener('click', e=>{ if(e.target===mRule) closeRl(); });

const rlTitle = document.getElementById('mRuleTitle');
const rlAction= document.getElementById('rlAction');
const rlId    = document.getElementById('rlId');
const rlMin   = document.getElementById('rlMin');
const rlMax   = document.getElementById('rlMax');
const rlLevel = document.getElementById('rlLevel');
const rlNotes = document.getElementById('rlNotes');

function toAddRule(){
  rlTitle.textContent = 'Add Rule (SLT → PB)';
  rlAction.value = 'add_rule';
  rlId.value=''; rlMin.value=0; rlMax.value=100; rlLevel.value=''; rlNotes.value='';
  openRl();
}
document.querySelectorAll('.js-edit-rule').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const r = JSON.parse(btn.getAttribute('data-rule')||'{}');
    rlTitle.textContent = 'Edit Rule (SLT → PB)';
    rlAction.value = 'update_rule';
    rlId.value   = r.threshold_id || '';
    rlMin.value  = r.min_percent ?? 0;
    rlMax.value  = r.max_percent ?? 100;
    rlLevel.value= r.level_id || '';
    rlNotes.value= r.notes || '';
    openRl();
  });
});

// Placeholder delete confirms (wire to backend endpoints later)
  <?php if (!empty($flash)): ?>
    Swal.fire({
      icon: '<?= ($flash['t'] ?? '') === 'ok' ? 'success' : 'error' ?>',
      title: <?= json_encode($flash['m'] ?? '') ?>,
      confirmButtonColor: '#ECA305'
    });
  <?php endif; ?>

  // ---- Helper to POST programmatically ----
  function postAction(action, payload){
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = 'levels_action.php';
    const all = { action, ...payload };
    Object.entries(all).forEach(([k,v])=>{
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = v;
      f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
  }

  // ---- Delete Level (confirm) ----
  document.querySelectorAll('.js-del-level').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.dataset.id;
      Swal.fire({
        icon: 'warning',
        title: 'Delete this level?',
        text: 'This cannot be undone.',
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes, delete'
      }).then(res=>{ if(res.isConfirmed) postAction('delete_level', { level_id: id }); });
    });
  });

  // ---- Delete Rule (confirm) ----
  document.querySelectorAll('.js-del-rule').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.dataset.id;
      Swal.fire({
        icon: 'warning',
        title: 'Delete this rule?',
        showCancelButton: true,
        confirmButtonColor: '#ECA305',
        cancelButtonColor: '#6b8b97',
        confirmButtonText: 'Yes, delete'
      }).then(res=>{ if(res.isConfirmed) postAction('delete_rule', { threshold_id: id }); });
    });
  });

  // Optional: open thresholds tab via ?tab=rules
  (function(){
    const tab = new URLSearchParams(location.search).get('tab');
    if (tab === 'rules') document.getElementById('tabRulesBtn')?.click();
  })();
</script>
</body>
</html>
