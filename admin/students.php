<?php
// admin/students.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$PAGE_TITLE  = 'Students';
$ACTIVE_MENU = 'students';

/* ---------- Filters (month/year) ---------- */
$now = new DateTime('now');
$selMonth = isset($_GET['m']) ? max(1, min(12, (int)$_GET['m'])) : (int)$now->format('n');
$selYear  = isset($_GET['y']) ? (int)$_GET['y'] : (int)$now->format('Y');

$start = DateTime::createFromFormat('Y-n-j H:i:s', sprintf('%04d-%d-1 00:00:00', $selYear, $selMonth));
$end   = (clone $start)->modify('first day of next month');

$startStr = $start->format('Y-m-d H:i:s');   // bind_param needs variables (by reference)
$endStr   = $end->format('Y-m-d H:i:s');

/* ---------- Load students + present-days (distinct started_at dates) ---------- */
$rows = [];
$sql = "
  SELECT 
    u.user_id, u.email, u.first_name, u.middle_name, u.last_name, u.ext_name,
    u.student_id_no, u.course, u.major, u.year_level, u.section, u.status,
    u.last_login_at, u.created_at, u.updated_at, u.email_verified_at,
    COALESCE(p.present_days, 0) AS present_days
  FROM users u
  LEFT JOIN (
    SELECT student_id, COUNT(DISTINCT DATE(started_at)) AS present_days
    FROM assessment_attempts
    WHERE started_at >= ? AND started_at < ?
    GROUP BY student_id
  ) p ON p.student_id = u.user_id
  WHERE u.role = 'student'
  ORDER BY u.last_name, u.first_name, u.user_id
";
if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('ss', $startStr, $endStr);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $name = trim(implode(' ', array_filter([
      $r['first_name'] ?? '',
      $r['middle_name'] ?: null,
      $r['last_name'] ?? '',
      $r['ext_name'] ? (' '.$r['ext_name']) : null
    ])));
    $r['full_name'] = $name ?: '—';
    $rows[] = $r;
  }
  $stmt->close();
}

