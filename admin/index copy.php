<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Admin Dashboard';
$ACTIVE_MENU = 'dashboard';

require_once __DIR__ . '/includes/header.php';  // <-- navbar + hamburger + backdrop + JS
require_once __DIR__ . '/includes/sidebar.php'; // <-- sidebar (with id="sidebar")
?>
<div class="main-content">
  <div class="dashboard-grid">
  </div>
  
</div>
</body>
</html>

