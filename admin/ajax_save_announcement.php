<?php
// admin/ajax_save_announcement.php
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

$title   = trim($_POST['title'] ?? '');
$body    = trim($_POST['body'] ?? '');
$status  = $_POST['status'] ?? 'active';
$mode    = $_POST['mode'] ?? 'create';
$id      = (int)($_POST['announcement_id'] ?? 0);

if ($title === '' || $body === '') {
    echo json_encode(['success' => false, 'message' => 'Title and message are required.']);
    exit;
}

/* audience & priority: fixed values na lang */
$audience = 'students';
$priority = 'normal';

$validStatus   = ['active','scheduled','inactive'];
if (!in_array($status, $validStatus, true)) $status = 'active';

$createdBy = (int)($_SESSION['user_id'] ?? 0);

try {
    if ($mode === 'edit' && $id > 0) {
        $stmt = $conn->prepare("
          UPDATE sra_announcements
          SET title = ?, body = ?, audience = ?, priority = ?, status = ?,
              start_date = NULL, end_date = NULL, updated_at = NOW()
          WHERE announcement_id = ?
        ");
        $stmt->bind_param(
          'sssssi',
          $title,
          $body,
          $audience,
          $priority,
          $status,
          $id
        );
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Announcement updated successfully.']);
    } else {
        $stmt = $conn->prepare("
          INSERT INTO sra_announcements
            (title, body, audience, priority, status, start_date, end_date, created_by)
          VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)
        ");
        $stmt->bind_param(
          'sssssi',
          $title,
          $body,
          $audience,
          $priority,
          $status,
          $createdBy
        );
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Announcement created successfully.']);
    }
} catch (Throwable $e) {
    error_log('Announcement save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error while saving announcement.']);
}
