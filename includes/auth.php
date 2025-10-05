<?php
// includes/auth.php
// Always include this at the VERY TOP of any protected page, BEFORE output.
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * True if there is a logged-in user.
 */
function is_logged_in(): bool {
  return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Returns current user's role string or '' if none.
 */
function current_role(): string {
  return $_SESSION['role'] ?? '';
}

/**
 * Check if user has one of the allowed roles.
 * $roles can be 'admin' or 'student' or an array ['admin','student']
 */
function has_role(string|array $roles): bool {
  $role = current_role();
  if (is_array($roles)) {
    return in_array($role, $roles, true);
  }
  return $role === $roles;
}

/**
 * For JSON endpoints (AJAX): deny with JSON error and HTTP code.
 */
function deny_json(string $message = 'Not authorized', int $code = 403): void {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => $message]);
  exit;
}

/**
 * For normal pages: show SweetAlert and redirect.
 * $redirect should be relative to the current file (e.g., '../login.php#login').
 */
function deny_page(
  string $title = 'Access Denied',
  string $text  = 'You do not have permission to access this page.',
  string $redirect = 'login.php#login'
): void {
  echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Access</title>
        <meta name='viewport' content='width=device-width, initial-scale=1'>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script></head><body>
        <script>
          Swal.fire({icon:'error', title:".json_encode($title).", text:".json_encode($text).", confirmButtonColor:'#1e8fa2'})
            .then(()=>{ window.location = ".json_encode($redirect)." });
        </script></body></html>";
  exit;
}

/**
 * Ensure user is logged in. If not, deny.
 */
function require_login(string $redirect = 'login.php#login'): void {
  if (!is_logged_in()) {
    deny_page('Please log in', 'You must be logged in to continue.', $redirect);
  }
}

/**
 * Ensure user has one (or more) of the allowed roles.
 */
function require_role(string|array $roles, string $redirect = 'login.php#login'): void {
  require_login($redirect);
  if (!has_role($roles)) {
    deny_page('Access Denied', 'You do not have permission for this area.', $redirect);
  }
}

/**
 * For JSON endpoints that require roles (admin-only APIs, etc.)
 */
function require_json_role(string|array $roles): void {
  if (!is_logged_in()) deny_json('Please log in first.', 401);
  if (!has_role($roles)) deny_json('Not authorized.', 403);
}
