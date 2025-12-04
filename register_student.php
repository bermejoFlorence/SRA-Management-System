<?php
// register_student.php
header('Content-Type: application/json');

// TEMP: i-on mo habang nagde-debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // kailangan para ma-access ang $_SESSION['google_pending']

require_once __DIR__ . '/db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// === PHPMailer ===
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function jexit(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

try {

    // ---------- INPUTS ----------

    $firstname     = trim($_POST['firstname']     ?? '');
    $middlename    = trim($_POST['middlename']    ?? '');
    $lastname      = trim($_POST['lastname']      ?? '');
    $extensionname = trim($_POST['extensionname'] ?? '');
    $studentid     = trim($_POST['studentid']     ?? '');
    $email         = trim($_POST['email']         ?? '');
    $password      = (string)($_POST['password']  ?? '');

    $program_id    = (int)($_POST['program_id']   ?? 0);   // FK → sra_programs.program_id
    $major_id_raw  = $_POST['major_id']           ?? '';   // optional
    $yearlevel_raw = trim($_POST['yearlevel']     ?? '');
    $section       = trim($_POST['section']       ?? '');
    $school_year   = trim($_POST['school_year']   ?? '');  // NEW: sa DB na rin

    // flag kung galing Google registration
    $google_mode   = (int)($_POST['google_mode']  ?? 0);

    $yearlevel_int = (int)$yearlevel_raw;

    $hasMajor = ($major_id_raw !== '' && $major_id_raw !== null);
    $major_id = $hasMajor ? (int)$major_id_raw : null;

    $role = 'student';

    // Profile photo from Google (kung meron)
    $googlePending   = $_SESSION['google_pending'] ?? null;
    $profilePhotoUrl = '';
    if ($google_mode === 1 && is_array($googlePending)) {
        $profilePhotoUrl = trim((string)($googlePending['profile_photo'] ?? ''));
    }

    $googleId = null;
if ($google_mode === 1 && is_array($googlePending)) {
    $googleId = trim((string)($googlePending['google_id'] ?? ''));
}
$googleId = ($googleId !== '') ? $googleId : null;

    // Gawing NULL kapag empty
    $profilePhoto = ($profilePhotoUrl !== '') ? $profilePhotoUrl : null;

    // ---------- BASIC VALIDATION ----------

    if (
        $firstname === '' || $lastname === '' || $email === '' || $password === '' ||
        $studentid === '' || $program_id <= 0 || $yearlevel_int <= 0 ||
        $section === '' || $school_year === ''
    ) {
        jexit(false, 'Please complete all required fields.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jexit(false, 'Invalid email address.');
    }

    // CBSUA domain gate
    if (!preg_match('/@cbsua\.edu\.ph$/i', $email)) {
        jexit(false, 'Only @cbsua.edu.ph emails are allowed.');
    }

    // year level sanity (1–4)
    if ($yearlevel_int < 1 || $yearlevel_int > 4) {
        jexit(false, 'Invalid year level.');
    }

    // Optional: simple check sa school_year format (pwede mong i-uncomment)
    /*
    if (!preg_match('/^\d{4}\s*-\s*\d{4}$/', $school_year)) {
        jexit(false, 'Invalid school year format. Use e.g. 2024-2025.');
    }
    */

    // ---------- VALIDATE PROGRAM & MAJOR + GET TEXT LABELS ----------

    // 1) program_id → program_code + program_name
    $stmt = $conn->prepare("
        SELECT program_code, program_name
        FROM sra_programs
        WHERE program_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $program_id);
    $stmt->execute();
    $stmt->bind_result($p_code, $p_name);
    if (!$stmt->fetch()) {
        $stmt->close();
        jexit(false, 'Selected course is not available.');
    }
    $stmt->close();

    // text for old `course` column
    $course_text = trim($p_code . ' – ' . $p_name);

    // 2) major (optional)
    $major_text = '';
    if ($hasMajor && $major_id > 0) {
        $stmt = $conn->prepare("
            SELECT major_name
            FROM sra_majors
            WHERE major_id = ? AND program_id = ? AND status = 'active'
        ");
        $stmt->bind_param('ii', $major_id, $program_id);
        $stmt->execute();
        $stmt->bind_result($m_name);
        if ($stmt->fetch()) {
            $major_text = $m_name;
        } else {
            $stmt->close();
            jexit(false, 'Selected major is not valid for this course.');
        }
        $stmt->close();
    } else {
        // walang major (ok lang kung program na walang major)
        $major_id   = null;
        $major_text = '';
    }

    // ---------- UNIQUENESS CHECKS ----------

    // email
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        jexit(false, 'Email is already registered.');
    }
    $stmt->close();

    // student ID
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id_no = ? LIMIT 1");
    $stmt->bind_param('s', $studentid);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        jexit(false, 'Student ID Number is already registered.');
    }
    $stmt->close();

    // ---------- PREPARE VALUES ----------

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // verification token (for email link)
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24); // +24h

    // ---------- INSERT USER ----------
    //
    // synced sa structure mo:
    //  - gamit ang `profile_photo`
    //  - may `school_year`
    //  - `status` default 'pending'

   // ---------- INSERT USER ----------
//
//  - gamit ang `profile_photo`
//  - may `school_year`
//  - may `google_id` (kung galing Google)
//  - `is_profile_complete` = 1 (kumpleto na profile niya after form)

$sql = "INSERT INTO users
    (email, password_hash, role,
     first_name, middle_name, last_name, ext_name,
     student_id_no, course, major,
     program_id, major_id,
     year_level, section, school_year,
     profile_photo, google_id,
     status, is_profile_complete,
     email_verify_token, email_verify_expires_at,
     created_at, updated_at)
    VALUES
    (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', 1, ?, ?, NOW(), NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    jexit(false, 'Prepare failed: ' . $conn->error);
}

$stmt->bind_param(
    'ssssssssssiiissssss',
    $email,
    $hash,
    $role,
    $firstname,
    $middlename,
    $lastname,
    $extensionname,
    $studentid,
    $course_text,
    $major_text,
    $program_id,
    $major_id,
    $yearlevel_int,
    $section,
    $school_year,
    $profilePhoto,
    $googleId,
    $token,
    $expires
);

$stmt->execute();
$user_id = $stmt->insert_id;
$stmt->close();

    // ---------- CLEAR GOOGLE PENDING (kung meron) ----------
    unset($_SESSION['google_pending']);

    // ---------- SEND VERIFICATION EMAIL ----------

    $verifyUrl = 'https://sra-management.com/verify_email.php?token=' . urlencode($token);

    $emailSent  = false;
    $emailError = '';

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        // ====== SMTP SETTINGS (palitan mo ito sa actual SMTP mo) ======
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'kylaceline.pasamba@cbsua.edu.ph';   // TODO: change
        $mail->Password   = 'qekl rncw kcye mzwz';               // TODO: change (App Password)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        // ============================================================

        $mail->setFrom('kylaceline.pasamba@cbsua.edu.ph', 'SRA Verification');
        $mail->addAddress($email, $firstname . ' ' . $lastname);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your SRA account';
        $mail->Body    = '
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;">
              <p>Hello <strong>' . htmlspecialchars($firstname) . '</strong>,</p>
              <p>Thanks for registering. Please verify your email to activate your account:</p>
              <p><a href="' . $verifyUrl . '" target="_blank"
                    style="display:inline-block;padding:10px 16px;border-radius:6px;
                           background:#1e8fa2;color:#fff;text-decoration:none;">
                    Verify my account
                 </a></p>
              <p>Or open this link: <br><code>' . htmlspecialchars($verifyUrl) . '</code></p>
              <p>This link will expire in 24 hours.</p>
              <hr>
              <p style="color:#888;">SRA – Management and Student Progress Monitoring</p>
            </div>';
        $mail->AltBody = "Hello $firstname,\n\nVerify your account: $verifyUrl\n\nLink expires in 24 hours.";

        $mail->send();
        $emailSent = true;
    } catch (Exception $e) {
        // huwag na nating ibagsak ang registration, pero pwede nating ibalik yung error para ma-debug mo
        $emailError = $e->getMessage();
    }

    if ($emailSent) {
        jexit(true, 'Registration successful! Please check your email to verify and activate your account.');
    } else {
        // Registered na, pero may problema sa email
        jexit(true, 'Registration saved, but verification email could not be sent. Please contact your instructor/administrator.', [
            'email_error' => $emailError  // pwede mong tanggalin sa production
        ]);
    }

} catch (mysqli_sql_exception $e) {
    jexit(false, 'Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    jexit(false, 'Server error: ' . $e->getMessage());
}
