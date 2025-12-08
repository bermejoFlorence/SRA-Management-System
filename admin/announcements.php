<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Announcements';
$ACTIVE_MENU = 'announcements';

require_once __DIR__ . '/includes/header.php';  // navbar + hamburger + backdrop + JS
require_once __DIR__ . '/includes/sidebar.php'; // sidebar
?>
<div class="main-content">
  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">Announcements</h1>
      <p class="page-subtitle">
        Create and manage updates that students will see on their dashboard.
      </p>
    </div>
    <button type="button" class="btn-main" onclick="document.getElementById('annFormCard').scrollIntoView({behavior:'smooth'});">
      <i class="fas fa-plus-circle"></i>
      <span>New Announcement</span>
    </button>
  </div>

  <div class="ann-layout">
    <!-- LEFT: Create / Edit form -->
    <div class="card ann-form-card" id="annFormCard">
      <div class="card-header">
        <h2>Create / Edit Announcement</h2>
        <p>Set the title, message, audience, and visibility period.</p>
      </div>

      <!-- NOTE: action & backend logic - gagawin natin sa next step -->
      <form method="post" action="announcements_save.php" class="ann-form">
        <div class="form-row">
          <div class="form-group">
            <label for="title">Title <span class="req">*</span></label>
            <input type="text" id="title" name="title" placeholder="e.g. Starting Level Test Deadline" required>
          </div>
          <div class="form-group">
            <label for="audience">Audience</label>
            <select id="audience" name="audience">
              <option value="students">Students</option>
              <option value="all">All Users</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date">
          </div>
          <div class="form-group">
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="priority">Priority</label>
            <select id="priority" name="priority">
              <option value="normal">Normal</option>
              <option value="important">Important</option>
            </select>
          </div>
          <div class="form-group">
            <label class="switch-label">
              <input type="checkbox" name="is_active" value="1" checked>
              <span class="switch-pill"></span>
              <span class="switch-text">Visible on student dashboard</span>
            </label>
          </div>
        </div>

        <div class="form-group">
          <label for="body">Message <span class="req">*</span></label>
          <textarea id="body" name="body" rows="4" placeholder="Write the full announcement message here…" required></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-main">
            <i class="fas fa-save"></i>
            <span>Save Announcement</span>
          </button>
          <button type="reset" class="btn-ghost" type="button">
            Clear
          </button>
        </div>
      </form>
    </div>

    <!-- RIGHT: List of announcements (sample static cards for now) -->
    <div class="card ann-list-card">
      <div class="card-header list-header">
        <div>
          <h2>Existing Announcements</h2>
          <p>Latest announcements that will appear on student dashboards.</p>
        </div>
        <span class="badge-count">2</span> <!-- sample count for now -->
      </div>

      <ul class="ann-list">
        <!-- Sample item #1 -->
        <li class="ann-item important">
          <div class="ann-item-main">
            <div class="ann-meta">
              <span class="ann-category">Deadline</span>
              <span class="ann-date">Aug 15, 2025 • Students</span>
            </div>
            <h3 class="ann-title">Starting Level Test Completion</h3>
            <p class="ann-body">
              All students are required to complete the Starting Level Test on or before
              <strong>August 15, 2025</strong>. Please log in early to avoid last-minute issues.
            </p>
          </div>
          <div class="ann-footer">
            <span class="ann-status active-dot">Active</span>
            <div class="ann-actions">
              <button type="button" class="btn-link"><i class="fas fa-pen"></i> Edit</button>
              <button type="button" class="btn-link danger"><i class="fas fa-box-archive"></i> Archive</button>
            </div>
          </div>
        </li>

        <!-- Sample item #2 -->
        <li class="ann-item">
          <div class="ann-item-main">
            <div class="ann-meta">
              <span class="ann-category">Reminder</span>
              <span class="ann-date">Aug 20, 2025 • Students</span>
            </div>
            <h3 class="ann-title">Power Builder Assessment Opening</h3>
            <p class="ann-body">
              The Power Builder Assessment will open on <strong>August 20, 2025</strong>.
              You may start once you finish the SLT stories required for your level.
            </p>
          </div>
          <div class="ann-footer">
            <span class="ann-status scheduled-dot">Scheduled</span>
            <div class="ann-actions">
              <button type="button" class="btn-link"><i class="fas fa-pen"></i> Edit</button>
              <button type="button" class="btn-link danger"><i class="fas fa-box-archive"></i> Archive</button>
            </div>
          </div>
        </li>
      </ul>

      <!-- Empty state (i-hide mo na lang pag may dynamic data na tayo) -->
      <!--
      <div class="ann-empty">
        <i class="fas fa-bullhorn"></i>
        <h3>No announcements yet</h3>
        <p>Create your first announcement and it will appear here.</p>
      </div>
      -->
    </div>
  </div>
