<?php
declare(strict_types=1);

/**
 * router.php
 *
 * This file serves as the central routing mechanism for the AFRIKENKID application.
 * It directs all incoming HTTP requests to the appropriate controller methods,
 * distinguishing between API endpoints (which return JSON responses) and
 * web routes (which render HTML templates). This separation ensures a clear
 * and organized structure for handling different types of client requests.
 */

use App\Controllers\AuthController;
use App\Controllers\BotController;
use App\Controllers\ApiKeyController;
use App\Controllers\ContactController;
use App\Controllers\ResumeController;
use App\Controllers\PaystackController;

/**
 * Helper function to get the current URI path without query string parameters.
 * This ensures consistent routing by removing query strings and trailing slashes.
 *
 * @return string The cleaned URI path (e.g., '/dashboard', '/api/bots/overview').
 */
function getUriPath(): string
{
    $uri = $_SERVER['REQUEST_URI'];
    $pos = strpos($uri, '?');
    if ($pos !== false) {
        $uri = substr($uri, 0, $pos);
    }
    return rtrim($uri, '/'); // Remove trailing slash for consistent routing
}

$path = getUriPath();
$method = $_SERVER['REQUEST_METHOD'];

// Initialize controllers for both API and Web routes.
// These controllers encapsulate the logic for handling specific application features.
$authController = new AuthController();
$botController = new BotController();
$apiKeyController = new ApiKeyController();
$contactController = new ContactController();
$resumeController = new ResumeController();
$paystackController = new PaystackController(); // Keep PaystackController for its web routes

/**
 * API Routes (JSON responses)
 * All API endpoints are prefixed with '/api'. These routes are designed to
 * return JSON data, typically for AJAX requests from the frontend.
 * Responses include a 'status' and 'message' or 'data' payload.
 */
