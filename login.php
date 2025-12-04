<?php
// login.php – Login / Register

require_once __DIR__ . '/db_connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ---- Load active courses/programs ----
$programs = [];
$majorsByProgram = [];

// Programs
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

// Majors grouped by program_id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>SRA – Login / Register</title>
  <link rel="stylesheet" href="styles/auth.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="container">
  <!-- LEFT (image + overlay + title) -->
  <section class="left-section" role="img" aria-label="Reading center">
    <div class="overlay"></div>
    <div class="left-content">
      <div class="logos" aria-hidden="true">
        <img src="l.png" alt="Logo"/>
        <img src="1.png" alt="CBSUA Logo"/>
      </div>

      <div class="title-block">
        <h1 class="main-title">Science<br>Research<br>Associates</h1>
        <p class="subtitle">Management and Student Progress Monitoring</p>
      </div>

      <div class="reading-center">
        <strong>READING CENTER</strong>
        <span>Central Bicol State University of Agriculture - Sipocot</span>
      </div>
    </div>
  </section>

  <!-- RIGHT (slider wrapper) -->
  <section class="right-section">
    <!-- Tabs -->
    <div class="auth-tabs" role="tablist" aria-label="Auth tabs">
      <button id="tabLogin"  class="tab-btn active" role="tab" aria-selected="true"  aria-controls="panelLogin">Login</button>
      <button id="tabRegister" class="tab-btn"        role="tab" aria-selected="false" aria-controls="panelRegister">Register</button>
    </div>

    <!-- Slider -->
    <div class="auth-slider" id="authSlider">
      <div class="auth-track" id="authTrack">
        <!-- LOGIN PANEL -->
        <div class="auth-panel" id="panelLogin" role="tabpanel" aria-labelledby="tabLogin">
          <h2 class="form-title">Login</h2>
          <form id="loginForm" class="form-stack" novalidate>
            <label>Email
              <input
                type="email"
                name="email"
                placeholder="name@cbsua.edu.ph"
                required
                autocomplete="email"
                inputmode="email"
                pattern="^[a-zA-Z0-9._%+\-]+@cbsua\.edu\.ph$"
                title="Use your @cbsua.edu.ph email"
                oninput="this.value=this.value.toLowerCase()"
              />
            </label>
            <label>Password
              <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password" />
            </label>
            <div class="buttons">
  <button type="submit" class="btn login-btn" id="loginBtn">Login</button>
</div>

<div class="or-divider">
  <span>or</span>
</div>

<div class="buttons">
  <a href="google_login.php" class="btn login-google-btn">
    Continue with Google (@cbsua.edu.ph)
  </a>
</div>

<p class="switch-hint">No account yet?
  <a href="#" id="goRegister">Create one</a>
</p>

          </form>
        </div>

        <!-- REGISTER PANEL -->
        <div class="auth-panel" id="panelRegister" role="tabpanel" aria-labelledby="tabRegister">
          <h2 class="form-title">Register Form</h2>

          <form id="registerForm" class="register-form" novalidate>
            <!-- grid ng fields -->
            <div class="field-grid">
              <label>Firstname
                <input type="text" name="firstname" required autocomplete="given-name"/>
              </label>

              <label>Middlename
                <input type="text" name="middlename" autocomplete="additional-name"/>
              </label>

              <label>Lastname
                <input type="text" name="lastname" required autocomplete="family-name"/>
              </label>

              <label>Extension Name
                <input type="text" name="extensionname" placeholder="e.g., Jr., II, Sr." />
              </label>

              <label>Student ID No.
                <input type="text" name="studentid" required />
              </label>

              <label>Email
                <input
                  type="email"
                  name="email"
                  placeholder="name@cbsua.edu.ph"
                  required
                  autocomplete="email"
                  inputmode="email"
                  pattern="^[a-zA-Z0-9._%+\-]+@cbsua\.edu\.ph$"
                  title="Use your @cbsua.edu.ph email"
                  oninput="this.value=this.value.toLowerCase()"
                />
              </label>

              <label>Password
                <input type="password" name="password" required autocomplete="new-password"/>
              </label>

              <!-- COURSE (dropdown from sra_programs) -->
              <label>Course
                <select name="program_id" id="programSelect" required>
                  <option value="">Select course</option>
                  <?php foreach ($programs as $p): ?>
                    <?php
                      $pid   = (int)$p['program_id'];
                      $code  = htmlspecialchars($p['program_code']);
                      $name  = htmlspecialchars($p['program_name']);
                    ?>
                    <option value="<?php echo $pid; ?>">
                      <?php echo $code . ' – ' . $name; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <!-- MAJOR (depends on course, may be disabled) -->
              <label>Major
                <select name="major_id" id="majorSelect" disabled>
                  <option value="">Select course first</option>
                </select>
              </label>

              <!-- YEAR LEVEL (1st–4th as dropdown) -->
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
                <input type="text" name="section" required />
              </label>
            </div>

            <!-- buttons sa pinakababa -->
            <div class="form-actions">
              <button type="button" class="btn login-alt" id="goLogin">Go to Login</button>
              <button type="submit" class="btn register-btn" id="registerBtn">Register</button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </section>
