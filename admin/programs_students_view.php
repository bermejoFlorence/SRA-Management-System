<?php
// admin/programs_students_view.php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Programs and Students';
$ACTIVE_MENU = 'prog_students';

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ---- Inputs ----
$program_id = (int)($_GET['program_id'] ?? 0);
if ($program_id <= 0) {
    header('Location: programs_students.php');
    exit;
}

$year_filter   = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int)$_GET['year_level'] : null;
$section_filter = trim($_GET['section'] ?? '');
$search_query   = trim($_GET['q'] ?? '');

// ---- Load program info ----
$stmt = $conn->prepare("SELECT program_code, program_name FROM sra_programs WHERE program_id = ?");
$stmt->bind_param('i', $program_id);
$stmt->execute();
$stmt->bind_result($program_code, $program_name);
if (!$stmt->fetch()) {
    $stmt->close();
    header('Location: programs_students.php');
    exit;
}
$stmt->close();

$course_title = trim($program_code . ' – ' . $program_name);

// ---- Load filter options (year levels & sections for this program) ----
$year_levels = [];
$res = $conn->prepare("
    SELECT DISTINCT year_level 
    FROM users 
    WHERE role = 'student' AND program_id = ? 
      AND year_level IS NOT NULL
    ORDER BY year_level ASC
");
$res->bind_param('i', $program_id);
$res->execute();
$out = $res->get_result();
while ($row = $out->fetch_assoc()) {
    $year_levels[] = (int)$row['year_level'];
}
$res->close();

$sections = [];
$res2 = $conn->prepare("
    SELECT DISTINCT section 
    FROM users 
    WHERE role = 'student' AND program_id = ? 
      AND section <> '' 
    ORDER BY section ASC
");
$res2->bind_param('i', $program_id);
$res2->execute();
$out2 = $res2->get_result();
while ($row = $out2->fetch_assoc()) {
    $sections[] = $row['section'];
}
$res2->close();

// ---- Subqueries for best percent per test type ----
$sub_slt = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'SLT' AND status = 'scored'
    GROUP BY student_id
";
$sub_pb = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'PB' AND status = 'scored'
    GROUP BY student_id
";
$sub_rb = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'RB' AND status = 'scored'
    GROUP BY student_id
";

// ---- Main students query with dynamic filters ----
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
      slt.best_percent AS slt_percent,
      pb.best_percent  AS pb_percent,
      rb.best_percent  AS rb_percent
    FROM users u
    LEFT JOIN ($sub_slt) slt ON slt.student_id = u.user_id
    LEFT JOIN ($sub_pb) pb  ON pb.student_id  = u.user_id
    LEFT JOIN ($sub_rb) rb  ON rb.student_id  = u.user_id
    WHERE u.role = 'student'
      AND u.program_id = ?
";

$params = [$program_id];
$types  = 'i';

if ($year_filter !== null) {
    $sql   .= " AND u.year_level = ? ";
    $params[] = $year_filter;
    $types   .= 'i';
}

if ($section_filter !== '') {
    $sql   .= " AND u.section = ? ";
    $params[] = $section_filter;
    $types   .= 's';
}

if ($search_query !== '') {
    $like = '%' . $search_query . '%';
    $sql .= " AND (
        u.student_id_no LIKE ?
        OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
        OR CONCAT(u.last_name,', ',u.first_name) LIKE ?
    ) ";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$sql .= " ORDER BY u.last_name ASC, u.first_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
.course-header-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin: 16px 16px 10px;
}

.course-header-bar h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  color: #064d00;
}

.course-header-bar .course-subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: #4b5563;
}

.course-header-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
}

.course-header-filters select,
.course-header-filters input[type="text"] {
  border-radius: 999px;
  border: 1px solid #d1d5db;
  padding: 6px 12px;
  font-size: 13px;
  min-width: 120px;
}

.course-header-filters button,
.course-header-filters .btn-export {
  border-radius: 999px;
  padding: 7px 14px;
  font-size: 13px;
  font-weight: 600;
  border: none;
  cursor: pointer;
}

.course-header-filters .btn-filter {
  background: #064d00;
  color: #ffffff;
}

.course-header-filters .btn-filter:hover {
  filter: brightness(1.05);
}

.course-header-filters .btn-export {
  background: #ffffff;
  border: 1px solid #d1d5db;
  color: #111827;
}

/* card + table */
.course-students-card {
  background: #ffffff;
  border-radius: 24px;
  margin: 0 16px 24px;
  padding: 16px 18px 22px;
  box-shadow: 0 18px 45px rgba(0,0,0,0.08);
}

.course-students-table {
  width: 100%;
  border-collapse: collapse;
  border-radius: 18px;
  overflow: hidden;
  margin-top: 10px;
}
.course-students-table th,
.course-students-table td {
  padding: 10px 12px;
  font-size: 13px;
  vertical-align: middle;
}

/* mas makapal ang header font */
.course-students-table th {
  color: #064d00;
  font-weight: 800;
  letter-spacing: 0.02em;
}

/* medyo makapal din ang body text */
.course-students-table td {
  font-weight: 500;
}


.course-students-table thead tr:first-child {
  background: #f4f8f0;
}

.course-students-table thead tr:nth-child(2) {
  background: #ecf4e6;
}

.course-students-table th {
  color: #064d00;
  font-weight: 700;
  text-align: left;
}

.course-students-table th.center,
.course-students-table td.center {
  text-align: center;
}

