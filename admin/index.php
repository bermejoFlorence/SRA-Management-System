<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';

$PAGE_TITLE  = 'Admin Dashboard';
$ACTIVE_MENU = 'dashboard';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
    @$conn->query("SET time_zone = '+08:00'");
}

/* ---------------------------------------------------------
   1) Filters: School Year & Program
--------------------------------------------------------- */
$selectedSy      = isset($_GET['sy']) ? trim($_GET['sy']) : '';
$selectedProgram = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

/* ---- Load School Year options (distinct from users) ---- */
$syOptions = [];
$syRes = $conn->query("
    SELECT DISTINCT school_year
    FROM users
    WHERE role = 'student'
      AND school_year IS NOT NULL
      AND school_year <> ''
    ORDER BY school_year DESC
");
while ($row = $syRes->fetch_assoc()) {
    $syOptions[] = $row['school_year'];
}
$syRes->free();

/* ---- Load Program options ---- */
$programs = [];
$progRes = $conn->query("
    SELECT program_id, program_code, program_name
    FROM sra_programs
    WHERE status = 'active'
    ORDER BY program_code ASC, program_name ASC
");
while ($row = $progRes->fetch_assoc()) {
    $programs[] = $row;
}
$progRes->free();

/* ---------------------------------------------------------
   2) Build data for chart: Student count per current level
--------------------------------------------------------- */
/*
    - Base table: sra_levels (para lahat ng colors lumabas)
    - Join student_level (is_current = 1)
    - Join users (role = 'student', plus optional filters)
    - Count: DISTINCT U.user_id per level
*/

$badgeLabels = [];
$badgeCounts = [];
$badgeColors = [];   // optional kung gusto mong gamitin color_hex

// Build dynamic JOIN para sa users (with filters)
$joinUser = "
    LEFT JOIN users U
           ON U.user_id = SL.student_id
          AND U.role = 'student'
";

$params = [];
$types  = '';

if ($selectedSy !== '') {
    $joinUser .= " AND U.school_year = ?";
    $params[] = $selectedSy;
    $types   .= 's';
}

if ($selectedProgram > 0) {
    $joinUser .= " AND U.program_id = ?";
    $params[] = $selectedProgram;
    $types   .= 'i';
}

$sql = "
    SELECT 
        L.level_id,
        L.name      AS level_name,
        L.color_hex AS color_hex,
        COUNT(DISTINCT U.user_id) AS total_students
    FROM sra_levels L
    LEFT JOIN student_level SL
           ON SL.level_id   = L.level_id
          AND SL.is_current = 1
    $joinUser
    GROUP BY L.level_id, L.name, L.color_hex
    ORDER BY L.order_rank, L.level_id
";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $badgeLabels[] = ucfirst(strtolower($row['level_name']));
    $badgeCounts[] = (int)$row['total_students'];

    // Gamitin yung color_hex kung meron, kung wala mag-default color na lang later
    $badgeColors[] = !empty($row['color_hex']) ? $row['color_hex'] : null;
}
$res->free();
$stmt->close();

// fallback kung walang color_hex: simple color palette
$defaultPalette = ['#00bcd4', '#2196f3', '#e91e63', '#9c27b0', '#673ab7', '#ff9800', '#f44336'];
if (!array_filter($badgeColors)) {
    // kung lahat null, gamitin palette
    $badgeColors = [];
    for ($i = 0; $i < count($badgeLabels); $i++) {
        $badgeColors[] = $defaultPalette[$i % count($defaultPalette)];
    }
} else {
    // palitan lang yung null entries ng palette
    foreach ($badgeColors as $i => $hex) {
        if (!$hex) {
            $badgeColors[$i] = $defaultPalette[$i % count($defaultPalette)];
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
  /* ====== Student Badge Chart Card ====== */
  .chart-card{
    border-radius: 16px;
    border: 1px solid #e3e7e3;
    box-shadow: 0 10px 28px rgba(0,0,0,.06);
    background: #fff;
    padding: 1.25rem 1.5rem;
    margin-top: 1.25rem;
  }

  .chart-card h3{
    font-size: 1.2rem;
    font-weight: 700;
    color: #064d00;
    margin: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
  }

  .chart-card h3 .icon-badge{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    background: #064d00;
    color: #fff;
    font-size: .9rem;
  }

  .chart-header-line{
    height: 2px;
    width: 100%;
    background: #064d00;
    margin: .75rem 0 1rem;
    opacity: .7;
  }

  .chart-filters{
    display: flex;
    flex-wrap: wrap;
    gap: .75rem 1rem;
    align-items: flex-end;
  }

  .chart-filters .form-group{
    min-width: 160px;
  }

  .chart-filters label{
    font-size: .8rem;
    font-weight: 600;
    color: #444;
    margin-bottom: 2px;
  }

  .chart-filters .form-select,
  .chart-filters .btn{
    font-size: .8rem;
  }

  .chart-help-text{
    font-size: .8rem;
    color: #666;
    margin-top: .5rem;
    margin-bottom: .75rem;
  }

  .chart-help-text strong{
    font-weight: 700;
  }

  .chart-wrapper{
    position: relative;
    width: 100%;
    height: 340px;      /* fixed canvas height pero responsive width */
  }

  @media (max-width: 576px){
    .chart-card{
      padding: 1rem 1.1rem;
    }
    .chart-wrapper{
      height: 280px;
    }
  }
</style>

<div class="main-content">

  <div class="banner">
    <img src="assets/picture2.jpg" alt="banner">
    <div class="quote">
      “The more that you read, the more things you will know. The more that you learn, the more places you’ll go.”<br>
      — Dr. Seuss
    </div>
  </div>
  <!-- ================== STUDENT BADGE CHART CARD ================== -->
  <div class="chart-card">

    <!-- Title row -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h3>
        <span class="icon-badge">
          <i class="fa-solid fa-award"></i>
        </span>
        Student Badge Chart
      </h3>
    </div>

    <div class="chart-header-line"></div>

    <!-- Filters -->
    <form method="get" class="chart-filters mb-2">
      <div class="form-group">
        <label for="sy">School Year</label>
        <select name="sy" id="sy" class="form-select form-select-sm">
          <option value="">All School Years</option>
          <?php foreach ($syOptions as $sy): ?>
            <option value="<?= htmlspecialchars($sy) ?>"
              <?= $sy === $selectedSy ? 'selected' : '' ?>>
              <?= htmlspecialchars($sy) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="program_id">Program</label>
        <select name="program_id" id="program_id" class="form-select form-select-sm">
          <option value="0">All Programs</option>
          <?php foreach ($programs as $prog): ?>
            <option value="<?= (int)$prog['program_id'] ?>"
              <?= ((int)$prog['program_id'] === $selectedProgram) ? 'selected' : '' ?>>
              <?= htmlspecialchars($prog['program_code'] . ' — ' . $prog['program_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button type="submit" class="btn btn-sm btn-success px-3">
          Apply
        </button>
      </div>
    </form>

    <!-- Small description text -->
    <p class="chart-help-text">
      Showing students with a <strong>current level/badge</strong>
      (based on SLT / assigned level)
      <?php if ($selectedSy || $selectedProgram): ?>
        — filtered by
        <?= $selectedSy ? '<strong>SY ' . htmlspecialchars($selectedSy) . '</strong>' : '' ?>
        <?= ($selectedSy && $selectedProgram ? ' · ' : '') ?>
        <?= $selectedProgram ? '<strong>selected program</strong>' : '' ?>.
      <?php else: ?>
        — for <strong>all school years</strong> and <strong>all programs</strong>.
      <?php endif; ?>
    </p>

    <!-- Chart canvas -->
    <div class="chart-wrapper">
      <canvas id="badgeChart"></canvas>
    </div>
  </div>
</div> <!-- end .main-content -->

</div>

<script>
(() => {
  const el = document.getElementById('badgeChart');
  if (!el) return;

  const labels  = <?= json_encode($badgeLabels) ?>;
  const counts  = <?= json_encode($badgeCounts) ?>;
  const colors  = <?= json_encode($badgeColors) ?>;

  if (!labels.length) {
    // optional: you can show a message using plain JS/HTML if walang data.
    return;
  }
new Chart(el.getContext('2d'), {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [{
      label: 'Number of students',
      data: counts,
      backgroundColor: colors,
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,   // <--- ADD THIS
    plugins: {
      legend: { display: false },
      title:  { display: false },
      tooltip: {
        callbacks: {
          label: ctx => {
            const value = ctx.parsed.y ?? 0;
            return `Students: ${value}`;
          }
        }
      }
    },
    scales: {
      x: {
        title: {
          display: true,
          text: 'Level / Badge Color'
        }
      },
      y: {
        beginAtZero: true,
        title: {
          display: true,
          text: 'Number of Students'
        },
        ticks: {
          precision: 0
        }
      }
    }
  }
});

})();
</script>

</body>
</html>
