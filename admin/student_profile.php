<?php
// admin/student_profile.php
// Single-student progress view: story performance + tab-switch summary + attendance + admin actions

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ---- Inputs ----
$student_id = (int)($_GET['user_id'] ?? 0);
if ($student_id <= 0) {
    header('Location: programs_students.php');
    exit;
}

/* ---------------- Student basic info ---------------- */
$sql = "
    SELECT 
        u.user_id,
        u.student_id_no,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.ext_name,
        u.year_level,
        u.section,
        p.program_code,
        p.program_name
    FROM users u
    LEFT JOIN sra_programs p ON p.program_id = u.program_id
    WHERE u.user_id = ?
      AND u.role = 'student'
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$res = $stmt->get_result();
$student = $res->fetch_assoc();
$stmt->close();

if (!$student) {
    header('Location: programs_students.php');
    exit;
}

$full_name = trim(
    $student['last_name'] . ', ' .
    $student['first_name'] .
    ($student['middle_name'] ? ' ' . mb_substr($student['middle_name'], 0, 1) . '.' : '') .
    ($student['ext_name'] ? ' ' . $student['ext_name'] : '')
);

$program_label = trim(($student['program_code'] ?? '') . ' – ' . ($student['program_name'] ?? ''));
$yl_sec = 'Year ' . (int)$student['year_level'] . ' – ' . htmlspecialchars((string)$student['section']);

/* ---------------- Validation status (pending / validated / invalid) ---------------- */
$validation_status = 'pending';
$sql = "SELECT status FROM assessment_validation WHERE student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $validation_status = $row['status'] ?: 'pending';
}
$stmt->close();

$status_label = 'Pending review';
$status_class = 'status-pill-pending';

if ($validation_status === 'validated') {
    $status_label = 'Validated';
    $status_class = 'status-pill-ok';
} elseif ($validation_status === 'invalid') {
    $status_label = 'Invalid';
    $status_class = 'status-pill-bad';
}

/* ---------------- Attendance (Total days present) ----------------
   - Count DISTINCT DATE(started_at) from assessment_attempts
   - No month/year filtering: lifetime usage
------------------------------------------------------------------- */
$present_days = 0;
$sql = "
    SELECT COUNT(DISTINCT DATE(started_at)) AS present_days
    FROM assessment_attempts
    WHERE student_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $present_days = (int)$row['present_days'];
}
$stmt->close();

/* ---------------- Story performance + tab logs ---------------- */
$sql = "
    SELECT 
        ats.attempt_story_id,
        aa.attempt_id,
        aa.set_type,
        aa.started_at,
        s.title AS story_title,
        ats.max_score,
        ats.score,
        ats.percent,
        ats.reading_seconds,
        COALESCE(t.hidden_count, 0) AS hidden_count
    FROM attempt_stories ats
    INNER JOIN assessment_attempts aa 
        ON aa.attempt_id = ats.attempt_id
    INNER JOIN stories s 
        ON s.story_id = ats.story_id
    LEFT JOIN (
        SELECT attempt_id, COUNT(*) AS hidden_count
        FROM slt_tab_log
        WHERE state = 'hidden'
        GROUP BY attempt_id
    ) t ON t.attempt_id = aa.attempt_id
    WHERE aa.student_id = ?
      AND aa.status <> 'invalidated'
    ORDER BY 
      FIELD(aa.set_type, 'SLT', 'PB', 'RB'),
      aa.started_at ASC,
      ats.sequence ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

/* ---------------- Helpers ---------------- */
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mapLevelName(string $setType): string {
    switch ($setType) {
        case 'SLT': return 'Starting Level';
        case 'PB':  return 'Power Builder';
        case 'RB':  return 'Rate Builder';
        default:    return $setType;
    }
}

