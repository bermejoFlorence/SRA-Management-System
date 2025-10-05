<?php
// student/includes/progress_gates.php
// -------------------------------------------------------
// Usage: require_once __DIR__ . '/progress_gates.php';
// Expects an open $conn (mysqli) and an authenticated user.
// -------------------------------------------------------

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!isset($conn)) {
  // Kung sakaling ma-require ito bago db_connect.php
  require_once __DIR__ . '/../../db_connect.php';
}

// ---- CONFIG / THRESHOLDS ----
if (!defined('PB_PASS_REQUIRED')) define('PB_PASS_REQUIRED', 8);
if (!defined('RB_PASS_REQUIRED')) define('RB_PASS_REQUIRED', 8);

// ---- HELPERS ----
function _one_row(mysqli $c, string $sql, array $params=[], string $types=''){
  $row = null;
  if ($st = $c->prepare($sql)) {
    if ($params) $st->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    if ($st->execute()) {
      $res = $st->get_result();
      if ($res) { $row = $res->fetch_assoc(); $res->free(); }
    }
    $st->close();
  }
  return $row ?: [];
}

function gates_redirect(string $to, string $flash=''){
  if ($flash) $_SESSION['flash_notice'] = $flash;
  header("Location: $to");
  exit;
}

// ---- CORE: compute aggregate progress for current student ----
$GATE_student_id = (int)($_SESSION['user_id'] ?? 0);

// Subukan muna sa table na "attempts"
// (Kung ibang table ang gamit mo, palitan mo lang ang query sa ibaba.)
$agg = _one_row(
  $conn,
  "SELECT
      SUM(set_type='SLT' AND status='passed') AS slt_done,
      SUM(set_type='PB'  AND status='passed') AS pb_passed,
      SUM(set_type='RB'  AND status='passed') AS rb_passed
   FROM attempts
   WHERE student_id=?",
  [$GATE_student_id], 'i'
);

// OPTIONAL: fallback kapag walang 'attempts' table; gumamit ng 'assessment_attempts'.
// I-comment out kung hindi kailangan.
/*
if (!$agg) {
  $agg = _one_row(
    $conn,
    \"SELECT
        SUM(set_type='SLT' AND status IN ('submitted','scored')) AS slt_done,
        SUM(set_type='PB'  AND status='scored')                 AS pb_passed,
        SUM(set_type='RB'  AND status='scored')                 AS rb_passed
     FROM assessment_attempts
     WHERE student_id=?\",
    [$GATE_student_id], 'i'
  );
}
*/

$GATE_slt_done = (int)($agg['slt_done']  ?? 0) > 0;
$GATE_pb_pass  = (int)($agg['pb_passed'] ?? 0);
$GATE_rb_pass  = (int)($agg['rb_passed'] ?? 0);

// Final booleans you can use anywhere:
$GATE_CAN_PB   = $GATE_slt_done;                        // unlock PB after SLT
$GATE_CAN_RB   = ($GATE_pb_pass >= PB_PASS_REQUIRED);   // unlock RB after PB threshold
$GATE_CAN_CERT = ($GATE_rb_pass >= RB_PASS_REQUIRED);   // (optional) certificate

// ---- UI helpers (optional) ----
function gate_link_attrs(bool $unlocked, string $lockTitle=''){
  if ($unlocked) return 'aria-disabled="false"';
  $t = htmlspecialchars($lockTitle ?: 'Locked');
  return 'class="disabled" aria-disabled="true" tabindex="-1" title="'.$t.'"';
}
