<?php
// /logout.php
require_once __DIR__ . '/includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// If this is the POST that actually logs out, destroy session and return JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Clear all session data
  $_SESSION = [];

  // Remove the session cookie
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'] ?? '/',
      $params['domain'] ?? '',
      $params['secure'] ?? false,
      $params['httponly'] ?? true
    );
  }

  // Destroy session
  session_destroy();

  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    // after-logout landing page
    'redirect' => 'index.php'
  ]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Logging out…</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- SweetAlert2 (use the same lib your admin pages use; CDN fallback here) -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Minimal neutral page while waiting for the modal */
    html,body{height:100%}
    body{
      margin:0; display:flex; align-items:center; justify-content:center;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
      background:#f6f8f7; color:#1f3a1f;
    }
    .fallback{
      display:none; max-width:520px; padding:18px 20px; border-radius:12px;
      background:#fff; border:1px solid #e6efe6; box-shadow:0 10px 24px rgba(0,0,0,.08);
    }
    .btn{display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:10px; border:0; cursor:pointer; font-weight:700}
    .btn-acc{background:#ECA305; color:#1b1b1b}
    .btn-ghost{background:#5C8891; color:#fff}
  </style>
</head>
<body>

<div class="fallback" id="fallbackBox" role="dialog" aria-modal="true">
  <h3 style="margin:0 0 8px;">Log out?</h3>
  <p style="margin:0 0 14px;">JavaScript seems disabled. Do you want to end your session?</p>
  <form method="post" style="display:inline">
    <button class="btn btn-acc" type="submit">Yes, log out</button>
  </form>
  <button class="btn btn-ghost" type="button" onclick="history.length>1?history.back():location.href='admin/index.php'">Cancel</button>
</div>

<script>
(function(){
  // If SweetAlert2 isn't available, show basic fallback UI
  if (!window.Swal) {
    document.getElementById('fallbackBox').style.display = 'block';
    return;
  }

  // Show confirm modal immediately
  Swal.fire({
    icon: 'warning',
    title: 'Log out?',
    text: 'You will be returned to the login screen.',
    showCancelButton: true,
    confirmButtonText: 'Yes, log out',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    confirmButtonColor: '#ECA305', // your accent yellow
    cancelButtonColor: '#5C8891'   // theme-ish teal/gray
  }).then(async (res) => {
    if (!res.isConfirmed) {
      // Back to previous page (or dashboard if no history)
      if (history.length > 1) history.back();
      else window.location.href = 'admin/index.php';
      return;
    }

    try{
      const r = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      const data = await r.json().catch(()=>({ok:true, redirect:'index.php'}));

      await Swal.fire({
        icon:'success',
        title:'Signed out',
        text:'You have been logged out successfully.',
        confirmButtonColor:'#ECA305',
        timer: 1200,
        showConfirmButton: false
      });

      window.location.href = (data && data.redirect) ? data.redirect : 'index.php';
    }catch(err){
      Swal.fire({
        icon:'error',
        title:'Logout failed',
        text: String(err || 'Please try again.'),
        confirmButtonColor:'#ECA305'
      }).then(()=>{ window.location.href = 'index.php'; });
    }
  });
})();
</script>
</body>
</html>
