<?php
// admin/includes/header.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$PAGE_TITLE = $PAGE_TITLE ?? 'Admin Dashboard';

/* Cache-busted CSS using filemtime */
$cssRel = 'styles/style.css';                 // URL for the browser (relative to /admin)
$cssFs  = __DIR__ . '/../styles/style.css';   // filesystem path for filemtime
$cssVer = file_exists($cssFs) ? filemtime($cssFs) : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($PAGE_TITLE); ?></title>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="<?php echo $cssRel . '?v=' . $cssVer; ?>">
</head>
<body>

  <!-- Top navbar (reusable) -->
<div class="navbar">
  <button class="hamburger" id="sidebarToggle" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">
    <i class="fas fa-bars"></i>
  </button>
  <div style="margin-left:auto"></div>
  <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
  <div class="user-icon"><img src="assets/user.png" alt="User"></div>
</div>

<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;
  const btn  = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  const closeBtn = document.getElementById('sidebarClose');

  function setExpanded(open){ if(btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  function open(){ body.classList.add('sidebar-open'); setExpanded(true); }
  function close(){ body.classList.remove('sidebar-open'); setExpanded(false); }

  if (btn) btn.addEventListener('click', () => body.classList.contains('sidebar-open') ? close() : open());
  if (backdrop) backdrop.addEventListener('click', close);
  if (closeBtn) closeBtn.addEventListener('click', close);

// close after tapping a *real* menu link on mobile (exclude submenu toggles)
document.addEventListener('click', (e) => {
  const link = e.target.closest('.sidebar a');
  if (!link) return;

  // If it's the "Stories" toggle (or any .has-sub), don't close the sidebar
  if (link.classList.contains('has-sub')) {
    e.preventDefault(); // prevent jumping to "#"
    return;
  }

  if (window.matchMedia('(max-width: 992px)').matches) close();
});


  // Esc to close
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });
});
</script>
