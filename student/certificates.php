<?php
// student/certificates.php — printable landscape certificate (Short/Letter)
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
   student/1.png  (CBSUA seal / main logo for left strip)
   student/2.png  (secondary logo, optional, also on left strip)
*/
$leftLogoRel   = '1.png';
$secondLogoRel = '2.png';

$leftLogoFs    = __DIR__ . '/' . $leftLogoRel;
$secondLogoFs  = __DIR__ . '/' . $secondLogoRel;

$leftLogoUrl   = $leftLogoRel;
$secondLogoUrl = $secondLogoRel;

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
  --green-main:#0b5e2b;
  --green-dark:#06451f;
  --green-light:#9dcaa5;
  --border-soft:#c7dccc;
  --ink:#111;
  --muted:#555;
  --bg:#f5f6f4;
}

/* ===== PRINT SETTINGS (Short/Letter, landscape) ===== */
@page{
  size: 8.5in 11in landscape;
  margin: 0.5in;
}
@media print{
  body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .toolbar{ display:none !important; }
}

/* ===== LAYOUT ===== */
html,body{
  margin:0;
  padding:0;
  background:#d9ded8;
  font-family: "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
}

.toolbar{
  display:flex;
  justify-content:center;
  gap:10px;
  padding:10px;
  background:#fff;
  border-bottom:1px solid #e3e7e1;
  position:sticky;
  top:0;
  z-index:20;
}
.toolbar button{
  padding:9px 16px;
  border-radius:999px;
  border:1px solid #cfd7cf;
  background:#fff;
  font-weight:600;
  cursor:pointer;
}
.toolbar .primary{
  background:linear-gradient(90deg,#0d6b2f,#158a3b);
  color:#fff;
  border-color:#0d6b2f;
}

.sheet{
  position:relative;
  width:10in;           /* 11 - 0.5*2 */
  height:7.5in;         /* 8.5 - 0.5*2 */
  margin:10px auto 20px;
  background:var(--bg);
  border:4px solid var(--green-main);    /* outer green frame */
  box-shadow:0 14px 40px rgba(0,0,0,.18);
}

/* inner soft border */
.sheet::before{
  content:"";
  position:absolute;
  inset:12px;
  border:1px solid var(--border-soft);
  pointer-events:none;
}

/* LEFT GREEN PANEL */
.left-panel{
  position:absolute;
  top:0;
  bottom:0;
  left:0;
  width:1.9in;
  background:linear-gradient(180deg,#0d6b2f,#0e7b34 55%,#0a481e);
  box-shadow:inset -4px 0 8px rgba(0,0,0,.35);
  z-index:2;
  color:#fff;
}
.left-panel-inner{
  position:absolute;
  inset:0.7in 0.25in 0.6in;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:flex-start;
  gap:0.35in;
}
.left-logo-main{
  width:1.3in;
  height:1.3in;
  object-fit:contain;
}
.left-logo-small{
  width:1.15in;
  height:0.8in;
  object-fit:contain;
}

/* MAIN CONTENT AREA */
.main{
  position:absolute;
  top:0;
  bottom:0;
  left:1.9in;
  right:0;
  padding:0.65in 0.9in 0.6in;
  z-index:3;
}

/* subtle building watermark (optional bg image) */
.watermark{
  position:absolute;
  inset:1.5in 0.9in 1.5in 2.2in;
  opacity:.07;
  background-position:center;
  background-size:cover;
  background-repeat:no-repeat;
  pointer-events:none;
}
/* Lagyan mo ng actual image file kung meron ka:
   .watermark{ background-image:url('building.png'); }
*/

/* HEADER TEXT */
.header-intro{
  text-align:center;
  position:relative;
  z-index:4;
}
.header-intro .republic{
  font-size:11px;
  color:var(--muted);
  letter-spacing:.8px;
}
.header-intro .school{
  margin-top:2px;
  font-size:14px;
  font-weight:700;
  letter-spacing:1.2px;
  text-transform:uppercase;
  color:#1b361d;
}
.header-intro .campuses{
  margin-top:3px;
  font-size:11px;
  letter-spacing:.8px;
  color:#a02121;
}

/* TITLE BLOCK */
.title-block{
  text-align:center;
  margin-top:0.45in;
  position:relative;
  z-index:4;
}
.title-block .cert{
  font-size:40px;
  line-height:1;
  letter-spacing:6px;
  font-weight:800;
  color:var(--green-main);
}
.title-block .of{
  margin-top:6px;
  font-size:22px;
  letter-spacing:4px;
  font-weight:600;
  color:var(--green-main);
}

/* GIVEN TEXT + NAME */
.given{
  margin-top:0.35in;
  text-align:center;
  font-size:13px;
  color:var(--muted);
}
.student-name{
  margin-top:0.20in;
  text-align:center;
  font-size:32px;
  letter-spacing:3px;
  font-weight:800;
  color:#333;
}
.name-line{
  width:4.8in;
  height:1px;
  background:#555;
  margin:6px auto 0;
}

/* BODY PARAGRAPH */
.body-text{
  position:relative;
  margin:0.45in auto 0;
  max-width:6.7in;
  text-align:center;
  font-size:14px;
  color:#222;
  font-style:italic;
  line-height:1.6;
}
.body-text b{
  font-style:normal;
}

/* SIGNATORIES */
.signatures{
  position:absolute;
  left:1.9in;
  right:1.0in;
  bottom:0.85in;
  display:flex;
  justify-content:space-between;
  gap:1.6in;
  z-index:4;
}
.sig{
  flex:1;
  text-align:center;
  font-size:12px;
}
.sig .sig-line{
  width:85%;
  height:1px;
  background:#444;
  margin:0 auto 8px;
}
.sig .name{
  font-weight:700;
  letter-spacing:.7px;
}
.sig .role{
  color:var(--muted);
}

/* FOOTNOTE/N0TE (optional) */
.note{
  position:absolute;
  left:1.9in;
  right:1.0in;
  bottom:0.45in;
  text-align:center;
  font-size:11px;
  color:#777;
}

/* Print tweaks: hide drop shadow outline differences */
@media print{
  .sheet{ box-shadow:none; }
}
</style>
</head>
<body>

<div class="toolbar">
  <button class="primary" onclick="window.print()">Print</button>
  <button onclick="location.href='index.php'">Back to Dashboard</button>
</div>

<div class="sheet">
  <!-- Left green strip with logos -->
  <div class="left-panel">
    <div class="left-panel-inner">
      <?php if (is_file($leftLogoFs)): ?>
        <img class="left-logo-main"
             src="<?= htmlspecialchars($leftLogoUrl) ?>?v=<?= @filemtime($leftLogoFs) ?>"
             alt="CBSUA Logo">
      <?php endif; ?>

      <?php if (is_file($secondLogoFs)): ?>
        <img class="left-logo-small"
             src="<?= htmlspecialchars($secondLogoUrl) ?>?v=<?= @filemtime($secondLogoFs) ?>"
             alt="Secondary Logo">
      <?php endif; ?>
    </div>
  </div>

  <!-- Main content -->
  <div class="main">
    <!-- watermark (optional image, see CSS comment) -->
    <div class="watermark"></div>

    <div class="header-intro">
      <div class="republic">Republic of the Philippines</div>
      <div class="school">
        <?= htmlspecialchars($schoolName) ?>
      </div>
      <div class="campuses">
        <?= htmlspecialchars(implode(' | ', $campuses)) ?>
      </div>
    </div>

    <div class="title-block">
      <div class="cert">CERTIFICATE</div>
      <div class="of">OF APPRECIATION</div>
    </div>

    <div class="given">is hereby given to</div>

    <div class="student-name">
      <?= htmlspecialchars($studentName) ?>
    </div>
    <div class="name-line"></div>

    <p class="body-text">
      has successfully achieved the target for the
      <b>SRA Level <?= htmlspecialchars($levelName ?: '—') ?></b> at the
      <?= htmlspecialchars($schoolName) ?> – <b><?= htmlspecialchars($campusName) ?></b> Reading Center.
      Given this <b><?= htmlspecialchars($day) ?></b> day of
      <b><?= htmlspecialchars($mon . ' ' . $year) ?></b>.
      <br><br>
      <span style="font-size:12px; font-style:normal; color:#444;">
        (Requirement: passed at least <?= (int)$requiredRBPass ?> Rate Builder stor<?= $requiredRBPass>1?'ies':'y' ?> •
        Your passed stories: <?= (int)$rbPassed ?>)
      </span>
    </p>

    <div class="signatures">
      <div class="sig">
        <div class="sig-line"></div>
        <div class="name"><?= htmlspecialchars($sign1Name) ?></div>
        <div class="role"><?= htmlspecialchars($sign1Role) ?></div>
      </div>
      <div class="sig">
        <div class="sig-line"></div>
        <div class="name"><?= htmlspecialchars($sign2Name) ?></div>
        <div class="role"><?= htmlspecialchars($sign2Role) ?></div>
      </div>
    </div>

    <div class="note">
      <?= $eligible
        ? 'This certificate is valid for the current school year.'
        : 'Not yet eligible — complete the Rate Builder requirement.' ?>
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
