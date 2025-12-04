<?php
// admin/student_reset.php
// Soft-reset: invalidate all existing attempts but keep history,
// then clear live state (unlocks, level, certificates) so the
// student can start again from the beginning.

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

try {
    $conn->begin_transaction();

    // 1) Invalidate ALL assessment attempts for this student (KEEP rows)
    $sql = "
      UPDATE assessment_attempts
      SET status = 'invalidated',
          remarks = CONCAT(
              COALESCE(remarks, ''),
              CASE 
                WHEN remarks IS NULL OR remarks = '' THEN '' 
                ELSE ' ' 
              END,
              '[Reset by admin ', NOW(), ']'
          )
      WHERE student_id = ?
        AND status <> 'invalidated'
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $stmt->close();

    // NOTE:
    // attempt_stories, attempt_answers, slt_tab_log
    // are NOT deleted – they remain as history linked to the
    // (now invalidated) attempts.

    // 2) Clear "live state" so student can start again

    // 2a) Module unlocks (PB/RB)
    $sql = "DELETE FROM module_unlocks WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $stmt->close();

    // 2b) Certificates
    $sql = "DELETE FROM certificates WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $stmt->close();

    // 2c) Current level
    $sql = "DELETE FROM student_level WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $stmt->close();

    // 3) Reset validation status back to 'pending'
    $reason = 'Reset all tests by admin';
    $sql = "
      INSERT INTO assessment_validation (student_id, status, reason, last_action_by, last_action_at)
      VALUES (?, 'pending', ?, ?, NOW())
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

    $conn->commit();

    header('Location: student_profile.php?user_id=' . $student_id . '&msg=reset_ok');
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    echo "Reset failed: " . htmlspecialchars($e->getMessage());
}
