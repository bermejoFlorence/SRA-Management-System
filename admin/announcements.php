<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', '../login.php#login');

$PAGE_TITLE  = 'Announcements';
$ACTIVE_MENU = 'announcements';

require_once __DIR__ . '/../db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

/* ---------- Load announcements from DB ---------- */
$announcements = [];
$sql = "
  SELECT
      announcement_id,
      title,
      body,
      audience,
      priority,
      status,
      start_date,
      end_date,
      created_at
  FROM sra_announcements
  ORDER BY created_at DESC
";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $announcements[] = $row;
}
$res->free();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
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

/* ---------- ADD/EDIT ANNOUNCEMENT MODAL ---------- */

.modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,0.45);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:900;
  padding:16px;
}

.modal-backdrop.show{
  display:flex;
}

.modal-dialog{
  background:#ffffff;
  border-radius:20px;
  max-width:520px;
  width:100%;
  box-shadow:0 20px 50px rgba(15,23,42,0.35);
  animation:modalFadeIn 0.18s ease-out;
}

@keyframes modalFadeIn{
  from{ opacity:0; transform:translateY(12px) scale(0.97); }
  to{ opacity:1; transform:translateY(0) scale(1); }
}

.modal-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:14px 18px 10px;
  border-bottom:1px solid #e5e7eb;
}

.modal-header h2{
  margin:0;
  font-size:18px;
  font-weight:700;
  color:#064d00;
}

.modal-close{
  background:transparent;
  border:none;
  font-size:22px;
  line-height:1;
  cursor:pointer;
  color:#6b7280;
}

.modal-close:hover{
  color:#111827;
}

.modal-body{
  padding:12px 18px 16px;
}

.modal-text{
  font-size:13px;
  color:#6b7280;
  margin:0 0 10px;
}

.form-row{
  display:flex;
  flex-direction:column;
  gap:4px;
  margin-bottom:10px;
}

.form-row label{
  font-size:13px;
  font-weight:600;
  color:#111827;
}

.form-row .req{
  color:#dc2626;
}

.form-row input,
.form-row select,
.form-row textarea{
  border-radius:10px;
  border:1px solid #d1d5db;
  padding:8px 12px;
  font-size:14px;
  outline:none;
  font-family:inherit;
  transition:border-color 0.15s, box-shadow 0.15s;
}

.form-row textarea{
  resize:vertical;
  min-height:80px;
}

.form-row input:focus,
.form-row select:focus,
.form-row textarea:focus{
  border-color:#1e8fa2;
  box-shadow:0 0 0 2px rgba(30,143,162,0.25);
}

.modal-footer{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:8px;
}

.btn-ghost{
  border-radius:999px;
  padding:8px 20px;
  background:#ffffff;
  border:1px solid #d1d5db;
  font-size:14px;
  font-weight:600;
  color:#111827;
  cursor:pointer;
}

.btn-ghost:hover{
  background:#f3f4f6;
}

