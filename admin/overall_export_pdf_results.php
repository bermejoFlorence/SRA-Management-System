<?php
// admin/overall_export_pdf_results.php
// Export PDF: ONLY students who finished SLT, PB, RB (all have percent)
// Grouped visually per course

require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// dompdf
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/* =========================
   SUBQUERIES: best percent
========================= */

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

/* =========================
   MAIN QUERY:
   - students only
   - must have SLT, PB, RB (all non-NULL)
========================= */

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
      u.status,
      p.program_code,
      p.program_name,
      m.major_name,
      slt.best_percent AS slt_percent,
      pb.best_percent  AS pb_percent,
      rb.best_percent  AS rb_percent
    FROM users u
    LEFT JOIN sra_programs p 
      ON p.program_id = u.program_id
    LEFT JOIN sra_majors m
      ON m.major_id = u.major_id
    LEFT JOIN ($sub_slt) slt ON slt.student_id = u.user_id
    LEFT JOIN ($sub_pb)  pb  ON pb.student_id  = u.user_id
    LEFT JOIN ($sub_rb)  rb  ON rb.student_id  = u.user_id
    WHERE u.role = 'student'
      AND slt.best_percent IS NOT NULL
      AND pb.best_percent  IS NOT NULL
      AND rb.best_percent  IS NOT NULL
      -- optional: kung gusto mo ACTIVE lang
      -- AND u.status = 'active'
";

$sql .= "
    ORDER BY 
      p.program_code ASC,
      m.major_name ASC,
      u.year_level ASC,
      u.section ASC,
      u.last_name ASC,
      u.first_name ASC
";

$res = $conn->query($sql);
$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
}
$res->free();

/* =========================
   LOGOS (same style as program export)
========================= */

$logo1Path = realpath(__DIR__ . '/1.png');
$logo2Path = realpath(__DIR__ . '/2.png');
$logo3Path = realpath(__DIR__ . '/3.png');

$logo1Data = ($logo1Path && file_exists($logo1Path)) ? base64_encode(file_get_contents($logo1Path)) : null;
$logo2Data = ($logo2Path && file_exists($logo2Path)) ? base64_encode(file_get_contents($logo2Path)) : null;
$logo3Data = ($logo3Path && file_exists($logo3Path)) ? base64_encode(file_get_contents($logo3Path)) : null;

$today  = date('F d, Y h:i A');
$title  = 'Overall Students Assessment Results (Finished SLT, PB & RB)';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?php echo htmlspecialchars($title); ?></title>
  <style>
    @page {
      margin: 100px 20px 30px 20px; /* top, right, bottom, left */
    }

    body {
      font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
      font-size: 9px;
      color: #111827;
    }

    /* Fixed header (repeats each page) */
    #page-header {
      position: fixed;
      top: -90px;
      left: 0;
      right: 0;
      height: 90px;
    }

    .header-table {
      width: 100%;
      border-collapse: collapse;
    }
    .header-table td {
      vertical-align: middle;
      font-size: 8px;
      border: none;
      padding: 2px 6px;
    }

    .header-left,
    .header-right {
      width: 140px;
      text-align: center;
      white-space: nowrap;
    }
    .header-left img,
    .header-right img {
      height: 40px;
      display: inline-block;
      margin: 0 4px;
    }

    .header-center {
      text-align: center;
      font-size: 9px;
      line-height: 1.25;
    }

    .header-center .line1 {
      font-weight: 600;
      font-size: 8px;
    }
    .header-center .line2 {
      font-weight: 800;
      font-size: 10px;
    }
    .header-center .line3 {
      font-size: 8px;
    }
    .header-center .line4 {
      font-size: 8px;
      font-style: italic;
    }

    .header-hr {
      border: 0;
      border-top: 1px solid #9ca3af;
      margin-top: 3px;
    }

    h1 {
      font-size: 13px;
      margin: 0 0 2px;
      text-align: center;
      font-weight: 800;
    }
    .subtitle {
      font-size: 9px;
      text-align: center;
      margin: 0 0 8px;
    }

    .meta {
      font-size: 8px;
      text-align: right;
      margin-bottom: 4px;
      width: 96%;
      margin-left: auto;
      margin-right: auto;
    }

    table {
      width: 96%;
      border-collapse: collapse;
      margin-top: 4px;
      margin-left: auto;
      margin-right: auto;
    }
    th, td {
      border: 1px solid #9ca3af;
      padding: 3px 4px;
      font-size: 8px;
    }
    th {
      background: #e5f3da;
      font-weight: bold;
      text-align: center;
    }
    td.center {
      text-align: center;
    }
    td.right {
      text-align: right;
    }

    .course-text {
      font-weight: 600;
      color: #064d00;
    }

  </style>
