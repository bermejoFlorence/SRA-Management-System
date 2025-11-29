<?php
// student/stories_pb.php — Power Builder intro with SLT gating
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';
// ... existing code above ...
$student_id = (int)($_SESSION['user_id'] ?? 0);


if ($pbAidInProgress > 0) {
  // total stories queued for THIS attempt
  $pbProgressTotal = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=?",
    [$pbAidInProgress], 'i'
  ) ?? 0);

  // how many in THIS attempt are already finished (score not null)
  $pbProgressDone = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories
      WHERE attempt_id=? AND score IS NOT NULL",
    [$pbAidInProgress], 'i'
  ) ?? 0);
} else {
  // no in-progress attempt → show 0 / total available
  $pbProgressDone  = 0;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$student_id = (int)($_SESSION['user_id'] ?? 0);

/* ---------- Helpers ---------- */
function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
  $val = null;
  if (!$stmt = $c->prepare($sql)) return $val;
  if ($params) $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
  if ($stmt->execute()){
    $res = $stmt->get_result();
    if ($res) { $row = $res->fetch_row(); $val = $row ? $row[0] : null; $res->free(); }
  }
  $stmt->close();
  return $val;
}
function human_duration($secs){
  $secs = (int)$secs;
  if ($secs <= 0) return 'No time limit';
  $m = intdiv($secs, 60);
  $s = $secs % 60;
  if ($m > 0 && $s === 0) return $m . ' minute' . ($m===1 ? '' : 's');
  if ($m > 0) return $m . 'm ' . $s . 's';
  return $s . 's';
}

function hex_to_rgb(string $hex): array {
  $h = ltrim($hex, '#');
  if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  $int = hexdec($h);
  return [($int >> 16) & 255, ($int >> 8) & 255, $int & 255];
}
function level_color_hex(?string $name, ?string $hexFromDb): ?string {
  if ($hexFromDb) return $hexFromDb;
  if (!$name) return null;
  $map = ['red'=>'#D32F2F','orange'=>'#EF6C00','yellow'=>'#F9A825','blue'=>'#1565C0','green'=>'#2E7D32'];
  return $map[strtolower(trim($name))] ?? null;
}

/* ---------- Check SLT completion & current level ---------- */
$hasSubmittedSLT = (int)(scalar(
  $conn,
  "SELECT COUNT(*) FROM assessment_attempts
    WHERE student_id=? AND set_type='SLT' AND status='submitted' LIMIT 1",
  [$student_id], 'i'
) ?? 0) > 0;

