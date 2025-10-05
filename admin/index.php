<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');
require_once __DIR__ . '/../db_connect.php';   // <-- add this so we can query

$PAGE_TITLE  = 'Admin Dashboard';
$ACTIVE_MENU = 'dashboard';

/* ---- Build data for chart: RB pass rate per color, last 30 days ---- */
$labels = [];
$rates  = [];
$passed = [];
$totals = [];

$sql = "
  SELECT
      L.level_id,
      L.name AS level_name,
      COALESCE(T.min_percent, 75) AS pass_min,
      COUNT(A.attempt_id) AS total_attempts,
      SUM(CASE WHEN A.percent >= COALESCE(T.min_percent, 75) THEN 1 ELSE 0 END) AS passed_attempts
  FROM sra_levels L
  LEFT JOIN assessment_attempts A
         ON A.level_id = L.level_id
        AND A.set_type = 'RB'
        AND A.status   = 'submitted'
        AND A.submitted_at >= (NOW() - INTERVAL 30 DAY)
  LEFT JOIN level_thresholds T
         ON T.level_id   = L.level_id
        AND T.applies_to = 'RB'
  WHERE LOWER(L.name) IN ('red','orange','yellow','blue','green','purple')
  GROUP BY L.level_id, L.name, pass_min
  ORDER BY L.order_rank, L.level_id
";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
  $labels[] = ucfirst(strtolower($row['level_name']));
  $t = (int)$row['total_attempts'];
  $p = (int)$row['passed_attempts'];
  $totals[] = $t;
  $passed[] = $p;
  $rates[]  = $t > 0 ? round(($p / $t) * 100, 1) : 0.0;
}
$res->free();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">

  <div class="banner">
    <img src="assets/picture2.jpg" alt="banner">
    <div class="quote">
      “The more that you read, the more things you will know. The more that you learn, the more places you’ll go.”<br>
      — Dr. Seuss
    </div>
  </div>

  <div class="chart-container" style="grid-column:1 / -1;">
    <h3>📊 RB Pass Rate by Color (last 30 days)</h3>
    <canvas id="adminChart" height="120"></canvas>
  </div>
</div>

<script>
(() => {
  const el = document.getElementById('adminChart');
  if (!el) return;

  const labels = <?= json_encode($labels) ?>;
  const dataPct = <?= json_encode($rates) ?>;
  const passed  = <?= json_encode($passed) ?>;
  const totals  = <?= json_encode($totals) ?>;

  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Pass rate (%)',
        data: dataPct
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        title: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => {
              const i = ctx.dataIndex;
              const pct = ctx.parsed.y ?? 0;
              return `${pct}%  (${passed[i]||0}/${totals[i]||0} passed)`;
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: 100,
          ticks: { callback: v => v + '%' }
        }
      }
    }
  });
})();
</script>

</body>
</html>
