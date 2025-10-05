<?php
// student/certificates.php — printable landscape certificate (Letter)
// Shows the latest certificate for the student's *current level* if they qualify.

require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$studentId   = (int)($_SESSION['user_id'] ?? 0);
$studentName = trim($_SESSION['full_name'] ?? 'Student');

/* ---------------- CONFIG ---------------- */
$schoolName = 'CENTRAL BICOL STATE UNIVERSITY OF AGRICULTURE';
$campuses   = ['Calabanga', 'Pasacao', 'Pili', 'Sipocot'];

// campus for body text (fallback to Sipocot if not in session)
$campusName = $_SESSION['campus_name'] ?? 'Sipocot';

/* Logos: place the actual files in the same folder as this PHP file.
   Example files:
   student/1.webp  (CBSUA seal, left)
   student/2.png   (SRA logo, right)
*/
$leftLogoRel  = '1.png';
$rightLogoRel = '2.png';

// Filesystem paths for existence check
$leftLogoFs   = __DIR__ . '/' . $leftLogoRel;
$rightLogoFs  = __DIR__ . '/' . $rightLogoRel;

// URL src (relative to this page)
$leftLogoUrl  = $leftLogoRel;
$rightLogoUrl = $rightLogoRel;

// Signatories
$sign1Name = 'MERCY M. ALMONTE';
$sign1Role = 'Coordinator, Reading Center';
$sign2Name = 'ROWEL M. CASTUERA';
$sign2Role = 'Campus Administrator';

/* ---------------- Helpers ---------------- */
function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
  $val = null;
  if (!$stmt = $c->prepare($sql)) return $val;
  if ($params) $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
  if ($stmt->execute()){
    $res = $stmt->get_result();
    if ($res){ $row = $res->fetch_row(); $val = $row ? $row[0] : null; $res->free(); }
  }
  $stmt->close();
  return $val;
}
function day_ordinal(int $d){
  if ($d%100>=11 && $d%100<=13) return $d.'th';
  $suffix = ['th','st','nd','rd','th','th','th','th','th','th'][$d%10] ?? 'th';
  return $d.$suffix;
}