$level = null;
if ($student_id > 0) {
  $stmt = $conn->prepare("
    SELECT L.level_id, L.name, L.color_hex
      FROM student_level SL
      JOIN sra_levels L ON L.level_id = SL.level_id
     WHERE SL.student_id = ? AND SL.is_current = 1
     LIMIT 1
  ");
  $stmt->bind_param('i', $student_id);
  $stmt->execute();
  $level = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}
$levelName = $level['name'] ?? null;
$lvHex     = level_color_hex($levelName ?? null, $level['color_hex'] ?? null);
$levelPillStyle = '';
if ($lvHex) {
  [$r,$g,$b] = hex_to_rgb($lvHex);
  $levelPillStyle = "background: rgba($r,$g,$b,.12); border:1px solid $lvHex; color:#1b3a1b;";
}
$levelId = isset($level['level_id']) ? (int)$level['level_id'] : 0;
/* ---- PB: find in-progress attempt (resume if exists) ---- */
$pbAidInProgress = 0;
$st = $conn->prepare("
  SELECT attempt_id
  FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status='in_progress'
  ORDER BY started_at DESC, attempt_id DESC
  LIMIT 1
");
$st->bind_param('i', $student_id);
$st->execute();
$pbAidInProgress = (int)($st->get_result()->fetch_assoc()['attempt_id'] ?? 0);
$st->close();

/* ---- PB: pass threshold (default 75 or per level_thresholds) ---- */
$pbPassThreshold = 75;
if ($levelId > 0) {
  $th = $conn->prepare("
    SELECT min_percent FROM level_thresholds
    WHERE applies_to='PB' AND level_id=? LIMIT 1
  ");
  $th->bind_param('i', $levelId);
  $th->execute();
  $row = $th->get_result()->fetch_assoc();
  $th->close();
  if ($row && $row['min_percent'] !== null) {
    $pbPassThreshold = (int)round((float)$row['min_percent']);
  }
}

/* ---- PB: if there is a passing PB attempt and NO in-progress -> go to summary ---- */
if ($pbAidInProgress === 0) {
  $lp = $conn->prepare("
    SELECT attempt_id
    FROM assessment_attempts
    WHERE student_id=? AND set_type='PB' AND status IN ('submitted','scored')
      AND percent >= ?
    ORDER BY submitted_at DESC, attempt_id DESC
    LIMIT 1
  ");
  $lp->bind_param('ii', $student_id, $pbPassThreshold);
  $lp->execute();
  $pass = $lp->get_result()->fetch_assoc();
  $lp->close();

  if ($pass) {
    header('Location: stories_pb_done.php?attempt_id='.(int)$pass['attempt_id']);
    exit;
  }
}

/* ----- PB: published stories for this level (dynamic denominator) ----- */
$pbPublishedTotal = 0;
if ($levelId) {
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='PB'
        AND ss.level_id = ?
        AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $pbPublishedTotal = (int)($res->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }
}
/* ----- Intro progress numbers (attempt-based) ----- */
$pbProgressDone  = 0;
$pbProgressTotal = (int)$pbPublishedTotal; // fallback to total available for level

if ($pbAidInProgress > 0) {
  // total stories queued for THIS attempt
  $pbProgressTotal = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=?",
    [$pbAidInProgress], 'i'
  ) ?? 0);

  // finished in THIS attempt (score not null)
  $pbProgressDone = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories
      WHERE attempt_id=? AND score IS NOT NULL",
    [$pbAidInProgress], 'i'
  ) ?? 0);
}


/* ----- PB: time limit summary across published stories of this level ----- */
$timeLimitLabel = 'No time limit';          // per story
$timeTotalLabel = 'No overall time limit';  // estimated total (all stories)

if ($levelId) {
  // Use MIN/MAX of nonzero values; if same -> fixed; if different -> range; if none -> no limit.
  $minTL = (int)(scalar($conn, "
              SELECT COALESCE(MIN(NULLIF(s.time_limit_seconds,0)), 0)
              FROM stories s
              JOIN story_sets ss ON ss.set_id = s.set_id
             WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
               AND (ss.status IS NULL OR ss.status IN ('published','draft'))",
             [$levelId], 'i') ?? 0);

  $maxTL = (int)(scalar($conn, "
              SELECT COALESCE(MAX(NULLIF(s.time_limit_seconds,0)), 0)
              FROM stories s
              JOIN story_sets ss ON ss.set_id = s.set_id
             WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
               AND (ss.status IS NULL OR ss.status IN ('published','draft'))",
             [$levelId], 'i') ?? 0);

  // Kung may kahit isang story na may time limit
  if ($maxTL > 0) {
    // Per-story label (same logic as before)
    if ($minTL === 0) $minTL = $maxTL; // iwas "0 – 10m"
    $timeLimitLabel = ($minTL === $maxTL)
      ? human_duration($minTL)
      : human_duration($minTL) . ' – ' . human_duration($maxTL);

    // Estimated total for 15 stories (or target)
    $storiesTarget = 15; // PB target stories

    $minTotal = $minTL * $storiesTarget;
    $maxTotal = $maxTL * $storiesTarget;

    if ($minTotal === $maxTotal) {
      $timeTotalLabel = human_duration($minTotal);
    } else {
      $timeTotalLabel = human_duration($minTotal) . ' – ' . human_duration($maxTotal);
    }
  }
}


/* ---------- PB progress (completed stories of 15) ---------- */
define('TOTAL_STORIES_PER_SET', 15);
$pbCompleted = (int)(scalar(
  $conn,
  "SELECT COUNT(DISTINCT s.story_id)
     FROM attempt_stories s
     JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
    WHERE a.student_id = ? AND a.set_type='PB' AND a.status='submitted'",
  [$student_id],'i'
) ?? 0);

