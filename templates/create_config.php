<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li><li class="breadcrumb-item active">Create Bot</li></ol></nav>
<div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-plus-circle-fill"></i> Create New Bot Configuration</h5></div>
<div class="card-body">
    <?php if (empty($user_api_keys)): ?>
        <div class="alert alert-warning"><strong>Action Required:</strong> You must <a href="/api-keys">add an API Key set</a> before you can create a bot.</div>
    <?php else: ?>
    <form method="post" action="/create-bot">
        <input type="hidden" name="action" value="create_config">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Configuration Name</label><input type="text" class="form-control" name="name" value="" placeholder="e.g., My BTC Test Bot" required></div>
            <div class="col-md-6"><label class="form-label">API Key Set</label><select class="form-select" name="user_api_key_id" required><option value="" disabled selected>-- Select an API Key --</option><?php foreach ($user_api_keys as $key): ?><option value="<?= htmlspecialchars((string)$key['id']) ?>"><?= htmlspecialchars($key['key_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Trading Symbol</label><input type="text" class="form-control" name="symbol" value="BTCUSDT" required></div>
            <div class="col-md-4"><label class="form-label">Margin Asset</label><input type="text" class="form-control" name="margin_asset" value="USDT" required></div>
            <div class="col-md-4"><label class="form-label">Kline Interval</label><input type="text" class="form-control" name="kline_interval" value="1m" required></div>
            <div class="col-md-4"><label class="form-label">Default Leverage</label><input type="number" class="form-control" name="default_leverage" value="100" required></div>
            <div class="col-md-4"><label class="form-label">AI Update Interval (s)</label><input type="number" class="form-control" name="ai_update_interval_seconds" value="60" required></div>
            <div class="col-md-4"><label class="form-label">Order Check Interval (s)</label><input type="number" class="form-control" name="order_check_interval_seconds" value="45" required></div>
            <div class="col-md-4"><label class="form-label">Pending Order Timeout (s)</label><input type="number" class="form-control" name="pending_entry_order_cancel_timeout_seconds" value="60" required></div>
            <div class="col-md-4"><label class="form-label">Profit Check Interval (s)</label><input type="number" class="form-control" name="profit_check_interval_seconds" value="60" required></div>
            <div class="col-md-4"><label class="form-label">Initial Margin Target (USDT)</label><input type="text" class="form-control" name="initial_margin_target_usdt" value="1.50" required></div>
            <div class="col-md-6"><label class="form-label">Auto Take Profit (USDT)</label><input type="text" class="form-control" name="take_profit_target_usdt" value="0.00" required></div>
            <div class="col-md-6"><label class="form-label">Quantity Calculation Method</label><select class="form-select" name="quantity_determination_method"><option value="INITIAL_MARGIN_TARGET" selected>Fixed (Initial Margin Target)</option><option value="AI_SUGGESTED">Dynamic (AI Suggested)</option></select></div>
            <div class="col-md-12 pt-3">
                <div class="form-check form-switch d-inline-block me-4"><input class="form-check-input" type="checkbox" role="switch" name="use_testnet" id="use_testnet" value="1" checked><label class="form-check-label" for="use_testnet">Use Testnet</label></div>
                <div class="form-check form-switch d-inline-block me-4"><input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" checked><label class="form-check-label" for="is_active">Enable Bot</label></div>
                <div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" name="allow_ai_to_update_strategy" id="allow_ai_to_update_strategy" value="1"><label class="form-check-label" for="allow_ai_to_update_strategy">Allow AI to Update Strategy</label></div>
            </div>
        </div><hr><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Configuration</button><a href="/dashboard" class="btn btn-secondary">Cancel</a>
    </form>
    <?php endif; ?>
</div></div>
