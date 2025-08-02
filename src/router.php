<?php
declare(strict_types=1);

/**
 * router.php
 *
 * This file defines the routing logic for the application,
 * directing incoming requests to the appropriate controller methods.
 * It separates API routes (JSON responses) from web routes (HTML responses)
 * and includes comprehensive comments for clarity.
 */

use App\Controllers\AuthController;
use App\Controllers\BotController;
use App\Controllers\ApiKeyController;
use App\Controllers\ContactController;
use App\Controllers\ResumeController;
use App\Controllers\PaystackController;

/**
 * Helper function to get the current URI path without query string parameters.
 * Removes any trailing slashes for consistent routing.
 *
 * @return string The cleaned URI path.
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

// Initialize controllers for both API and Web routes
$authController = new AuthController();
$botController = new BotController();
$apiKeyController = new ApiKeyController();
$contactController = new ContactController();
$resumeController = new ResumeController();
$paystackController = new PaystackController();

/**
 * API Routes (JSON responses)
 * All API endpoints are prefixed with '/api'.
 * Responses are typically JSON objects indicating status and data/error messages.
 */
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');

    // Extract the specific API action from the path
    $api_action = substr($path, strlen('/api'));
    $api_action = trim($api_action, '/');

    try {
        switch ($api_action) {
            // GET /api/bots/overview - Retrieves an overview of a specific bot configuration.
            // Requires 'id' query parameter.
            case 'bots/overview':
                $config_id = (int)($_GET['id'] ?? 0);
                $botController->getBotOverviewApi($config_id);
                break;

            // GET /api/bots/statuses - Retrieves the statuses of all active bots.
            case 'bots/statuses':
                $botController->getBotStatusesApi();
                break;

            // POST /api/bots/start - Starts a bot.
            // Expects bot configuration data in the request body.
            case 'bots/start':
                if ($method === 'POST') {
                    $botController->startBotApi();
                } else {
                    http_response_code(405); // Method Not Allowed
                    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                }
                break;

            // POST /api/bots/stop - Stops a running bot.
            // Expects bot identification data in the request body.
            case 'bots/stop':
                if ($method === 'POST') {
                    $botController->stopBotApi();
                } else {
                    http_response_code(405); // Method Not Allowed
                    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                }
                break;

            // POST /api/bots/delete - Deletes a bot configuration.
            // Expects configuration ID in the request body.
            case 'bots/delete':
                if ($method === 'POST') {
                    $botController->handleDeleteConfig();
                } else {
                    http_response_code(405); // Method Not Allowed
                    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                }
                break;

            // POST /api/bots/update-config - Updates a bot's configuration.
            // Expects updated configuration data in the request body.
            case 'bots/update-config':
                if ($method === 'POST') {
                    $botController->handleUpdateConfig();
                } else {
                    http_response_code(405); // Method Not Allowed
                    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                }
                break;

            // POST /api/bots/update-strategy - Updates a bot's trading strategy.
            // Expects strategy data in the request body.
            case 'bots/update-strategy':
                if ($method === 'POST') {
                    $botController->handleUpdateStrategy();
                } else {
                    http_response_code(405); // Method Not Allowed
                    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                }
                break;

            default:
                // If no matching API endpoint is found
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'API endpoint not found.']);
                exit;
        }
    } catch (Throwable $e) {
        // Catch any exceptions during API request processing and return a 500 error
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'API Error: ' . $e->getMessage()]);
    }
    exit; // Terminate script execution after handling API request
}

/**
 * Web Routes (HTML responses)
 * These routes render HTML pages for the user interface.
 */
switch ($path) {
    // Redirect root path to dashboard
    case '':
    case '/':
        header('Location: /dashboard');
        exit;

    // GET /dashboard - Displays the main dashboard page.
    case '/dashboard':
        $botController->showDashboard();
        break;

    // /login - Handles user login.
    // GET: Displays the login form.
    // POST: Processes login credentials.
    case '/login':
        if ($method === 'POST') {
            $authController->handleLogin();
        } else {
            $authController->showLogin();
        }
        break;

    // /register - Handles new user registration.
    // GET: Displays the registration form.
    // POST: Processes registration data.
    case '/register':
        if ($method === 'POST') {
            $authController->handleRegister();
        } else {
            $authController->showRegister();
        }
        break;

    // GET /logout - Logs out the current user.
    case '/logout':
        $authController->handleLogout();
        break;

    // /api-keys - Manages API keys for external services.
    // GET: Displays the API keys management page.
    // POST: Handles adding or deleting API keys based on 'action' parameter.
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

    // /create-bot - Allows creation of new bot configurations.
    // GET: Displays the form to create a new bot.
    // POST: Processes the new bot configuration data.
    case '/create-bot':
        if ($method === 'POST') {
            $botController->handleCreateConfig();
        } else {
            $botController->showCreateBotForm();
        }
        break;

    // GET /portfolio - Displays the portfolio page.
    case '/portfolio':
        require_once __DIR__ . '/../templates/portfolio.php';
        break;

    // POST /contact/submit - Handles submission of the contact form.
    // Redirects to portfolio contact section if not a POST request.
    case '/contact/submit':
        if ($method === 'POST') {
            $contactController->handleContactForm();
        } else {
            header('Location: /portfolio#contact'); // Redirect if not a POST request
            exit;
        }
        break;

    // GET /resume/pdf - Generates and serves the resume as a PDF.
    case '/resume/pdf':
        $resumeController->generateResumePdf();
        break;

    // Paystack Payment Routes
    case '/paystack-payment':
        $paystackController->showPaymentPage();
        break;

    case '/paystack/initialize':
        if ($method === 'POST') {
            $paystackController->initializePayment();
        } else {
            header('Location: /paystack-payment'); // Redirect if not a POST request
            exit;
        }
        break;

    case '/paystack/verify':
        // Paystack typically redirects with GET parameters after a transaction
        // but we can also handle POST if needed.
        $paystackController->verifyPayment();
        break;

    default:
        /**
         * Dynamic Routes
         * Handles routes that follow a pattern, such as /bots/{id}.
         */
        // Matches /bots/{id} to show details for a specific bot.
        if (preg_match('/^\/bots\/(\d+)$/', $path, $matches)) {
            $config_id = (int)$matches[1];
            $botController->showBotDetails($config_id);
        } else {
            // 404 Not Found for any unmatched routes
            http_response_code(404);
            echo "<h1>404 Not Found</h1><p>The requested URL was not found on this server.</p>";
        }
        break;
}