/* ---------- UI Chrome ---------- */
$PAGE_TITLE  = 'Power Builder Assessment';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'pb';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
:root{
  --g:#003300; --acc:#ECA305; --acc-soft:rgba(236,163,5,.14);
  --bg:#f5f7f6; --card:#fff; --ink:#213421; --muted:#6b7c6b; --line:#e6efe6;
  --shadow:0 10px 28px rgba(0,0,0,.08);
}
.main-content{ width:calc(100% - 220px); margin-left:220px; background:var(--bg); }
@media (max-width:992px){ .main-content{ width:100%; margin-left:0; } }
.pb-wrap{ max-width:1320px; margin:0 auto; padding:16px 24px; padding-top:0; }

.pb-hero{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:12px 0 16px; padding:24px 28px;
  background: radial-gradient(1100px 180px at 18% -20%, var(--acc-soft), transparent 60%),
              linear-gradient(180deg,#fff,#fefefe);
  border:1px solid #eef2ee; border-radius:16px; box-shadow:var(--shadow);
}
.pb-hero h1{ margin:0 0 4px; color:var(--g); font-weight:900; letter-spacing:.2px;
  font-size:clamp(1.4rem,1.1rem + 1.2vw,2rem); }
.pb-hero p{ margin:0; color:var(--ink); opacity:.85; font-size:clamp(.95rem,.9rem + .2vw,1rem); }

.card{ background:var(--card); border:1px solid #eef2ee; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.06);
  padding:clamp(16px,2.4vw,24px); margin:0 0 16px; }
.callout{ padding:0; overflow:hidden; }
.callout-head{ display:flex; align-items:center; gap:12px; padding:18px 20px;
  background: radial-gradient(900px 140px at 0% -25%, var(--acc-soft), transparent 60%),
              linear-gradient(180deg,#fff,#fcfdfc);
  border-bottom:1px solid var(--line); }
.callout-head .icon{ width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center;
  background:rgba(0,51,0,.08); color:var(--g); }
.callout-head .kicker{ display:block; font-size:.72rem; letter-spacing:.3px; text-transform:uppercase; color:var(--muted); margin-bottom:2px; }
.callout-head h3{ margin:0; color:var(--g); font-weight:900; font-size:clamp(1.05rem,.95rem + .4vw,1.25rem); }
.callout-body{ padding:18px 20px 16px; }

.rulelist{ list-style:none; margin:10px 0 12px; padding:0; }
.rulelist.check li{ position:relative; margin:8px 0; padding:10px 12px 10px 44px;
  background:#fff; border:1px solid var(--line); border-radius:12px; line-height:1.55; color:var(--ink); }
.rulelist.check li::before{
  content:"\f00c"; font-family:"Font Awesome 6 Free"; font-weight:900;
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  background:var(--g); color:#fff; font-size:.7rem; box-shadow:0 0 0 2px rgba(0,51,0,.08);
}

.ack{ display:flex; align-items:center; gap:10px; background:rgba(0,51,0,.05);
  border:1px dashed rgba(0,51,0,.18); color:var(--ink); padding:10px 12px; border-radius:10px; margin:12px 0 10px; }
.actions{ display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
.btn{ display:inline-flex; align-items:center; justify-content:center; padding:12px 20px; border:0; border-radius:12px;
  background:var(--g); color:#fff; font-weight:800; cursor:pointer; transition:filter .15s ease, transform .06s ease; }
.btn:hover:not(:disabled){ filter:brightness(1.06); }
.btn:active:not(:disabled){ transform:translateY(1px); }
.btn:disabled{ background:#9aa89f; cursor:not-allowed; }
.btn-ghost{ background:#eef2ed; color:#1f3a1f; }

.pill{ background:#eff3ef; color:#1b3a1b; border:1px solid #d9e3d9; border-radius:999px; padding:6px 10px; font-weight:700; font-size:.9rem; }
.lock{ background:#fffaf5; border-color:#f8e0a8; }
.lock h3{ color:#7b4b00; }
.head-meta{
  margin-left:auto; text-align:right;
  display:flex; flex-direction:column; align-items:flex-end;
}
.head-meta .meta-label{
  font-size:.72rem; letter-spacing:.3px; text-transform:uppercase; color:var(--muted);
}
.head-meta .meta-value{
  font-weight:900; font-size:clamp(.95rem,.9rem + .25vw,1.05rem); color:var(--g);
}

</style>

<div class="main-content">
  <div class="pb-wrap">

    <section class="pb-hero">
      <div>
        <h1>Power Builder Assessment</h1>
        <p>Build your comprehension through short, leveled stories and questions.</p>
      </div>
      <?php if ($levelName): ?>
        <span class="pill" style="<?= htmlspecialchars($levelPillStyle) ?>">Color Category: <?= htmlspecialchars($levelName) ?></span>
      <?php endif; ?>
    </section>

    <?php if (!$hasSubmittedSLT || !$levelName): ?>
      <!-- Blocker when SLT is not done yet -->
      <section class="card callout lock" role="region" aria-labelledby="pbBlockTitle">
<div class="callout-head">
  <div class="icon"><i class="fas fa-clipboard-check"></i></div>
  <div>
    <span class="kicker">Please read</span>
    <h3 id="pbBlockTitle">Before you start</h3>
  </div>

  <div class="head-meta" aria-label="Time information">
    <div class="meta-label">Per Story Time Limit</div>
    <div class="meta-value"><?= htmlspecialchars($timeLimitLabel) ?></div>
  </div>
</div>


        <div class="callout-body">
          <p style="margin:0 0 10px; line-height:1.6;">
            Power Builder is personalized based on your <strong>Starting Level</strong>.
            Please take the Starting Level Test first so we can match you with the right
            color category and difficulty. Once you finish the SLT, you’ll be able to start Power Builder.
          </p>
          <div class="actions" style="margin-top:12px;">
            <a class="btn" href="stories_sl.php">Go to Starting Level Test</a>
            <a class="btn-ghost" href="index.php">Back to Dashboard</a>
          </div>
        </div>
      </section>
    <?php else: ?>
      <!-- Instructions + Start -->
      <section class="card callout" role="region" aria-labelledby="pbRulesTitle">
  <div class="callout-head">
    <div class="icon"><i class="fas fa-clipboard-check"></i></div>
    <div>
      <span class="kicker">Please read</span>
      <h3 id="pbRulesTitle">Before you start</h3>
    </div>

    <div class="head-meta" aria-label="Time information">
      <div class="meta-label">Per Story Time Limit</div>
      <div class="meta-value"><?= htmlspecialchars($timeLimitLabel) ?></div>
    </div>
  </div>
  <div class="callout-body">
          <ul class="rulelist check">
            <li><strong>15 stories total.</strong> Answer the questions after each story to check your understanding.</li>
            <li><strong>One read only.</strong> After you move forward, you cannot go back to re-read the story.</li>
            <li><strong>Single submission.</strong> Choices are final once submitted for a story.</li>
            <li><strong>Target:</strong> Pass at least <strong>8/15</strong> stories to unlock <em>Rate Builder</em>.</li>
            <li><strong>Stay online.</strong> A refresh or lost connection ends the attempt and it cannot be resumed.</li>
          </ul>

<div class="pill" style="margin:8px 0 14px;">
  Current progress: <strong><?= (int)$pbProgressDone ?>/<?= (int)$pbProgressTotal ?></strong>
</div>


          <label class="ack" for="ack">
            <input type="checkbox" id="ack" value="1">
            I have read and understood the instructions.
          </label>

          <div class="actions">
  <!-- BUTTON, hindi SUBMIT -->
  <button id="btnStart" type="button" class="btn" disabled>
    Start Power Builder
  </button>
  <a class="btn-ghost" href="index.php">Back to Dashboard</a>
</div>

        </div>
      </section>
    <?php endif; ?>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const ack = document.getElementById('ack');
  const btn = document.getElementById('btnStart');
  if (!btn) return;

  const refresh = () => { btn.disabled = !(ack?.checked); };
  ack?.addEventListener('change', refresh);
  window.addEventListener('online',  refresh);
  window.addEventListener('offline', refresh);
  refresh();

  // ✅ idikit natin dito ang attempt_id kung meron
  const aidInProgress = <?= json_encode((int)$pbAidInProgress) ?>;

  btn.addEventListener('click', () => {
    // ✅ kung may in-progress → resume (first unfinished); else → create new attempt
    const url = (aidInProgress > 0)
      ? `stories_pb_start.php?aid=${aidInProgress}&next=1`
      : 'stories_pb_start.php';
    window.location.href = url;
  });
});

</script>

</body>
</html>