</head>
<body>

<!-- REPEATING HEADER -->
<div id="page-header">
  <table class="header-table">
    <tr>
      <td class="header-left">
        <?php if ($logo1Data): ?>
          <img src="data:image/png;base64,<?php echo $logo1Data; ?>" alt="">
        <?php endif; ?>
        <?php if ($logo2Data): ?>
          <img src="data:image/png;base64,<?php echo $logo2Data; ?>" alt="">
        <?php endif; ?>
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
        <?php if ($logo3Data): ?>
          <img src="data:image/png;base64,<?php echo $logo3Data; ?>" alt="">
        <?php endif; ?>
      </td>
    </tr>
  </table>
  <hr class="header-hr" />
</div>

<!-- MAIN CONTENT -->
<div id="content">
  <h1><?php echo htmlspecialchars($title); ?></h1>
  <p class="subtitle">
    Includes only students who have completed all three assessments: Starting Level Test (SLT),
    Power Builder (PB), and Rate Builder (RB).
  </p>

  <div class="meta">
    Generated on: <?php echo htmlspecialchars($today); ?><br>
    Total students in this report: <?php echo count($rows); ?>
  </div>

  <?php if (empty($rows)): ?>
    <p style="width:96%;margin:0 auto;">No students found with completed SLT, PB, and RB.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th style="width:25px;">#</th>
          <th style="width:65px;">Student ID</th>
          <th style="width:140px;">Name of Student</th>
          <th style="width:130px;">Course</th>
          <th style="width:100px;">Major</th>
          <th style="width:70px;">Year &amp; Section</th>
          <th style="width:55px;">SLT</th>
          <th style="width:55px;">PB</th>
          <th style="width:55px;">RB</th>
        </tr>
      </thead>
       <tbody>
      <?php
      $i = 1;
      $fmt = function($v) {
          return $v !== null ? number_format($v, 2) . '%' : '—';
      };

      foreach ($rows as $s):
          $full_name = trim(
              $s['last_name'] . ', ' . $s['first_name'] .
              ($s['middle_name'] ? ' ' . mb_substr($s['middle_name'],0,1) . '.' : '') .
              ($s['ext_name'] ? ' ' . $s['ext_name'] : '')
          );

          // Course (code + name)
          $course = trim(($s['program_code'] ?? '') . ' – ' . ($s['program_name'] ?? ''));
          if ($course === '–') {
              $course = 'No Program';
          }

          // Major
          $major = $s['major_name'] ?: '—';

          // Year & Section
          $yl  = $s['year_level'] ? ('Year ' . (int)$s['year_level']) : '—';
          $sec = $s['section'] !== '' ? $s['section'] : '—';
          $ys  = $yl . ' - ' . $sec;

          $slt = $s['slt_percent'];
          $pb  = $s['pb_percent'];
          $rb  = $s['rb_percent'];
      ?>
        <tr>
          <td class="center"><?php echo $i++; ?></td>
          <td class="center"><?php echo htmlspecialchars($s['student_id_no']); ?></td>
          <td><?php echo htmlspecialchars($full_name); ?></td>
          <td class="course-text"><?php echo htmlspecialchars($course); ?></td>
          <td><?php echo htmlspecialchars($major); ?></td>
          <td class="center"><?php echo htmlspecialchars($ys); ?></td>
          <td class="center"><?php echo $fmt($slt); ?></td>
          <td class="center"><?php echo $fmt($pb); ?></td>
          <td class="center"><?php echo $fmt($rb); ?></td>
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

/* =========================
   GENERATE LANDSCAPE PDF
========================= */

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');   // ✅ naka-landscape

$filename = 'SRA_Overall_Students_Finished_SLT_PB_RB_' . date('Ymd_His') . '.pdf';
$dompdf->render();
$dompdf->stream($filename, ['Attachment' => true]); // ✅ auto-download
exit;
