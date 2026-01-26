<?php
// Optional: kung nasa subfolder ang page na tatawag dito, i-set mo ito bago mag-include:
// $ASSET_PREFIX = '../';  // halimbawa sa pages/nested/index.php
$ASSET_PREFIX = $ASSET_PREFIX ?? '';
?>
<header class="site-header">

  <!-- 🔹 INSTITUTIONAL STRIP (CBSUA) -->
  <div class="institution-bar">
    <div class="institution-inner">
      <div class="institution-left">
        <!-- Palitan ang filename/path kung iba ang pangalan ng CBSUA logo mo -->
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
