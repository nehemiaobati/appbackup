<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use App\Services\MailService;
use PDO;
use PDOException;

class AuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function showLogin(): void
    {
        $this->render('login');
    }

    public function handleLogin(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $_SESSION['error_message'] = "Username and password are required.";
            header('Location: /login');
            exit;
        }

        $stmt = $this->pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: /dashboard');
            exit;
        } else {
            $_SESSION['error_message'] = "Invalid username or password.";
            header('Location: /login');
            exit;
        }
    }

    public function showRegister(): void
    {
        $this->render('register');
    }

    public function handleRegister(): void
    {
        try {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';

            if (empty($username) || empty($email) || empty($password)) {
                throw new \Exception("All fields are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid email address format.");
            }
            if ($password !== $password_confirm) {
                throw new \Exception("Passwords do not match.");
            }
            if (strlen($password) < 8) {
                throw new \Exception("Password must be at least 8 characters long.");
            }

            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                throw new \Exception("Username or email is already registered.");
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            
            // Send welcome email
            $mailService = new MailService();
            $welcomeSubject = "Welcome to Afrikenkid!";
            $welcomeBody = "<h1>Welcome, {$username}!</h1><p>Thank you for registering with Afrikenkid. We're excited to have you on board.</p>";
            $mailService->sendEmail($email, $username, $welcomeSubject, $welcomeBody);

            $_SESSION['success_message'] = "Registration successful! You can now log in.";
            header('Location: /login');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            header('Location: /register');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /register');
            exit;
        }
    }

    public function handleLogout(): void
    {
        if (isset($_SESSION['user_id'])) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();
        }
        header('Location: /login');
        exit;
    }

    private function render(string $template, array $data = []): void
    {
        extract($data);
        $current_user_id = $_SESSION['user_id'] ?? null;
        $username_for_header = $_SESSION['username'] ?? null;
        $view = $template; // For layout.php to know which nav item to highlight

        ob_start();
        require __DIR__ . "/../../templates/{$template}.php";
        $content = ob_get_clean();
        require __DIR__ . "/../../templates/layout.php";
    }
}
