<?php
// verify_email.php
require_once __DIR__ . '/db_connect.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify Email</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php
if ($token === '') {
  echo "<script>Swal.fire({icon:'error',title:'Invalid link',text:'Missing token.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
  exit;
}

$stmt = $conn->prepare("SELECT user_id, status, email_verify_expires_at, email_verified_at
                        FROM users WHERE email_verify_token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
  echo "<script>Swal.fire({icon:'error',title:'Invalid link',text:'Token not found.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
  exit;
}

if (!empty($user['email_verified_at']) || $user['status'] === 'active') {
  echo "<script>Swal.fire({icon:'info',title:'Already verified',text:'You can login now.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
  exit;
}

$now = new DateTimeImmutable('now');
$exp = new DateTimeImmutable($user['email_verify_expires_at'] ?? '2000-01-01 00:00:00');
if ($now > $exp) {
  echo "<script>Swal.fire({icon:'error',title:'Link expired',text:'Please request a new verification email.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
  exit;
}

// Activate
$uid = (int)$user['user_id'];
$upd = $conn->prepare("UPDATE users
  SET status='active', email_verified_at=NOW(), email_verify_token=NULL, email_verify_expires_at=NULL, updated_at=NOW()
  WHERE user_id=? LIMIT 1");
$upd->bind_param('i', $uid);
$ok = $upd->execute();
$upd->close();

if ($ok) {
  echo "<script>Swal.fire({icon:'success',title:'Email verified!',text:'Your account is now active.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
} else {
  echo "<script>Swal.fire({icon:'error',title:'Activation failed',text:'Please contact admin.'})
    .then(()=>{ window.location='login.php#login'; });</script>";
}
?>
</body>
</html>
