<?php
// student/stories_rb.php — Rate Builder intro with PB gating (dynamic) + progress
require_once __DIR__ . '/../includes/auth.php';
require_role('student', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

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
  $m = intdiv($secs, 60); $s = $secs % 60;
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
  $map = ['red'=>'#D32F2F','orange'=>'#EF6C00','yellow'=>'#F9A825','blue'=>'#1565C0','green'=>'#2E7D32','purple'=>'#7E57C2'];
  return $map[strtolower(trim($name))] ?? null;
}

/* ---------- Current level ---------- */
$level = null;
if ($student_id > 0) {
  $st = $conn->prepare("
    SELECT L.level_id, L.name, L.color_hex
      FROM student_level SL
      JOIN sra_levels L ON L.level_id = SL.level_id
     WHERE SL.student_id = ? AND SL.is_current = 1
     LIMIT 1
  ");
  $st->bind_param('i', $student_id);
  $st->execute();
  $level = $st->get_result()->fetch_assoc();
  $st->close();
}
$levelId   = isset($level['level_id']) ? (int)$level['level_id'] : 0;
$levelName = $level['name'] ?? null;
$lvHex     = level_color_hex($levelName ?? null, $level['color_hex'] ?? null);
$levelPillStyle = '';
if ($lvHex) { [$r,$g,$b] = hex_to_rgb($lvHex); $levelPillStyle = "background: rgba($r,$g,$b,.12); border:1px solid $lvHex; color:#1b3a1b;"; }

/* ---------- RB: published stories (dynamic denominator) ---------- */
$rbPublishedTotal = 0;
if ($levelId) {
  if ($stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM stories s
      JOIN story_sets ss ON ss.set_id = s.set_id
      WHERE ss.set_type='RB'
        AND ss.level_id = ?
        AND s.status='published'
        AND (ss.status IS NULL OR ss.status IN ('published','draft'))
  ")) {
    $stmt->bind_param('i', $levelId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rbPublishedTotal = (int)($res->fetch_assoc()['c'] ?? 0);
    $stmt->close();
  }
}

/* ---------- RB: time limit summary across published stories ---------- */
$timeLimitLabel = 'No time limit';
if ($levelId) {
  $minTL = (int)(scalar($conn, "
              SELECT COALESCE(MIN(NULLIF(s.time_limit_seconds,0)), 0)
              FROM stories s
              JOIN story_sets ss ON ss.set_id = s.set_id
             WHERE ss.set_type='RB' AND ss.level_id=? AND s.status='published'
               AND (ss.status IS NULL OR ss.status IN ('published','draft'))",
             [$levelId], 'i') ?? 0);
  $maxTL = (int)(scalar($conn, "
              SELECT COALESCE(MAX(NULLIF(s.time_limit_seconds,0)), 0)
              FROM stories s
              JOIN story_sets ss ON ss.set_id = s.set_id
             WHERE ss.set_type='RB' AND ss.level_id=? AND s.status='published'
               AND (ss.status IS NULL OR ss.status IN ('published','draft'))",
             [$levelId], 'i') ?? 0);

  if ($maxTL > 0) {
    $timeLimitLabel = ($minTL === $maxTL)
      ? human_duration($minTL)
      : human_duration($minTL) . ' – ' . human_duration($maxTL);
  }
}

/* ---------- PB → RB GATING (dynamic & aligned with PB summary rule) ---------- */
$PB_OVERALL_PASS = 75.0; // passing grade for PB overall

// How many PB stories are published for this level?
$pbPublishedTotal = (int)(scalar($conn, "
  SELECT COUNT(*)
  FROM stories s
  JOIN story_sets ss ON ss.set_id = s.set_id
  WHERE ss.set_type='PB' AND ss.level_id=? AND s.status='published'
    AND (ss.status IS NULL OR ss.status IN ('published','draft'))
", [$levelId], 'i') ?? 0);

// Latest submitted PB attempt overall percent
$pbOverallPercent = (float)(scalar($conn, "
  SELECT percent
  FROM assessment_attempts
  WHERE student_id=? AND set_type='PB' AND status='submitted'
  ORDER BY submitted_at DESC, attempt_id DESC
  LIMIT 1
", [$student_id], 'i') ?? 0.0);
$pbOverallPass = ($pbOverallPercent >= $PB_OVERALL_PASS);

// Per-story passing threshold (by level; default to 75 if not set)
$pbPassThreshold = (float)(scalar($conn, "
  SELECT min_percent FROM level_thresholds
  WHERE applies_to='PB' AND level_id=? LIMIT 1
", [$levelId], 'i') ?? 75.0);

// How many PB stories have you passed (distinct story_id across submitted attempts)?
$pbPassed = (int)(scalar($conn, "
  SELECT COUNT(DISTINCT s.story_id)
  FROM attempt_stories s
  JOIN assessment_attempts a ON a.attempt_id = s.attempt_id
  WHERE a.student_id = ? AND a.set_type='PB' AND a.status='submitted'
    AND s.percent >= ?
", [$student_id, $pbPassThreshold], 'id') ?? 0);

// Dynamic required passes: scale 8/15 based on published PB
$requiredPBPass = ($pbPublishedTotal > 0)
  ? max(1, (int)ceil($pbPublishedTotal * (8/15)))
  : 8;

// Final unlock decision
$rbUnlocked = $pbOverallPass || ($pbPassed >= $requiredPBPass);

/* ---------- In-progress RB attempt (for resume + progress) ---------- */
$rbAidInProgress = (int)(scalar(
  $conn,
  "SELECT attempt_id
     FROM assessment_attempts
    WHERE student_id=? AND set_type='RB' AND status='in_progress'
    ORDER BY started_at DESC, attempt_id DESC
    LIMIT 1",
  [$student_id], 'i'
) ?? 0);

$rbProgDone  = 0;
$rbProgTotal = (int)$rbPublishedTotal;
if ($rbAidInProgress > 0) {
  $rbProgTotal = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=?",
    [$rbAidInProgress],'i'
  ) ?? 0);
  $rbProgDone = (int)(scalar(
    $conn,
    "SELECT COUNT(*) FROM attempt_stories WHERE attempt_id=? AND score IS NOT NULL",
    [$rbAidInProgress],'i'
  ) ?? 0);
}

/* ---------- UI Chrome ---------- */
$PAGE_TITLE  = 'Rate Builder Assessment';
$ACTIVE_MENU = 'learn';
$ACTIVE_SUB  = 'rb';

// ---- RB pass threshold (default 75) ----
$rbPassThreshold = (float)(scalar(
  $conn,
  "SELECT min_percent FROM level_thresholds
   WHERE applies_to='RB' AND level_id=? LIMIT 1",
  [$levelId], 'i'
) ?? 75.0);

// ---- Latest submitted RB attempt; if passed, redirect straight to summary
$rbPassedAttemptId = (int)(scalar(
  $conn,
  "SELECT attempt_id
     FROM assessment_attempts
    WHERE student_id=? AND set_type='RB' AND status='submitted' AND percent >= ?
    ORDER BY submitted_at DESC, attempt_id DESC
    LIMIT 1",
  [$student_id, $rbPassThreshold], 'id'
) ?? 0);

if ($rbPassedAttemptId > 0) {
  header('Location: stories_rb_done.php?attempt_id=' . $rbPassedAttemptId);
  exit;
}

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
.rb-wrap{ max-width:1320px; margin:0 auto; padding:16px 24px; padding-top:0; }

.rb-hero{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  margin:12px 0 16px; padding:24px 28px;
  background: radial-gradient(1100px 180px at 18% -20%, var(--acc-soft), transparent 60%),
              linear-gradient(180deg,#fff,#fefefe);
  border:1px solid #eef2ee; border-radius:16px; box-shadow:var(--shadow);
}
.rb-hero h1{ margin:0 0 4px; color:var(--g); font-weight:900; letter-spacing:.2px;
  font-size:clamp(1.4rem,1.1rem + 1.2vw,2rem); }
.rb-hero p{ margin:0; color:var(--ink); opacity:.85; font-size:clamp(.95rem,.9rem + .2vw,1rem); }

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
.head-meta{ margin-left:auto; text-align:right; display:flex; flex-direction:column; align-items:flex-end; }
.head-meta .meta-label{ font-size:.72rem; letter-spacing:.3px; text-transform:uppercase; color:var(--muted); }
.head-meta .meta-value{ font-weight:900; font-size:clamp(.95rem,.9rem + .25vw,1.05rem); color:var(--g); }
.slt-warning {
  color: #c23934;          /* soft red, hindi sobrang lakas */
  font-size: .9rem;
  margin: 4px 0 10px;
  line-height: 1.5;
}
</style>

<div class="main-content">
  <div class="rb-wrap">

    <section class="rb-hero">
      <div>
        <h1>Rate Builder Assessment</h1>
        <p>Build your rate and accuracy through short, leveled stories and questions.</p>
      </div>
      <?php if ($levelName): ?>
        <span class="pill" style="<?= htmlspecialchars($levelPillStyle) ?>">Color Category: <?= htmlspecialchars($levelName) ?></span>
      <?php endif; ?>
    </section>

    <?php if (!$rbUnlocked): ?>
      <!-- Blocker when PB not yet passed -->
      <section class="card callout lock" role="region" aria-labelledby="rbBlockTitle">
        <div class="callout-head">
          <div class="icon"><i class="fas fa-lock"></i></div>
          <div>
            <span class="kicker">Locked</span>
            <h3 id="rbBlockTitle">Finish Power Builder first</h3>
          </div>
          <div class="head-meta" aria-label="Time limit information">
            <div class="meta-label">Time Limit</div>
            <div class="meta-value"><?= htmlspecialchars($timeLimitLabel) ?></div>
          </div>
        </div>
        <div class="callout-body">
          <p style="margin:0 0 10px; line-height:1.6;">
            Rate Builder unlocks after you either:
            <br>• get <strong><?= (int)$PB_OVERALL_PASS ?>%</strong> or higher PB overall, <em>or</em>
            <br>• pass at least <strong><?= (int)$requiredPBPass ?></strong> Power Builder stories.
          </p>
          <div class="actions" style="margin-top:12px;">
            <a class="btn" href="stories_pb.php">Go to Power Builder</a>
            <a class="btn-ghost" href="index.php">Back to Dashboard</a>
          </div>
        </div>
      </section>
    <?php else: ?>
      <!-- Instructions + Start -->
      <section class="card callout" role="region" aria-labelledby="rbRulesTitle">
        <div class="callout-head">
          <div class="icon"><i class="fas fa-clipboard-check"></i></div>
          <div>
            <span class="kicker">Please read</span>
            <h3 id="rbRulesTitle">Before you start</h3>
          </div>
          <div class="head-meta" aria-label="Time limit information">
            <div class="meta-label">Time Limit</div>
            <div class="meta-value"><?= htmlspecialchars($timeLimitLabel) ?></div>
          </div>
        </div>
        <div class="callout-body">
          <ul class="rulelist check">
            <li><strong>Stories available: <?= (int)$rbPublishedTotal; ?>.</strong> Answer questions after each story.</li>
            <li><strong>One read only.</strong> After you move forward, you cannot go back to re-read the story.</li>
            <li><strong>Single submission.</strong> Choices are final once submitted for a story.</li>
            <li><strong>Stay online.</strong> A refresh or lost connection ends the attempt and it cannot be resumed.</li>
          </ul>

                 <p class="slt-warning">
    While taking the test, please stay on this page. The administrator can see when you
    leave or switch tabs. If you are frequently away from the test or stay off the page
    for a long time, your attempt may be rejected and you may be required to start from
    the beginning. Even a passing score may be marked as <strong>invalid</strong>.
  </p>
          <div class="pill" style="margin:8px 0 14px;">
            Current progress: <strong><?= (int)$rbProgDone ?>/<?= (int)$rbProgTotal ?></strong>
          </div>

          <label class="ack" for="ack">
            <input type="checkbox" id="ack" value="1">
            I have read and understood the instructions.
          </label>

          <div class="actions">
            <button id="btnStart" type="button" class="btn" disabled>Start Rate Builder</button>
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
  if (!btn) return; // guard when locked

  const refresh = () => { btn.disabled = !(ack?.checked); };
  ack?.addEventListener('change', refresh);
  window.addEventListener('online',  refresh);
  window.addEventListener('offline', refresh);
  refresh();

  // Resume if there is an in-progress RB attempt
  const aidInProgress = <?= json_encode((int)$rbAidInProgress) ?>;

  btn.addEventListener('click', () => {
    const url = (aidInProgress > 0)
      ? `stories_rb_start.php?aid=${aidInProgress}&next=1`
      : 'stories_rb_start.php';
    window.location.href = url;
  });
});
</script>

</body>
</html>
