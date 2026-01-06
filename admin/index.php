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
   1.5) TOP 3 OVERALL PERFORMERS (STRICT)
   - STRICT: must have best SLT + best PB + best RB
   - Weighted Overall % = SUM(score)/SUM(max) * 100 from those 3 best attempts
   NOTE: Requires MySQL 8+ (ROW_NUMBER)
--------------------------------------------------------- */
$top3Rows = [];

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
),
best3 AS (
  SELECT
    student_id,
    SUM(total_score) AS sum_score,
    SUM(total_max)   AS sum_max,
    COUNT(DISTINCT set_type) AS types_count
  FROM best
  WHERE set_type IN ('SLT','PB','RB')
  GROUP BY student_id
)
SELECT
  U.user_id,
  U.first_name, U.middle_name, U.last_name,
  U.course,
  U.year_level,
  U.section,
  U.school_year,
  U.profile_photo,
  b3.sum_score,
  b3.sum_max,
  ROUND(
    CASE WHEN b3.sum_max > 0
         THEN (b3.sum_score / b3.sum_max) * 100
         ELSE 0
    END
  , 2) AS overall_percent
FROM best3 b3
JOIN users U
  ON U.user_id = b3.student_id
 AND U.role = 'student'
WHERE b3.types_count = 3
";

if ($selectedSy !== '') {
    $topSql .= " AND U.school_year = ? ";
    $topParams[] = $selectedSy;
    $topTypes   .= 's';
}

$topSql .= "
ORDER BY overall_percent DESC, b3.sum_score DESC, U.user_id ASC
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

