<?php
// student/slt_tab_switch_log.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

try {
    // Basahin ang JSON body mula sa fetch()
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new Exception('Invalid payload');
    }

    // Galing sa session at sa JS
    $user_id   = (int)($_SESSION['user_id'] ?? 0);
    $attemptId = (int)($data['attempt_id'] ?? 0);
    $stateRaw  = $data['state'] ?? '';
    $state     = ($stateRaw === 'hidden') ? 'hidden' : 'visible'; // default: visible

    if (!$user_id || !$attemptId) {
        throw new Exception('Missing user/attempt');
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Insert log sa slt_tab_log
    $stmt = $conn->prepare("
        INSERT INTO slt_tab_log (user_id, attempt_id, state, user_agent)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('iiss', $user_id, $attemptId, $state, $ua);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}
