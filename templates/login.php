<div class="row justify-content-center"><div class="col-md-6 col-lg-4"><div class="card shadow-lg"><div class="card-header text-center"><h4><i class="bi bi-box-arrow-in-right"></i> Secure Login</h4></div><div class="card-body p-4">
    <?php
    // Display error message if set in session
    if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['error_message']); ?>
        </div>
        <?php unset($_SESSION['error_message']); // Clear the message after displaying ?>
    <?php endif; ?>
    <?php
    // Display success message if set in session
    if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['success_message']); ?>
        </div>
        <?php unset($_SESSION['success_message']); // Clear the message after displaying ?>
    <?php endif; ?>
    <form method="post" action="/login"><input type="hidden" name="action" value="login"><div class="mb-3"><label for="username_login" class="form-label">Username</label><input type="text" id="username_login" class="form-control" name="username" required></div><div class="mb-3"><label for="password_login" class="form-label">Password</label><input type="password" id="password_login" class="form-control" name="password" required></div>
<div class="mb-3">
    <!-- reCAPTCHA v2 "I'm not a robot" checkbox -->
    <div class="g-recaptcha" data-sitekey="6LdqSp0rAAAAAJ31BoyRO5XxhWMF-ztVkL2bX6bw"></div>
</div>
<button type="submit" class="btn btn-dark w-100">Login</button></form>
</div><div class="card-footer text-center"><a href="/register">Create a new account</a></div></div></div></div>