/* ---------------------------------------------------------
   2) Build data for chart: Student count per current level
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
  /* ====== Shared Card ====== */
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
    font-weight: 800;
    color: #064d00;
    margin: 0;
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  .chart-card h3 .icon-badge{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
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
    opacity: .65;
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
    font-weight:800;
    color:#1c3c1f;
    white-space:nowrap;
  }

  .chart-filters .form-select{
    font-size:.85rem;
    padding-block:.25rem;
  }

  .chart-filters .btn{
    font-size:.85rem;
    font-weight:700;
    padding:.3rem .9rem;
  }

  .chart-help-text{
    font-size:.82rem;
    color:#555;
    margin-top:.2rem;
    margin-bottom:.85rem;
  }
  .chart-help-text em{ font-style:italic; }

  /* ====== TOP 3 (Clean Podium) ====== */
  .top3-grid{
    display:grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    align-items: stretch;
  }

  .topcard{
    border-radius: 16px;
    border: 1px solid #e6ebe6;
    background: #ffffff;
    box-shadow: 0 10px 24px rgba(0,0,0,.06);
    overflow:hidden;
    position: relative;
  }

  .topcard::before{
    content:"";
    position:absolute;
    left:0; right:0; top:0;
    height: 5px;
    background: #0b6a00;
    opacity: .85;
  }

  .topcard.rank1{
    transform: translateY(-8px);
    border-color: rgba(212,175,55,.35);
  }
  .topcard.rank1::before{ background: #d4af37; }

  .topcard.rank2{ border-color: rgba(158,158,158,.30); }
  .topcard.rank2::before{ background: #9e9e9e; }

  .topcard.rank3{ border-color: rgba(205,127,50,.32); }
  .topcard.rank3::before{ background: #cd7f32; }

  .topcard-body{
    padding: 1rem 1rem .9rem;
    display:flex;
    gap:.85rem;
    align-items:flex-start;
  }

  .avatar-wrap{
    width: 64px;
    height: 64px;
    border-radius: 999px;
    border: 2px solid rgba(6,77,0,.18);
    background: #f3f6f3;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    flex: 0 0 auto;
  }
  .avatar-wrap img{
    width:100%;
    height:100%;
    object-fit: cover;
    display:block;
  }
  .avatar-wrap .fa-user{
    font-size: 1.4rem;
    color: rgba(6,77,0,.55);
  }

  .top-meta{
    min-width:0;
    flex: 1 1 auto;
  }

  .top-name{
    font-weight: 900;
    color: #0f2f10;
    margin: 0;
    font-size: 1.02rem;
    line-height: 1.15;
    overflow:hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .top-sub{
    margin-top:.25rem;
    font-size:.84rem;
    color:#4b5a4b;
    line-height: 1.25;
  }
  .top-sub b{ color:#1d2d1d; }

  .rank-pill{
    position:absolute;
    right: .9rem;
    top: .8rem;
    font-weight: 900;
    font-size: .9rem;
    color: #0f2f10;
    background: rgba(0,0,0,.04);
    border: 1px solid rgba(0,0,0,.06);
    border-radius: 999px;
    padding: .2rem .55rem;
  }

  .topcard.rank1 .rank-pill{ background: rgba(212,175,55,.18); border-color: rgba(212,175,55,.25); }
  .topcard.rank2 .rank-pill{ background: rgba(158,158,158,.15); border-color: rgba(158,158,158,.22); }
  .topcard.rank3 .rank-pill{ background: rgba(205,127,50,.16); border-color: rgba(205,127,50,.23); }

  .topcard-footer{
    border-top: 1px solid #edf2ed;
    padding: .85rem 1rem 1rem;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap: .75rem;
    background: #fbfcfb;
  }

  .stat-title{
    font-size:.72rem;
    letter-spacing:.4px;
    text-transform: uppercase;
    font-weight: 800;
    color:#567156;
  }

  .stat-value{
    font-size: 1.65rem;
    font-weight: 1000;
    color:#064d00;
    line-height: 1;
  }

  .stat-value small{
    font-size: .95rem;
    font-weight: 900;
  }

  /* arrange podium order visually: 2 - 1 - 3 */
  .topcard.pos2{ order: 1; }
  .topcard.pos1{ order: 2; }
  .topcard.pos3{ order: 3; }

  /* ====== Badge Chart ====== */
  .chart-wrapper{
    position: relative;
    width: 100%;
    height: 340px;
  }

  @media (max-width: 992px){
    .top3-grid{
      grid-template-columns: 1fr;
    }
    .topcard.rank1{ transform:none; }
    .top-name{ white-space: normal; }
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

  <!-- ================== TOP 3 OVERALL PERFORMERS (STRICT) ================== -->
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
      STRICT mode: overall score is computed only for students with <strong>completed SLT, PB, and RB</strong> (best attempt per test).
      <?= $selectedSy ? '— filtered by <strong>SY ' . h($selectedSy) . '</strong>.' : '— for <strong>all school years</strong>.' ?>
    </p>

    <?php if (empty($top3Rows)): ?>
      <div class="alert alert-light border" style="border-radius:12px;">
        No top performers yet<?= $selectedSy ? ' for SY ' . h($selectedSy) : '' ?>.
        Ensure students have completed <strong>SLT + PB + RB</strong> to generate overall scores.
      </div>
    <?php else: ?>
      <?php
        // Arrange podium: 2nd, 1st, 3rd
        $ordered = [];
        if (isset($top3Rows[1])) $ordered[] = ['rank'=>2, 'pos'=>2, 'row'=>$top3Rows[1]];
        if (isset($top3Rows[0])) $ordered[] = ['rank'=>1, 'pos'=>1, 'row'=>$top3Rows[0]];
        if (isset($top3Rows[2])) $ordered[] = ['rank'=>3, 'pos'=>3, 'row'=>$top3Rows[2]];
      ?>

      <div class="top3-grid">
        <?php foreach ($ordered as $it):
          $rank = (int)$it['rank'];
          $pos  = (int)$it['pos'];
          $r    = $it['row'];

          $nm     = full_name($r);
          $course = safe_text($r['course'] ?? '');
          $yl     = safe_text($r['year_level'] ?? '');
          $sec    = safe_text($r['section'] ?? '');
          $pct    = number_format((float)$r['overall_percent'], 2);

          $photo  = trim((string)($r['profile_photo'] ?? ''));
          $hasImg = ($photo !== '' && is_http_url($photo));
        ?>
          <div class="topcard rank<?= $rank ?> pos<?= $pos ?>">
            <div class="rank-pill"><?= str_pad((string)$rank, 2, '0', STR_PAD_LEFT) ?></div>

            <div class="topcard-body">
              <div class="avatar-wrap">
                <?php if ($hasImg): ?>
                  <img
                    src="<?= h($photo) ?>"
                    alt="<?= h($nm) ?>"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    onerror="this.outerHTML='<i class=&quot;fa-solid fa-user&quot;></i>';"
                  >
                <?php else: ?>
                  <i class="fa-solid fa-user"></i>
                <?php endif; ?>
              </div>

              <div class="top-meta">
                <p class="top-name" title="<?= h($nm) ?>"><?= h($nm) ?></p>
                <div class="top-sub"><b>Course:</b> <?= h($course) ?></div>
                <div class="top-sub"><b>Year/Section:</b> <?= h($yl) ?><?= ($yl !== '—' && $sec !== '—') ? ' - ' : '' ?><?= h($sec) ?></div>
              </div>
            </div>

            <div class="topcard-footer">
              <div>
                <div class="stat-title">Overall Percentage</div>
                <div class="stat-value"><?= $pct ?><small>%</small></div>
              </div>
              <div style="text-align:right;">
                <div class="stat-title">Rank</div>
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
        x: {
          title: { display: true, text: 'Level / Badge Color' }
        },
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
