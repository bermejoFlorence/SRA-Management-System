<?php
// admin/ajax_add_program.php
// Called via AJAX from Programs & Students page when adding a course/program.

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

header('Content-Type: application/json');

// adjust kung iba ang gamit mong connection file
require_once __DIR__ . '/../db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// --------- INPUTS ---------
$code        = trim($_POST['program_code'] ?? '');
$name        = trim($_POST['program_name'] ?? '');
$status      = $_POST['status'] ?? 'active';
$first_major = trim($_POST['first_major'] ?? '');

// basic validation
if ($code === '' || $name === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Course code and title are required.'
    ]);
    exit;
}

// normalize code (BSIT, BSED, etc.)
$code = strtoupper($code);

// --------- CHECK DUPLICATE CODE ---------
$stmt = $conn->prepare("SELECT COUNT(*) FROM sra_programs WHERE program_code = ?");
$stmt->bind_param('s', $code);
$stmt->execute();
$stmt->bind_result($cnt);
$stmt->fetch();
$stmt->close();

if ($cnt > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'A course with this code already exists.'
    ]);
    exit;
}

// --------- INSERT PROGRAM (sra_programs) ---------
$stmt = $conn->prepare("
    INSERT INTO sra_programs (program_code, program_name, status)
    VALUES (?, ?, ?)
");
$stmt->bind_param('sss', $code, $name, $status);
$stmt->execute();
$program_id = (int)$stmt->insert_id;
$stmt->close();

// --------- OPTIONAL MAJOR (sra_majors) ---------
if ($program_id > 0 && $first_major !== '') {
    $stmt = $conn->prepare("
        INSERT INTO sra_majors (program_id, major_name, status)
        VALUES (?, ?, 'active')
    ");
    $stmt->bind_param('is', $program_id, $first_major);
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'message' => $first_major !== ''
        ? 'Course and first major successfully added.'
        : 'Course/program successfully added.'
]);
