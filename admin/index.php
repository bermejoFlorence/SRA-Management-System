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
function initials_from_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return '?';
    $parts = explode(' ', $name);
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = (count($parts) > 1) ? mb_substr($parts[count($parts)-1], 0, 1, 'UTF-8') : '';
    $ini = mb_strtoupper($first . $last, 'UTF-8');
    return $ini !== '' ? $ini : '?';
}
function safe_text($v, $fallback='—'): string {
    $v = trim((string)$v);
    return $v !== '' ? $v : $fallback;
}
function is_http_url($v): bool {
    $v = trim((string)$v);
    return (bool)preg_match('~^https?://~i', $v);
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
   1.5) TOP 3 Overall Performers (Overall %)
   - Weighted Overall % = SUM(best.total_score) / SUM(best.total_max) * 100
   - Best attempt per set_type (SLT/PB/RB) per student
   - Filter: School Year only
   NOTE: Requires MySQL 8+ (ROW_NUMBER window function)
--------------------------------------------------------- */
$top3Rows    = [];
$topLabels   = [];
$topPercents = [];
$topColors   = ['#d4af37', '#9e9e9e', '#cd7f32']; // gold, silver, bronze

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
  U.profile_photo,
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
GROUP BY U.user_id, U.first_name, U.middle_name, U.last_name, U.course, U.year_level, U.section, U.school_year, U.profile_photo
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

foreach ($top3Rows as $i => $r) {
    $nm = full_name($r);
    $topLabels[]   = $nm;
    $topPercents[] = (float)$r['overall_percent'];
    if (!isset($topColors[$i])) $topColors[$i] = '#064d00';
}

/* ---------------------------------------------------------
   2) Build data for chart: Student count per current level
--------------------------------------------------------- */
$badgeLabels = [];
$badgeCounts = [];
$badgeColors = [];

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

// mapping by level name kung walang color_hex sa DB
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

