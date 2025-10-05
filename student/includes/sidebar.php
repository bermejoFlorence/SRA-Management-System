<?php
// student/includes/sidebar.php
$ACTIVE_MENU = $ACTIVE_MENU ?? 'dashboard';   // 'dashboard' | 'learn' | 'results' | 'certs' | 'help' | 'settings'
$ACTIVE_SUB  = $ACTIVE_SUB  ?? '';            // 'slt' | 'pb' | 'rb'

$active    = fn($k) => ($k === $ACTIVE_MENU) ? 'active' : '';
$subActive = fn($k) => ($ACTIVE_MENU === 'learn' && $ACTIVE_SUB === $k) ? 'active' : '';
$openCls   = fn($k) => ($ACTIVE_MENU === $k) ? 'open' : '';
?>
<div class="sidebar" id="sidebar">
  <!-- (optional) mobile close button, same as admin -->
  <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">
    <i class="fas fa-times"></i>
  </button>

  <!-- Logo (same look as admin) -->
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

  <!-- Stories / Learning with submenu (mirrors admin “Stories”) -->
  <a href="#" class="has-sub <?php echo $active('learn'), ' ', $openCls('learn'); ?>" data-subtoggle="learn">
    <i class="fas fa-book-open"></i> Stories
    <i class="chev fas fa-chevron-down"></i>
  </a>
  <div class="submenu <?php echo $openCls('learn'); ?>" data-submenu="learn">
    <!-- Use student-facing routes; adjust filenames if yours differ -->
    <a class="<?php echo $subActive('slt'); ?>" href="stories_sl.php">Starting Level Assessment</a>
    <a class="<?php echo $subActive('pb');  ?>" href="stories_pb.php">Power Builder Assessment</a>
    <a class="<?php echo $subActive('rb');  ?>" href="stories_rb.php">Rate Builder Assessment</a>
  </div>

  <!-- <a class="<?php echo $active('results'); ?>" href="results.php">
    <i class="fas fa-chart-line"></i> My Results
  </a>
  <a class="<?php echo $active('certs'); ?>" href="certificates.php">
    <i class="fas fa-certificate"></i> Certificates
  </a>

  <div style="margin:14px 10px 6px; font-size:12px; text-transform:uppercase; opacity:.7; letter-spacing:.5px;">
    Account
  </div>
  <a class="<?php echo $active('help'); ?>" href="help.php">
    <i class="fas fa-circle-question"></i> Help
  </a>
  <a class="<?php echo $active('settings'); ?>" href="settings.php">
    <i class="fas fa-gear"></i> Settings
  </a> -->

  <hr style="border:none; border-top:1px solid rgba(255,255,255,.25); margin:12px 0;">
  <a href="../logout.php">
    <i class="fas fa-right-from-bracket"></i> Logout
  </a>
</div>

<style>
/* Reuse the same submenu look/feel from admin */
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
// Same toggler behavior as admin
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

