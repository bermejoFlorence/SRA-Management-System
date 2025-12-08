<?php
// admin/includes/sidebar.php
$ACTIVE_MENU = $ACTIVE_MENU ?? 'dashboard';
$ACTIVE_SUB  = $ACTIVE_SUB  ?? ''; // 'slt' | 'pb' | 'rb'

$active    = fn($k) => ($k === $ACTIVE_MENU) ? 'active' : '';
$subActive = fn($k) => ($ACTIVE_MENU === 'stories' && $ACTIVE_SUB === $k) ? 'active' : '';
$openCls   = fn($k) => ($ACTIVE_MENU === $k) ? 'open' : '';
?>
<div class="sidebar" id="sidebar">
  <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">
    <i class="fas fa-times"></i>
  </button>

  <div class="logo">
    <img src="assets/logo.png" alt="SRA Logo" class="logo-img">
    <span class="logo-text">
      <span class="accent">S</span>cience
      <span class="accent">R</span>esearch
      <span class="accent">A</span>ssociates
    </span>
  </div>

  <a class="<?php echo $active('dashboard'); ?>" href="index.php">
    <i class="fas fa-home"></i> Dashboard
  </a>

  <!-- Core -->
  <div style="margin:14px 10px 6px; font-size:12px; text-transform:uppercase; opacity:.7; letter-spacing:.5px;">
    Core
  </div>

  <!-- Stories with submenu -->
  <a href="#" class="has-sub <?php echo $active('stories'), ' ', $openCls('stories'); ?>" data-subtoggle="stories">
    <i class="fas fa-book-open"></i> Stories
    <i class="chev fas fa-chevron-down"></i>
  </a>
  <div class="submenu <?php echo $openCls('stories'); ?>" data-submenu="stories">
    <a class="<?php echo $subActive('slt'); ?>" href="stories_sl.php">Starting Level Assessment</a>
    <a class="<?php echo $subActive('pb');  ?>" href="stories_pb.php">Power Builder Assessment</a>
    <a class="<?php echo $subActive('rb');  ?>" href="stories_rb.php">Rate Builder Assessment</a>
  </div>

  <!-- NEW: Programs & Students -->
  <a class="<?php echo $active('prog_students'); ?>" href="programs_students.php">
    <i class="fas fa-layer-group"></i> Programs &amp; Students
  </a>

  <!-- Configuration -->
  <div style="margin:14px 10px 6px; font-size:12px; text-transform:uppercase; opacity:.7; letter-spacing:.5px;">
    Configuration
  </div>

  <a class="<?php echo $active('levels'); ?>" href="levels.php">
    <i class="fas fa-swatchbook"></i> Levels &amp; Thresholds
  </a>

  <!-- NEW: Announcements menu -->
  <a class="<?php echo $active('announcements'); ?>" href="announcements.php">
    <i class="fas fa-bullhorn"></i> Announcements
  </a>

  <hr style="border:none; border-top:1px solid rgba(255,255,255,.25); margin:12px 0;">
  <a href="../logout.php">
    <i class="fas fa-right-from-bracket"></i> Logout
  </a>
</div>

<style>
/* submenu styles */
.sidebar .has-sub{ display:flex; align-items:center; gap:10px; }
.sidebar .has-sub .chev{ margin-left:auto; transition:transform .18s; }
.sidebar .has-sub.open .chev{ transform:rotate(180deg); }

.sidebar .submenu{ display:none; padding-left:34px; }
.sidebar .submenu.open{ display:block; }
.sidebar .submenu a{ display:flex; align-items:center; gap:8px; padding:8px 10px; font-size:14px; opacity:.95; }
.sidebar .submenu a.active{ background:rgba(255,255,255,.15); }
.sidebar .submenu .dot{ width:6px; height:6px; border-radius:999px; background:#fff; opacity:.8; }
</style>

<script>
// collapse/expand Stories submenu without leaving the page
document.querySelectorAll('[data-subtoggle]').forEach(link=>{
  link.addEventListener('click', e=>{
    e.preventDefault();
    const key = link.getAttribute('data-subtoggle');
    const menu = document.querySelector(`[data-submenu="${key}"]`);
    link.classList.toggle('open');
    menu?.classList.toggle('open');
  });
});
</script>
