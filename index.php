<?php /* index.php */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>SRA Reading Center - Home</title>
  <link rel="stylesheet" href="styles/style.css" />
</head>
<body>

<?php
  // Kung ilalagay mo ang index sa root, hindi na kailangan i-set ang $ASSET_PREFIX.
  // include header
  include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="hero" id="home" role="banner" aria-label="Welcome">
  <div class="hero-content">
    <h2>Welcome to SRA Reading Center</h2>
    <p>Empowering students with better reading skills through engaging programs</p>
  </div>
</section>

<!-- About -->
<section id="about">
  <h2>About Us</h2>

  <div class="about-wrapper">
    <div class="about-text">
      <h3>Who We Are</h3>
      <p>
        The <strong>SRA Reading Center</strong> at CBSUA Sipocot Campus is dedicated to helping students
        improve their reading and comprehension skills. Using the well-known
        <em>SRA Reading Laboratory</em> program, the center provides a structured,
        self-paced learning environment that adapts to each student’s reading level.
        This initiative supports CBSUA’s mission to offer quality education and empower
        students through innovative learning approaches.
      </p>
    </div>

    <div class="slideshow-container" aria-label="Reading Center Slideshow">
  <div class="slides fade">
    <img src="1.jpg" alt="SRA Reading Center Activity 1">
  </div>
  <div class="slides fade">
    <img src="2.jpg" alt="SRA Reading Center Activity 2">
  </div>
  <div class="slides fade">
    <img src="3.jpg" alt="SRA Reading Center Activity 3">
  </div>


      <button class="prev" aria-label="Previous slide">&#10094;</button>
      <button class="next" aria-label="Next slide">&#10095;</button>
    </div>
  </div>

  <div class="about">
    <div class="card fixed-height">
      <h3>Our Mission</h3>
      <p>We aim to improve literacy and critical reading skills for students of all ages with our research-based programs.</p>
    </div>
    <div class="card fixed-height">
      <h3>Our Vision</h3>
      <p>To create a generation of confident readers who enjoy learning and exploring knowledge through reading.</p>
    </div>
    <div class="card fixed-height">
      <h3>History</h3>
      <p>Established decades ago, SRA has been at the forefront of reading education, providing schools with reliable learning programs.</p>
    </div>
  </div>
</section>

<section id="stories">
  <h2>Success Stories</h2>
  <div class="stories">

    <div class="card">
      <img src="1.jpg" alt="Reading Assessment Improvement">
      <h3>Improved Reading Proficiency</h3>
      <p>
        Students who completed the SRA Reading Program demonstrated measurable
        improvements in reading comprehension, vocabulary, and reading speed
        through structured and level-appropriate assessments.
      </p>
    </div>

    <div class="card">
      <img src="picture2.jpg" alt="Student Confidence in Reading">
      <h3>Increased Confidence in Learning</h3>
      <p>
        Through self-paced reading activities, students gained confidence in
        understanding academic texts, enabling them to participate more actively
        in class discussions and written tasks.
      </p>
    </div>

    <div class="card">
      <img src="picture.png" alt="Academic Support and Guidance">
      <h3>Strengthened Academic Support</h3>
      <p>
        The SRA Reading Center supports faculty and students by identifying reading
        needs early and providing guided interventions aligned with CBSUA’s
        commitment to quality education.
      </p>
    </div>

  </div>
</section>


<!-- Resources -->
<section id="resources">
  <h2>Resources</h2>
  <div class="resources">
    <div class="card">
      <h3>Sample Exercises</h3>
      <p>Free exercises to practice reading comprehension, critical thinking, and vocabulary.</p>
    </div>
    <div class="card">
      <h3>Articles & Tips</h3>
      <p>Educational articles and tips for students, teachers, and parents to improve reading skills.</p>
    </div>
    <div class="card">
      <h3>Downloadable Materials</h3>
      <p>Access SRA workbooks, storybooks, and guides in digital format for easy use.</p>
    </div>
  </div>
</section>

<!-- Contact -->
<section id="contact">
  <h2>Contact Us</h2>
  <div class="contact-info">
    <p>© 2025 Science Research Associates Reading Center. All Rights Reserved.</p>
    <p>Email: info@srareading.org | Phone: (123) 456-7890</p>
    <p>123 Learning Lane, Education City</p>
  </div>
</section>

<footer>
  <p>© 2025 Science Research Associates Reading Center. All Rights Reserved.</p>
  <p><a href="#">Facebook</a> | <a href="#">Twitter</a> | <a href="#">Instagram</a></p>
</footer>

<script>
  // Mobile nav toggling
  const body = document.body;
  const hamburger = document.getElementById('hamburger');
  const nav = document.getElementById('primary-nav');
  const overlay = document.getElementById('nav-overlay');

  function closeNav(){
    body.classList.remove('nav-open');
    hamburger.setAttribute('aria-expanded','false');
    overlay.setAttribute('hidden','');
  }
  function openNav(){
    body.classList.add('nav-open');
    hamburger.setAttribute('aria-expanded','true');
    overlay.removeAttribute('hidden');
  }

  if (hamburger) {
    hamburger.addEventListener('click', () => {
      if (body.classList.contains('nav-open')) closeNav(); else openNav();
    });
  }
  if (overlay) overlay.addEventListener('click', closeNav);
  window.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });

  // Close nav when clicking a link (mobile)
  if (nav) nav.querySelectorAll('a').forEach(a => a.addEventListener('click', closeNav));

  // Simple slideshow
  let slideIndex = 0;
  function showSlides(n) {
    const slides = document.getElementsByClassName("slides");
    if (!slides.length) return;

    if (n >= slides.length) { slideIndex = 0; }
    if (n < 0) { slideIndex = slides.length - 1; }

    for (let i = 0; i < slides.length; i++) slides[i].style.display = "none";
    slides[slideIndex].style.display = "block";
  }

  document.addEventListener("DOMContentLoaded", () => {
    showSlides(slideIndex);
    const prevBtn = document.querySelector(".prev");
    const nextBtn = document.querySelector(".next");
    if (prevBtn && nextBtn) {
      prevBtn.addEventListener("click", () => { slideIndex -= 1; showSlides(slideIndex); });
      nextBtn.addEventListener("click", () => { slideIndex += 1; showSlides(slideIndex); });
    }
    setInterval(() => { slideIndex++; showSlides(slideIndex); }, 4000);
  });
</script>

</body>
</html>
