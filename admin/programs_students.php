<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Programs and Students';
$ACTIVE_MENU = 'prog_students';

require_once __DIR__ . '/includes/header.php';  // navbar + hamburger + backdrop + JS
require_once __DIR__ . '/includes/sidebar.php'; // sidebar (with id="sidebar")

// --- Load courses/programs with student count ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (isset($conn) && $conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

$courses = [];
if (isset($conn) && $conn instanceof mysqli) {
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
}
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
        <button type="button" class="btn btn-accent" id="btnAddCourse">
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
</body>
</html>
