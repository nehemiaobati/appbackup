<?php
// Set the view variable for the layout file
$view = 'landing';
// Define username for header if logged in (though landing page is for non-logged in)
// This is just to ensure the layout doesn't break if it expects these variables.
// For a truly public page, these might not be needed or should be handled differently.
$username_for_header = $_SESSION['username'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;
?>

<?php // Removed ob_start(); from here ?>

<main class="container mt-5">
    <section class="text-center mb-5">
        <h1>Welcome to AFRIKENKID</h1>
        <p class="lead">Your ultimate solution for managing and deploying AI-powered bots.</p>
        <p>Discover how our platform can help you automate tasks, optimize strategies, and achieve your goals.</p>
        <div class="mt-4">
            <a href="#features" class="btn btn-primary btn-lg me-2"><i class="bi bi-star-fill"></i> Features</a>
            <a href="#contact" class="btn btn-outline-secondary btn-lg"><i class="bi bi-envelope-fill"></i> Contact Us</a>
            <a href="/login" class="btn btn-outline-success btn-lg me-2"><i class="bi bi-box-arrow-in-right"></i> Login</a>
            <a href="/register" class="btn btn-outline-info btn-lg"><i class="bi bi-person-plus-fill"></i> Register</a>
        </div>
    </section>

    <hr class="my-5">

    <section id="features" class="mb-5">
        <h2>Key Features</h2>
        <div class="row g-4">
            <div class="col-md-4 text-center">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-robot display-4 text-primary mb-3"></i>
                        <h5 class="card-title">AI Bot Management</h5>
                        <p class="card-text">Easily deploy, monitor, and manage your AI bots with our intuitive dashboard.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-graph-up display-4 text-success mb-3"></i>
                        <h5 class="card-title">Performance Analytics</h5>
                        <p class="card-text">Gain insights into bot performance with real-time analytics and detailed reports.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <i class="bi bi-shield-fill-check display-4 text-danger mb-3"></i>
                        <h5 class="card-title">Secure & Reliable</h5>
                        <p class="card-text">Your data and bots are protected with industry-leading security measures.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <hr class="my-5">

    <section id="contact" class="mb-5">
        <h2>Get In Touch</h2>
        <p>Have questions or need support? Reach out to us!</p>
        <div class="row">
            <div class="col-md-6">
                <form action="/contact/submit" method="POST" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                        <div class="invalid-feedback">Please enter your name.</div>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                        <div class="invalid-feedback">Please enter a valid email address.</div>
                    </div>
                    <div class="mb-3">
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                        <div class="invalid-feedback">Please enter your message.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i> Send Message</button>
                </form>
            </div>
            <div class="col-md-6 ps-md-5">
                <h5 class="mb-3">Contact Information</h5>
                <p><i class="bi bi-geo-alt-fill text-primary"></i> 123 AI Street, Innovation City, CA 90210</p>
                <p><i class="bi bi-envelope-fill text-primary"></i> support@afrikenkid.com</p>
                <p><i class="bi bi-telephone-fill text-primary"></i> +1 (555) 123-4567</p>
                <div class="mt-4">
                    <a href="#" class="btn btn-outline-dark me-2"><i class="bi bi-linkedin"></i></a>
                    <a href="#" class="btn btn-outline-dark me-2"><i class="bi bi-twitter"></i></a>
                    <a href="#" class="btn btn-outline-dark"><i class="bi bi-github"></i></a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php
// Removed $content = ob_get_clean(); from here.
// The showLandingPage method in AuthController now handles capturing the output.
?>
