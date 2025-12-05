<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Programs and Students';
$ACTIVE_MENU = 'prog_students';

require_once __DIR__ . '/../db_connect.php';        // ✅ siguradong may $conn
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

// --- Load courses/programs with student count ---
$courses = [];

$sql = "
    SELECT 
        p.program_id,
        p.program_code,
        p.program_name,
        COUNT(CASE WHEN u.role = 'student' THEN u.user_id END) AS student_count
    FROM sra_programs p
    LEFT JOIN users u 
        ON u.program_id = p.program_id
       AND u.role = 'student'
    WHERE p.status = 'active'
    GROUP BY p.program_id, p.program_code, p.program_name
    ORDER BY student_count DESC, p.program_code ASC
";

$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $courses[] = $row;
}
$res->free();

?>

<style>
/* main wrapper card */
.courses-card {
  background: #ffffff;
  border-radius: 24px;
  padding: 20px 24px 24px;
  margin: 16px 16px 32px;
  box-shadow: 0 18px 45px rgba(0, 0, 0, 0.08);
}

/* header area */
.courses-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.courses-card-header h1 {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  color: #064d00;
}

.courses-card-header .subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: #4b5563;
}

.header-actions .btn-accent {
  border-radius: 999px;
  padding: 10px 22px;
  border: none;
  font-weight: 600;
  background: #f5a425;
  color: #1f2327;
  cursor: pointer;
  box-shadow: 0 10px 24px rgba(245, 164, 37, 0.45);
}

.header-actions .btn-accent:hover {
  filter: brightness(0.95);
}

/* table wrapper */
.courses-table-wrapper {
  margin-top: 4px;
}

/* table styles similar sa screenshot */
.courses-table {
  width: 100%;
  border-collapse: collapse;
  overflow: hidden;
  border-radius: 18px;
}

.courses-table thead tr {
  background: #f4f8f0;
}

.courses-table th,
.courses-table td {
  padding: 12px 18px;
  font-size: 14px;
}

.courses-table th {
  text-align: left;
  font-weight: 700;
  color: #064d00;
}

.courses-table tbody tr:nth-child(even) {
  background: #fcfdf9;
}

.courses-table tbody tr:nth-child(odd) {
  background: #ffffff;
}

.courses-table tbody tr:hover {
  background: #f1f5f9;
}

.course-title {
  font-weight: 600;
  color: #0b3d0f;
}

/* number of students badge */
.badge-count {
  display: inline-flex;
  min-width: 40px;
  justify-content: center;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  background: #e5f3da;
  color: #064d00;
  font-weight: 700;
}

/* view details button pill */
.btn-view {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 8px 18px;
  border-radius: 999px;
  border: none;
  text-decoration: none;
  background: linear-gradient(135deg, #f5a425, #f6c445);
  color: #1f2327;
  font-weight: 700;
  font-size: 14px;
  box-shadow: 0 10px 24px rgba(245, 164, 37, 0.45);
}

.btn-view:hover {
  filter: brightness(0.95);
}

/* empty state */
.empty-state {
  padding: 28px 12px;
  text-align: center;
}

.empty-state p {
  margin: 0;
}

.empty-state .hint {
  margin-top: 6px;
  font-size: 13px;
  color: #6b7280;
}

/* responsive tweaks */
@media (max-width: 768px) {
  .courses-card {
    margin: 10px;
    padding: 16px;
  }

  .courses-card-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .courses-table th,
  .courses-table td {
    padding: 10px 12px;
  }
}
/* ---------- ADD COURSE MODAL ---------- */

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: none; /* hidden by default */
  align-items: center;
  justify-content: center;
  z-index: 900;
  padding: 16px;
}

.modal-backdrop.show {
  display: flex;
}

.modal-dialog {
  background: #ffffff;
  border-radius: 20px;
  max-width: 480px;
  width: 100%;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
  animation: modalFadeIn 0.18s ease-out;
}

@keyframes modalFadeIn {
  from {
    opacity: 0;
    transform: translateY(12px) scale(0.97);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px 10px;
  border-bottom: 1px solid #e5e7eb;
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #064d00;
}

.modal-close {
  background: transparent;
  border: none;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  color: #6b7280;
}

.modal-close:hover {
  color: #111827;
}

.modal-body {
  padding: 12px 18px 16px;
}

.modal-text {
  font-size: 13px;
  color: #6b7280;
  margin: 0 0 10px;
}

.form-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 10px;
}

.form-row label {
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}

.form-row .req {
  color: #dc2626;
}