// fallback kung walang color_hex: simple color palette
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
  /* ====== Shared Chart Card ====== */
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
  .chart-help-text em{
    font-style:italic;
  }

  /* ====== NEW: Podium Player Cards (Top 3) ====== */
  .podium-wrap{
    display:flex;
    justify-content:center;
    gap: 1rem;
    align-items:flex-end;
    flex-wrap:wrap;
    margin-top: .25rem;
  }

  .player-card{
    position: relative;
    width: 280px;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(6,77,0,.15);
    box-shadow: 0 14px 28px rgba(0,0,0,.08);
    background: #0e3a10; /* base green */
    color: #fff;
  }

  .player-card .pc-bg{
    position:absolute;
    inset:0;
    background:
      radial-gradient(circle at 20% 15%, rgba(255,255,255,.10), transparent 35%),
      radial-gradient(circle at 80% 20%, rgba(255,255,255,.08), transparent 42%),
      linear-gradient(180deg, rgba(255,255,255,.08), rgba(0,0,0,.12));
    pointer-events:none;
  }

  .player-card .pc-top{
    position: relative;
    padding: 1rem 1rem .75rem;
    display:flex;
    align-items:center;
    gap:.85rem;
  }

  .rank-chip{
    position:absolute;
    top: .75rem;
    right: .75rem;
    font-weight: 900;
    font-size: .95rem;
    letter-spacing: .5px;
    padding: .25rem .55rem;
    border-radius: 999px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
  }

  .avatar{
    width: 66px;
    height: 66px;
    border-radius: 999px;
    overflow:hidden;
    border: 3px solid rgba(255,255,255,.25);
    box-shadow: 0 10px 20px rgba(0,0,0,.20);
    background: rgba(255,255,255,.10);
    display:flex;
    align-items:center;
    justify-content:center;
    flex: 0 0 auto;
  }
  .avatar img{
    width: 100%;
    height: 100%;
    object-fit: cover;
    display:block;
  }
  .avatar .ini{
    font-weight: 900;
    font-size: 1.15rem;
    letter-spacing: .5px;
  }

  .pc-name{
    font-weight: 900;
    font-size: 1.02rem;
    line-height: 1.15;
    margin: 0;
    max-width: 170px;
    overflow:hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .pc-meta{
    font-size: .82rem;
    opacity: .92;
    line-height: 1.2;
    margin-top: .15rem;
  }

  .pc-bottom{
    position: relative;
    padding: .75rem 1rem 1rem;
    background: rgba(255,255,255,.06);
    border-top: 1px solid rgba(255,255,255,.12);
    display:flex;
    justify-content:space-between;
    gap: .75rem;
    align-items:flex-end;
  }

  .stat-label{
    font-size: .72rem;
    opacity: .90;
    letter-spacing: .4px;
    text-transform: uppercase;
  }

  .stat-value{
    font-size: 1.65rem;
    font-weight: 1000;
    line-height: 1;
  }

  .stat-value small{
    font-size: .95rem;
    font-weight: 900;
  }

  /* highlight by rank */
  .player-card.rank1{ transform: translateY(-10px); }
  .player-card.rank1 .rank-chip{ background: rgba(212,175,55,.22); border-color: rgba(212,175,55,.35); }
  .player-card.rank2 .rank-chip{ background: rgba(158,158,158,.18); border-color: rgba(158,158,158,.30); }
  .player-card.rank3 .rank-chip{ background: rgba(205,127,50,.20); border-color: rgba(205,127,50,.34); }

  .player-card.rank1{ border-color: rgba(212,175,55,.35); }
  .player-card.rank2{ border-color: rgba(158,158,158,.30); }
  .player-card.rank3{ border-color: rgba(205,127,50,.34); }

  .pc-divider{
    height: 1px;
    width: 100%;
    background: rgba(255,255,255,.14);
    margin-top: .2rem;
  }

  /* ====== Existing Badge Chart Wrapper ====== */
  .chart-wrapper{
    position: relative;
    width: 100%;
    height: 340px;
  }

  @media (max-width: 992px){
    .player-card{ width: 260px; }
    .pc-name{ max-width: 150px; }
  }

  @media (max-width: 576px){
    .chart-card{
      padding: 1rem 1.1rem;
    }
    .chart-wrapper{
      height: 280px;
    }
    .podium-wrap{
      gap: .85rem;
    }
    .player-card{
      width: 100%;
      max-width: 420px;
    }
    .pc-name{
      white-space: normal;
      max-width: none;
    }
    .player-card.rank1{ transform: none; }
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

  <!-- ================== TOP 3 OVERALL PERFORMERS (PODIUM) ================== -->
  <div class="chart-card">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h3>
        <span class="icon-badge"><i class="fa-solid fa-trophy"></i></span>
        Top Overall Performers (Overall %)
      </h3>
    </div>

    <div class="chart-header-line"></div>

    <!-- Filter: School Year only. Preserve program_id so badge filter won't reset -->
    <form method="get" class="chart-filters mb-2">
      <input type="hidden" name="program_id" value="<?= (int)$selectedProgram ?>">
      <div class="filter-inline">
        <span class="filter-label">School Year</span>
        <select name="sy" class="form-select form-select-sm">
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
      based on <em>best SLT + best PB + best RB</em> attempts
      <?= $selectedSy ? '— filtered by <strong>SY ' . h($selectedSy) . '</strong>.' : '— for <strong>all school years</strong>.' ?>
    </p>

    <?php if (empty($top3Rows)): ?>
      <div class="alert alert-light border" style="border-radius:12px;">
        No top performers found<?= $selectedSy ? ' for SY ' . h($selectedSy) : '' ?>.
      </div>
    <?php else: ?>
      <?php
        // Arrange like podium: [2nd, 1st, 3rd]
        $podium = [];
        if (isset($top3Rows[1])) $podium[] = ['rank' => 2, 'row' => $top3Rows[1]];
        if (isset($top3Rows[0])) $podium[] = ['rank' => 1, 'row' => $top3Rows[0]];
        if (isset($top3Rows[2])) $podium[] = ['rank' => 3, 'row' => $top3Rows[2]];
      ?>

      <div class="podium-wrap">
        <?php foreach ($podium as $p): 
          $rank = (int)$p['rank'];
          $r    = $p['row'];
          $nm   = full_name($r);
          $ini  = initials_from_name($nm);

          $course = safe_text($r['course'] ?? '');
          $yl     = safe_text($r['year_level'] ?? '');
          $sec    = safe_text($r['section'] ?? '');
          $pct    = number_format((float)$r['overall_percent'], 2);

          $photo  = trim((string)($r['profile_photo'] ?? ''));
          $hasImg = ($photo !== '' && is_http_url($photo));
        ?>
          <div class="player-card rank<?= $rank ?>" data-rank="<?= $rank ?>">
            <div class="pc-bg"></div>

            <div class="pc-top">
              <div class="rank-chip"><?= str_pad((string)$rank, 2, '0', STR_PAD_LEFT) ?></div>

              <div class="avatar" data-initials="<?= h($ini) ?>">
                <?php if ($hasImg): ?>
                  <img
                    src="<?= h($photo) ?>"
                    alt="<?= h($nm) ?>"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    onerror="this.closest('.avatar').innerHTML='<span class=&quot;ini&quot;><?= h($ini) ?></span>';"
                  >
                <?php else: ?>
                  <span class="ini"><?= h($ini) ?></span>
                <?php endif; ?>
              </div>

              <div style="min-width:0;">
                <p class="pc-name" title="<?= h($nm) ?>"><?= h($nm) ?></p>
                <div class="pc-meta"><strong>Course:</strong> <?= h($course) ?></div>
                <div class="pc-meta"><strong>Year/Section:</strong> <?= h($yl) ?><?= ($yl !== '—' && $sec !== '—') ? ' - ' : '' ?><?= h($sec) ?></div>
                <div class="pc-divider"></div>
              </div>
            </div>

            <div class="pc-bottom">
              <div>
                <div class="stat-label">Overall Percentage</div>
                <div class="stat-value"><?= $pct ?><small>%</small></div>
              </div>
              <div style="text-align:right;">
                <div class="stat-label">Rank</div>
                <div class="stat-value"><?= $rank ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

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

      <div class="filter-inline">
        <span class="filter-label">School Year</span>
        <select name="sy" id="sy" class="form-select form-select-sm">
          <option value="">All School Years</option>
          <?php foreach ($syOptions as $sy): ?>
            <option value="<?= h($sy) ?>"
              <?= $sy === $selectedSy ? 'selected' : '' ?>>
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
        <button type="submit" class="btn btn-sm btn-success">
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
        <?= $selectedSy ? '<strong>SY ' . h($selectedSy) . '</strong>' : '' ?>
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
