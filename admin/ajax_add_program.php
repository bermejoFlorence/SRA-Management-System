<?php
// admin/ajax_add_program.php
// Called via AJAX from Programs & Students page when adding a course/program
// or when attaching a new major to an existing program.

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

header('Content-Type: application/json');

require_once __DIR__ . '/../db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/**
 * Small helper para consistent ang JSON response
 */
function jexit(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// --------- INPUTS ---------
$mode             = $_POST['course_mode'] ?? 'new';         // 'new' or 'existing'
$mode             = ($mode === 'existing') ? 'existing' : 'new';

$picker           = $_POST['program_picker']      ?? '__new';  // not required server-side, informational lang
$existingProgramId= (int)($_POST['existing_program_id'] ?? 0);

$code        = trim($_POST['program_code'] ?? '');
$name        = trim($_POST['program_name'] ?? '');
$status      = $_POST['status'] ?? 'active';
$first_major = trim($_POST['first_major'] ?? '');

// normalize
$status = $status === 'inactive' ? 'inactive' : 'active';

// ======================================================
// MODE 1: EXISTING PROGRAM  →  add new MAJOR only
// ======================================================
if ($mode === 'existing') {
    if ($existingProgramId <= 0) {
        jexit(false, 'Please select an existing course/program.');
    }
    if ($first_major === '') {
        jexit(false, 'Please enter a major to add to the selected course/program.');
    }

    // Check kung existing talaga ang program_id
    $stmt = $conn->prepare("SELECT program_id FROM sra_programs WHERE program_id = ? LIMIT 1");
    $stmt->bind_param('i', $existingProgramId);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close();
        jexit(false, 'Selected course/program does not exist.');
    }
    $stmt->close();

    // Optional: iwas duplicate major name sa loob ng same program
    $stmt = $conn->prepare("
        SELECT 1
        FROM sra_majors
        WHERE program_id = ? AND major_name = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $existingProgramId, $first_major);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        jexit(false, 'This major already exists for the selected course/program.');
    }
    $stmt->close();

    // Insert bagong major para sa existing program
    $stmt = $conn->prepare("
        INSERT INTO sra_majors (program_id, major_name, status)
        VALUES (?, ?, 'active')
    ");
    $stmt->bind_param('is', $existingProgramId, $first_major);
    $stmt->execute();
    $stmt->close();

    jexit(true, 'New major successfully added to the selected course/program.');
}

// ======================================================
// MODE 2: NEW PROGRAM  →  insert sa sra_programs (+ optional major)
// ======================================================

// basic validation
if ($code === '' || $name === '') {
    jexit(false, 'Course code and title are required.');
}

// normalize code (BSIT, BSED, etc.)
$code = strtoupper($code);

// CHECK DUPLICATE CODE (pwede mo ring idagdag AND program_name kung gusto mo)
$stmt = $conn->prepare("SELECT COUNT(*) FROM sra_programs WHERE program_code = ?");
$stmt->bind_param('s', $code);
$stmt->execute();
$stmt->bind_result($cnt);
$stmt->fetch();
$stmt->close();

if ($cnt > 0) {
    jexit(false, 'A course with this code already exists. If you want to add a major, choose the course from the dropdown.');
}

// INSERT PROGRAM (sra_programs)
$stmt = $conn->prepare("
    INSERT INTO sra_programs (program_code, program_name, status)
    VALUES (?, ?, ?)
");
$stmt->bind_param('sss', $code, $name, $status);
$stmt->execute();
$program_id = (int)$stmt->insert_id;
$stmt->close();

// OPTIONAL MAJOR (sra_majors)
if ($program_id > 0 && $first_major !== '') {
    $stmt = $conn->prepare("
        INSERT INTO sra_majors (program_id, major_name, status)
        VALUES (?, ?, 'active')
    ");
    $stmt->bind_param('is', $program_id, $first_major);
    $stmt->execute();
    $stmt->close();

    jexit(true, 'Course and first major successfully added.');
}

jexit(true, 'Course/program successfully added.');
