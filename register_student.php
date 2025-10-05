<?php
// register_student.php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

// === PHPMailer ===
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

// CBSUA email-only gate

function jexit($ok, $msg, $extra = []) {
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}

$firstname     = trim($_POST['firstname']     ?? '');
$middlename    = trim($_POST['middlename']    ?? '');
$lastname      = trim($_POST['lastname']      ?? '');
$extensionname = trim($_POST['extensionname'] ?? '');
$studentid     = trim($_POST['studentid']     ?? '');
$email         = trim($_POST['email']         ?? '');
$password      = (string)($_POST['password']  ?? '');
$course        = trim($_POST['course']        ?? '');
$major         = trim($_POST['major']         ?? '');
$yearlevel     = trim($_POST['yearlevel']     ?? '');
$section       = trim($_POST['section']       ?? '');

if ($firstname === '' || $lastname === '' || $email === '' || $password === '' ||
    $studentid === '' || $course === '' || $yearlevel === '' || $section === '') {
  jexit(false, 'Please complete all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  jexit(false, 'Invalid email address.');
}

/* === CBSUA domain gate (case-insensitive) === */
if (!preg_match('/@cbsua\.edu\.ph$/i', $email)) {
  jexit(false, 'Only @cbsua.edu.ph emails are allowed.');
}
// Optional: enforce school email domain
// if (!preg_match('/@cbsua\.edu\.ph$/i', $email)) {
//   jexit(false, 'Please use your @cbsua.edu.ph email.');
// }

// Uniqueness checks
$stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) jexit(false, 'Email is already registered.');
$stmt->close();

$stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id_no = ? LIMIT 1");
$stmt->bind_param('s', $studentid);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) jexit(false, 'Student ID Number is already registered.');
$stmt->close();

$hash           = password_hash($password, PASSWORD_DEFAULT);
$yearlevel_int  = (int)$yearlevel;
$role           = 'student';

// Generate verification token (valid for 24h)
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 60*60*24);

// Insert (status = pending)
$sql = "INSERT INTO users
 (email, password_hash, role, first_name, middle_name, last_name, ext_name,
  student_id_no, course, major, year_level, section,
  status, email_verify_token, email_verify_expires_at, created_at, updated_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, NOW(), NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) jexit(false, 'Prepare failed: '.$conn->error);

$stmt->bind_param(
  'ssssssssssisss',
  $email,
  $hash,
  $role,
  $firstname,
  $middlename,
  $lastname,
  $extensionname,
  $studentid,
  $course,
  $major,
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

// === Send verification email ===
$verifyUrl = 'https://sra-management.com/verify_email.php?token=' . urlencode($token);

$mail = new PHPMailer(true);
try {
  $mail->isSMTP();
  // ====== SMTP SETTINGS (edit to your provider) ======
  // Example for Gmail (requires 2FA + App Password):
  $mail->Host       = 'smtp.gmail.com';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'kylaceline.pasamba@cbsua.edu.ph';   // TODO: change
  $mail->Password   = 'qekl rncw kcye mzwz';       // TODO: change (App Password)
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = 587;

  // If using Hostinger/other SMTP, replace Host/Port/Creds as needed.
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
