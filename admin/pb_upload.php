<?php
// admin/pb_upload.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
// Avoid leaking HTML notices into JSON
ini_set('display_errors', '0');

function jfail($msg, $code = 400){
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function jok($data = []){
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---- CSRF ---- */
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrf || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  jfail('Invalid CSRF token', 403);
}

/* ---- Inputs ---- */
$story_id = (int)($_GET['story_id'] ?? 0);
if ($story_id <= 0) jfail('Missing story_id');

$file = $_FILES['image'] ?? $_FILES['file'] ?? null; // accept both keys just in case
if (!$file) jfail('No file received');
if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
  jfail('Upload error (code ' . (int)($file['error'] ?? -1) . ')');
}

if ($file['size'] > 4 * 1024 * 1024) jfail('File too large (max 4MB)');

/* ---- Validate mime/type ---- */
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (strpos((string)$mime, 'image/') !== 0) jfail('Only image uploads are allowed');

$ext = match ($mime) {
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  default      => null
};
if (!$ext) jfail('Unsupported image type');

/* ---- Dest paths ---- */
$baseDir  = realpath(__DIR__ . '/..');                   // project root (filesystem)
$relDirFs = '/uploads/stories/' . $story_id;             // filesystem relative to app root
$absDir   = $baseDir . $relDirFs;

if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
  jfail('Cannot create upload directory');
}

$filename = 'img_' . $story_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$absPath  = $absDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $absPath)) {
  jfail('Failed to move uploaded file');
}

/* ---- Build URL that works even if app is in a subfolder ---- */
/*
  SCRIPT_NAME e.g.: /yourapp/admin/pb_upload.php
  appBase becomes  '' (if app at /) or '/yourapp' (if app in subfolder)
*/
$script  = $_SERVER['SCRIPT_NAME'] ?? '';
$appBase = rtrim(dirname(dirname($script)), '/'); // up 2 levels from /admin/file.php
$url     = $appBase . $relDirFs . '/' . $filename;

/* ---- Done ---- */
jok([
  'url'  => $url,
  'name' => (string)($file['name'] ?? $filename),
  'type' => (string)$mime,
  'size' => (int)$file['size'],
]);
