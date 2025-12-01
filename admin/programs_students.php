<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Programs and Students';
$ACTIVE_MENU = 'prog_students';

require_once __DIR__ . '/includes/header.php';  // <-- navbar + hamburger + backdrop + JS
require_once __DIR__ . '/includes/sidebar.php'; // <-- sidebar (with id="sidebar")
?>
<div class="main-content">
  <div class="dashboard-grid">
  </div>
  
</div>
</body>
</html>

