
<?php
// login_process.php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db_connect.php';


function jexit($ok, $msg, $extra = []) {
  echo json_encode(array_merge(['success'=>$ok, 'message'=>$msg], $extra));
  exit;
}

$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
  jexit(false, 'Please enter your email and password.');
}

// CBSUA email-only gate
if (!preg_match('/^[a-z0-9._%+\-]+@cbsua\.edu\.ph$/', $email)) {
  jexit(false, 'Only @cbsua.edu.ph emails are allowed.');
}

$stmt = $conn->prepare("SELECT user_id, email, password_hash, role, first_name, last_name, status
                        FROM users WHERE email = ? LIMIT 1");
if (!$stmt) jexit(false, 'DB error: '.$conn->error);
$stmt->bind_param('s', $email);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
  jexit(false, 'Account not found.');
}

if (!password_verify($password, $user['password_hash'])) {
  jexit(false, 'Invalid email or password.');
}

// Require activation
if ($user['status'] !== 'active') {
  if ($user['status'] === 'pending') {
    jexit(false, 'Please verify your email first. Check your inbox for the verification link.');
  }
  jexit(false, 'Your account is not active. Please contact the administrator.');
}

// Success: set session
session_regenerate_id(true);
$_SESSION['user_id']   = (int)$user['user_id'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];
$_SESSION['first_name']= $user['first_name'];
$_SESSION['full_name'] = $user['first_name'].' '.$user['last_name'];

// Update last login
$upd = $conn->prepare("UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE user_id = ? LIMIT 1");
$uid = (int)$user['user_id'];
$upd->bind_param('i', $uid);
$upd->execute();
$upd->close();

// Role-based redirect
$redirect = 'index.php';
if ($user['role'] === 'admin')   $redirect = 'admin/index.php';
if ($user['role'] === 'student') $redirect = 'student/index.php';

jexit(true, 'Login successful.', ['redirect' => $redirect]);