.form-row input,
.form-row select {
  border-radius: 999px;
  border: 1px solid #d1d5db;
  padding: 8px 12px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.form-row input:focus,
.form-row select:focus {
  border-color: #1e8fa2;
  box-shadow: 0 0 0 2px rgba(30, 143, 162, 0.25);
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.btn.btn-ghost {
  border-radius: 999px;
  padding: 8px 16px;
  background: #f3f4f6;
  border: none;
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  cursor: pointer;
}

.btn.btn-ghost:hover {
  background: #e5e7eb;
}

/* reuse existing .btn-accent style */
/* tweak modal buttons para mas soft look */
.modal-footer .btn {
  font-size: 14px;
  font-weight: 600;
}

.modal-footer .btn-ghost {
  border-radius: 999px;
  padding: 8px 20px;
  background: #ffffff;
  border: 1px solid #d1d5db;
  color: #111827;
}

.modal-footer .btn-ghost:hover {
  background: #f3f4f6;
}

.modal-footer .btn-accent.pill {
  border-radius: 999px;
  padding: 8px 22px;
  border: none;
  background: linear-gradient(135deg, #f5a425, #f6c445);
  color: #1f2327;
  box-shadow: 0 10px 24px rgba(245, 164, 37, 0.35);
}

.modal-footer .btn-accent.pill:hover {
  filter: brightness(0.95);
}
/* Header "Add Course" button – white + green pill */
.header-actions .btn-add-course {
  border-radius: 999px;
  padding: 10px 24px;
  border: 1px solid rgba(6, 77, 0, 0.22); /* soft green border */
  background: #ffffff;
  color: #064d00;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
  box-shadow: 0 12px 30px rgba(6, 77, 0, 0.12);
  transition: background 0.15s, transform 0.12s, box-shadow 0.12s;
}

.header-actions .btn-add-course:hover {
  background: #e5f3da; /* light green */
  transform: translateY(-1px);
  box-shadow: 0 16px 32px rgba(6, 77, 0, 0.18);
}

.header-actions .btn-add-course:active {
  transform: translateY(0);
  box-shadow: 0 8px 18px rgba(6, 77, 0, 0.18);
}

</style>

<div class="main-content">
  <section class="card courses-card">
    <div class="courses-card-header">
      <div>
        <h1>Course and Students Management</h1>
        <p class="subtitle">
          Overview of all active courses/programs and the students registered in the SRA system.
        </p>
      </div>
      <div class="header-actions">
       <button type="button" class="btn btn-add-course" id="btnAddCourse">
  + Add Course
</button>

      </div>
    </div>

    <div class="courses-table-wrapper">
      <?php if (empty($courses)): ?>
        <div class="empty-state">
          <p>No courses found yet.</p>
          <p class="hint">Click <strong>Add Course</strong> to create your first course/program.</p>
        </div>
      <?php else: ?>
        <table class="courses-table">
          <thead>
            <tr>
              <th style="width:60px;">#</th>
              <th>Course Title</th>
              <th style="width:180px; text-align:center;">Number of Students</th>
              <th style="width:200px; text-align:center;">Action (View Details)</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i = 1;
          foreach ($courses as $c):
              $fullTitle = trim($c['program_code'] . ' – ' . $c['program_name']);
          ?>
            <tr>
              <td><?php echo $i++; ?></td>
              <td>
                <span class="course-title"><?php echo htmlspecialchars($fullTitle); ?></span>
              </td>
              <td style="text-align:center;">
                <span class="badge-count">
                  <?php echo (int)$c['student_count']; ?>
                </span>
              </td>
              <td style="text-align:center;">
                <a href="programs_students_view.php?program_id=<?php echo (int)$c['program_id']; ?>"
                   class="btn btn-view">
                  View Details
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
</div>
<!-- Add Course Modal -->
<div class="modal-backdrop" id="addCourseBackdrop" aria-hidden="true">
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addCourseTitle">
    <div class="modal-header">
      <h2 id="addCourseTitle">Add Course / Program</h2>
      <button type="button" class="modal-close" id="addCourseClose" aria-label="Close">
        &times;
      </button>
    </div>
          <form id="addCourseForm" class="modal-body">
  <p class="modal-text">
    Create a new course/program students can register under. You can add majors later.
  </p>

  <!-- 🔽 DROPDOWN: existing codes + create new -->
  <div class="form-row">
    <label for="program_picker">Course Code / Program</label>
    <select id="program_picker" name="program_picker">
      <option value="__new" selected>+ Create new program</option>
      <?php foreach ($courses as $c): ?>
        <option
          value="<?php echo (int)$c['program_id']; ?>"
          data-code="<?php echo htmlspecialchars($c['program_code'], ENT_QUOTES); ?>"
          data-title="<?php echo htmlspecialchars($c['program_name'], ENT_QUOTES); ?>"
        >
          <?php echo htmlspecialchars($c['program_code'] . ' – ' . $c['program_name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- hidden flags para alam ng backend kung new o existing -->
  <input type="hidden" id="course_mode" name="course_mode" value="new">
  <input type="hidden" id="existing_program_id" name="existing_program_id" value="">

  <div class="form-row">
    <label for="program_code">Course Code <span class="req">*</span></label>
    <input type="text" id="program_code" name="program_code"
           placeholder="e.g. BSED, BSIT"
           maxlength="20" required />
  </div>

  <div class="form-row">
    <label for="program_name">Course Title <span class="req">*</span></label>
    <input type="text" id="program_name" name="program_name"
           placeholder="e.g. Bachelor of Secondary Education"
           maxlength="191" required />
  </div>

  <!-- optional first major -->
  <div class="form-row">
    <label for="first_major">Major <span style="font-weight:400; font-size:12px; color:#6b7280;">(optional)</span></label>
    <input type="text" id="first_major" name="first_major"
           placeholder="e.g. Mathematics, English, Electronics" />
  </div>

  <div class="form-row">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="active" selected>Active</option>
      <option value="inactive">Inactive</option>
    </select>
  </div>

  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" id="addCourseCancel">Cancel</button>
    <button type="submit" class="btn btn-accent pill" id="addCourseSave">Save Course</button>
  </div>
</form>


  </div>
</div>

<script>
(function() {
  const btnOpen   = document.getElementById('btnAddCourse');
  const backdrop  = document.getElementById('addCourseBackdrop');
  const btnClose  = document.getElementById('addCourseClose');
  const btnCancel = document.getElementById('addCourseCancel');
  const form      = document.getElementById('addCourseForm');
  const btnSave   = document.getElementById('addCourseSave');

  // bagong refs para sa dropdown + inputs
  const picker          = document.getElementById('program_picker');
  const codeInput       = document.getElementById('program_code');
  const nameInput       = document.getElementById('program_name');
  const modeInput       = document.getElementById('course_mode');
  const existingIdInput = document.getElementById('existing_program_id');

  if (!btnOpen || !backdrop || !form) return;

  // 🔁 apply state base sa dropdown (new vs existing)
  function applyPickerState() {
    if (!picker || !codeInput || !nameInput || !modeInput || !existingIdInput) return;

    const v = picker.value;

    if (v === '__new') {
      // ➕ Create new program
      modeInput.value       = 'new';
      existingIdInput.value = '';

      codeInput.readOnly = false;
      nameInput.readOnly = false;

      codeInput.required = true;
      nameInput.required = true;

      // placeholders for new
      codeInput.placeholder = 'e.g. BSED, BSIT';
      nameInput.placeholder = 'e.g. Bachelor of Secondary Education';

      // optional: clear kapag balik sa new
      codeInput.value = '';
      nameInput.value = '';
    } else {
      // ✅ Existing program: auto-fill code + title
      modeInput.value       = 'existing';
      existingIdInput.value = v;

      const opt   = picker.options[picker.selectedIndex];
      const code  = opt.getAttribute('data-code')  || '';
      const title = opt.getAttribute('data-title') || '';

      codeInput.value = code;
      nameInput.value = title;

      // lock para di mabago master data
      codeInput.readOnly = true;
      nameInput.readOnly = true;

      // hindi na kailangang required (existing na)
      codeInput.required = false;
      nameInput.required = false;

      codeInput.placeholder = '';
      nameInput.placeholder = '';
    }
  }

  const openModal = () => {
    backdrop.classList.add('show');
    // reset form + balik sa "create new"
    if (form) form.reset();
    if (picker) picker.value = '__new';
    applyPickerState();

    if (codeInput && !codeInput.readOnly) {
      codeInput.focus();
    }
  };

  const closeModal = () => {
    backdrop.classList.remove('show');
    if (form) form.reset();
    if (picker) picker.value = '__new';
    applyPickerState();
  };

  btnOpen.addEventListener('click', openModal);
  btnClose.addEventListener('click', closeModal);
  btnCancel.addEventListener('click', closeModal);

  if (picker) {
    picker.addEventListener('change', applyPickerState);
  }
  // initial state
  applyPickerState();

  // close when clicking outside dialog
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) closeModal();
  });

  // submit handler
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    btnSave.disabled = true;

    const fd = new FormData(form);

    try {
      const res  = await fetch('ajax_add_program.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        closeModal();

        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: 'Course saved',
            text: data.message || 'The course/major has been saved.',
            confirmButtonColor: '#1e8fa2'
          }).then(() => {
            window.location.reload();
          });
        } else {
          alert(data.message || 'Saved.');
          window.location.reload();
        }
      } else {
        if (window.Swal) {
          Swal.fire({
            icon: 'error',
            title: 'Unable to save',
            text: data.message || 'Please check the form and try again.',
            confirmButtonColor: '#1e8fa2'
          });
        } else {
          alert(data.message || 'Unable to save.');
        }
      }
    } catch (err) {
      console.error(err);
      if (window.Swal) {
        Swal.fire({
          icon: 'error',
          title: 'Network error',
          text: 'Please try again.',
          confirmButtonColor: '#1e8fa2'
        });
      } else {
        alert('Network error. Please try again.');
      }
    } finally {
      btnSave.disabled = false;
    }
  });

})();
</script>


</body>
</html>

