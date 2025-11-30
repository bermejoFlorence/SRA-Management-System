
<?php /* Login <-> Register sliding panels on one page (with backend hooks) */ ?>
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
            <p class="switch-hint">No account yet?
              <a href="#" id="goRegister">Create one</a>
            </p>
          </form>
        </div>

        <!-- REGISTER PANEL -->
        <div class="auth-panel" id="panelRegister" role="tabpanel" aria-labelledby="tabRegister">
          <h2 class="form-title">Register Form</h2>
          <form id="registerForm" class="form-grid" novalidate>
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

            <label>Course
              <input type="text" name="course" required />
            </label>

            <label>Major
              <input type="text" name="major" />
            </label>

            <label>Year Level
              <input type="number" name="yearlevel" min="1" max="10" required />
            </label>

            <label>Section
              <input type="text" name="section" required />
            </label>

            <div class="buttons">
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
(() => {
  const ALLOWED_DOMAIN = 'cbsua.edu.ph';
  const confirmColor   = '#1e8fa2';

  const track = document.getElementById('authTrack');
  const slider = document.getElementById('authSlider');
  const tabLogin = document.getElementById('tabLogin');
  const tabRegister = document.getElementById('tabRegister');
  const goRegister = document.getElementById('goRegister');
  const goLogin = document.getElementById('goLogin');

  function setActive(which) {
    const isLogin = which === 'login';
    track.style.transform = isLogin ? 'translateX(0%)' : 'translateX(-50%)';
    tabLogin.classList.toggle('active', isLogin);
    tabRegister.classList.toggle('active', !isLogin);
    tabLogin.setAttribute('aria-selected', isLogin ? 'true' : 'false');
    tabRegister.setAttribute('aria-selected', !isLogin ? 'true' : 'false');
    requestAnimationFrame(() => {
      const activePanel = document.getElementById(isLogin ? 'panelLogin' : 'panelRegister');
      slider.style.height = activePanel.offsetHeight + 'px';
    });
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