function formatDuration(?float $seconds): string {
    if ($seconds === null) return '—';
    $seconds = (int)round($seconds);
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

// Focus rating based on hidden_count (tab switches)
function focusRating(int $hiddenCount): array {
    if ($hiddenCount <= 0) {
        return ['label' => 'Focused', 'class' => 'focus-ok'];
    } elseif ($hiddenCount <= 2) {
        return ['label' => 'Minor interruptions', 'class' => 'focus-mid'];
    }
    return ['label' => 'Frequent tab switching', 'class' => 'focus-low'];
}

/* ---------------- Flash message (optional) ---------------- */
$msg_code = $_GET['msg'] ?? '';
$flash_msg = '';
$flash_class = '';

switch ($msg_code) {
    case 'validated':
        $flash_msg = 'Student results successfully validated.';
        $flash_class = 'flash-ok';
        break;
    case 'invalid':
        $flash_msg = 'Student results marked as invalid.';
        $flash_class = 'flash-warn';
        break;
    case 'reset_ok':
        $flash_msg = 'All tests for this student were reset.';
        $flash_class = 'flash-info';
        break;
}

$PAGE_TITLE  = $full_name;
$ACTIVE_MENU = 'prog_students';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
.student-progress-header {
  margin: 16px 16px 10px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.student-header-left h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  color: #064d00;
}
.student-header-left p {
  margin: 2px 0;
  font-size: 13px;
  color: #4b5563;
}

/* status pill sa top-right */
.student-header-status {
  display: flex;
  align-items: center;
  gap: 8px;
}
.status-label-text {
  font-size: 12px;
  color: #6b7280;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  border: 1px solid transparent;
}
.status-pill-pending {
  background: #f9fafb;
  border-color: #d1d5db;
  color: #4b5563;
}
.status-pill-ok {
  background: #dcfce7;
  border-color: #16a34a;
  color: #166534;
}
.status-pill-bad {
  background: #fee2e2;
  border-color: #b91c1c;
  color: #991b1b;
}

/* flash message */
.flash-banner {
  margin: 4px 16px 6px;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.flash-ok {
  background: #ecfdf3;
  color: #15803d;
  border: 1px solid #16a34a;
}
.flash-warn {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fbbf24;
}
.flash-info {
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #3b82f6;
}

.student-progress-card {
  background: #ffffff;
  border-radius: 24px;
  margin: 0 16px 24px;
  padding: 16px 18px 22px;
  box-shadow: 0 18px 45px rgba(0,0,0,0.08);
}

/* Attendance + actions */
.attendance-actions-layout {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
}
.attendance-info {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: center;
}
.attendance-pill {
  background: #f4f8f0;
  border-radius: 999px;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 600;
  color: #064d00;
  border: 1px solid #d1e3c9;
}
.btn-small {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 7px 14px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  border: none;
  cursor: pointer;
}
.btn-validate {
  background: #064d00;
  color: #ffffff;
}
.btn-validate:hover { filter: brightness(1.05); }
.btn-invalidate {
  background: #ffffff;
  border: 1px solid #b91c1c;
  color: #b91c1c;
}
.btn-invalidate:hover { background: #fef2f2; }
.btn-reset {
  background: #ffffff;
  border: 1px solid #f97316;
  color: #c2410c;
}
.btn-reset:hover { background: #fff7ed; }

/* Story table */
.student-progress-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  border-radius: 18px;
  overflow: hidden;
  margin-top: 8px;
  border: 1px solid #e5e7eb;
}

.student-progress-table th,
.student-progress-table td {
  padding: 9px 11px;
  font-size: 13px;
  vertical-align: middle;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #e5e7eb;
}

.student-progress-table th:last-child,
.student-progress-table td:last-child {
  border-right: none;
}

.student-progress-table tbody tr:last-child td {
  border-bottom: none;
}

.student-progress-table thead tr {
  background: #f4f8f0;
}
.student-progress-table th {
  font-weight: 700;
  color: #064d00;
  text-align: left;
}

.student-progress-table th.center,
.student-progress-table td.center {
  text-align: center;
}
.student-progress-table tbody tr:nth-child(even) {
  background: #fcfdf9;
}
.student-progress-table tbody tr:nth-child(odd) {
  background: #ffffff;
}
.student-progress-table tbody tr:hover {
  background: #f1f5f9;
}

/* Percent badge */
.badge-percent {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 56px;
  padding: 4px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.badge-percent.ok {
  background: #e5f3da;
  color: #065f46;
}
.badge-percent.mid {
  background: #fef3c7;
  color: #92400e;
}
.badge-percent.low {
  background: #fee2e2;
  color: #b91c1c;
}

/* Focus status */
.focus-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
}
.focus-ok {
  background: #e0f7f0;
  color: #065f46;
}
.focus-mid {
  background: #fef3c7;
  color: #92400e;
}
.focus-low {
  background: #fee2e2;
  color: #b91c1c;
}

@media (max-width: 900px) {
  .student-progress-header {
    margin: 12px 10px 6px;
    flex-direction: column;
    align-items: flex-start;
  }
  .student-progress-card {
    margin: 0 10px 18px;
    padding: 14px 12px 18px;
  }
  .student-progress-table th,
  .student-progress-table td {
    padding: 7px 7px;
    font-size: 12px;
  }
  .attendance-actions-layout {
    flex-direction: column;
    align-items: flex-start;
  }
}

/* ---------- Confirmation modal ---------- */
.confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 3000;
}
.confirm-overlay.show {
  display: flex;
}
.confirm-dialog {
  width: min(420px, 92vw);
  background: #ffffff;
  border-radius: 18px;
  box-shadow: 0 24px 70px rgba(0,0,0,0.35);
  overflow: hidden;
  border: 1px solid #e5e7eb;
}
.confirm-header {
  padding: 10px 14px;
  background: #064d00;
  color: #ffffff;
  font-size: 14px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.confirm-header button {
  background: transparent;
  border: none;
  color: #ffffff;
  cursor: pointer;
  font-size: 16px;
}
.confirm-body {
  padding: 14px 16px 6px;
  font-size: 13px;
  color: #374151;
}
.confirm-footer {
  padding: 10px 16px 14px;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.confirm-btn {
  padding: 7px 14px;
  font-size: 12px;
  border-radius: 999px;
  border: none;
  cursor: pointer;
  font-weight: 700;
}
.confirm-btn-cancel {
  background: #f9fafb;
  border: 1px solid #d1d5db;
  color: #374151;
}
.confirm-btn-primary {
  background: #064d00;
  color: #ffffff;
}
.confirm-btn-danger {
  background: #b91c1c;
  color: #ffffff;
}
</style>

<div class="main-content">

  <div class="student-progress-header">
    <div class="student-header-left">
      <h1><?php echo h($full_name); ?></h1>
      <p><?php echo h($student['student_id_no']); ?> · <?php echo h($program_label); ?></p>
      <p><?php echo h($yl_sec); ?></p>
    </div>
    <div class="student-header-status">
      <span class="status-label-text">Overall status:</span>
      <span class="status-pill <?php echo h($status_class); ?>">
        <?php echo h($status_label); ?>
      </span>
    </div>
  </div>

  <?php if ($flash_msg): ?>
    <div class="flash-banner <?php echo h($flash_class); ?>">
      <span><?php echo h($flash_msg); ?></span>
    </div>
  <?php endif; ?>

  <!-- Attendance + Admin Actions card -->
  <section class="student-progress-card">
    <h2 style="margin:0 0 6px; font-size:16px; font-weight:700; color:#111827;">
      Attendance &amp; Admin Actions
    </h2>
    <p style="margin:0 0 10px; font-size:12px; color:#6b7280;">
      Attendance is based on the total number of distinct days where the student started at least one assessment. Use the actions to validate, invalidate, or reset the student&rsquo;s assessment results.
    </p>

    <div class="attendance-actions-layout">
      <div class="attendance-info">
        <div class="attendance-pill">
          <span style="font-weight:600;">Total days present in the system:</span>
          &nbsp;<?php echo (int)$present_days; ?>
        </div>
      </div>

      <div class="attendance-actions" style="display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end;">
        <!-- Validate -->
        <form method="post" action="student_validate.php" style="margin:0;" class="js-action-form">
          <input type="hidden" name="user_id" value="<?php echo (int)$student_id; ?>">
          <button type="button"
                  class="btn-small btn-validate js-open-confirm"
                  data-title="Validate Results"
                  data-message="Are you sure you want to validate this student&rsquo;s assessment results? This will allow certificate printing if all requirements are met."
                  data-btn="Yes, validate"
                  data-style="primary">
            ✅ Validate Results
          </button>
        </form>

        <!-- Mark as Invalid -->
        <form method="post" action="student_invalidate.php" style="margin:0;" class="js-action-form">
          <input type="hidden" name="user_id" value="<?php echo (int)$student_id; ?>">
          <button type="button"
                  class="btn-small btn-invalidate js-open-confirm"
                  data-title="Mark as Invalid"
                  data-message="Mark this student&rsquo;s assessment as INVALID? This will prevent certificate generation until you validate a new test run."
                  data-btn="Yes, mark invalid"
                  data-style="danger">
            ❌ Mark as Invalid
          </button>
        </form>

        <!-- Reset All Tests -->
        <form method="post" action="student_reset.php" style="margin:0;" class="js-action-form">
          <input type="hidden" name="user_id" value="<?php echo (int)$student_id; ?>">
          <button type="button"
                  class="btn-small btn-reset js-open-confirm"
                  data-title="Reset All Tests"
                  data-message="Reset ALL tests for this student and send them back to the beginning? All existing scores, unlocks, and certificates will be removed. This cannot be undone."
                  data-btn="Yes, reset all tests"
                  data-style="danger">
            🔁 Reset All Tests
          </button>
        </form>
      </div>
    </div>
  </section>

  <!-- Story performance table -->
  <section class="student-progress-card">
    <h2 style="margin:0 0 6px; font-size:16px; font-weight:700; color:#111827;">
      Story Performance &amp; Focus
    </h2>
    <p style="margin:0 0 10px; font-size:12px; color:#6b7280;">
      Each row represents a story taken by the student, with score, time spent, and tab-switch behavior during the attempt.
    </p>

    <?php if (empty($rows)): ?>
      <p style="margin:8px 4px; font-size:14px; color:#4b5563;">
        No assessment data found for this student yet.
      </p>
    <?php else: ?>
      <table class="student-progress-table">
        <thead>
          <tr>
            <th class="center">#</th>
            <th>Level</th>
            <th>Story Title</th>
            <th class="center">No. of Items</th>
            <th class="center">Correct Answer</th>
            <th class="center">Percentage</th>
            <th class="center">Time Spent</th>
            <th class="center">Tab Switches</th>
            <th>Focus Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $i = 1;
          foreach ($rows as $r):
              $levelName = mapLevelName($r['set_type']);
              $percent   = $r['percent'];
              $badgeClass = 'low';
              if ($percent === null) {
                  $badgeClass = 'low';
              } elseif ($percent >= 80) {
                  $badgeClass = 'ok';
              } elseif ($percent >= 60) {
                  $badgeClass = 'mid';
              }

              $focus = focusRating((int)$r['hidden_count']);
          ?>
            <tr>
              <td class="center"><?php echo $i++; ?></td>
              <td><?php echo h($levelName); ?></td>
              <td><?php echo h($r['story_title']); ?></td>
              <td class="center"><?php echo (int)$r['max_score']; ?></td>
              <td class="center"><?php echo (int)$r['score']; ?></td>
              <td class="center">
                <?php if ($percent !== null): ?>
                  <span class="badge-percent <?php echo $badgeClass; ?>">
                    <?php echo (float)$percent; ?>%
                  </span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="center">
                <?php echo formatDuration($r['reading_seconds']); ?>
              </td>
              <td class="center">
                <?php echo (int)$r['hidden_count']; ?>
              </td>
              <td>
                <span class="focus-pill <?php echo h($focus['class']); ?>">
                  <?php echo h($focus['label']); ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<!-- Confirmation modal -->
<div class="confirm-overlay" id="confirmOverlay" aria-hidden="true">
  <div class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="confirm-header">
      <span id="confirmTitle">Confirm action</span>
      <button type="button" id="confirmCloseTop" aria-label="Close">✕</button>
    </div>
    <div class="confirm-body">
      <p id="confirmMessage"></p>
    </div>
    <div class="confirm-footer">
      <button type="button" class="confirm-btn confirm-btn-cancel" id="confirmCancel">Cancel</button>
      <button type="button" class="confirm-btn confirm-btn-primary" id="confirmOk">Proceed</button>
    </div>
  </div>
</div>

<script>
(function(){
  const overlay = document.getElementById('confirmOverlay');
  const titleEl = document.getElementById('confirmTitle');
  const msgEl   = document.getElementById('confirmMessage');
  const btnOk   = document.getElementById('confirmOk');
  const btnCancel = document.getElementById('confirmCancel');
  const btnCloseTop = document.getElementById('confirmCloseTop');

  let currentForm = null;

  function openModal(form, opts){
    currentForm = form;
    titleEl.textContent = opts.title || 'Confirm action';
    msgEl.innerHTML = opts.message || '';
    btnOk.textContent = opts.buttonLabel || 'Proceed';

    btnOk.classList.remove('confirm-btn-primary','confirm-btn-danger');
    if (opts.style === 'danger') {
      btnOk.classList.add('confirm-btn-danger');
    } else {
      btnOk.classList.add('confirm-btn-primary');
    }

    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden','false');
  }

  function closeModal(){
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden','true');
    currentForm = null;
  }

  document.querySelectorAll('.js-open-confirm').forEach(btn => {
    btn.addEventListener('click', () => {
      const form = btn.closest('form');
      if (!form) return;
      const opts = {
        title: btn.getAttribute('data-title'),
        message: btn.getAttribute('data-message'),
        buttonLabel: btn.getAttribute('data-btn'),
        style: btn.getAttribute('data-style') || 'primary'
      };
      openModal(form, opts);
    });
  });

  btnOk.addEventListener('click', () => {
    if (currentForm) {
      currentForm.submit();
    }
  });

  btnCancel.addEventListener('click', closeModal);
  btnCloseTop.addEventListener('click', closeModal);

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.classList.contains('show')) {
      closeModal();
    }
  });
})();
</script>

</body>
</html>