</div>

<style>
/* --------- Page header --------- */
.main-content{
  padding:24px 28px 32px;
}

.page-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}

.page-title{
  margin:0;
  font-size:22px;
  font-weight:600;
  color:#fff;
}

.page-subtitle{
  margin:4px 0 0;
  font-size:13px;
  opacity:.85;
  color:#f0f4f0;
}

/* --------- Layout --------- */
.ann-layout{
  display:grid;
  grid-template-columns:minmax(0, 1.1fr) minmax(0, 1.6fr);
  gap:18px;
  align-items:flex-start;
}

@media (max-width: 992px){
  .ann-layout{
    grid-template-columns:minmax(0,1fr);
  }
}

/* --------- Cards --------- */
.card{
  background:#f9faf9;
  border-radius:18px;
  padding:18px 20px 20px;
  box-shadow:0 14px 40px rgba(0,0,0,.16);
}

.card-header h2{
  margin:0;
  font-size:16px;
  font-weight:600;
  color:#013107;
}
.card-header p{
  margin:4px 0 0;
  font-size:13px;
  color:#555;
}

.ann-form-card{
  background:#fdfefb;
}

.ann-list-card{
  background:#ffffff;
}

/* --------- Form --------- */
.ann-form{
  margin-top:14px;
}

.form-row{
  display:grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
  gap:12px;
}

@media (max-width: 720px){
  .form-row{
    grid-template-columns:minmax(0,1fr);
  }
}

.form-group{
  display:flex;
  flex-direction:column;
  gap:6px;
  margin-bottom:10px;
}

.form-group label{
  font-size:13px;
  font-weight:500;
  color:#123118;
}

.req{
  color:#d9534f;
}

.ann-form input[type="text"],
.ann-form input[type="date"],
.ann-form select,
.ann-form textarea{
  border-radius:10px;
  border:1px solid #d6e1d6;
  padding:8px 10px;
  font-size:13px;
  font-family:inherit;
  background:#ffffff;
  outline:none;
  transition:border-color .18s, box-shadow .18s, background .18s;
}

.ann-form input:focus,
.ann-form select:focus,
.ann-form textarea:focus{
  border-color:#0b6e2a;
  box-shadow:0 0 0 1px rgba(11,110,42,.18);
  background:#fbfffa;
}

/* switch */
.switch-label{
  display:flex;
  align-items:center;
  gap:8px;
  margin-top:20px;
  cursor:pointer;
  font-size:13px;
}
.switch-label input{
  display:none;
}
.switch-pill{
  width:34px;
  height:18px;
  border-radius:999px;
  background:#c3d6c3;
  position:relative;
  transition:background .18s;
}
.switch-pill::after{
  content:'';
  position:absolute;
  top:2px;
  left:3px;
  width:14px;
  height:14px;
  border-radius:50%;
  background:#fff;
  box-shadow:0 1px 3px rgba(0,0,0,.25);
  transition:transform .18s;
}
.switch-label input:checked + .switch-pill{
  background:#0b6e2a;
}
.switch-label input:checked + .switch-pill::after{
  transform:translateX(13px);
}
.switch-text{
  color:#123118;
}

