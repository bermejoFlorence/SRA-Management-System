<?php
// complete_profile.php
session_start();
require_once __DIR__ . '/db_connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Load user
$stmt = $conn->prepare("SELECT email, profile_photo, is_profile_complete FROM users WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: login.php');
    exit;
}

// If already complete, diretso dashboard
if ((int)$user['is_profile_complete'] === 1) {
    header('Location: student/index.php');
    exit;
}

// ------- Load programs & majors (same as login.php) -------
$programs = [];
$majorsByProgram = [];

$res = $conn->query("
    SELECT program_id, program_code, program_name
    FROM sra_programs
    WHERE status = 'active'
    ORDER BY program_code ASC, program_name ASC
");
while ($row = $res->fetch_assoc()) {
    $programs[] = $row;
}
$res->free();

$res = $conn->query("
    SELECT major_id, program_id, major_name
    FROM sra_majors
    WHERE status = 'active'
    ORDER BY major_name ASC
");
while ($row = $res->fetch_assoc()) {
    $pid = (int)$row['program_id'];
    if (!isset($majorsByProgram[$pid])) {
        $majorsByProgram[$pid] = [];
    }
    $majorsByProgram[$pid][] = [
        'major_id'   => (int)$row['major_id'],
        'major_name' => $row['major_name'],
    ];
}
$res->free();

// ------- Handle POST (save profile) -------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname     = trim($_POST['firstname'] ?? '');
    $middlename    = trim($_POST['middlename'] ?? '');
    $lastname      = trim($_POST['lastname'] ?? '');
    $extensionname = trim($_POST['extensionname'] ?? '');
    $studentid     = trim($_POST['studentid'] ?? '');
    $program_id    = (int)($_POST['program_id'] ?? 0);
    $major_id      = (int)($_POST['major_id'] ?? 0);
    $yearlevel     = (int)($_POST['yearlevel'] ?? 0);
    $section       = trim($_POST['section'] ?? '');

    // TODO: validation

    // Example: update users (depende sa columns ng users table mo)
    $stmt = $conn->prepare("
        UPDATE users
        SET first_name = ?, middle_name = ?, last_name = ?, updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->bind_param('sssi', $firstname, $middlename, $lastname, $userId);
    $stmt->execute();
    $stmt->close();

    // TODO: insert/update student profile table mo (sra_students, etc.)

    // Mark profile complete
    $stmt = $conn->prepare("UPDATE users SET is_profile_complete = 1 WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    header('Location: student/index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Complete Profile</title>
  <link rel="stylesheet" href="styles/auth.css">
</head>
<body>
<div class="container">
  <section class="right-section">
    <h2 class="form-title">Complete Your Profile</h2>
    <p>We already got your CBSUA email and profile picture from Google. Please complete your student details below.</p>

    <form method="post" class="register-form">
      <div class="field-grid">
        <label>Email
          <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
        </label>

        <label>Firstname
          <input type="text" name="firstname" required>
        </label>

        <label>Middlename
          <input type="text" name="middlename">
        </label>

        <label>Lastname
          <input type="text" name="lastname" required>
        </label>

        <label>Extension Name
          <input type="text" name="extensionname" placeholder="e.g., Jr., II, Sr.">
        </label>

        <label>Student ID No.
          <input type="text" name="studentid" required>
        </label>

        <label>Course
          <select name="program_id" id="programSelect" required>
            <option value="">Select course</option>
            <?php foreach ($programs as $p): ?>
              <?php
                $pid  = (int)$p['program_id'];
                $code = htmlspecialchars($p['program_code']);
                $name = htmlspecialchars($p['program_name']);
              ?>
              <option value="<?php echo $pid; ?>">
                <?php echo $code . ' – ' . $name; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Major
          <select name="major_id" id="majorSelect" disabled>
            <option value="">Select course first</option>
          </select>
        </label>

        <label>Year Level
          <select name="yearlevel" id="yearLevelSelect" required>
            <option value="">Select year level</option>
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
          </select>
        </label>

        <label>Section
          <input type="text" name="section" required>
        </label>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn register-btn">Save Profile</button>
      </div>
    </form>
  </section>
</div>

<script>
const MAJORS_BY_PROGRAM = <?php echo json_encode($majorsByProgram, JSON_UNESCAPED_UNICODE); ?>;
const programSelect = document.getElementById('programSelect');
const majorSelect   = document.getElementById('majorSelect');

function refreshMajors() {
  const pid = programSelect.value;
  const majors = MAJORS_BY_PROGRAM[pid] || [];

  majorSelect.innerHTML = '';

  if (!pid) {
    majorSelect.disabled = true;
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'Select course first';
    majorSelect.appendChild(opt);
    return;
  }

  if (majors.length === 0) {
    majorSelect.disabled = true;
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'No major for this course';
    majorSelect.appendChild(opt);
  } else {
    majorSelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select major';
    placeholder.disabled = true;
    placeholder.selected = true;
    majorSelect.appendChild(placeholder);

    majors.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.major_id;
      opt.textContent = m.major_name;
      majorSelect.appendChild(opt);
    });
  }
}

if (programSelect && majorSelect) {
  programSelect.addEventListener('change', refreshMajors);
  refreshMajors();
}
</script>
</body>
</html>
