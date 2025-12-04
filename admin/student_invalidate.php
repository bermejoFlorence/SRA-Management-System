<?php
// admin/student_invalidate.php
// Marks a student's assessment as "invalid" (no reset yet)

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$student_id = (int)($_POST['user_id'] ?? 0);
if ($student_id <= 0) {
    header('Location: programs_students.php');
    exit;
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$reason   = 'Marked invalid by admin'; // later pwede ka maglagay ng textarea para editable

$sql = "
  INSERT INTO assessment_validation (student_id, status, reason, last_action_by, last_action_at)
  VALUES (?, 'invalid', ?, ?, NOW())
  ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    reason = VALUES(reason),
    last_action_by = VALUES(last_action_by),
    last_action_at = VALUES(last_action_at)
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('isi', $student_id, $reason, $admin_id);
$stmt->execute();
$stmt->close();

header('Location: student_profile.php?user_id=' . $student_id . '&msg=invalid');
exit;
