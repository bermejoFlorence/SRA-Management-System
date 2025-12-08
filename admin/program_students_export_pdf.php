<?php
// admin/programs_students_export_pdf.php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ---- Load all active programs + student counts (same as page) ----
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
$courses = [];
$totalStudents = 0;

while ($row = $res->fetch_assoc()) {
    $row['student_count'] = (int)$row['student_count'];
    $totalStudents += $row['student_count'];
    $courses[] = $row;
}
$res->free();

// ---- Build HTML layout for PDF ----
$now = date('F d, Y h:i A');
$title = 'Course and Students Overview (SRA System)';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
  * {
    box-sizing: border-box;
    font-family: "Poppins", Arial, sans-serif;
  }
  body {
    margin: 20px;
    font-size: 11px;
    color: #111827;
  }
  h1 {
    font-size: 18px;
    margin: 0 0 4px;
    color: #064d00;
  }
  .subtitle {
    font-size: 10px;
    color: #4b5563;
    margin-bottom: 12px;
  }
  .meta {
    font-size: 9px;
    color: #6b7280;
    margin-bottom: 8px;
  }
  .summary-box {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    padding: 6px 10px;
    margin-bottom: 10px;
    background: #f9fafb;
    font-size: 10px;
  }
  .summary-box strong {
    color: #064d00;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 4px;
  }
  th, td {
    padding: 6px 8px;
    border: 1px solid #e5e7eb;
  }
  th {
    background: #f4f8f0;
    color: #064d00;
    font-size: 10px;
    text-align: left;
  }
  tbody tr:nth-child(even) {
    background: #fcfdf9;
  }
  .course-title {
    font-weight: 600;
    color: #0b3d0f;
    font-size: 10px;
  }
  .text-center {
    text-align: center;
  }
  .badge-count {
    display: inline-block;
    min-width: 28px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #e5f3da;
    color: #064d00;
    font-weight: 700;
    font-size: 9px;
  }
</style>
</head>
<body>

  <h1><?php echo htmlspecialchars($title); ?></h1>
  <p class="subtitle">
    Overview of all active courses/programs and the number of students registered in the SRA system.
  </p>
  <p class="meta">
    Generated on: <?php echo htmlspecialchars($now); ?>
  </p>

  <div class="summary-box">
    <strong>Total active courses:</strong> <?php echo count($courses); ?><br>
    <strong>Total registered students (all courses):</strong> <?php echo $totalStudents; ?>
  </div>

  <?php if (empty($courses)): ?>
    <p>No active courses found.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th>Course Title</th>
          <th style="width:120px;" class="text-center">Number of Students</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $i = 1;
      foreach ($courses as $c):
        $fullTitle = trim($c['program_code'] . ' – ' . $c['program_name']);
      ?>
        <tr>
          <td class="text-center"><?php echo $i++; ?></td>
          <td class="course-title">
            <?php echo htmlspecialchars($fullTitle); ?>
          </td>
          <td class="text-center">
            <span class="badge-count"><?php echo $c['student_count']; ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// ---- Generate & force download PDF via Dompdf ----
require_once __DIR__ . '/../vendor/autoload.php';

$options = new Options();
$options->set('isRemoteEnabled', true); // kung may external fonts/images ka
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');  // pwede mo gawing 'portrait' kung gusto mo
$dompdf->render();

$filename = 'SRA_Courses_Overview_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]); // ✅ auto-download
exit;