/* ---------------- Data: current level ---------------- */
$level = null;
if ($studentId){
  $st = $conn->prepare("
    SELECT L.level_id, L.name
      FROM student_level SL
      JOIN sra_levels L ON L.level_id = SL.level_id
     WHERE SL.student_id = ? AND SL.is_current = 1
     LIMIT 1
  ");
  $st->bind_param('i', $studentId);
  $st->execute();
  $level = $st->get_result()->fetch_assoc();
  $st->close();
}
$levelId   = (int)($level['level_id'] ?? 0);
$levelName = $level['name'] ?? null;

/* ---------------- Eligibility: RB requirement ---------------- */
$rbPublishedTotal = (int)(scalar($conn, "
  SELECT COUNT(*)
    FROM stories s
    JOIN story_sets ss ON ss.set_id = s.set_id
   WHERE ss.set_type='RB' AND ss.level_id=? AND s.status='published'
     AND (ss.status IS NULL OR ss.status IN ('published','draft'))
", [$levelId], 'i') ?? 0);

$requiredRBPass = ($rbPublishedTotal > 0) ? max(1, (int)ceil($rbPublishedTotal * (8/15))) : 8;

$rbPassThreshold = (float)(scalar($conn, "
  SELECT COALESCE(min_percent,75)
    FROM level_thresholds
   WHERE applies_to='RB' AND level_id=? LIMIT 1
", [$levelId], 'i') ?? 75.0);

$rbPassed = (int)(scalar($conn, "
  SELECT COUNT(DISTINCT s.story_id)
    FROM attempt_stories s
    JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
   WHERE a.student_id=? AND a.set_type='RB' AND a.status='submitted'
     AND a.level_id=? AND s.percent >= ?
", [$studentId, $levelId, $rbPassThreshold], 'iid') ?? 0);

$eligible = ($rbPassed >= $requiredRBPass);

/* ---------------- Award date ---------------- */
$awardDateStr = scalar($conn, "
  SELECT DATE(a.submitted_at)
    FROM assessment_attempts a
   WHERE a.student_id=? AND a.set_type='RB' AND a.status='submitted' AND a.level_id=?
   ORDER BY a.submitted_at DESC, a.attempt_id DESC
   LIMIT 1
", [$studentId, $levelId], 'ii');

$awardDate = $awardDateStr ? new DateTime($awardDateStr) : new DateTime('now');
$day  = day_ordinal((int)$awardDate->format('j'));
$mon  = $awardDate->format('F');
$year = $awardDate->format('Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --g:#0a3a1a; --g2:#0f5f2a;
  --gold:#d0a32b; --gold2:#f5d46b;
  --ink:#111; --muted:#666;
}

/* ===== PRINT SETTINGS ===== */
@page{ size: Letter landscape; margin: 0.5in; }
@media print{
  body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .toolbar{ display:none !important; }
}

/* ===== LAYOUT ===== */
html,body{ background:#e9eceb; margin:0; }

.toolbar{
  display:flex; justify-content:center; gap:10px; padding:10px;
  background:#fff; border-bottom:1px solid #e4e7e6;
  position:sticky; top:0; z-index:10;
}
.toolbar button{
  padding:10px 14px; border-radius:10px; border:1px solid #dfe3e2;
  background:#fff; font-weight:700; cursor:pointer;
}
.toolbar .primary{ background:linear-gradient(90deg,#1c612e,#29964a); color:#fff; border-color:#1c612e; }

/* Canvas that fits exactly inside @page margins */
.sheet{
  position:relative;
  width:10.5in;                /* 11 - (0.5*2) */
  height:7.5in;                /* 8.5 - (0.5*2) */
  margin:0 auto;
  background:#fff;
  box-shadow:0 12px 36px rgba(0,0,0,.18);
  border:1px solid #e7ecea;
}
@media print{ .sheet{ box-shadow:none; border:0; } }

/* Background side panels */
.side{
  position:absolute; top:0; bottom:0; width:180px; z-index:1;
  background: linear-gradient(180deg, #06270f, #174d2b 60%, #0e2218);
  overflow:hidden;              /* important: clip rotated gold bars */
}
.side.left  { left:0; }
.side.right { right:0; }

/* gold slanted ribbons (clipped by .side overflow) */
.side:before, .side:after{
  content:""; position:absolute; width:6px; top:0; bottom:0; z-index:1;
  background: linear-gradient(180deg, var(--gold2), var(--gold));
  transform: rotate(10deg);
  box-shadow: 0 0 0 1px rgba(0,0,0,.06);
}
.side.left:before  { left:140px; } .side.left:after  { left:160px; }
.side.right:before { right:140px; } .side.right:after { right:160px; }

/* Inner white panel */
.inner{
  position:absolute; top:0; bottom:0; left:180px; right:180px;
  background:#fff; z-index:2;
}

/* Header with logos */
.header{
  position:absolute; left:180px; right:180px; top:0.45in; z-index:3;
  display:flex; align-items:center; justify-content:space-between; gap:12px;
}
.hdr-side{ width:1.7in; display:flex; justify-content:center; align-items:center; }
.hdr-center{ flex:1; text-align:center; }
.hdr-logo{
  width:1.5in; height:1.5in; object-fit:contain; display:block;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.school{
  display:inline-block; background:#eee; padding:6px 16px; border-radius:6px;
  font-weight:800; letter-spacing:.5px; color:#333;
}
.campuses{ margin-top:6px; color:#b00; font-weight:600; letter-spacing:.3px; }

/* Foreground content (above backgrounds) */
.title, .ribbon, .name, .body, .signs, .note { position:absolute; z-index:3; }

/* Main title */
.title{
  left:0; right:0; top:1.6in; text-align:center;
  font-weight:900; letter-spacing:3px; color:#161616; font-size:48px;
}

/* Ribbon */
.ribbon{
  left:3.2in; right:3.2in; top:2.35in; text-align:center;
  background: linear-gradient(90deg, #1a5b2f, #2c8b4a);
  color:#fff; font-weight:800; text-transform:lowercase; letter-spacing:.5px;
  padding:10px 14px; border-radius:999px; box-shadow: 0 4px 10px rgba(0,0,0,.12);
}

/* Name */
.name{
  left:0; right:0; top:2.95in; text-align:center;
  font-size:34px; font-weight:900; color:#111; letter-spacing:1px;
}

/* Body paragraph */
.body{
  left:2.2in; right:2.2in; top:3.55in; text-align:center;
  font-size:16px; color:#222; line-height:1.6;
}
.small{ font-size:13px; } .muted{ color:#666; }

/* Signatures – moved up to reduce gap */
.signs{
  left:2.2in; right:2.2in; bottom:1.15in; display:flex; gap:48px;
}
.sig{ flex:1; text-align:center; }
.sig .line{ margin:0 auto 6px; height:2px; width:80%; background:#333; }
.sig .n{ font-weight:800; } .sig .r{ color:#444; font-size:14px; }

/* Footnote */
.note{
  left:0; right:0; bottom:0.35in; text-align:center; color:#7a7a7a; font-size:12px;
}

/* Optional: slightly narrower side panels in print for extra breathing room */
@media print{
  .side{ width:170px; }
  .inner{ left:170px; right:170px; }
  .header{ left:170px; right:170px; }
}
</style>

</head>
<body>

<div class="toolbar">
  <button class="primary" onclick="window.print()">Print</button>
  <button onclick="location.href='index.php'">Back to Dashboard</button>
</div>

<div class="wrap">
  <div class="sheet">

    <!-- background -->
    <div class="side left"></div>
    <div class="side right"></div>
    <div class="inner"></div>

    <!-- header (logos + school name) -->
    <div class="header">
      <div class="hdr-side">
        <?php if (is_file($leftLogoFs)): ?>
          <img class="hdr-logo"
               src="<?= htmlspecialchars($leftLogoUrl) ?>?v=<?= @filemtime($leftLogoFs) ?>"
               alt="CBSUA Seal">
        <?php endif; ?>
      </div>

      <div class="hdr-center">
        <div class="school"><?= htmlspecialchars($schoolName) ?></div>
        <div class="campuses"><?= htmlspecialchars(implode(' | ', $campuses)) ?></div>
      </div>

      <div class="hdr-side">
        <?php if (is_file($rightLogoFs)): ?>
          <img class="hdr-logo"
               src="<?= htmlspecialchars($rightLogoUrl) ?>?v=<?= @filemtime($rightLogoFs) ?>"
               alt="SRA Logo">
        <?php endif; ?>
      </div>
    </div>

    <!-- content -->
    <div class="title">CERTIFICATE</div>

    <div class="ribbon">is hereby given to</div>

    <div class="name"><?= htmlspecialchars($studentName) ?></div>

    <div class="body">
      has successfully achieved the target for the
      <b>SRA Level <?= htmlspecialchars($levelName ?: '—') ?></b> at the
      <?= htmlspecialchars($schoolName) ?> – <b><?= htmlspecialchars($campusName) ?></b> Reading
      Center. Given this <b><?= htmlspecialchars($day) ?></b> day of
      <b><?= htmlspecialchars($mon . ' ' . $year) ?></b>.
      <div class="small muted" style="margin-top:8px;">
        (Requirement: passed at least <?= (int)$requiredRBPass ?> Rate Builder stor<?= $requiredRBPass>1?'ies':'y' ?> •
        Your passed stories: <?= (int)$rbPassed ?>)
      </div>
    </div>

    <div class="signs">
      <div class="sig">
        <div class="line"></div>
        <div class="n"><?= htmlspecialchars($sign1Name) ?></div>
        <div class="r"><?= htmlspecialchars($sign1Role) ?></div>
      </div>
      <div class="sig">
        <div class="line"></div>
        <div class="n"><?= htmlspecialchars($sign2Name) ?></div>
        <div class="r"><?= htmlspecialchars($sign2Role) ?></div>
      </div>
    </div>

    <div class="note">
      <?= $eligible ? 'This certificate is valid for the current school year.' : 'Not yet eligible — complete the Rate Builder requirement.' ?>
    </div>

  </div>
</div>

<?php if (!$eligible): ?>
<script>
  alert('You have not unlocked the certificate yet.\n\nRequirement: pass at least <?= (int)$requiredRBPass ?> RB stories.\nYour passed stories: <?= (int)$rbPassed ?>.');
</script>
<?php endif; ?>

</body>
</html>
