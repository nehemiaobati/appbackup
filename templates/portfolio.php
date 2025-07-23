<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nehemia Obati - Software Developer</title>
    
    <!-- Google Fonts Import -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- All CSS is contained within this style tag -->
    <style>
        /* Part 1: Global Design System & Structure */
        :root {
            --primary-bg: #112221;
            --card-bg: #192B2A;
            --accent-color: #007bff; /* Professional blue */
            --accent-highlight: #4dabf7;
            --text-color: #FFFFFF;
            --body-text-color: #E0E0E0;
            --dark-text: #000000;
            --border-color: #3a4c4b;
        }

        /* Global Resets and Base Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-color);
            overflow-x: hidden;
        }
        .container { max-width: 1140px; margin: 0 auto; padding: 0 20px; }
        section { padding: 80px 0; }
        h1, h2, h3, h4 { font-weight: 700; color: var(--text-color); }
        h1 { font-size: 3.2rem; }
        h2 { font-size: 2.5rem; margin-bottom: 50px; text-align: center; }
        h3 { font-size: 1.5rem; color: var(--accent-highlight); margin-bottom: 10px; }
        h4 { font-size: 1.1rem; color: var(--text-color); margin-bottom: 15px; }
        p { color: var(--body-text-color); line-height: 1.7; }
        a { color: var(--accent-highlight); text-decoration: none; }
        .section-title p.subtitle {
            color: var(--accent-highlight); font-weight: 500; display: block;
            margin-bottom: 5px; position: relative;
        }
        .section-title p.subtitle::before {
            content: ''; display: inline-block; width: 30px; height: 2px;
            background-color: var(--accent-highlight); margin-right: 10px; vertical-align: middle;
        }

        .btn {
            display: inline-block; background-color: var(--accent-color); color: var(--text-color);
            padding: 12px 28px; border-radius: 8px; text-decoration: none;
            font-weight: 600; transition: all 0.3s ease; border: none; cursor: pointer;
        }
        .btn:hover {
            background-color: var(--accent-highlight); transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.4);
        }

        /* 1. Header / Navigation */
        .main-header {
            display: flex; justify-content: space-between; align-items: center; padding: 20px 40px;
            position: sticky; top: 0; width: 100%; z-index: 1000; background-color: var(--primary-bg);
            transition: background-color 0.3s ease;
        }
        .main-header.scrolled { box-shadow: 0 2px 10px rgba(0,0,0,0.5); }
        .logo { font-size: 1.8rem; font-weight: 700; color: var(--accent-color); }
        .main-nav a {
            color: var(--text-color); text-decoration: none; margin: 0 15px;
            font-weight: 500; transition: color 0.3s ease;
        }
        .main-nav a:hover { color: var(--accent-highlight); }
        .nav-right { display: flex; align-items: center; }
        .menu-toggle { display: none; background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; }

        /* 2. Hero Section */
        #home { padding-top: 100px; }
        .hero-grid { display: grid; grid-template-columns: 2fr 1fr; align-items: center; gap: 50px; }
        .hero-text h1 strong { color: var(--accent-color); }
        .hero-text .subtitle { font-size: 1.5rem; margin: 10px 0 20px; font-weight: 500; }
        .hero-text p { margin-bottom: 30px; max-width: 600px; }
        .hero-image-container { text-align: center; }
        .hero-image {
            width: 250px; height: 250px; border-radius: 50%; object-fit: cover;
            border: 5px solid var(--card-bg); box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }

        /* 3. Skills Section */
        #skills .skills-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; }
        .skill-category { background-color: var(--card-bg); padding: 30px; border-radius: 12px; }
        .skill-category h3 { border-bottom: 2px solid var(--accent-color); padding-bottom: 10px; margin-bottom: 20px; }
        .skill-category ul { list-style: none; }
        .skill-category ul li { padding: 8px 0; border-bottom: 1px solid var(--border-color); }
        .skill-category ul li:last-child { border-bottom: none; }

        /* 4. Work History Section */
        #work .work-timeline { position: relative; max-width: 900px; margin: 0 auto; }
        #work .work-timeline::after {
            content: ''; position: absolute; width: 4px; background-color: var(--card-bg);
            top: 0; bottom: 0; left: 50%; margin-left: -2px;
        }
        .work-item { padding: 10px 40px; position: relative; background-color: inherit; width: 50%; }
        .work-item:nth-child(odd) { left: 0; }
        .work-item:nth-child(even) { left: 50%; }
        .work-item::after {
            content: ''; position: absolute; width: 20px; height: 20px; right: -10px;
            background-color: var(--accent-color); border: 4px solid var(--primary-bg);
            top: 25px; border-radius: 50%; z-index: 1;
        }
        .work-item:nth-child(even)::after { left: -10px; }
        .work-content { padding: 20px 30px; background-color: var(--card-bg); position: relative; border-radius: 8px; }
        .work-content .date { font-weight: 600; color: var(--accent-highlight); margin-bottom: 10px; }
        .work-content h3 { font-size: 1.3rem; margin-bottom: 10px; }
        .work-content .company { font-weight: 500; margin-bottom: 15px; }
        .work-content ul { padding-left: 20px; }
        .work-content li { margin-bottom: 8px; }

        /* 5. Portfolio Section */
        .portfolio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 30px; }
        .portfolio-card {
            background: var(--card-bg); border-radius: 12px; overflow: hidden;
            display: flex; flex-direction: column; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
        }
        .portfolio-card:hover { transform: translateY(-5px); }
        .portfolio-card img { width: 100%; height: 220px; object-fit: cover; }
        .portfolio-content { padding: 25px; flex-grow: 1; display: flex; flex-direction: column; }
        .portfolio-content h3 { font-size: 1.3rem; }
        .portfolio-content p { flex-grow: 1; margin: 15px 0 25px 0; }
        .portfolio-content .btn { align-self: flex-start; }

        /* 6. Education Section */
        .education-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .education-item { background-color: var(--card-bg); padding: 30px; border-radius: 12px; }

        /* 7. Languages & Interests */
        .personal-details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .detail-card { background-color: var(--card-bg); padding: 30px; border-radius: 12px; }
        .detail-card ul { list-style: none; }
        .detail-card li { padding: 5px 0; }

        /* 8. References Section */
        .references-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px; }
        .reference-item { background-color: var(--card-bg); padding: 30px; border-radius: 12px; }
        .reference-item .name { font-size: 1.3rem; font-weight: 700; color: var(--accent-highlight); }
        .reference-item .title { font-weight: 500; margin-bottom: 10px; }

        /* 9. Contact Section */
        .contact-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 50px; }
        .contact-info .info-item { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; }
        .contact-info .icon { font-size: 2rem; color: var(--accent-color); }
        .contact-form input, .contact-form textarea {
            width: 100%; padding: 15px; background-color: var(--card-bg);
            border: 1px solid var(--border-color); border-radius: 8px;
            color: var(--text-color); font-family: 'Poppins', sans-serif; font-size: 1rem;
        }
        .contact-form textarea { resize: vertical; min-height: 120px; }

        /* 10. Footer */
        .main-footer { background-color: var(--card-bg); text-align: center; padding: 20px 0; margin-top: 60px; }

        /* Responsive Media Queries */
        @media (max-width: 992px) {
            h1 { font-size: 2.8rem; }
            .hero-grid { grid-template-columns: 1fr; text-align: center; }
            .hero-text { order: 2; }
            .hero-image-container { order: 1; margin-bottom: 40px; }
            .hero-text p { margin-left: auto; margin-right: auto; }
            #work .work-timeline::after { left: 10px; }
            .work-item { width: 100%; padding-left: 50px; padding-right: 10px; }
            .work-item:nth-child(even) { left: 0%; }
            .work-item::after { left: 1px; }
        }
        @media (max-width: 768px) {
            .main-header { padding: 15px 20px; }
            .main-nav {
                display: none; flex-direction: column; position: absolute;
                top: 70px; left: 0; width: 100%; background-color: var(--primary-bg);
            }
            .main-nav.active { display: flex; }
            .main-nav a { margin: 15px; }
            .nav-right .btn { display: none; }
            .menu-toggle { display: block; }
            .education-grid, .personal-details-grid, .contact-grid, .references-grid { grid-template-columns: 1fr; }
            .resume-btn { margin-top: 15px; margin-left: 0 !important; }
        }
    </style>
