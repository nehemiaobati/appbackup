<div id="bot-overview-page" data-config-id="<?= $config_data['id'] ?>">
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Overview: <span id="breadcrumb-bot-name"><?= htmlspecialchars($config_data['name']) ?></span></li></ol></nav>
<div class="row">
    <!-- Left Column -->
    <div class="col-lg-8">
        <!-- Bot Status -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="bi bi-activity"></i> Bot Status</h5></div>
            <div class="card-body placeholder-glow">
                <div class="row">
                    <div class="col-md-4"><strong>Status:</strong> <span id="bot-status-text"><span class="placeholder col-6"></span></span></div>
                    <div class="col-md-4"><strong>PID:</strong> <span id="bot-pid"><span class="placeholder col-4"></span></span></div>
                    <div class="col-md-4"><strong>Last Heartbeat:</strong> <span id="bot-heartbeat"><span class="placeholder col-8"></span></span></div>
                </div>
                <div id="bot-messages-container" class="mt-3"></div>
            </div>
            <div class="card-footer bg-white text-end" id="bot-controls-container">
                <button class="btn btn-success disabled placeholder col-2" aria-disabled="true"></button>
            </div>
        </div>

        <!-- Performance Summary -->
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Performance Summary</h5></div>
            <div class="card-body text-center placeholder-glow">
                <div class="row">
                    <div class="col-md-3 col-6"><h6 class="text-muted">Total Profit (USDT)</h6><h4 id="perf-total-profit"><span class="placeholder col-5"></span></h4></div>
                    <div class="col-md-3 col-6"><h6 class="text-muted">Trades Executed</h6><h4 id="perf-trades-executed"><span class="placeholder col-3"></span></h4></div>
                    <div class="col-md-3 col-6"><h6 class="text-muted">Win Rate</h6><h4 id="perf-win-rate"><span class="placeholder col-4"></span></h4></div>
                    <div class="col-md-3 col-6"><h6 class="text-muted">Last Trade Ago</h6><h4 id="perf-last-trade-ago"><span class="placeholder col-6"></span></h4></div>
                </div>
            </div>
        </div>
        
        <!-- NEW: AI Strategy Directives Editor -->
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-diagram-3"></i> AI Strategy Directives</h5></div>
            <div class="card-body">
                <form id="update-strategy-form">
                    <input type="hidden" name="strategy_id" id="strategy-id-input" value="">
                    <div class="mb-3">
                        <label for="strategy-json-editor" class="form-label">
                            Live strategy JSON for <strong id="strategy-name-label">...</strong> (v<span id="strategy-version-label">...</span>). 
                            <span class="text-muted">Last updated by <strong id="strategy-updater-label">...</strong> on <span id="strategy-updated-label">...</span></span>
                        </label>
                        <textarea class="form-control" id="strategy-json-editor" name="strategy_json" rows="15" placeholder="Loading strategy..."></textarea>
                        <div class="form-text">Caution: Modifying these directives directly impacts the AI's decision-making process. Ensure the JSON is valid.</div>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Save Strategy</button>
                </form>
            </div>
        </div>

        <!-- Recent Trades -->
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-table"></i> Recent Trades</h5></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead><tr><th>Symbol</th><th>Side</th><th>Qty</th><th>Price</th><th>P/L (USDT)</th><th>Timestamp</th><th>Info</th></tr></thead>
                    <tbody id="recent-trades-body">
                        <tr><td colspan="7" class="text-center p-4"><div class="spinner-border spinner-border-sm" role="status"></div> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI Decisions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-cpu"></i> AI Decisions & Feedback</h5></div>
            <div class="card-body p-2" id="ai-logs-container">
               <p class="text-center text-muted p-4"><div class="spinner-border spinner-border-sm" role="status"></div> Loading...</p>
            </div>
        </div>
    </div>
    
    <!-- Right Column -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-sliders"></i> Bot Configuration</h5></div>
            <div class="card-body">
                <form id="update-config-form" method="post" action="/api/bots/update-config">
                    <input type="hidden" name="config_id" value="<?= $config_data['id'] ?>">
                    <div class="mb-3"><label class="form-label">Config Name</label><input type="text" class="form-control" name="name" value="<?= htmlspecialchars($config_data['name'] ?? '') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Trading Symbol</label><input type="text" class="form-control" name="symbol" value="<?= htmlspecialchars($config_data['symbol'] ?? 'BTCUSDT') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Margin Asset</label><input type="text" class="form-control" name="margin_asset" value="<?= htmlspecialchars($config_data['margin_asset'] ?? 'USDT') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Kline Interval</label><input type="text" class="form-control" name="kline_interval" value="<?= htmlspecialchars($config_data['kline_interval'] ?? '1m') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Default Leverage</label><input type="number" class="form-control" name="default_leverage" value="<?= $config_data['default_leverage'] ?? 10 ?>" required></div>
                    <div class="mb-3"><label class="form-label">AI Update Interval (s)</label><input type="number" class="form-control" name="ai_update_interval_seconds" value="<?= $config_data['ai_update_interval_seconds'] ?? 60 ?>" required></div>
                    <div class="mb-3"><label class="form-label">Order Check Interval (s)</label><input type="number" class="form-control" name="order_check_interval_seconds" value="<?= $config_data['order_check_interval_seconds'] ?? 45 ?>" required></div>
                    <div class="mb-3"><label class="form-label">Pending Order Timeout (s)</label><input type="number" class="form-control" name="pending_entry_order_cancel_timeout_seconds" value="<?= $config_data['pending_entry_order_cancel_timeout_seconds'] ?? 60 ?>" required></div>
                    <div class="mb-3"><label class="form-label">Profit Check Interval (s)</label><input type="number" class="form-control" name="profit_check_interval_seconds" value="<?= $config_data['profit_check_interval_seconds'] ?? 60 ?>" required></div>
                    <div class="mb-3"><label class="form-label">Initial Margin Target (USDT)</label><input type="text" class="form-control" name="initial_margin_target_usdt" value="<?= rtrim(rtrim(number_format((float)$config_data['initial_margin_target_usdt'], 8), '0'), '.') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Auto Take Profit (USDT)</label><input type="text" class="form-control" name="take_profit_target_usdt" value="<?= rtrim(rtrim(number_format((float)$config_data['take_profit_target_usdt'], 8), '0'), '.') ?>" required></div>
                    <div class="mb-3"><label class="form-label">Quantity Calculation Method</label>
                        <select class="form-select" name="quantity_determination_method">
                            <option value="INITIAL_MARGIN_TARGET" <?= ($config_data['quantity_determination_method'] ?? '') === 'INITIAL_MARGIN_TARGET' ? 'selected' : '' ?>>Fixed (Initial Margin Target)</option>
                            <option value="AI_SUGGESTED" <?= ($config_data['quantity_determination_method'] ?? '') === 'AI_SUGGESTED' ? 'selected' : '' ?>>Dynamic (AI Suggested)</option>
                        </select>
                    </div>
                    <div class="mb-3 d-flex flex-column">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="use_testnet" id="use_testnet_edit" value="1" <?= !empty($config_data['use_testnet']) ? 'checked' : '' ?>><label class="form-check-label" for="use_testnet_edit">Use Testnet</label></div>
                        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active_edit" value="1" <?= !empty($config_data['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="is_active_edit">Enable Bot</label></div>
                        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" name="allow_ai_to_update_strategy" id="allow_ai_update_edit" value="1" <?= !empty($config_data['allow_ai_to_update_strategy']) ? 'checked' : '' ?>><label class="form-check-label" for="allow_ai_update_edit">Allow AI to Update Strategy</label></div>
                    </div>
                    
                    <hr>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Update Configuration</button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