$apiRoutes = [
    'bots/overview' => ['GET', [$botController, 'getBotOverviewApi']],
    'bots/statuses' => ['GET', [$botController, 'getBotStatusesApi']],
    'bots/start' => ['POST', [$botController, 'startBotApi']],
    'bots/stop' => ['POST', [$botController, 'stopBotApi']],
    'bots/delete' => ['POST', [$botController, 'handleDeleteConfig']],
    'bots/update-config' => ['POST', [$botController, 'handleUpdateConfig']],
    'bots/update-strategy' => ['POST', [$botController, 'handleUpdateStrategy']],
];

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    $api_action = substr($path, strlen('/api'));
    $api_action = trim($api_action, '/');

    try {
        $matched = false;
        foreach ($apiRoutes as $route_pattern => $route_config) {
            list($allowed_method, $controller_method) = $route_config;

            // Handle dynamic API routes like /api/bots/{id}/something
            if (strpos($route_pattern, '{id}') !== false) {
                $regex_pattern = str_replace('{id}', '(\d+)', $route_pattern);
                if (preg_match('#^' . $regex_pattern . '$#', $api_action, $matches)) {
                    if ($method === $allowed_method) {
                        $matched = true;
                        // Assuming dynamic routes pass ID as the first argument to the controller method
                        // This needs to be adjusted based on how your controller methods expect parameters
                        call_user_func($controller_method, (int)$matches[1]);
                        break;
                    }
                }
            } elseif ($api_action === $route_pattern) {
                if ($method === $allowed_method) {
                    $matched = true;
                    call_user_func($controller_method);
                    break;
                }
            }
        }

        if (!$matched) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'API endpoint not found or method not allowed.']);
        }
    } catch (Throwable $e) {
        error_log("API Error on {$path}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'API Error: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * Web Routes (HTML responses)
 * These routes are responsible for rendering HTML pages for the user interface.
 * They typically involve fetching data and passing it to a template for display.
 */
switch ($path) {
    /**
     * GET / (root)
     * Redirects the root path to the landing page.
     */
    case '':
    case '/':
        $authController->showLandingPage();
        exit;

    /**
     * GET /dashboard
     * Displays the main user dashboard, including bot configurations and user balance information.
     */
    case '/dashboard':
        $botController->dashboard();
        break;

    /**
     * /login
     * Handles user login functionality.
     * GET: Displays the login form.
     * POST: Processes submitted login credentials.
     */
    case '/login':
        if ($method === 'POST') {
            $authController->handleLogin();
        } else {
            $authController->showLogin();
        }
        break;

    /**
     * /register
     * Handles new user registration.
     * GET: Displays the registration form.
     * POST: Processes submitted registration data.
     */
    case '/register':
        if ($method === 'POST') {
            $authController->handleRegister();
        } else {
            $authController->showRegister();
        }
        break;

    /**
     * GET /logout
     * Logs out the current user by destroying the session.
     */
    case '/logout':
        $authController->handleLogout();
        break;

    /**
     * /api-keys
     * Manages API keys for external services (e.g., Binance, Gemini).
     * GET: Displays the API keys management page.
     * POST: Handles adding or deleting API keys based on the 'action' parameter.
     *
     * @param string $_POST['action'] 'add_key' or 'delete_key'.
     */
    case '/api-keys':
        if ($method === 'POST') {
            if (isset($_POST['action']) && $_POST['action'] === 'add_key') {
                $apiKeyController->handleAddKey();
            } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_key') {
                $apiKeyController->handleDeleteKey();
            } else {
                // Fallback for unknown POST action on /api-keys
                $_SESSION['error_message'] = "Invalid action for API Keys.";
                header('Location: /api-keys');
                exit;
            }
        } else {
            $apiKeyController->showApiKeys();
        }
        break;

    /**
     * /create-bot
     * Allows users to create new bot configurations.
     * GET: Displays the form to create a new bot.
     * POST: Processes the submitted new bot configuration data.
     */
    case '/create-bot':
        if ($method === 'POST') {
            $botController->handleCreateConfig();
        } else {
            $botController->showCreateBotForm();
        }
        break;

    /**
     * GET /portfolio
     * Displays the portfolio page, which may include contact information.
     */
    case '/portfolio':
        require_once __DIR__ . '/../templates/portfolio.php';
        break;

    /**
     * POST /contact/submit
     * Handles the submission of the contact form.
     * Redirects to the portfolio contact section if accessed via a non-POST request.
     */
    case '/contact/submit':
        if ($method === 'POST') {
            $contactController->handleContactForm();
        } else {
            header('Location: /portfolio#contact'); // Redirect if not a POST request
            exit;
        }
        break;

    /**
     * GET /resume/pdf
     * Generates and serves the user's resume as a PDF file.
     */
    case '/resume/pdf':
        $resumeController->generateResumePdf();
        break;

    /**
     * /paystack-payment
     * Displays the payment page for Paystack transactions.
     */
    case '/paystack-payment':
        $paystackController->showPaymentPage();
        break;

    /**
     * POST /paystack/initialize
     * Initializes a new payment transaction with Paystack.
     * Redirects to the payment page if accessed via a non-POST request.
     */
    case '/paystack/initialize':
        if ($method === 'POST') {
            $paystackController->initializePayment();
        } else {
            header('Location: /paystack-payment'); // Redirect if not a POST request
            exit;
        }
        break;

    /**
     * /paystack/verify
     * Verifies a Paystack transaction after the user completes payment.
     * This route is typically hit by Paystack's callback or a user redirect.
     */
    case '/paystack/verify':
        $paystackController->verifyPayment();
        break;

    case '/admin/users':
        $adminController = new App\Controllers\AdminController(); // Instantiate AdminController
        $adminController->showUsersAndBalances();
        break;

    case '/admin/delete-user':
        if ($method === 'POST') {
            $adminController = new App\Controllers\AdminController(); // Instantiate AdminController
            $adminController->handleDeleteUser();
        } else {
            $_SESSION['error_message'] = "Invalid request for user deletion.";
            header('Location: /admin/users');
            exit;
        }
        break;

    default:
        /**
         * Dynamic Routes
         * Handles routes that follow a pattern, such as /bots/{id} for specific bot details.
         */
        // Matches /bots/{id} to show details for a specific bot configuration.
        // The {id} is captured as a numeric value.
        if (preg_match('/^\/bots\/(\d+)$/', $path, $matches)) {
            $config_id = (int)$matches[1];
            $botController->showBotDetails($config_id);
        } else {
            // 404 Not Found for any unmatched routes.
            http_response_code(404);
            echo "<h1>404 Not Found</h1><p>The requested URL was not found on this server.</p>";
        }
        break;
}