.btn-accent{
  border-radius:999px;
  padding:8px 22px;
  border:none;
  background:linear-gradient(135deg,#f5a425,#f6c445);
  color:#1f2327;
  box-shadow:0 10px 24px rgba(245,164,37,0.35);
  font-size:14px;
  font-weight:600;
  cursor:pointer;
}

.btn-accent:hover{
  filter:brightness(0.95);
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
            <tr
              data-id="<?php echo (int)$a['announcement_id']; ?>"
              data-title="<?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?>"
              data-body="<?php echo htmlspecialchars($a['body'], ENT_QUOTES); ?>"
              data-audience="<?php echo htmlspecialchars($a['audience'], ENT_QUOTES); ?>"
              data-priority="<?php echo htmlspecialchars($a['priority'], ENT_QUOTES); ?>"
              data-status="<?php echo htmlspecialchars($a['status'], ENT_QUOTES); ?>"
              data-start="<?php echo htmlspecialchars($a['start_date'] ?? '', ENT_QUOTES); ?>"
              data-end="<?php echo htmlspecialchars($a['end_date'] ?? '', ENT_QUOTES); ?>"
            >
              <td><?php echo $i++; ?></td>
              <td>
                <div class="ann-detail-title">
                  <?php echo htmlspecialchars($a['title']); ?>
                  <?php if ($a['priority'] === 'important'): ?>
                    <span style="margin-left:6px; font-size:11px; color:#b45309;">• Important</span>
                  <?php endif; ?>
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
                  <button type="button"
                          class="btn-pill btn-edit btn-edit-ann">
                    <i class="fas fa-pen"></i>Edit
                  </button>
                  <button type="button"
                          class="btn-pill btn-delete btn-delete-ann">
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

<!-- Add/Edit Announcement Modal -->
<div class="modal-backdrop" id="annBackdrop" aria-hidden="true">
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="annModalTitle">
    <div class="modal-header">
      <h2 id="annModalTitle">Add Announcement</h2>
      <button type="button" class="modal-close" id="annClose">&times;</button>
    </div>

    <form id="annForm" class="modal-body">
      <p class="modal-text">
        Create or update announcements that will be displayed on the student dashboard.
      </p>

      <input type="hidden" name="announcement_id" id="announcement_id" value="">
      <input type="hidden" name="mode" id="ann_mode" value="create">

      <div class="form-row">
        <label for="ann_title">Title <span class="req">*</span></label>
        <input type="text" id="ann_title" name="title"
               placeholder="e.g. Starting Level Test Completion"
               maxlength="191" required />
      </div>

      <div class="form-row">
        <label for="ann_body">Message <span class="req">*</span></label>
        <textarea id="ann_body" name="body"
                  placeholder="Write the full announcement message here..."
                  required></textarea>
      </div>

      <div class="form-row">
        <label for="ann_audience">Audience</label>
        <select id="ann_audience" name="audience">
          <option value="students">Students only</option>
          <option value="all">All users</option>
        </select>
      </div>

      <div class="form-row">
        <label for="ann_priority">Priority</label>
        <select id="ann_priority" name="priority">
          <option value="normal">Normal</option>
          <option value="important">Important</option>
        </select>
      </div>

      <div class="form-row">
        <label for="ann_status">Status</label>
        <select id="ann_status" name="status">
          <option value="active">Active</option>
          <option value="scheduled">Scheduled</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="form-row">
        <label>Visibility dates
          <span style="font-weight:400; font-size:12px; color:#6b7280;">(optional)</span>
        </label>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <input type="date" id="ann_start_date" name="start_date" />
          <input type="date" id="ann_end_date" name="end_date" />
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-ghost" id="annCancel">Cancel</button>
        <button type="submit" class="btn-accent" id="annSaveBtn">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const backdrop  = document.getElementById('annBackdrop');
  const btnOpen   = document.getElementById('btnAddAnnouncement');
  const btnClose  = document.getElementById('annClose');
  const btnCancel = document.getElementById('annCancel');
  const form      = document.getElementById('annForm');
  const saveBtn   = document.getElementById('annSaveBtn');
  const titleEl   = document.getElementById('annModalTitle');

  const idInput   = document.getElementById('announcement_id');
  const modeInput = document.getElementById('ann_mode');
  const titleInput= document.getElementById('ann_title');
  const bodyInput = document.getElementById('ann_body');
  const audInput  = document.getElementById('ann_audience');
  const priInput  = document.getElementById('ann_priority');
  const statusInp = document.getElementById('ann_status');
  const startInp  = document.getElementById('ann_start_date');
  const endInp    = document.getElementById('ann_end_date');

  if (!backdrop || !form) return;

  function openModal(mode, row){
    modeInput.value = mode;
    if (mode === 'edit' && row){
      titleEl.textContent = 'Edit Announcement';
      idInput.value   = row.dataset.id || '';
      titleInput.value= row.dataset.title || '';
      bodyInput.value = row.dataset.body || '';
      audInput.value  = row.dataset.audience || 'students';
      priInput.value  = row.dataset.priority || 'normal';
      statusInp.value = row.dataset.status || 'active';
      startInp.value  = row.dataset.start || '';
      endInp.value    = row.dataset.end || '';
    } else {
      titleEl.textContent = 'Add Announcement';
      idInput.value   = '';
      form.reset();
      audInput.value  = 'students';
      priInput.value  = 'normal';
      statusInp.value = 'active';
      startInp.value  = '';
      endInp.value    = '';
    }
    backdrop.classList.add('show');
    setTimeout(()=>titleInput.focus(), 80);
  }

  function closeModal(){
    backdrop.classList.remove('show');
    form.reset();
    idInput.value = '';
    modeInput.value = 'create';
  }

  btnOpen?.addEventListener('click', ()=>openModal('create', null));
  btnClose?.addEventListener('click', closeModal);
  btnCancel?.addEventListener('click', closeModal);

  backdrop.addEventListener('click', (e)=>{
    if (e.target === backdrop) closeModal();
  });

  // Edit buttons
  document.querySelectorAll('.btn-edit-ann').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const row = btn.closest('tr');
      if (row) openModal('edit', row);
    });
  });

  // Delete buttons
  document.querySelectorAll('.btn-delete-ann').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const row = btn.closest('tr');
      if (!row) return;
      const id  = row.dataset.id;
      const title = row.dataset.title || 'this announcement';

      if (window.Swal){
        const res = await Swal.fire({
          icon: 'warning',
          title: 'Delete announcement?',
          text: 'Are you sure you want to delete "' + title + '"?',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          cancelButtonText: 'Cancel',
          confirmButtonColor: '#d33',
          cancelButtonColor: '#6b7280'
        });
        if (!res.isConfirmed) return;
      } else {
        if (!confirm('Delete this announcement?')) return;
      }

      try{
        const fd = new FormData();
        fd.append('announcement_id', id);

        const resp = await fetch('ajax_delete_announcement.php', {
          method: 'POST',
          body: fd
        });
        const data = await resp.json();

        if (data.success){
          if (window.Swal){
            Swal.fire({
              icon:'success',
              title:'Deleted',
              text:data.message || 'Announcement deleted.',
              confirmButtonColor:'#1e8fa2'
            }).then(()=>window.location.reload());
          }else{
            alert(data.message || 'Deleted.');
            window.location.reload();
          }
        }else{
          if (window.Swal){
            Swal.fire({
              icon:'error',
              title:'Error',
              text:data.message || 'Unable to delete.',
              confirmButtonColor:'#1e8fa2'
            });
          }else{
            alert(data.message || 'Unable to delete.');
          }
        }
      }catch(err){
        console.error(err);
        if (window.Swal){
          Swal.fire({
            icon:'error',
            title:'Network error',
            text:'Please try again.',
            confirmButtonColor:'#1e8fa2'
          });
        }else{
          alert('Network error, please try again.');
        }
      }
    });
  });

  // Submit (create / edit)
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    saveBtn.disabled = true;

    const fd = new FormData(form);

    try{
      const resp = await fetch('ajax_save_announcement.php', {
        method: 'POST',
        body: fd
      });
      const data = await resp.json();

      if (data.success){
        closeModal();
        if (window.Swal){
          Swal.fire({
            icon:'success',
            title:'Saved',
            text:data.message || 'Announcement saved successfully.',
            confirmButtonColor:'#1e8fa2'
          }).then(()=>window.location.reload());
        }else{
          alert(data.message || 'Saved.');
          window.location.reload();
        }
      }else{
        if (window.Swal){
          Swal.fire({
            icon:'error',
            title:'Unable to save',
            text:data.message || 'Please check the form and try again.',
            confirmButtonColor:'#1e8fa2'
          });
        }else{
          alert(data.message || 'Unable to save.');
        }
      }
    }catch(err){
      console.error(err);
      if (window.Swal){
        Swal.fire({
          icon:'error',
          title:'Network error',
          text:'Please try again.',
          confirmButtonColor:'#1e8fa2'
        });
      }else{
        alert('Network error, please try again.');
      }
    }finally{
      saveBtn.disabled = false;
    }
  });
})();
</script>

</body>
</html>
