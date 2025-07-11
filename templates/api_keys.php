<div class="row"><div class="col-lg-7">
<div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-key-fill"></i> Your API Key Sets</h5></div>
<div class="card-body">
    <?php if (empty($user_keys)): ?><p class="text-muted">You have not added any API key sets yet.</p>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Name</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($user_keys as $key): ?>
            <tr><td><?= htmlspecialchars($key['key_name']) ?></td><td><?= date('Y-m-d H:i', strtotime($key['created_at'])) ?></td><td><span class="badge bg-<?= $key['is_active'] ? 'success' : 'secondary' ?>"><?= $key['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><form method="post" action="/api-keys"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key_id" value="<?= $key['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div></div></div>
<div class="col-lg-5">
<div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-plus-circle-fill"></i> Add New API Key Set</h5></div>
<div class="card-body">
    <form method="post" action="/api-keys"><input type="hidden" name="action" value="add_key">
        <div class="mb-3"><label for="key_name" class="form-label">Key Set Name</label><input type="text" id="key_name" class="form-control" name="key_name" placeholder="e.g., My Mainnet Key" required></div>
        <div class="mb-3"><label for="binance_api_key" class="form-label">Binance API Key</label><input type="password" id="binance_api_key" class="form-control" name="binance_api_key" required></div>
        <div class="mb-3"><label for="binance_api_secret" class="form-label">Binance API Secret</label><input type="password" id="binance_api_secret" class="form-control" name="binance_api_secret" required></div>
        <div class="mb-3"><label for="gemini_api_key" class="form-label">Gemini API Key</label><input type="password" id="gemini_api_key" class="form-control" name="gemini_api_key" required></div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Save Securely</button>
    </form>
</div></div></div></div>