/* helpers */
function dval(?string $dt){
  if (!$dt) return null;
  $t = strtotime($dt); if (!$t) return null;
  return date('Y-m-d H:i', $t);
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
:root{
  --green:#003300;
  --green-ink:#123b12;
  --accent:#ECA305;
  --bg:#f4f6f3;
  --card:#fff;
  --line:#e6efe6;
  --muted:#6b7c6b;
  --shadow:0 10px 28px rgba(0,0,0,.08);
}
.main-content{ width:calc(100% - 220px); margin-left:220px; background:var(--bg); min-height:100vh; }
@media (max-width:992px){ .main-content{ width:100%; margin-left:0; } }

.wrap{ max-width:1280px; margin:0 auto; padding:20px; }
.card{ background:var(--card); border:1px solid var(--line); border-radius:16px; box-shadow:var(--shadow); }
.header{
  display:flex; align-items:center; justify-content:space-between;
  padding:16px 18px; border-bottom:1px solid var(--line);
}
.header h2{ margin:0; color:var(--green-ink); font-weight:900; letter-spacing:.2px; }

.filters{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.select{ position:relative; }
.select select{
  appearance:none; -webkit-appearance:none; -moz-appearance:none;
  padding:10px 36px 10px 12px; border:1px solid #dfe6df; border-radius:999px; background:#fff; font-weight:700;
}
.select:after{
  content:"▾"; position:absolute; right:12px; top:50%; transform:translateY(-50%); pointer-events:none; color:#2c3d2c; font-weight:900;
}

.btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:10px 16px; border-radius:999px; border:1px solid #dfe6df; background:#fff; cursor:pointer; font-weight:800;
  transition: filter .15s ease, transform .06s ease, box-shadow .15s ease;
}
.btn:hover{ filter:brightness(1.02); box-shadow:0 4px 16px rgba(0,0,0,.08); }
.btn:active{ transform:translateY(1px); }

.btn-apply{
  background: linear-gradient(180deg, #ffd780, var(--accent));
  border-color:#d39b06; color:#1b1b1b;
}
.btn-view{
  background: linear-gradient(180deg, #ffd780, var(--accent));
  border:1px solid #d39b06; color:#1b1b1b; font-weight:900;
}
.btn-view:hover{ filter:brightness(1.02); }

.table{ width:100%; border-collapse:separate; border-spacing:0; }
.thead{
  display:grid; grid-template-columns: 60px 2.2fr 1.2fr 1.2fr 160px;
  padding:12px 18px; background:#f7faf7; color:#234123; font-weight:900; border-bottom:1px solid var(--line);
}
.tr{
  display:grid; grid-template-columns: 60px 2.2fr 1.2fr 1.2fr 160px;
  padding:14px 18px; border-top:1px solid var(--line); align-items:center; background:#fff;
}
.tr:nth-child(odd){ background:#fcfdfc; }
.name{ font-weight:800; color:#203920; }
.course{ color:#213a21; font-weight:700; }
.muted{ color:var(--muted); }

.note{
  padding:12px 18px; color:#5b725b; font-size:.92rem; border-top:1px dashed var(--line); background:#fafcf9; border-radius:0 0 16px 16px;
}

/* ---------- Modal ---------- */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  display:none; align-items:center; justify-content:center; z-index:2000; opacity:0;
  transition:opacity .18s ease-out;
}
.modal-backdrop.show{ display:flex; opacity:1; }
.modal{
  width:min(760px,96vw); background:#fff; border:1px solid var(--line); border-radius:16px;
  box-shadow:0 30px 90px rgba(0,0,0,.25); display:flex; flex-direction:column; overflow:hidden;
}
.modal header{
  background:var(--green); color:#fff; padding:12px 16px; display:flex; justify-content:space-between; align-items:center;
}
.modal header h3{ margin:0; font-weight:900; letter-spacing:.2px; }
.modal header .x{ background:transparent; color:#fff; border:1px solid rgba(255,255,255,.3); border-radius:10px; padding:6px 10px; cursor:pointer; }
.modal section{ padding:16px; }
.modal footer{ padding:12px 16px; display:flex; justify-content:flex-end; gap:8px; background:#fafafa; border-top:1px solid #eee; }

.details{
  display:grid; grid-template-columns: 1fr 1fr; gap:12px;
}
.detail{
  background:#fcfdfc; border:1px solid #e9f0e9; border-radius:12px; padding:10px 12px;
}
.detail .dt{ display:block; font-size:.78rem; letter-spacing:.3px; text-transform:uppercase; color:#6b7c6b; margin-bottom:3px; }
.detail .dd{ font-weight:800; color:#213a21; }

@media (max-width:720px){
  .thead{ display:none; }
  .tr{ grid-template-columns: 1fr 1fr; row-gap:6px; }
  .tr > div:nth-child(1){ grid-column:1 / 2; font-weight:900; color:#2c4a2c; }
  .tr > div:nth-child(2){ grid-column:1 / -1; order:3; }
  .tr > div:nth-child(3){ grid-column:1 / 2; order:4; }
  .tr > div:nth-child(4){ grid-column:2 / 3; order:5; text-align:right; }
  .tr > div:nth-child(5){ grid-column:1 / -1; order:6; }
  .details{ grid-template-columns: 1fr; }
}
</style>

<div class="main-content">
  <div class="wrap">
    <div class="card">

      <div class="header">
        <h2>Students</h2>

        <form class="filters" method="get" action="">
          <?php
            $months = [
              1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
              7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
            ];
            $curY = (int)date('Y');
            $years = range($curY-4, $curY+1);
          ?>
          <div class="select">
            <select name="m" aria-label="Month">
              <?php foreach($months as $num=>$name): ?>
                <option value="<?= (int)$num ?>" <?= $num===$selMonth?'selected':'' ?>><?= htmlspecialchars($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="select">
            <select name="y" aria-label="Year">
              <?php foreach($years as $y): ?>
                <option value="<?= (int)$y ?>" <?= $y===$selYear?'selected':'' ?>><?= (int)$y ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-apply" type="submit">Apply</button>
        </form>
      </div>

      <!-- table -->
      <div class="thead">
        <div>#</div>
        <div>Name of Student</div>
        <div>Course</div>
        <div>No. of Days present</div>
        <div>Action (View Details)</div>
      </div>

      <?php if (!$rows): ?>
        <div class="tr"><div>—</div><div class="muted">No students found.</div></div>
      <?php else: ?>
        <?php foreach($rows as $i=>$r): 
          $data = [
            'user_id'   => (int)$r['user_id'],
            'name'      => $r['full_name'],
            'email'     => $r['email'] ?? '',
            'student_id_no' => $r['student_id_no'] ?? '',
            'course'    => $r['course'] ?? '',
            'major'     => $r['major'] ?? '',
            'year_level'=> isset($r['year_level']) ? (int)$r['year_level'] : null,
            'section'   => $r['section'] ?? '',
            'status'    => $r['status'] ?? '',
            'present_days' => (int)$r['present_days'],
            'window'    => $months[$selMonth] . ' ' . $selYear,
            'last_login_at'     => dval($r['last_login_at']),
            'created_at'        => dval($r['created_at']),
            'updated_at'        => dval($r['updated_at']),
            'email_verified_at' => dval($r['email_verified_at']),
          ];
          $json = json_encode($data, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
        ?>
          <div class="tr">
            <div><?= $i+1 ?></div>
            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="course"><?= htmlspecialchars($r['course'] ?: '—') ?></div>
            <div><strong><?= (int)$r['present_days'] ?></strong></div>
            <div>
              <button type="button" class="btn btn-view js-view" data-student='<?= $json ?>'>
                View Details
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="note">
        <em>“No. of Days present”</em> = bilang ng natatanging araw (date) kung kailan may kahit isang assessment activity (SLT/PB/RB) na <b>nagsimula</b> para sa estudyante sa napiling buwan.
      </div>

    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="studentModal" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mTitle">
    <header>
      <h3 id="mTitle">Student Details</h3>
      <button type="button" class="x" id="mCloseTop">✖</button>
    </header>
    <section>
      <div class="details">
        <div class="detail"><span class="dt">Name</span><span class="dd" id="mName">—</span></div>
        <div class="detail"><span class="dt">Email</span><span class="dd" id="mEmail">—</span></div>
        <div class="detail"><span class="dt">Student ID No.</span><span class="dd" id="mStudId">—</span></div>
        <div class="detail"><span class="dt">Status</span><span class="dd" id="mStatus">—</span></div>
        <div class="detail"><span class="dt">Course</span><span class="dd" id="mCourse">—</span></div>
        <div class="detail"><span class="dt">Major</span><span class="dd" id="mMajor">—</span></div>
        <div class="detail"><span class="dt">Year Level</span><span class="dd" id="mYear">—</span></div>
        <div class="detail"><span class="dt">Section</span><span class="dd" id="mSection">—</span></div>
        <div class="detail"><span class="dt">Present days (month)</span><span class="dd" id="mPresent">—</span></div>
        <div class="detail"><span class="dt">Selected month</span><span class="dd" id="mWindow">—</span></div>
        <div class="detail"><span class="dt">Last login</span><span class="dd" id="mLastLogin">—</span></div>
        <div class="detail"><span class="dt">Account created</span><span class="dd" id="mCreated">—</span></div>
        <div class="detail"><span class="dt">Last updated</span><span class="dd" id="mUpdated">—</span></div>
        <div class="detail"><span class="dt">Email verified</span><span class="dd" id="mVerified">—</span></div>
      </div>
    </section>
    <footer>
      <button type="button" class="btn btn-view" id="mClose">Close</button>
    </footer>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('studentModal');
  const open  = () => { modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); };
  const close = () => { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); };

  // Fill helper
  const byId = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = (v ?? '—') || '—'; };

  // Buttons
  document.querySelectorAll('.js-view').forEach(btn => {
    btn.addEventListener('click', () => {
      let data = {};
      try { data = JSON.parse(btn.getAttribute('data-student') || '{}'); } catch(e){ data = {}; }

      byId('mName',     data.name);
      byId('mEmail',    data.email);
      byId('mStudId',   data.student_id_no);
      byId('mStatus',   data.status);
      byId('mCourse',   data.course);
      byId('mMajor',    data.major);
      byId('mYear',     data.year_level != null ? String(data.year_level) : '—');
      byId('mSection',  data.section);
      byId('mPresent',  data.present_days != null ? String(data.present_days) : '0');
      byId('mWindow',   data.window);
      byId('mLastLogin',data.last_login_at);
      byId('mCreated',  data.created_at);
      byId('mUpdated',  data.updated_at);
      byId('mVerified', data.email_verified_at);

      open();
    });
  });

  // Close wiring
  document.getElementById('mClose')?.addEventListener('click', close);
  document.getElementById('mCloseTop')?.addEventListener('click', close);
  modal.addEventListener('click', (e)=>{ if (e.target === modal) close(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
})();
</script>

</body>
</html>
