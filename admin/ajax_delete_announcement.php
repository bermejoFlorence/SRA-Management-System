<?php
// admin/ajax_delete_announcement.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

header('Content-Type: application/json');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$id = (int)($_POST['announcement_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid announcement id.']);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM sra_announcements WHERE announcement_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        echo json_encode(['success' => true, 'message' => 'Announcement deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Announcement not found.']);
    }
} catch (Throwable $e) {
    error_log('Announcement delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error while deleting announcement.']);
}
