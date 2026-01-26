<?php
// Optional: kung nasa subfolder ang page na tatawag dito, i-set mo ito bago mag-include:
// $ASSET_PREFIX = '../';  // halimbawa sa pages/nested/index.php
$ASSET_PREFIX = $ASSET_PREFIX ?? '';
?>

/* 🔹 CBSUA institutional strip sa pinakataas */
.site-header {
  /* kung may existing kang header{} rule, ok lang.
     Ito lang ang gagamitin para sa mas specific na styling kung kailangan. */
}

.institution-bar {
  background-color: #022808; /* darker green than main header */
  color: #f8f8f8;
  font-size: 13px;
  line-height: 1.3;
}

.institution-inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 6px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.institution-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.institution-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 4px;
  background: #ffffff;
  padding: 2px;
}

.institution-text {
  display: flex;
  flex-direction: column;
}

.institution-name {
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.institution-campus {
  font-size: 11px;
  opacity: 0.9;
}

.institution-right {
  font-size: 11px;
  opacity: 0.9;
  text-align: right;
}

.institution-unit-label {
  white-space: nowrap;
}

/* Wrapper ng existing header content mo para linis layout */
.main-header {
  /* kung dati naka-flex ang header mo, ilipat mo yung flex styles dito kung kinakailangan.
     Halimbawa:
     display: flex;
     align-items: center;
     justify-content: space-between;
     padding: 10px 16px;
     background-color: #054a0b;  // same as old
  */
}

/* 🔹 Responsiveness para sa maliit na screen */
@media (max-width: 768px) {
  .institution-inner {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
  }

  .institution-right {
    text-align: left;
  }

  .institution-name {
    font-size: 12px;
  }

  .institution-campus {
    font-size: 10px;
  }

  .institution-unit-label {
    white-space: normal;
  }
}

<header class="site-header">

  <!-- 🔹 INSTITUTIONAL STRIP (CBSUA) -->
  <div class="institution-bar">
    <div class="institution-inner">
      <div class="institution-left">
        <!-- Palitan ang filename kung iba ang pangalan ng CBSUA logo mo -->
        <img
          src="<?= $ASSET_PREFIX ?>cbsua-logo.png"
          alt="Central Bicol State University of Agriculture logo"
          class="institution-logo"
        >
        <div class="institution-text">
          <span class="institution-name">Central Bicol State University of Agriculture</span>
          <span class="institution-campus">Sipocot Campus</span>
        </div>
      </div>

      <div class="institution-right">
        <span class="institution-unit-label">
          Official Academic Support Unit: SRA Reading Center
        </span>
      </div>
    </div>
  </div>
  <!-- 🔹 END INSTITUTIONAL STRIP -->

  <!-- 🔹 MAIN HEADER (existing design) -->
  <div class="main-header">
    <div class="logo-container">
      <img src="<?= $ASSET_PREFIX ?>logo.png" alt="SRA Logo">
      <h1 class="site-title">
        <span>S</span>CIENCE <span>R</span>ESEARCH <span>A</span>SSOCIATES
      </h1>
    </div>

    <!-- Hamburger (mobile) -->
    <button class="hamburger" id="hamburger"
      aria-label="Open menu"
      aria-controls="primary-nav"
      aria-expanded="false">
      <span></span><span></span><span></span>
    </button>

    <!-- Desktop/Mobile Nav -->
    <nav id="primary-nav" class="nav">
      <a href="<?= $ASSET_PREFIX ?>#home">Home</a>
      <a href="<?= $ASSET_PREFIX ?>#about">About</a>
      <a href="<?= $ASSET_PREFIX ?>#services">Services</a>
      <a href="<?= $ASSET_PREFIX ?>#stories">Stories</a>
      <a href="<?= $ASSET_PREFIX ?>#resources">Resources</a>
      <a href="<?= $ASSET_PREFIX ?>#contact">Contact</a>
      <a href="<?= $ASSET_PREFIX ?>login.php" class="btn-login">Login</a>
    </nav>

    <!-- Dim background when mobile nav is open -->
    <div class="nav-overlay" id="nav-overlay" hidden></div>
  </div>
  <!-- 🔹 END MAIN HEADER -->

</header>
