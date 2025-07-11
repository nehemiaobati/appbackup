<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFRIKENKID</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="container-fluid mt-4">
    <header class="d-flex justify-content-between align-items-center p-3 my-3 text-white bg-dark rounded shadow-sm">
        <h4 class="mb-0"><i class="bi bi-robot"></i> AFRIKENKID </h4>
        <?php if (isset($current_user_id)): ?>
            <div>
                <span class="me-3">User: <strong><?= htmlspecialchars($username_for_header) ?></strong></span>
                <a href="/logout" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        <?php endif; ?>
    </header>

    <div id="alert-container">
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_SESSION['success_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($_SESSION['error_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php unset($_SESSION['error_message']); endif; ?>
    </div>

    <?php if (isset($current_user_id)): // User is logged in, show protected views ?>
        <ul class="nav nav-pills mb-3">
          <li class="nav-item"><a href="/dashboard" class="nav-link <?= in_array($view, ['dashboard', 'create_config', 'bot_detail']) ? 'active' : '' ?>"><i class="bi bi-gear-wide-connected"></i> Bots Dashboard</a></li>
          <li class="nav-item"><a href="/api-keys" class="nav-link <?= $view === 'api_keys' ? 'active' : '' ?>"><i class="bi bi-key-fill"></i> API Keys</a></li>
          <li class="nav-item"><a href="#" class="nav-link disabled"><i class="bi bi-wrench-adjustable-circle"></i> Settings</a></li>
        </ul>
        <?= $content ?>
    <?php else: // User is NOT logged in, show public views ?>
        <?= $content ?>
    <?php endif; ?>
    <footer class="text-center text-muted mt-5 py-3 border-top">
        © <?= date('Y') ?> AFRIKENKID. All Rights Reserved.
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($current_user_id)): // Only include dashboard JS for logged-in users ?>
<script src="/assets/js/app.js"></script>
<?php endif; ?>

</body>
</html>
