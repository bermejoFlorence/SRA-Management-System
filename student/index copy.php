<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$PAGE_TITLE  = 'Science Research Dashboard';
$ACTIVE_MENU = 'dashboard';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

?>


<div class="main-content">

</div>

</body>
</html>
