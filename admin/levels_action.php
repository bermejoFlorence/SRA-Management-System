<?php
// admin/levels_action.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------------- Helpers ---------------- */
function flash_set($type, $msg){
  $_SESSION['levels_flash'] = ['t'=>$type, 'm'=>$msg];
}
function go($query=''){
  header('Location: levels.php' . ($query ? ('?'.$query) : ''));
  exit;
}
function norm_hex($h){
  $h = trim((string)$h);
  if ($h === '') return '#cccccc';
  if ($h[0] !== '#') $h = '#'.$h;
  if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $h)) return '#cccccc';
  return strtolower($h);
}

$action = $_POST['action'] ?? '';

if ($action === '') { flash_set('error','No action provided.'); go(); }

/* ---------------- Actions ---------------- */
try {

  /* ===== LEVELS ===== */
  if ($action === 'add_level') {
    $name  = trim($_POST['name'] ?? '');
    $code  = trim($_POST['code'] ?? '');
    $hex   = norm_hex($_POST['color_hex'] ?? '#cccccc');
    $order = (int)($_POST['order_rank'] ?? 0);

    if ($name === '' || $code === '') { flash_set('error','Name and code are required.'); go(); }

    $chk = $conn->prepare("SELECT COUNT(*) c FROM sra_levels WHERE LOWER(code)=LOWER(?)");
    if(!$chk){ flash_set('error','DB error: '.$conn->error); go(); }
    $chk->bind_param("s",$code); $chk->execute();
    if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) { flash_set('error','Duplicate level code.'); go(); }

    $st = $conn->prepare("INSERT INTO sra_levels (code,name,color_hex,order_rank,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())");
    if(!$st){ flash_set('error','DB error: '.$conn->error); go(); }
    $st->bind_param("sssi",$code,$name,$hex,$order); $st->execute();

    flash_set('ok','Level added.'); go();
  }
  elseif ($action === 'update_level') {
    $id    = (int)($_POST['level_id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $code  = trim($_POST['code'] ?? '');
    $hex   = norm_hex($_POST['color_hex'] ?? '#cccccc');
    $order = (int)($_POST['order_rank'] ?? 0);

    if ($id<=0 || $name==='' || $code===''){ flash_set('error','Invalid data.'); go(); }

    $chk = $conn->prepare("SELECT COUNT(*) c FROM sra_levels WHERE LOWER(code)=LOWER(?) AND level_id<>?");
    if(!$chk){ flash_set('error','DB error: '.$conn->error); go(); }
    $chk->bind_param("si",$code,$id); $chk->execute();
    if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) { flash_set('error','Duplicate level code.'); go(); }

    $st = $conn->prepare("UPDATE sra_levels SET code=?,name=?,color_hex=?,order_rank=?,updated_at=NOW() WHERE level_id=?");
    if(!$st){ flash_set('error','DB error: '.$conn->error); go(); }
    $st->bind_param("sssii",$code,$name,$hex,$order,$id); $st->execute();

    flash_set('ok','Level updated.'); go();
  }
  elseif ($action === 'delete_level') {
    $id = (int)($_POST['level_id'] ?? 0);
    if ($id <= 0) { flash_set('error','Invalid level.'); go(); }

    $totalRef = 0;
    foreach ([
      "SELECT COUNT(*) c FROM story_sets WHERE level_id=?",
      "SELECT COUNT(*) c FROM level_thresholds WHERE level_id=?",
      "SELECT COUNT(*) c FROM module_unlocks WHERE level_id=?",
      "SELECT COUNT(*) c FROM student_level WHERE level_id=?",
      "SELECT COUNT(*) c FROM assessment_attempts WHERE level_id=?",
      "SELECT COUNT(*) c FROM certificates WHERE level_id=?",
    ] as $sql) {
      if ($st = $conn->prepare($sql)) {
        $st->bind_param("i",$id); $st->execute();
        $totalRef += (int)$st->get_result()->fetch_assoc()['c'];
      }
    }
    if ($totalRef > 0) { flash_set('error','Cannot delete: level is in use.'); go(); }

    $st = $conn->prepare("DELETE FROM sra_levels WHERE level_id=?");
    if(!$st){ flash_set('error','DB error: '.$conn->error); go(); }
    $st->bind_param("i",$id); $st->execute();

    flash_set('ok','Level deleted.'); go();
  }

  /* ===== THRESHOLDS (SLT → PB) ===== */
  elseif ($action === 'add_rule') {
    $minp  = (float)($_POST['min_percent'] ?? 0);
    $maxp  = isset($_POST['max_percent']) && $_POST['max_percent'] !== '' ? (float)$_POST['max_percent'] : 100;
    $lvlid = (int)($_POST['level_id'] ?? 0);
    $gb    = trim($_POST['grade_band'] ?? '');
    $minw  = isset($_POST['min_wpm']) && $_POST['min_wpm'] !== '' ? (int)$_POST['min_wpm'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($lvlid <= 0) { flash_set('error','Choose PB color.'); go('tab=rules'); }
    if ($minp < 0 || $minp > 100 || $maxp < 0 || $maxp > 100 || $minp > $maxp) {
      flash_set('error','Invalid % range. (Min 0–100, Max 0–100, Min ≤ Max)'); go('tab=rules');
    }

    // unique per (level_id, min_percent) kung gusto mo
    $chk = $conn->prepare("SELECT COUNT(*) c FROM level_thresholds WHERE applies_to='SLT' AND level_id=? AND min_percent=?");
    if(!$chk){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
    $chk->bind_param("id",$lvlid,$minp); $chk->execute();
    if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) { flash_set('error','Rule with the same Min % already exists for this color.'); go('tab=rules'); }

    // Encode notes bilang JSON para valid sa CHECK(JSON_VALID(other_rules))
    $other = ($notes === '') ? null : json_encode(['notes'=>$notes], JSON_UNESCAPED_UNICODE);

    if ($minw === null) {
      $sql = "INSERT INTO level_thresholds
              (applies_to, level_id, grade_band, min_percent, max_percent, min_wpm, other_rules, created_at, updated_at)
              VALUES ('SLT', ?, ?, ?, ?, NULL, ?, NOW(), NOW())";
      $st  = $conn->prepare($sql);
      if(!$st){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
      $st->bind_param("isdds", $lvlid, $gb, $minp, $maxp, $other);
    } else {
      $sql = "INSERT INTO level_thresholds
              (applies_to, level_id, grade_band, min_percent, max_percent, min_wpm, other_rules, created_at, updated_at)
              VALUES ('SLT', ?, ?, ?, ?, ?, ?, NOW(), NOW())";
      $st  = $conn->prepare($sql);
      if(!$st){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
      $st->bind_param("isddis", $lvlid, $gb, $minp, $maxp, $minw, $other);
    }

    if (!$st->execute()) { flash_set('error','DB insert failed: '.$st->error); go('tab=rules'); }
    flash_set('ok','Rule added.'); go('tab=rules');
  }
  elseif ($action === 'update_rule') {
    $id    = (int)($_POST['threshold_id'] ?? 0);
    $minp  = (float)($_POST['min_percent'] ?? 0);
    $maxp  = isset($_POST['max_percent']) && $_POST['max_percent'] !== '' ? (float)$_POST['max_percent'] : 100;
    $lvlid = (int)($_POST['level_id'] ?? 0);
    $gb    = trim($_POST['grade_band'] ?? '');
    $minw  = isset($_POST['min_wpm']) && $_POST['min_wpm'] !== '' ? (int)$_POST['min_wpm'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($id<=0 || $lvlid<=0) { flash_set('error','Invalid data.'); go('tab=rules'); }
    if ($minp < 0 || $minp > 100 || $maxp < 0 || $maxp > 100 || $minp > $maxp) {
      flash_set('error','Invalid % range. (Min 0–100, Max 0–100, Min ≤ Max)'); go('tab=rules');
    }

    $chk = $conn->prepare("SELECT COUNT(*) c FROM level_thresholds WHERE applies_to='SLT' AND level_id=? AND min_percent=? AND threshold_id<>?");
    if(!$chk){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
    $chk->bind_param("idi",$lvlid,$minp,$id); $chk->execute();
    if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) { flash_set('error','Another rule with the same Min % already exists for this color.'); go('tab=rules'); }

    $other = ($notes === '') ? null : json_encode(['notes'=>$notes], JSON_UNESCAPED_UNICODE);

    if ($minw === null) {
      $sql = "UPDATE level_thresholds
              SET level_id=?, grade_band=?, min_percent=?, max_percent=?, min_wpm=NULL,
                  other_rules=?, updated_at=NOW()
              WHERE threshold_id=? AND applies_to='SLT'";
      $st  = $conn->prepare($sql);
      if(!$st){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
      $st->bind_param("isddsi", $lvlid, $gb, $minp, $maxp, $other, $id);
    } else {
      $sql = "UPDATE level_thresholds
              SET level_id=?, grade_band=?, min_percent=?, max_percent=?, min_wpm=?,
                  other_rules=?, updated_at=NOW()
              WHERE threshold_id=? AND applies_to='SLT'";
      $st  = $conn->prepare($sql);
      if(!$st){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
      $st->bind_param("isddisi", $lvlid, $gb, $minp, $maxp, $minw, $other, $id);
    }

    if (!$st->execute()) { flash_set('error','DB update failed: '.$st->error); go('tab=rules'); }
    flash_set('ok','Rule updated.'); go('tab=rules');
  }
  elseif ($action === 'delete_rule') {
    $id = (int)($_POST['threshold_id'] ?? 0);
    if ($id <= 0) { flash_set('error','Invalid rule.'); go('tab=rules'); }

    $st = $conn->prepare("DELETE FROM level_thresholds WHERE threshold_id=? AND applies_to='SLT'");
    if(!$st){ flash_set('error','DB error: '.$conn->error); go('tab=rules'); }
    $st->bind_param("i",$id); $st->execute();

    flash_set('ok','Rule deleted.'); go('tab=rules');
  }
  else {
    flash_set('error','Unknown action.'); go();
  }

} catch (Throwable $e) {
  flash_set('error','Unexpected error: '.$e->getMessage());
  go();
}
