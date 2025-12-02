<?php
// admin/programs_students_export_pdf.php
// Export PDF of students in a program (filtered), PASSED ONLY (RB >= 75%)

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// dompdf
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// ---- Inputs (same as view) ----
$program_id = (int)($_GET['program_id'] ?? 0);
if ($program_id <= 0) {
    die('Invalid program.');
}

$year_filter    = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int)$_GET['year_level'] : null;
$section_filter = trim($_GET['section'] ?? '');
$search_query   = trim($_GET['q'] ?? '');

// ---- Load program info ----
$stmt = $conn->prepare("SELECT program_code, program_name FROM sra_programs WHERE program_id = ?");
$stmt->bind_param('i', $program_id);
$stmt->execute();
$stmt->bind_result($program_code, $program_name);
if (!$stmt->fetch()) {
    $stmt->close();
    die('Program not found.');
}
$stmt->close();

$course_title = trim($program_code . ' – ' . $program_name);

// ---- Subqueries for best percent per test type ----
$sub_slt = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'SLT'
      AND percent IS NOT NULL
      AND status <> 'invalidated'
    GROUP BY student_id
";

$sub_pb = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'PB'
      AND percent IS NOT NULL
      AND status <> 'invalidated'
    GROUP BY student_id
";

$sub_rb = "
    SELECT student_id, MAX(percent) AS best_percent
    FROM assessment_attempts
    WHERE set_type = 'RB'
      AND percent IS NOT NULL
      AND status <> 'invalidated'
    GROUP BY student_id
";

// ---- Main query (same base as view, pero PASSED ONLY) ----
// Rule: passed kung RB best_percent >= 75 (pwede mong baguhin itong threshold)
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
      AND rb.best_percent IS NOT NULL
      AND rb.best_percent >= 75
";

$params = [$program_id];
$types  = 'i';

if ($year_filter !== null) {
    $sql      .= " AND u.year_level = ? ";
    $params[]  = $year_filter;
    $types    .= 'i';
}

if ($section_filter !== '') {
    $sql      .= " AND u.section = ? ";
    $params[]  = $section_filter;
    $types    .= 's';
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

$sql .= " ORDER BY u.year_level ASC, u.section ASC, u.last_name ASC, u.first_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

// ====================
// Build HTML w/ header
// ====================
$today = date('F d, Y h:i A');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?php echo htmlspecialchars($course_title); ?> – Passed Students</title>
  <style>
    @page {
      margin: 140px 30px 30px 30px; /* top, right, bottom, left */
    }

    body {
      font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
      font-size: 11px;
      color: #111827;
    }

    /* Fixed header (repeats each page) */
    #page-header {
      position: fixed;
      top: -120px;     /* just above body margin-top */
      left: 0;
      right: 0;
      height: 110px;
    }

    .header-table {
      width: 100%;
      border-collapse: collapse;
    }

    .header-table td {
      vertical-align: middle;
      font-size: 9px;
    }

    .header-left {
      width: 160px;
    }
    .header-left img {
      display: block;
      margin-bottom: 2px;
    }

    .header-center {
      text-align: center;
      font-size: 10px;
      line-height: 1.2;
    }

    .header-center .line1 {
      font-weight: 600;
      font-size: 9px;
    }
    .header-center .line2 {
      font-weight: 800;
      font-size: 11px;
    }
    .header-center .line3 {
      font-size: 9px;
    }
    .header-center .line4 {
      font-size: 9px;
      font-style: italic;
    }

    .header-right {
      width: 120px;
      text-align: right;
    }
    .header-right img {
      max-height: 60px;
    }

    .header-hr {
      border: 0;
      border-top: 1px solid #9ca3af;
      margin-top: 4px;
    }

    h1 {
      font-size: 14px;
      margin: 0 0 2px;
      text-align: center;
      font-weight: 800;
    }
    h2 {
      font-size: 11px;
      margin: 0 0 10px;
      text-align: center;
      font-weight: 700;
    }

    .meta {
      font-size: 9px;
      text-align: right;
      margin-bottom: 6px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 4px;
    }
    th, td {
      border: 1px solid #9ca3af;
      padding: 4px 6px;
      font-size: 10px;
    }
    th {
      background: #e5f3da;
      font-weight: bold;
      text-align: center;
    }
    td.center {
      text-align: center;
    }
  </style>
</head>
<body>

<!-- REPEATING HEADER -->
<div id="page-header">
  <table class="header-table">
    <tr>
      <td class="header-left">
        <img src="../1.png" alt="Logo 1" style="height:40px;">
        <img src="../2.png" alt="Logo 2" style="height:35px;">
      </td>
      <td class="header-center">
        <div class="line1">Republic of the Philippines</div>
        <div class="line2">CENTRAL BICOL STATE UNIVERSITY OF AGRICULTURE</div>
        <div class="line3">Sipocot, Camarines Sur 4418</div>
        <div class="line4">
          Website: www.cbsua.edu.ph<br/>
          Email Address: cbsua.sipocot@cbsua.edu.ph<br/>
          Landline: (054) 881-6681
        </div>
      </td>
      <td class="header-right">
        <img src="../3.png" alt="Certification Logo">
      </td>
    </tr>
  </table>
  <hr class="header-hr" />
</div>

<!-- MAIN CONTENT -->
<div id="content">
  <h1><?php echo htmlspecialchars($course_title); ?></h1>
  <h2>List of Passed Students (Rate Builder ≥ 75%)</h2>

  <div class="meta">
    Generated on: <?php echo htmlspecialchars($today); ?>
  </div>

  <?php if (empty($rows)): ?>
    <p>No passed students found for this course with the current filters.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th style="width:25px;">#</th>
          <th style="width:80px;">Student ID</th>
          <th>Name of Student</th>
          <th style="width:90px;">Year &amp; Section</th>
          <th style="width:65px;">SLT</th>
          <th style="width:65px;">PB</th>
          <th style="width:65px;">RB</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $i = 1;
      foreach ($rows as $s):
          $full_name = trim(
              $s['last_name'] . ', ' . $s['first_name'] .
              ($s['middle_name'] ? ' ' . mb_substr($s['middle_name'],0,1) . '.' : '') .
              ($s['ext_name'] ? ' ' . $s['ext_name'] : '')
          );
          $ys = 'Year ' . (int)$s['year_level'] . ' – ' . (string)$s['section'];

          $fmt = function($v) {
              return $v !== null ? number_format($v, 2) . '%' : '—';
          };
      ?>
        <tr>
          <td class="center"><?php echo $i++; ?></td>
          <td class="center"><?php echo htmlspecialchars($s['student_id_no']); ?></td>
          <td><?php echo htmlspecialchars($full_name); ?></td>
          <td class="center"><?php echo htmlspecialchars($ys); ?></td>
          <td class="center"><?php echo $fmt($s['slt_percent']); ?></td>
          <td class="center"><?php echo $fmt($s['pb_percent']); ?></td>
          <td class="center"><?php echo $fmt($s['rb_percent']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ====================
// Generate PDF
// ====================
$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');

// important: para ma-resolve nang tama ang ../1.png, ../2.png, ../3.png
$dompdf->setBasePath(realpath(__DIR__ . '/..'));

$dompdf->render();

$filename = preg_replace('/\s+/', '_', $program_code . '_Passed_Students_' . date('Ymd_His')) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