</div>

<script>
// PHP → JS majors map
const MAJORS_BY_PROGRAM = <?php echo json_encode($majorsByProgram, JSON_UNESCAPED_UNICODE); ?>;

(() => {
  const ALLOWED_DOMAIN = 'cbsua.edu.ph';
  const confirmColor   = '#1e8fa2';

  const track = document.getElementById('authTrack');
  const slider = document.getElementById('authSlider');
  const tabLogin = document.getElementById('tabLogin');
  const tabRegister = document.getElementById('tabRegister');
  const goRegister = document.getElementById('goRegister');
  const goLogin = document.getElementById('goLogin');

  const programSelect = document.getElementById('programSelect');
  const majorSelect   = document.getElementById('majorSelect');

  function setActive(which) {
    const isLogin = which === 'login';

    // slide animation
    track.style.transform = isLogin ? 'translateX(0%)' : 'translateX(-50%)';

    // tab active state
    tabLogin.classList.toggle('active', isLogin);
    tabRegister.classList.toggle('active', !isLogin);
    tabLogin.setAttribute('aria-selected', isLogin ? 'true' : 'false');
    tabRegister.setAttribute('aria-selected', !isLogin ? 'true' : 'false');

    // panel fade/slide animation
    const loginPanel    = document.getElementById('panelLogin');
    const registerPanel = document.getElementById('panelRegister');
    loginPanel.classList.toggle('active-panel', isLogin);
    registerPanel.classList.toggle('active-panel', !isLogin);

    // adjust card height
    requestAnimationFrame(() => {
      const activePanel = isLogin ? loginPanel : registerPanel;
      slider.style.height = activePanel.offsetHeight + 'px';
    });

    // update hash
    history.replaceState(null, '', isLogin ? '#login' : '#register');
  }

  tabLogin.addEventListener('click', () => setActive('login'));
  tabRegister.addEventListener('click', () => setActive('register'));
  if (goRegister) goRegister.addEventListener('click', (e)=>{ e.preventDefault(); setActive('register'); });
  if (goLogin) goLogin.addEventListener('click', ()=> setActive('login'));

  const initial = (location.hash || '#login').replace('#','');
  setActive(initial === 'register' ? 'register' : 'login');

  window.addEventListener('resize', () => {
    const isLogin = tabLogin.classList.contains('active');
    const activePanel = document.getElementById(isLogin ? 'panelLogin' : 'panelRegister');
    slider.style.height = activePanel.offsetHeight + 'px';
  });

  const showDomainError = () => Swal.fire({
    icon: 'error',
    title: 'CBSUA email only',
    text: `Please use your @${ALLOWED_DOMAIN} email address.`,
    confirmButtonColor: confirmColor
  });

  const emailAllowed = (email) => {
    email = String(email || '').trim().toLowerCase();
    const parts = email.split('@');
    return parts.length === 2 && parts[1] === ALLOWED_DOMAIN;
  };

  // ---------- Major dropdown logic ----------
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
    refreshMajors(); // initial state
  }

  // ---------- REGISTER HANDLER ----------
  const registerForm = document.getElementById('registerForm');
  const registerBtn  = document.getElementById('registerBtn');
  registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    registerBtn.disabled = true;

    const fd = new FormData(registerForm);
    const email = String(fd.get('email') || '').trim().toLowerCase();

    if (!emailAllowed(email)) {
      await showDomainError();
      registerBtn.disabled = false;
      return;
    }
    fd.set('email', email);

    try {
      const res = await fetch('register_student.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Registered!',
          text: data.message || 'Please check your email to verify your account.',
          confirmButtonColor: confirmColor
        }).then(() => {
          registerForm.reset();
          refreshMajors(); // reset majors dropdown state
          document.getElementById('tabLogin').click();
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Registration Failed',
          text: data.message || 'Please try again.',
          confirmButtonColor: confirmColor
        });
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Network Error',
        text: 'Please try again.',
        confirmButtonColor: confirmColor
      });
    } finally {
      registerBtn.disabled = false;
    }
  });

  // ---------- LOGIN HANDLER ----------
  const loginForm = document.getElementById('loginForm');
  const loginBtn  = document.getElementById('loginBtn');
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginBtn.disabled = true;

    const fd = new FormData(loginForm);
    const email = String(fd.get('email') || '').trim().toLowerCase();

    if (!emailAllowed(email)) {
      await showDomainError();
      loginBtn.disabled = false;
      return;
    }
    fd.set('email', email);

    try {
      const res = await fetch('login_process.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Welcome!',
          text: data.message || 'Login successful.',
          confirmButtonColor: confirmColor
        }).then(() => {
          if (data.redirect) window.location = data.redirect;
          else window.location = 'index.php';
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Login Failed',
          text: data.message || 'Please try again.',
          confirmButtonColor: confirmColor
        });
      }
    } catch (err) {
      Swal.fire({
        icon: 'error',
        title: 'Network Error',
        text: 'Please try again.',
        confirmButtonColor: confirmColor
      });
    } finally {
      loginBtn.disabled = false;
    }
  });
})();
</script>

</body>
</html>
