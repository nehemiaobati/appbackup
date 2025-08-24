<?php
declare(strict_types=1);
// This template displays a list of all users and their balances.
// It expects an array of $users, each with id, username, email, role, and balance_cents.
?>

<h2 class="mb-4">Admin Panel: All Users and Balances</h2>

<?php if (empty($users)): ?>
    <div class="alert alert-info" role="alert">
        No users found in the system.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Balance (KES)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$user['id']) ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['role']) ?></td>
                        <td><?= number_format($user['balance_cents'] / 100, 2) ?></td>
                        <td>
                            <!-- Example action: Delete User. This would typically be a POST request. -->
                            <form action="/admin/delete-user" method="POST" style="display:inline-block;">
                                <input type="hidden" name="user_id" value="<?= htmlspecialchars((string)$user['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete user <?= htmlspecialchars($user['username']) ?>?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