</head>
<body>

    <div id="alert-container" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 1001; width: 90%; max-width: 600px;">
    <?php
    session_start(); // Ensure session is started for message handling
    if (isset($_SESSION['success_message'])): ?>
    <div style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 15px; margin-bottom: 15px; border-radius: 5px; text-align: center; position: relative;">
        <?= htmlspecialchars($_SESSION['success_message']) ?>
        <button type="button" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #155724;" onclick="this.parentElement.style.display='none';">×</button>
    </div>
    <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div style="background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; margin-bottom: 15px; border-radius: 5px; text-align: center; position: relative;">
        <?= htmlspecialchars($_SESSION['error_message']) ?>
        <button type="button" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #721c24;" onclick="this.parentElement.style.display='none';">×</button>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>
    </div>

    <header class="main-header" id="mainHeader">
        <a href="#home" class="logo">Nehemia Obati</a>
        <div class="nav-right">
            <nav class="main-nav" id="mainNav">
                <a href="#home">Home</a>
                <a href="#skills">Skills</a>
                <a href="#portfolio">Portfolio</a>
                <a href="#work">Experience</a>
                <a href="#education">Education</a>
                <a href="#contact">Contact</a>
            </nav>
            <a href="#contact" class="btn">Contact Me</a>
            <button class="menu-toggle" id="menuToggle">☰</button>
        </div>
    </header>

    <main>
        <!-- Hero Section -->
        <section id="home">
            <div class="container">
                <div class="hero-grid">
                    <div class="hero-text">
                        <h1>I am <strong>Nehemia Obati</strong></h1>
                        <p class="subtitle">Software Developer</p>
                        <p>I'm a full-stack developer with a passion for crafting dynamic and user-friendly web experiences. Fluent in technologies from front-end languages to back-end powerhouses like Python and PHP, and proficient in cloud platforms like GCP and AWS. My expertise in Bash and Linux empowers me to manage server environments with ease. I am constantly exploring new technologies to stay at the forefront of web development and am excited to leverage my comprehensive skillset to create innovative and impactful solutions.</p>
                        <a href="#portfolio" class="btn">View My Work</a>
                        <a href="/resume/pdf" class="btn resume-btn" target="_blank" style="margin-left: 10px;">Download Resume (PDF)</a>
                    </div>
                    <div class="hero-image-container">
                        <img src="https://placehold.co/250x250/EFEFEF/333333?text=N.O." alt="Nehemia Obati" class="hero-image">
                    </div>
                </div>
            </div>
        </section>

        <!-- Skills Section -->
        <section id="skills">
            <div class="container">
                <div class="section-title"><h2>Technical Skills</h2></div>
                <div class="skills-grid">
                    <div class="skill-category">
                        <h3>Cloud & Servers</h3>
                        <ul>
                            <li>Cloud Environments Setup (GCP, AWS, Azure)</li>
                            <li>Local/Self-Hosted Server Setup</li>
                            <li>Linux/Windows Server Management</li>
                            <li>Windows IIS & Apache2 Web Server Config</li>
                        </ul>
                    </div>
                    <div class="skill-category">
                        <h3>Programming & Web</h3>
                        <ul>
                            <li>PHP (CodeIgniter) & Python (Flask)</li>
                            <li>HTML5, CSS, JavaScript</li>
                            <li>MySQL Database Management</li>
                        </ul>
                    </div>
                     <div class="skill-category">
                        <h3>Automation & Tools</h3>
                        <ul>
                            <li>Power Automate</li>
                            <li>Bash Scripting</li>
                            <li>Git & Version Control</li>
                            <li>Manual & Automated Testing</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Portfolio Section -->
        <section id="portfolio">
            <div class="container">
                <div class="section-title"><h2>Portfolio</h2></div>
                <div class="portfolio-grid">
                    <div class="portfolio-card">
                        <img src="https://placehold.co/600x400/192B2A/FFFFFF?text=PIMIS" alt="PIMIS Project Screenshot">
                        <div class="portfolio-content">
                            <h3>PIMIS - Public Investment Management System</h3>
                            <p>A system for the National Treasury. Project TENDER NO. TNT/025/2020-2021.</p>
                            <a href="https://pimisdev.treasury.go.ke/" target="_blank" class="btn">View Project</a>
                        </div>
                    </div>
                    <div class="portfolio-card">
                        <img src="https://placehold.co/600x400/192B2A/FFFFFF?text=ECIPMS" alt="ECIPMS Project Screenshot">
                        <div class="portfolio-content">
                            <h3>ECIPMS - County Integrated Planning Management System</h3>
                            <p>Automated M&E system for Kakamega County Government. CONTRACT FOR THE SUPPLY, INSTALLATION AND COMMISSIONING OF STANDARDIZED AUTOMATED MONITORING AND EVALUATION SYSTEM. Project TENDER NO. CGKK/OG/2020/2021/01.</p>
                             <a href="https://ecipms.kingsway.co.ke/" target="_blank" class="btn">View Project</a>
                        </div>
                    </div>
                     <div class="portfolio-card">
                        <img src="https://placehold.co/600x400/192B2A/FFFFFF?text=IFMIS" alt="IFMIS Project Screenshot">
                        <div class="portfolio-content">
                            <h3>IFMIS - National Treasury</h3>
                            <p>Onsite support for IFMIS applications and E-Procurement enhancement. TENDER FOR PROVISION OF ONSITE SUPPORT FOR IFMIS APPLICATIONS AND ENHANCEMENT OF IFMIS E-PROCUREMENT. Project TENDER NO. TNT/029/2019-2020.</p>
                        </div>
                    </div>
                    <div class="portfolio-card">
                        <img src="https://placehold.co/600x400/192B2A/FFFFFF?text=Oracle" alt="Oracle Project Screenshot">
                        <div class="portfolio-content">
                            <h3>Oracle E-Procurement - National Treasury</h3>
                            <p>Provision of Oracle application support licenses. TENDER FOR THE PROVISION OF ORACLE APPLICATION SUPPORT LICENSES. Project TENDER NO. TNT/026/2019-2020.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Work History Section -->
        <section id="work">
            <div class="container">
                <div class="section-title"><h2>Work Experience</h2></div>
                <div class="work-timeline">
                    <div class="work-item">
                        <div class="work-content">
                            <p class="date">2021-01 - Current</p>
                            <h3>ICT Support</h3>
                            <p class="company">Kingsway Business Systems LTD</p>
                            <ul>
                                <li>Provided technical support, troubleshooting hardware/software, and environment setup for key government information systems (PIMIS, ECIPMS, IFMIS).</li>
                                <li>Maintained infrastructure, including software patching/updates, backups, and system performance monitoring.</li>
                                <li>Conducted manual and automated testing; maintained testing environments.</li>
                                <li>Documented technical procedures, installation instructions, and system specifications.</li>
                                <li>Delivered developer and user training sessions on new technologies and system features.</li>
                                <li>Interfaced with project managers and business users on technical matters.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Education Section -->
        <section id="education">
            <div class="container">
                <div class="section-title"><h2>Education & Certifications</h2></div>
                <div class="education-grid">
                    <div class="education-item">
                        <h3>Computer Science</h3>
                        <h4>Zetech University - Ruiru</h4>
                        <p>Graduated: 2021-11</p>
                    </div>
                    <div class="education-item">
                        <h3>Certificate: CCNA 1-3 & Cyber Ops</h3>
                        <h4>Zetech University - Ruiru</h4>
                        <p>Completed: 2020-09</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Personal Details -->
        <section id="personal-details">
             <div class="container">
                <div class="personal-details-grid">
                    <div class="detail-card">
                        <h3>Languages</h3>
                        <ul><li>English</li><li>Kiswahili</li></ul>
                    </div>
                     <div class="detail-card">
                        <h3>Interests</h3>
                        <ul><li>E-Sports</li><li>Basketball</li><li>Travelling</li></ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- References Section -->
        <section id="references">
            <div class="container">
                <div class="section-title"><h2>References</h2></div>
                <div class="references-grid">
                    <div class="reference-item">
                        <p class="name">Kenneth Kadenge</p><p class="title">Project Manager, Kingsway Business Service Ltd.</p>
                        <p>Tel: 0722 310 030</p>
                    </div>
                    <div class="reference-item">
                        <p class="name">Dan Njiru</p><p class="title">Head of Department, Zetech University</p>
                        <p>Tel: 0719 321 351</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact">
            <div class="container">
                 <div class="section-title"><h2>Get In Touch</h2></div>
                <div class="contact-grid">
                    <div class="contact-info">
                        <div class="info-item"><div class="icon">📍</div><div><h4>Address</h4><p>00100, Nairobi Kenya</p></div></div>
                        <div class="info-item"><div class="icon">✉️</div><div><h4>Email</h4><p><a href="mailto:nehemiaobati@gmail.com">nehemiaobati@gmail.com</a></p></div></div>
                        <div class="info-item"><div class="icon">📞</div><div><h4>Phone</h4><p><a href="tel:+254794587533">+254794587533</a></p></div></div>
                    </div>
                    <form action="/contact/submit" method="POST" class="contact-form">
                        <div class="form-group"><input type="text" name="name" placeholder="Name" required></div>
                        <div class="form-group"><input type="email" name="email" placeholder="Email" required></div>
                        <div class="form-group"><textarea name="message" placeholder="Your Message" required></textarea></div>
                        <button type="submit" class="btn">Send Message</button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <footer class="main-footer">
        <div class="container">
            <p>© 2025 Nehemia Obati. All Rights Reserved.</p>
        </div>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const header = document.getElementById('mainHeader');
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navLinks = mainNav.querySelectorAll('a');

        // Sticky Header on Scroll
        window.addEventListener('scroll', () => {
            header.classList.toggle('scrolled', window.scrollY > 50);
        });
        
        // Mobile Menu Toggle
        menuToggle.addEventListener('click', () => {
            mainNav.classList.toggle('active');
        });
        
        // Close mobile menu when a link is clicked
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if(mainNav.classList.contains('active')){
                    mainNav.classList.remove('active');
                }
            });
        });
    });
    </script>
</body>
</html>
