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
   Helpers
--------------------------------------------------------- */
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
function full_name(array $u): string {
    $fn = trim((string)($u['first_name'] ?? ''));
    $mn = trim((string)($u['middle_name'] ?? ''));
    $ln = trim((string)($u['last_name'] ?? ''));
    $name = trim($fn . ' ' . ($mn !== '' ? ($mn . ' ') : '') . $ln);
    return $name !== '' ? $name : 'Unknown Student';
}
function safe_text($v, $fallback='—'): string {
    $v = trim((string)$v);
    return $v !== '' ? $v : $fallback;
}

/* ---------------------------------------------------------
   1) Filters: School Year & Program (existing)
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

/* ---- Load Program options (existing) ---- */
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
   2) NEW: Top 3 Overall Performers (Overall %)
   - Overall % is weighted: SUM(score) / SUM(max) * 100
   - Use BEST attempt per set_type (SLT/PB/RB) per student
   - Filter: School Year only
   - MySQL 8+ required (ROW_NUMBER window function)
--------------------------------------------------------- */
$top3Rows   = [];
$topLabels  = [];
$topPercents= [];
$topColors  = ['#d4af37', '#9e9e9e', '#cd7f32']; // gold, silver, bronze (simple)

$topParams = [];
$topTypes  = '';

$topSql = "
WITH ranked AS (
  SELECT
    A.attempt_id,
    A.student_id,
    A.set_type,
    A.total_score,
    A.total_max,
    A.percent,
    A.submitted_at,
    ROW_NUMBER() OVER (
      PARTITION BY A.student_id, A.set_type
      ORDER BY A.percent DESC, A.submitted_at DESC, A.attempt_id DESC
    ) AS rn
  FROM assessment_attempts A
  WHERE A.status IN ('submitted','scored')
),
best AS (
  SELECT * FROM ranked WHERE rn = 1
)
SELECT
  U.user_id,
  U.first_name, U.middle_name, U.last_name,
  U.course,
  U.year_level,
  U.section,
  U.school_year,
  SUM(best.total_score) AS sum_score,
  SUM(best.total_max)   AS sum_max,
  ROUND(
    CASE WHEN SUM(best.total_max) > 0
         THEN (SUM(best.total_score) / SUM(best.total_max)) * 100
         ELSE 0
    END
  , 2) AS overall_percent
FROM best
JOIN users U
  ON U.user_id = best.student_id
 AND U.role = 'student'
";

if ($selectedSy !== '') {
    $topSql .= " WHERE U.school_year = ? ";
    $topParams[] = $selectedSy;
    $topTypes   .= 's';
}

$topSql .= "
GROUP BY U.user_id, U.first_name, U.middle_name, U.last_name, U.course, U.year_level, U.section, U.school_year
HAVING SUM(best.total_max) > 0
ORDER BY overall_percent DESC, sum_score DESC, U.user_id ASC
LIMIT 3
";

$topStmt = $conn->prepare($topSql);
if (!empty($topParams)) {
    $topStmt->bind_param($topTypes, ...$topParams);
}
$topStmt->execute();
$topRes = $topStmt->get_result();
while ($r = $topRes->fetch_assoc()) {
    $top3Rows[] = $r;
}
$topRes->free();
$topStmt->close();

// Build chart arrays
foreach ($top3Rows as $i => $r) {
    $nm = full_name($r);
    $topLabels[]   = $nm;
    $topPercents[] = (float)$r['overall_percent'];
    // ensure color exists even if fewer than 3
    if (!isset($topColors[$i])) $topColors[$i] = '#064d00';
}

/* ---------------------------------------------------------
   3) Existing: Student count per current level (Badge Chart)
--------------------------------------------------------- */
$badgeLabels = [];
$badgeCounts = [];
$badgeColors = [];

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

$nameColorMap = [
    'green'  => '#4caf50',
    'blue'   => '#2196f3',
    'brown'  => '#795548',
    'orange' => '#ff9800',
    'red'    => '#f44336',
    'aqua'   => '#00bcd4',
    'gold'   => '#ffd600',
    'purple' => '#9c27b0',
    'yellow' => '#ffeb3b',
];

while ($row = $res->fetch_assoc()) {
    $levelName  = strtolower($row['level_name']);
    $badgeLabels[] = ucfirst($levelName);
    $badgeCounts[] = (int)$row['total_students'];

    if (!empty($row['color_hex'])) {
        $badgeColors[] = $row['color_hex'];
    } elseif (isset($nameColorMap[$levelName])) {
        $badgeColors[] = $nameColorMap[$levelName];
    } else {
        $badgeColors[] = null;
    }
}

$res->free();
$stmt->close();