.course-students-table tbody tr:nth-child(even) {
  background: #fcfdf9;
}
.course-students-table tbody tr:nth-child(odd) {
  background: #ffffff;
}
.course-students-table tbody tr:hover {
  background: #f1f5f9;
}

.badge-test {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 54px;
  padding: 4px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}

.badge-test.ok {
  background: #e5f3da;
  color: #065f46;
}
.badge-test.mid {
  background: #fef3c7;
  color: #92400e;
}
.badge-test.low {
  background: #fee2e2;
  color: #b91c1c;
}
.badge-test.none {
  background: #e5e7eb;
  color: #4b5563;
}

.btn-view-detail {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 6px 14px;
  border-radius: 999px;
  background: linear-gradient(135deg, #f5a425, #f6c445);
  border: none;
  text-decoration: none;
  font-size: 13px;
  font-weight: 700;
  color: #1f2327;
  box-shadow: 0 8px 20px rgba(245, 164, 37, 0.4);
}

.btn-view-detail:hover {
  filter: brightness(0.96);
}

@media (max-width: 900px) {
  .course-header-bar {
    flex-direction: column;
    align-items: flex-start;
    margin: 12px 10px 6px;
  }
  .course-students-card {
    margin: 0 10px 18px;
    padding: 14px 12px 18px;
  }
  .course-students-table th,
  .course-students-table td {
    padding: 8px 8px;
    font-size: 12px;
  }
}
</style>

<div class="main-content">

  <!-- Top bar: course title + filters -->
  <div class="course-header-bar">
    <div>
      <h1><?php echo htmlspecialchars($course_title); ?></h1>
      <p class="course-subtitle">
        Students enrolled in this course and their SRA assessment results.
      </p>
    </div>

    <form class="course-header-filters" method="get" action="programs_students_view.php">
      <input type="hidden" name="program_id" value="<?php echo (int)$program_id; ?>"/>

      <select name="year_level">
        <option value="">All Year Levels</option>
        <?php foreach ($year_levels as $yl): ?>
          <option value="<?php echo $yl; ?>" <?php echo ($year_filter === $yl ? 'selected' : ''); ?>>
            Year <?php echo $yl; ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="section">
        <option value="">All Sections</option>
        <?php foreach ($sections as $sec): ?>
          <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo ($section_filter === $sec ? 'selected' : ''); ?>>
            <?php echo htmlspecialchars($sec); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" placeholder="Search student..." value="<?php echo htmlspecialchars($search_query); ?>"/>

      <button type="submit" class="btn-filter">Apply</button>

      <!-- Placeholder export link (backend to be implemented later) -->
      <button type="button" class="btn-export" onclick="alert('Export PDF not implemented yet.')">
        Export PDF Results
      </button>
    </form>
  </div>

  <!-- Card with table -->
  <section class="course-students-card">
    <?php if (empty($students)): ?>
      <p style="margin:8px 4px; font-size:14px; color:#4b5563;">
        No students found for this course with the current filters.
      </p>
    <?php else: ?>
      <table class="course-students-table">
       <thead>
  <tr>
    <th rowspan="2" class="center">#</th>
    <th rowspan="2" class="center">Student ID</th>
    <th rowspan="2">Name of Student</th>
    <th rowspan="2" class="center">Year Level &amp; Section</th>
    <th class="center" colspan="3">Test Results</th>
    <th rowspan="2" class="center">Actions</th>
  </tr>
  <tr>
    <th class="center">Starting Level</th>
    <th class="center">Power Builder</th>
    <th class="center">Rate Builder</th>
  </tr>
</thead>

        <tbody>
        <?php
        $i = 1;
        foreach ($students as $s):
            $full_name = trim(
                $s['last_name'] . ', ' .
                $s['first_name'] .
                ($s['middle_name'] ? ' ' . mb_substr($s['middle_name'],0,1) . '.' : '') .
                ($s['ext_name'] ? ' ' . $s['ext_name'] : '')
            );
            $yl_sec = 'Year ' . (int)$s['year_level'] . ' – ' . htmlspecialchars((string)$s['section']);

            $slt = $s['slt_percent'];
            $pb  = $s['pb_percent'];
            $rb  = $s['rb_percent'];

            $badgeClass = function($v) {
                if ($v === null) return 'none';
                if ($v >= 80)   return 'ok';
                if ($v >= 60)   return 'mid';
                return 'low';
            };
        ?>
          <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars($s['student_id_no']); ?></td>
            <td><?php echo htmlspecialchars($full_name); ?></td>
            <td class="center"><?php echo $yl_sec; ?></td>

            <td class="center">
              <?php if ($slt !== null): ?>
                <span class="badge-test <?php echo $badgeClass($slt); ?>">
                  <?php echo (float)$slt; ?>%
                </span>
              <?php else: ?>
                <span class="badge-test none">Not taken</span>
              <?php endif; ?>
            </td>

            <td class="center">
              <?php if ($pb !== null): ?>
                <span class="badge-test <?php echo $badgeClass($pb); ?>">
                  <?php echo (float)$pb; ?>%
                </span>
              <?php else: ?>
                <span class="badge-test none">Not taken</span>
              <?php endif; ?>
            </td>

            <td class="center">
              <?php if ($rb !== null): ?>
                <span class="badge-test <?php echo $badgeClass($rb); ?>">
                  <?php echo (float)$rb; ?>%
                </span>
              <?php else: ?>
                <span class="badge-test none">Not taken</span>
              <?php endif; ?>
            </td>

            <td class="center">
              <a href="student_profile.php?user_id=<?php echo (int)$s['user_id']; ?>"
                 class="btn-view-detail">
                View full details
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

</body>
</html>
