<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailService;
use PDOException;
use App\Services\RecaptchaService;
use Exception; // Explicitly import Exception for clarity

/**
 * AuthController.php
 *
 * This Controller handles all user authentication-related operations,
 * including displaying login/registration forms, processing user credentials,
 * and managing user sessions. It interacts with the Database and RecaptchaService
 * for data persistence and security, and MailService for sending welcome emails.
 */

class AuthController extends BaseController
{
    /**
     * Displays the login page.
     * If the user is already logged in, they are redirected to the dashboard.
     *
     * @return void
     */
    public function showLogin(): void
    {
        // Redirect to dashboard if user is already authenticated.
        // Using checkAuth from BaseController, but for showLogin, we want to redirect if *already* logged in.
        // So, we keep this specific check here.
        if (isset($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }
        $this->render('login');
    }

    /**
     * Handles user login attempts via POST request.
     * Validates input, verifies reCAPTCHA, authenticates user credentials,
     * and manages session state upon successful login.
     *
     * @param string $_POST['username'] The submitted username.
     * @param string $_POST['password'] The submitted password.
     * @param string $_POST['g-recaptcha-response'] The reCAPTCHA token.
     * @return void Redirects to dashboard on success, or back to login with an error message.
     */
    public function handleLogin(): void
    {
        // Prevent re-login if already authenticated.
        if (isset($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $username = trim(htmlspecialchars($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

        // Input validation: Check for empty fields.
        if (empty($username) || empty($password) || empty($recaptchaResponse)) {
            $_SESSION['error_message'] = "Username, password, and reCAPTCHA are required.";
            header('Location: /login');
            exit;
        }

        // reCAPTCHA verification.
        $recaptchaService = new RecaptchaService();
        if (!$recaptchaService->verify($recaptchaResponse)) {
            $_SESSION['error_message'] = "reCAPTCHA verification failed. Please try again.";
            header('Location: /login');
            exit;
        }

        // Fetch user from database using prepared statement to prevent SQL injection.
        $stmt = $this->pdo->prepare("SELECT id, username, email, password_hash, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Verify password and manage session.
        if ($user && password_verify($password, $user['password_hash'])) {
            // Update the last_login timestamp for the user.
            $updateStmt = $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            // Regenerate session ID for security (session fixation prevention).
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_email'] = $user['email']; // Store email for potential future use (e.g., pre-filling forms).
            $_SESSION['user_role'] = $user['role']; // Store user role in session
            header('Location: /dashboard');
            exit;
        } else {
            $_SESSION['error_message'] = "Invalid username or password.";
            header('Location: /login');
            exit;
        }
    }

    /**
     * Displays the user registration page.
     *
     * @return void
     */
    public function showRegister(): void
    {
        $this->render('register');
    }

    /**
     * Handles new user registration via POST request.
     * Validates input, verifies reCAPTCHA, checks for existing users,
     * hashes password, inserts new user into database, and sends a welcome email.
     *
     * @param string $_POST['username'] The desired username.
     * @param string $_POST['email'] The user's email address.
     * @param string $_POST['password'] The chosen password.
     * @param string $_POST['password_confirm'] Confirmation of the chosen password.
     * @param string $_POST['g-recaptcha-response'] The reCAPTCHA token.
     * @return void Redirects to login on success, or back to register with an error message.
     */
    public function handleRegister(): void
    {
        try {
            // Sanitize and trim incoming POST data.
            $username = trim(htmlspecialchars($_POST['username'] ?? ''));
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            $recaptchaResponse = trim(htmlspecialchars($_POST['g-recaptcha-response'] ?? ''));

            // Comprehensive input validation.
            if (empty($username) || empty($email) || empty($password) || empty($recaptchaResponse)) {
                throw new Exception("All fields and reCAPTCHA are required.");
            }
            $recaptchaService = new RecaptchaService();
            if (!$recaptchaService->verify($recaptchaResponse)) {
                throw new Exception("reCAPTCHA verification failed. Please try again.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address format.");
            }
            if ($password !== $password_confirm) {
                throw new Exception("Passwords do not match.");
            }
            if (strlen($password) < 8) {
                throw new Exception("Password must be at least 8 characters long.");
            }

            // Check if username or email already exists using prepared statement.
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                throw new Exception("Username or email is already registered.");
            }

            // Hash password securely before storing.
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            
            // Send welcome email to the new user.
            $mailService = new MailService();
            $welcomeSubject = "Welcome to Afrikenkid!";
            $welcomeBody = "<h1>Welcome, {$username}!</h1><p>Thank you for registering with Afrikenkid. We're excited to have you on board.</p>";
            $mailService->sendEmail($email, $username, $welcomeSubject, $welcomeBody);

            $_SESSION['success_message'] = "Registration successful! You can now log in.";
            header('Location: /login');
            exit;
        } catch (PDOException $e) {
            // Handle database-specific errors.
            error_log("Auth Registration Database Error: " . $e->getMessage()); // Log for debugging
            $_SESSION['error_message'] = "Database error during registration. Please try again later.";
            header('Location: /register');
            exit;
        } catch (Exception $e) {
            // Handle general application exceptions (e.g., validation errors).
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /register');
            exit;
        }
    }

    /**
     * Handles user logout.
     * Destroys the current session and redirects the user to the login page.
     *
     * @return void
     */
    public function handleLogout(): void
    {
        if (isset($_SESSION['user_id'])) {
            // Clear all session variables.
            $_SESSION = [];
            // Invalidate the session cookie.
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            // Destroy the session.
            session_destroy();
        }
        header('Location: /login');
        exit;
    }


    /**
     * Renders the landing page of the application.
     * This page is accessible to unauthenticated users.
     *
     * @return void
     */
    public function showLandingPage(): void
    {
        // Set the view variable for the layout file to highlight the correct nav item (if any).
        // For a landing page, no specific nav item is active by default.
        $view = 'landing'; 
        
        // Ensure session variables are available for layout.php, even if null.
        $username_for_header = $_SESSION['username'] ?? '';
        $current_user_id = $_SESSION['user_id'] ?? null;

        // Start output buffering for the content of the landing page.
        ob_start();
        // Include the landing page template.
        require __DIR__ . "/../../templates/landing.php";
        // Get the buffered content and assign it to the $content variable.
        $content = ob_get_clean();
        
        // Include the main layout file to render the complete page.
        require __DIR__ . "/../../templates/layout.php";
    }
}
