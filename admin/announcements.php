<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Announcements';
$ACTIVE_MENU = 'announcements';

// ✳️ TEMP DATA LANG – papalitan natin ito ng real DB query mamaya
$announcements = [
    [
        'id'          => 1,
        'title'       => 'Starting Level Test Completion',
        'body'        => 'All students are required to complete the Starting Level Test on or before August 15, 2025. Log in early to avoid delays.',
        'created_at'  => '2025-07-20 09:15:00',
        'status'      => 'active',
    ],
    [
        'id'          => 2,
        'title'       => 'Power Builder Assessment Opening',
        'body'        => 'The Power Builder Assessment will open on August 20, 2025 for all eligible students who have completed their SLT stories.',
        'created_at'  => '2025-07-25 14:30:00',
        'status'      => 'scheduled',
    ],
    [
        'id'          => 3,
        'title'       => 'System Maintenance',
        'body'        => 'The SRA system will undergo maintenance on August 5, 2025 from 7:00 PM to 9:00 PM. Please plan your tests accordingly.',
        'created_at'  => '2025-07-18 16:05:00',
        'status'      => 'inactive',
    ],
];

require_once __DIR__ . '/includes/header.php';  // navbar + hamburger
require_once __DIR__ . '/includes/sidebar.php'; // sidebar
?>

<style>
/* ------- Main wrapper card (same feel as courses-card) ------- */
.ann-card{
  background:#ffffff;
  border-radius:24px;
  padding:20px 24px 24px;
  margin:16px 16px 32px;
  box-shadow:0 18px 45px rgba(0,0,0,0.08);
}

/* header area */
.ann-card-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}

.ann-card-header h1{
  margin:0;
  font-size:26px;
  font-weight:800;
  color:#064d00;
}

.ann-card-header .subtitle{
  margin:4px 0 0;
  font-size:14px;
  color:#4b5563;
}

/* Add Announcement button – white + green pill */
.header-actions .btn-add-ann{
  border-radius:999px;
  padding:10px 24px;
  border:1px solid rgba(6,77,0,0.22);
  background:#ffffff;
  color:#064d00;
  font-weight:700;
  font-size:14px;
  cursor:pointer;
  box-shadow:0 12px 30px rgba(6,77,0,0.12);
  transition:background 0.15s, transform 0.12s, box-shadow 0.12s;
}

.header-actions .btn-add-ann:hover{
  background:#e5f3da;
  transform:translateY(-1px);
  box-shadow:0 16px 32px rgba(6,77,0,0.18);
}

.header-actions .btn-add-ann:active{
  transform:translateY(0);
  box-shadow:0 8px 18px rgba(6,77,0,0.18);
}

/* ------- Table wrapper ------- */
.ann-table-wrapper{
  margin-top:4px;
}

.ann-table{
  width:100%;
  border-collapse:collapse;
  overflow:hidden;
  border-radius:18px;
}

.ann-table thead tr{
  background:#f4f8f0;
}

.ann-table th,
.ann-table td{
  padding:12px 18px;
  font-size:14px;
}

.ann-table th{
  text-align:left;
  font-weight:700;
  color:#064d00;
}

.ann-table tbody tr:nth-child(even){
  background:#fcfdf9;
}

.ann-table tbody tr:nth-child(odd){
  background:#ffffff;
}

.ann-table tbody tr:hover{
  background:#f1f5f9;
}

/* ------- Announcement detail cell ------- */
.ann-detail-title{
  font-weight:600;
  color:#0b3d0f;
  margin-bottom:2px;
}

.ann-detail-body{
  font-size:13px;
  color:#4b5563;
  max-width:650px;
  overflow:hidden;
  display:-webkit-box;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
}

/* date column */
.ann-date{
  font-size:13px;
  color:#374151;
}

/* status badge */
.ann-status-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:80px;
  padding:4px 12px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
}

.ann-status-active{
  background:#e5f3da;
  color:#065f46;
}

.ann-status-scheduled{
  background:#fef3c7;
  color:#92400e;
}

.ann-status-inactive{
  background:#e5e7eb;
  color:#374151;
}

/* action buttons */
.ann-actions{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
}