/* buttons */
.btn-main{
  display:inline-flex;
  align-items:center;
  gap:6px;
  border:none;
  border-radius:999px;
  padding:8px 16px;
  font-size:13px;
  font-weight:500;
  cursor:pointer;
  background:#0b6e2a;
  color:#fff;
  box-shadow:0 8px 20px rgba(0,0,0,.25);
  transition:transform .12s ease, box-shadow .12s ease, background .12s ease;
}
.btn-main:hover{
  background:#095321;
  box-shadow:0 10px 26px rgba(0,0,0,.3);
  transform:translateY(-1px);
}
.btn-main i{
  font-size:14px;
}

.btn-ghost{
  border-radius:999px;
  border:1px solid #c2d2c2;
  padding:7px 14px;
  font-size:13px;
  background:transparent;
  cursor:pointer;
  color:#214020;
}
.btn-ghost:hover{
  background:#eef4ee;
}

.form-actions{
  margin-top:6px;
  display:flex;
  align-items:center;
  gap:10px;
}

/* --------- List --------- */
.list-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
}

.badge-count{
  min-width:26px;
  height:26px;
  border-radius:999px;
  background:#013107;
  color:#ffd768;
  font-size:13px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:600;
}

.ann-list{
  list-style:none;
  margin:14px 0 0;
  padding:0;
  display:flex;
  flex-direction:column;
  gap:10px;
}

.ann-item{
  border-radius:14px;
  padding:10px 12px 11px;
  background:#f6faf6;
  border:1px solid #dde7dd;
  display:flex;
  flex-direction:column;
  gap:6px;
}
.ann-item.important{
  border-color:#f2b54d;
  background:#fffaf1;
}

.ann-meta{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.6px;
}
.ann-category{
  padding:2px 8px;
  border-radius:999px;
  background:rgba(1,49,7,.07);
  color:#0b6e2a;
  font-weight:600;
}
.ann-item.important .ann-category{
  background:rgba(236,163,5,.08);
  color:#c97a03;
}
.ann-date{
  opacity:.7;
  color:#334333;
}

.ann-title{
  margin:2px 0 0;
  font-size:14px;
  font-weight:600;
  color:#102510;
}
.ann-body{
  margin:2px 0 0;
  font-size:13px;
  color:#3a4b3a;
}

.ann-footer{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  margin-top:2px;
}
.ann-status{
  font-size:12px;
  display:inline-flex;
  align-items:center;
  gap:6px;
  color:#285028;
}
.ann-status::before{
  content:'';
  width:8px;
  height:8px;
  border-radius:50%;
  background:#8bc34a;
}
.ann-status.scheduled-dot::before{
  background:#ffb74d;
}
.ann-status.active-dot::before{
  background:#4caf50;
}

.ann-actions{
  display:flex;
  align-items:center;
  gap:6px;
}

.btn-link{
  border:none;
  background:none;
  padding:0;
  font-size:12px;
  cursor:pointer;
  color:#0b6e2a;
  display:inline-flex;
  align-items:center;
  gap:4px;
}
.btn-link.danger{
  color:#c0392b;
}
.btn-link i{
  font-size:12px;
}

/* empty state (optional) */
.ann-empty{
  margin-top:10px;
  padding:22px 16px;
  border-radius:14px;
  background:#f6faf6;
  text-align:center;
  color:#445944;
}
.ann-empty i{
  font-size:26px;
  margin-bottom:6px;
  color:#0b6e2a;
}
.ann-empty h3{
  margin:0 0 4px;
  font-size:15px;
}
.ann-empty p{
  margin:0;
  font-size:13px;
}
</style>

</body>
</html>
