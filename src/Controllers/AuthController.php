<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use App\Services\MailService;
use PDO;
use PDOException;
use App\Services\RecaptchaService;

class AuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function showLogin(): void
    {
        // If the user is already logged in, redirect them to the dashboard.
        if (isset($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }
        $this->render('login');
    }

    public function handleLogin(): void
    {
        // Check if the user is already logged in
        if (isset($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $username = trim(htmlspecialchars($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

        // Check if username, password, or reCAPTCHA response is empty
        if (empty($username) || empty($password) || empty($recaptchaResponse)) {
            $_SESSION['error_message'] = "Username, password, and reCAPTCHA are required.";
            header('Location: /login');
            exit;
        }

        $recaptchaService = new RecaptchaService();
        if (!$recaptchaService->verify($recaptchaResponse)) {
            $_SESSION['error_message'] = "reCAPTCHA verification failed. Please try again.";
            header('Location: /login');
            exit;
        }

        $stmt = $this->pdo->prepare("SELECT id, username, email, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Update the last_login timestamp
            $updateStmt = $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_email'] = $user['email']; // Store user email in session for later use (e.g., pre-filling payment forms)
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
            $username = trim(htmlspecialchars($_POST['username'] ?? ''));
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            $recaptchaResponse = trim(htmlspecialchars($_POST['g-recaptcha-response'] ?? ''));

            if (empty($username) || empty($email) || empty($password) || empty($recaptchaResponse)) {
                throw new \Exception("All fields and reCAPTCHA are required.");
            }
            $recaptchaService = new RecaptchaService();
            if (!$recaptchaService->verify($recaptchaResponse)) {
                throw new \Exception("reCAPTCHA verification failed. Please try again.");
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

    // Removed the dashboard method as per user request to move balance logic to BotController.
    // The /dashboard route will now be handled by BotController.

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

    /**
     * Renders the landing page.
     */
    public function showLandingPage(): void
    {
        // Set the view variable for the layout file to highlight the correct nav item (if any)
        // For a landing page, no specific nav item is active by default.
        $view = 'landing'; 
        
        // Ensure session variables are available for layout.php, even if null
        $username_for_header = $_SESSION['username'] ?? '';
        $current_user_id = $_SESSION['user_id'] ?? null;

        // Start output buffering for the content of the landing page
        ob_start();
        // Include the landing page template
        require __DIR__ . "/../../templates/landing.php";
        // Get the buffered content and assign it to the $content variable
        $content = ob_get_clean();
        
        // Include the main layout file to render the complete page
        require __DIR__ . "/../../templates/layout.php";
    }
}