.btn-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:7px 14px;
  border-radius:999px;
  border:none;
  font-size:13px;
  font-weight:600;
  cursor:pointer;
  text-decoration:none;
  transition:filter 0.12s, transform 0.12s;
}

.btn-pill i{
  margin-right:6px;
  font-size:13px;
}

.btn-edit{
  background:#ffffff;
  border:1px solid #d1d5db;
  color:#064d00;
  box-shadow:0 6px 14px rgba(156,163,175,0.4);
}

.btn-edit:hover{
  filter:brightness(0.97);
  transform:translateY(-1px);
}

.btn-delete{
  background:linear-gradient(135deg,#f97373,#ef4444);
  color:#ffffff;
  box-shadow:0 10px 22px rgba(239,68,68,0.45);
}

.btn-delete:hover{
  filter:brightness(0.97);
  transform:translateY(-1px);
}

/* empty state */
.ann-empty{
  padding:28px 12px;
  text-align:center;
}

.ann-empty p{
  margin:0;
}

.ann-empty .hint{
  margin-top:6px;
  font-size:13px;
  color:#6b7280;
}

/* responsive tweaks */
@media (max-width:768px){
  .ann-card{
    margin:10px;
    padding:16px;
  }

  .ann-card-header{
    flex-direction:column;
    align-items:flex-start;
  }

  .ann-table th,
  .ann-table td{
    padding:10px 12px;
  }
}
</style>

<div class="main-content">
  <section class="card ann-card">
    <div class="ann-card-header">
      <div>
        <h1>Announcements Management</h1>
        <p class="subtitle">
          View and manage all announcements shown on the student dashboard.
        </p>
      </div>
      <div class="header-actions">
        <!-- later: open modal / go to create page -->
        <button type="button" class="btn-add-ann" id="btnAddAnnouncement">
          + Add Announcement
        </button>
      </div>
    </div>

    <div class="ann-table-wrapper">
      <?php if (empty($announcements)): ?>
        <div class="ann-empty">
          <p>No announcements created yet.</p>
          <p class="hint">Click <strong>Add Announcement</strong> to create your first message.</p>
        </div>
      <?php else: ?>
        <table class="ann-table">
          <thead>
            <tr>
              <th style="width:60px;">#</th>
              <th>Announcement Details</th>
              <th style="width:180px;">Date Created</th>
              <th style="width:140px;">Status</th>
              <th style="width:210px; text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i = 1;
          foreach ($announcements as $a):
              $created = date('M d, Y', strtotime($a['created_at']));
              $status  = strtolower($a['status']);
              $badgeClass = 'ann-status-inactive';
              $label      = 'Inactive';
              if ($status === 'active'){
                  $badgeClass = 'ann-status-active';
                  $label      = 'Active';
              } elseif ($status === 'scheduled'){
                  $badgeClass = 'ann-status-scheduled';
                  $label      = 'Scheduled';
              }
          ?>
            <tr>
              <td><?php echo $i++; ?></td>
              <td>
                <div class="ann-detail-title">
                  <?php echo htmlspecialchars($a['title']); ?>
                </div>
                <div class="ann-detail-body">
                  <?php echo htmlspecialchars($a['body']); ?>
                </div>
              </td>
              <td>
                <span class="ann-date"><?php echo $created; ?></span>
              </td>
              <td>
                <span class="ann-status-badge <?php echo $badgeClass; ?>">
                  <?php echo $label; ?>
                </span>
              </td>
              <td>
                <div class="ann-actions">
                  <!-- later: link to edit.php?id=... -->
                  <a href="#"
                     class="btn-pill btn-edit">
                    <i class="fas fa-pen"></i>Edit
                  </a>
                  <button type="button"
                          class="btn-pill btn-delete">
                    <i class="fas fa-trash-alt"></i>Delete
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
// placeholder lang muna – later lalagyan natin ng modal / redirect
document.getElementById('btnAddAnnouncement')?.addEventListener('click', () => {
  // example: redirect to a create page
  // window.location.href = 'announcements_create.php';
  console.log('Add Announcement clicked');
});
</script>

</body>
</html>