$defaultPalette = ['#00bcd4', '#2196f3', '#e91e63', '#9c27b0', '#673ab7', '#ff9800', '#f44336'];
if (!array_filter($badgeColors)) {
    $badgeColors = [];
    for ($i = 0; $i < count($badgeLabels); $i++) {
        $badgeColors[] = $defaultPalette[$i % count($defaultPalette)];
    }
} else {
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
  /* ====== Shared Card Style ====== */
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

  /* ====== Filters (reused) ====== */
  .chart-filters{
    display:flex;
    flex-wrap:wrap;
    gap:.75rem 1rem;
    align-items:center;
    margin-bottom:.25rem;
  }
  .chart-filters .filter-inline{
    display:flex;
    align-items:center;
    gap:.4rem;
  }
  .chart-filters .filter-label{
    font-size:.85rem;
    font-weight:700;
    color:#1c3c1f;
    white-space:nowrap;
  }
  .chart-filters .form-select{
    font-size:.85rem;
    padding-block:.25rem;
  }
  .chart-filters .btn{
    font-size:.85rem;
    font-weight:600;
    padding:.3rem .9rem;
  }
  .chart-help-text{
    font-size:.8rem;
    color:#555;
    margin-top:.2rem;
    margin-bottom:.75rem;
  }
  .chart-help-text em{ font-style:italic; }

  /* ====== Existing Badge Chart Wrapper ====== */
  .chart-wrapper{
    position: relative;
    width: 100%;
    height: 340px;
  }

  /* ====== NEW: Top 3 Layout ====== */
  .top3-layout{
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 1rem;
    align-items: stretch;
  }
  .top3-podium{
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: .75rem;
  }
  .podium-card{
    border: 1px solid #e7eee7;
    border-radius: 14px;
    padding: .9rem .9rem;
    background: #fff;
    box-shadow: 0 10px 22px rgba(0,0,0,.05);
    min-height: 140px;
    display: flex;
    flex-direction: column;
    gap: .35rem;
  }
  .podium-rank{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
  }
  .rank-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width: 30px;
    height: 30px;
    border-radius: 999px;
    color: #fff;
    font-weight: 800;
    font-size: .9rem;
  }
  .rank-1{ background:#d4af37; }
  .rank-2{ background:#9e9e9e; }
  .rank-3{ background:#cd7f32; }

  .podium-name{
    font-weight: 800;
    color: #0f2f10;
    font-size: .95rem;
    line-height: 1.15;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .podium-meta{
    font-size: .82rem;
    color: #4b5a4b;
    line-height: 1.2;
  }
  .podium-score{
    margin-top: auto;
    font-weight: 900;
    color: #064d00;
    font-size: 1.55rem;
    letter-spacing: .2px;
  }
  .podium-score small{
    font-size: .9rem;
    font-weight: 800;
  }

  .top3-chart-wrap{
    position: relative;
    width: 100%;
    height: 260px;
  }

  /* Responsive adjustments */
  @media (max-width: 992px){
    .top3-layout{
      grid-template-columns: 1fr;
    }
    .top3-chart-wrap{
      height: 240px;
    }
  }
  @media (max-width: 576px){
    .chart-card{ padding: 1rem 1.1rem; }
    .chart-wrapper{ height: 280px; }
    .top3-podium{
      grid-template-columns: 1fr;
    }
    .podium-card{ min-height: 125px; }
    .top3-chart-wrap{ height: 220px; }
    .podium-name{ white-space: normal; }
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

  <!-- ================== NEW: TOP 3 OVERALL PERFORMERS CARD ================== -->
  <div class="chart-card">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h3>
        <span class="icon-badge"><i class="fa-solid fa-trophy"></i></span>
        Top Overall Performers (Overall %)
      </h3>
    </div>

    <div class="chart-header-line"></div>

    <!-- Filters: School Year only (preserve program_id to avoid resetting badge chart filter) -->
    <form method="get" class="chart-filters mb-2">
      <input type="hidden" name="program_id" value="<?= (int)$selectedProgram ?>">
      <div class="filter-inline">
        <span class="filter-label">School Year</span>
        <select name="sy" id="sy_top" class="form-select form-select-sm">
          <option value="">All School Years</option>
          <?php foreach ($syOptions as $sy): ?>
            <option value="<?= h($sy) ?>" <?= $sy === $selectedSy ? 'selected' : '' ?>>
              <?= h($sy) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-inline">
        <button type="submit" class="btn btn-sm btn-success">Apply</button>
      </div>
    </form>

    <p class="chart-help-text">
      Top 3 students by <strong>weighted overall percentage</strong>
      across <em>best SLT + best PB + best RB</em> attempts
      <?= $selectedSy ? '— filtered by <strong>SY ' . h($selectedSy) . '</strong>.' : '— for <strong>all school years</strong>.' ?>
    </p>

    <?php if (empty($top3Rows)): ?>
      <div class="alert alert-light border" style="border-radius:12px;">
        No top performers found<?= $selectedSy ? ' for SY ' . h($selectedSy) : '' ?>.
      </div>
    <?php else: ?>
      <div class="top3-layout">
        <!-- Podium -->
        <div class="top3-podium">
          <?php foreach ($top3Rows as $i => $r): 
            $rank = $i + 1;
            $nm   = full_name($r);
            $course = safe_text($r['course'] ?? '');
            $yl   = safe_text($r['year_level'] ?? '');
            $sec  = safe_text($r['section'] ?? '');
            $pct  = number_format((float)$r['overall_percent'], 2);
          ?>
            <div class="podium-card">
              <div class="podium-rank">
                <span class="rank-badge rank-<?= $rank ?>"><?= $rank ?></span>
                <span class="podium-meta"><?= $rank === 1 ? 'Champion' : ($rank === 2 ? '2nd Place' : '3rd Place') ?></span>
              </div>

              <div class="podium-name" title="<?= h($nm) ?>"><?= h($nm) ?></div>
              <div class="podium-meta"><strong>Course:</strong> <?= h($course) ?></div>
              <div class="podium-meta"><strong>Year/Section:</strong> <?= h($yl) ?><?= $yl !== '—' && $sec !== '—' ? ' - ' : '' ?><?= h($sec) ?></div>

              <div class="podium-score"><?= $pct ?><small>%</small></div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Chart -->
        <div>
          <div class="top3-chart-wrap">
            <canvas id="top3Chart"></canvas>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>

  <!-- ================== STUDENT BADGE CHART CARD (existing) ================== -->
  <div class="chart-card">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h3>
        <span class="icon-badge"><i class="fa-solid fa-award"></i></span>
        Student Badge Chart
      </h3>
    </div>

    <div class="chart-header-line"></div>

    <form method="get" class="chart-filters mb-2">

      <div class="filter-inline">
        <span class="filter-label">School Year</span>
        <select name="sy" id="sy" class="form-select form-select-sm">
          <option value="">All School Years</option>
          <?php foreach ($syOptions as $sy): ?>
            <option value="<?= h($sy) ?>" <?= $sy === $selectedSy ? 'selected' : '' ?>>
              <?= h($sy) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-inline">
        <span class="filter-label">Program</span>
        <select name="program_id" id="program_id" class="form-select form-select-sm">
          <option value="0">All Programs</option>
          <?php foreach ($programs as $prog): ?>
            <option value="<?= (int)$prog['program_id'] ?>"
              <?= ((int)$prog['program_id'] === $selectedProgram) ? 'selected' : '' ?>>
              <?= h($prog['program_code'] . ' — ' . $prog['program_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-inline">
        <button type="submit" class="btn btn-sm btn-success">Apply</button>
      </div>
    </form>

    <p class="chart-help-text">
      Showing students with a <strong>current level/badge</strong> (based on SLT / assigned level)
      <?php if ($selectedSy || $selectedProgram): ?>
        — filtered by
        <?= $selectedSy ? '<strong>SY ' . h($selectedSy) . '</strong>' : '' ?>
        <?= ($selectedSy && $selectedProgram ? ' · ' : '') ?>
        <?= $selectedProgram ? '<strong>selected program</strong>' : '' ?>.
      <?php else: ?>
        — for <strong>all school years</strong> and <strong>all programs</strong>.
      <?php endif; ?>
    </p>

    <div class="chart-wrapper">
      <canvas id="badgeChart"></canvas>
    </div>
  </div>
</div> <!-- end .main-content -->

</div>

<script>
(() => {
  // ===== Top 3 chart (horizontal bar) =====
  const topEl = document.getElementById('top3Chart');
  if (topEl) {
    const topLabels   = <?= json_encode($topLabels) ?>;
    const topPercents = <?= json_encode($topPercents) ?>;
    const topColors   = <?= json_encode($topColors) ?>;

    if (topLabels.length) {
      new Chart(topEl.getContext('2d'), {
        type: 'bar',
        data: {
          labels: topLabels,
          datasets: [{
            label: 'Overall %',
            data: topPercents,
            backgroundColor: topColors,
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const v = ctx.parsed.x ?? 0;
                  return `Overall: ${Number(v).toFixed(2)}%`;
                }
              }
            }
          },
          scales: {
            x: {
              beginAtZero: true,
              max: 100,
              title: { display: true, text: 'Overall Percentage (%)' },
              ticks: { callback: (v) => v + '%' }
            },
            y: {
              title: { display: true, text: 'Student' }
            }
          }
        }
      });
    }
  }

  // ===== Existing badge chart =====
  const el = document.getElementById('badgeChart');
  if (!el) return;

  const labels  = <?= json_encode($badgeLabels) ?>;
  const counts  = <?= json_encode($badgeCounts) ?>;
  const colors  = <?= json_encode($badgeColors) ?>;

  if (!labels.length) return;

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
      maintainAspectRatio: false,
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
        x: { title: { display: true, text: 'Level / Badge Color' } },
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Number of Students' },
          ticks: { precision: 0 }
        }
      }
    }
  });
})();
</script>

</body>
</html>
