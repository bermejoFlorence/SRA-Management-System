<?php
// register_student.php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// === PHPMailer ===
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function jexit($ok, $msg, $extra = []) {
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}

// ---------- INPUTS ----------

$firstname     = trim($_POST['firstname']     ?? '');
$middlename    = trim($_POST['middlename']    ?? '');
$lastname      = trim($_POST['lastname']      ?? '');
$extensionname = trim($_POST['extensionname'] ?? '');
$studentid     = trim($_POST['studentid']     ?? '');
$email         = trim($_POST['email']         ?? '');
$password      = (string)($_POST['password']  ?? '');

// bago: galing sa login.php register form
$program_id    = (int)($_POST['program_id']   ?? 0);      // FK → sra_programs.program_id
$major_id_raw  = $_POST['major_id']           ?? '';      // optional
$yearlevel_raw = trim($_POST['yearlevel']     ?? '');
$section       = trim($_POST['section']       ?? '');

$yearlevel_int = (int)$yearlevel_raw;
$major_id      = ($major_id_raw !== '') ? (int)$major_id_raw : 0; // 0 = no major
$role          = 'student';

// ---------- BASIC VALIDATION ----------

if ($firstname === '' || $lastname === '' || $email === '' || $password === '' ||
    $studentid === '' || $program_id <= 0 || $yearlevel_int <= 0 || $section === '') {
  jexit(false, 'Please complete all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  jexit(false, 'Invalid email address.');
}

/* === CBSUA domain gate (case-insensitive) === */
if (!preg_match('/@cbsua\.edu\.ph$/i', $email)) {
  jexit(false, 'Only @cbsua.edu.ph emails are allowed.');
}

// year level sanity (adjust if may 5th year, etc.)
if ($yearlevel_int < 1 || $yearlevel_int > 4) {
  jexit(false, 'Invalid year level.');
}

// ---------- VALIDATE PROGRAM & MAJOR + GET TEXT LABELS ----------

// 1) program_id → kunin program_code + program_name
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

// Course text para sa lumang `course` column (para di mabasag ibang bahagi ng system)
$course_text = trim($p_code . ' – ' . $p_name);

// 2) major_id (optional) → kunin major_name kung meron
$major_text = '';
if ($major_id > 0) {
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
}

// ---------- UNIQUENESS CHECKS ----------

$stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
  $stmt->close();
  jexit(false, 'Email is already registered.');
}
$stmt->close();

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

$hash     = password_hash($password, PASSWORD_DEFAULT);

// Generate verification token (valid for 24h)
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 60*60*24);

// ---------- INSERT USER (WITH PROGRAM/MAJOR) ----------
//
// NOTE: dito ginamit pa rin natin ang `course` at `major` (text) columns
// para hindi mabasag kung may ibang page na umaasa pa sa kanila,
// pero sabay na rin tayong nagse-set ng FK: program_id, major_id.

$sql = "INSERT INTO users
 (email, password_hash, role, first_name, middle_name, last_name, ext_name,
  student_id_no, course, major, program_id, major_id, year_level, section,
  status, email_verify_token, email_verify_expires_at, created_at, updated_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, NOW(), NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  jexit(false, 'Prepare failed: '.$conn->error);
}

$stmt->bind_param(
  'ssssssssssiiisss',
  $email,
  $hash,
  $role,
  $firstname,
  $middlename,
  $lastname,
  $extensionname,
  $studentid,
  $course_text,     // from sra_programs (code – name)
  $major_text,      // from sra_majors (optional)
  $program_id,      // FK
  $major_id,        // 0 or FK
  $yearlevel_int,
  $section,
  $token,
  $expires
);

if (!$stmt->execute()) {
  jexit(false, 'Registration failed. '.$stmt->error);
}
$user_id = $stmt->insert_id;
$stmt->close();

// ---------- SEND VERIFICATION EMAIL (UNCHANGED LOGIC) ----------

$verifyUrl = 'https://sra-management.com/verify_email.php?token=' . urlencode($token);

$mail = new PHPMailer(true);
try {
  $mail->isSMTP();
  // ====== SMTP SETTINGS (edit to your provider) ======
  // Example for Gmail (requires 2FA + App Password):
  $mail->Host       = 'smtp.gmail.com';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'kylaceline.pasamba@cbsua.edu.ph';   // TODO: change
  $mail->Password   = 'qekl rncw kcye mzwz';               // TODO: change (App Password)
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = 587;
  // ================================================

  $mail->setFrom('kylaceline.pasamba@cbsua.edu.ph', 'SRA Verification');
  $mail->addAddress($email, $firstname.' '.$lastname);

  $mail->isHTML(true);
  $mail->Subject = 'Verify your SRA account';
  $mail->Body    = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;">
      <p>Hello <strong>'.htmlspecialchars($firstname).'</strong>,</p>
      <p>Thanks for registering. Please verify your email to activate your account:</p>
      <p><a href="'.$verifyUrl.'" target="_blank"
            style="display:inline-block;padding:10px 16px;border-radius:6px;
                   background:#1e8fa2;color:#fff;text-decoration:none;">
            Verify my account
         </a></p>
      <p>Or open this link: <br><code>'.htmlspecialchars($verifyUrl).'</code></p>
      <p>This link will expire in 24 hours.</p>
      <hr>
      <p style="color:#888;">SRA – Management and Student Progress Monitoring</p>
    </div>';
  $mail->AltBody = "Hello $firstname,\n\nVerify your account: $verifyUrl\n\nLink expires in 24 hours.";

  $mail->send();

  jexit(true, 'Registration successful! Please check your email to verify and activate your account.');
} catch (Exception $e) {
  // (Optional) You can keep the user row as pending; allow "Resend verification" later.
  jexit(false, 'Registered, but failed to send verification email. Please try again or contact admin. Error: '.$mail->ErrorInfo);
}
