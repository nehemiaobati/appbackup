<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use PDO;
use Exception;

class AdminController extends BaseController
{
    /**
     * Ensures the user is authenticated and has an 'admin' role.
     * If not, redirects to the login page or dashboard.
     *
     * @return void
     */
    protected function checkAdminAuth(): void
    {
        $this->checkAuth(); // First, ensure user is logged in
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            $_SESSION['error_message'] = "Access denied. You do not have administrative privileges.";
            header('Location: /dashboard'); // Redirect non-admin users
            exit;
        }
    }

    /**
     * Displays a list of all users and their balances.
     * Accessible only to admin users.
     *
     * @return void
     */
    public function showUsersAndBalances(): void
    {
        $this->checkAdminAuth();

        try {
            $stmt = $this->pdo->prepare("SELECT id, username, email, role, balance_cents FROM users ORDER BY username ASC");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->render('admin_users', ['users' => $users]);
        } catch (Exception $e) {
            error_log("AdminController Error fetching users: " . $e->getMessage());
            $_SESSION['error_message'] = "Error fetching user data.";
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * Handles the deletion of a user.
     * Accessible only to admin users.
     *
     * @return void
     */
    public function handleDeleteUser(): void
    {
        $this->checkAdminAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error_message'] = "Invalid request method.";
            header('Location: /admin/users');
            exit;
        }

        $userIdToDelete = (int)($_POST['user_id'] ?? 0);

        if ($userIdToDelete <= 0) {
            $_SESSION['error_message'] = "Invalid user ID provided.";
            header('Location: /admin/users');
            exit;
        }

        // Prevent an admin from deleting themselves
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userIdToDelete) {
            $_SESSION['error_message'] = "You cannot delete your own admin account.";
            header('Location: /admin/users');
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Due to ON DELETE CASCADE, related records in other tables (bot_configurations, user_api_keys, etc.)
            // will be automatically deleted when the user is deleted.
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userIdToDelete]);

            $this->pdo->commit();
            $_SESSION['success_message'] = "User with ID {$userIdToDelete} deleted successfully.";
            header('Location: /admin/users');
            exit;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("AdminController Error deleting user {$userIdToDelete}: " . $e->getMessage());
            $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
            header('Location: /admin/users');
            exit;
        }
    }
}
