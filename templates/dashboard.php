<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h5 class="card-title"><i class="bi bi-person-circle"></i> Welcome, <?= htmlspecialchars($username ?? 'User') ?>!</h5>
        <p class="card-text">
            <?php if ($balance !== null): ?>
                Your Total Successful Balance: <strong><?= number_format($balance / 100, 2) ?> KES</strong>
            <?php elseif ($balance_error_message): ?>
                <span class="text-danger"><?= htmlspecialchars($balance_error_message) ?></span>
            <?php else: ?>
                Your Total Successful Balance: <strong>Loading...</strong>
            <?php endif; ?>
        </p>
    </div>
</div>
<div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><h5><i class="bi bi-gear-wide-connected"></i> Bot Configurations & Status</h5><a href="/create-bot" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle-fill"></i> Create New Bot</a></div>
<div class="card-body">
    <div id="bot-cards-container" class="row g-4">
        <div class="col-12 text-center p-4">
            <div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>
            <p class="mt-2 text-muted">Loading bot statuses...</p>
        </div>
    </div>

    <template id="bot-card-template">
        <div class="col-md-6 col-lg-4">
            <div class="card bot-card shadow-sm">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title mb-1">
                        <a href="#" class="text-decoration-none text-dark bot-name"></a>
                    </h5>
                    <h6 class="card-subtitle mb-2 text-muted bot-symbol"></h6>
                    <div class="mb-3">
                        Status: <span class="badge rounded-pill bot-status"></span>
                    </div>
                    <div class="mb-3">
                        Total P/L: <span class="fw-bold bot-profit-value"></span>
                    </div>
                    <div class="mt-auto">
                        <div class="btn-group w-100 bot-actions" role="group"></div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div></div>
