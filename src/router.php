<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BotController;
use App\Controllers\ApiKeyController;

// Helper function to get the current URI path without query string
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

// API Routes (JSON responses)
if ($path === '/api' || str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    $botController = new BotController();

    // Extract API action from path
    $api_action = substr($path, strlen('/api'));
    $api_action = trim($api_action, '/');

    try {
        switch ($api_action) {
            case 'bots/overview':
                $config_id = (int)($_GET['id'] ?? 0);
                $botController->getBotOverviewApi($config_id);
                break;
            case 'bots/statuses':
                $botController->getBotStatusesApi();
                break;
            case 'bots/start':
                if ($method === 'POST') {
                    $botController->startBotApi();
                }
                break;
            case 'bots/stop':
                if ($method === 'POST') {
                    $botController->stopBotApi();
                }
                break;
            case 'bots/delete':
                if ($method === 'POST') {
                    $botController->handleDeleteConfig();
                }
                break;
            case 'bots/update-config':
                if ($method === 'POST') {
                    $botController->handleUpdateConfig();
                }
                break;
            case 'bots/update-strategy':
                if ($method === 'POST') {
                    $botController->handleUpdateStrategy();
                }
                break;
            default:
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'API endpoint not found.']);
                exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'API Error: ' . $e->getMessage()]);
    }
    exit;
}

// Web Routes (HTML responses)
$authController = new AuthController();
$botController = new BotController();
$apiKeyController = new ApiKeyController();

switch ($path) {
    case '': // Root path, redirect to dashboard
    case '/':
        header('Location: /dashboard');
        exit;
    case '/dashboard':
        $botController->showDashboard();
        break;
    case '/login':
        if ($method === 'POST') {
            $authController->handleLogin();
        } else {
            $authController->showLogin();
        }
        break;
    case '/register':
        if ($method === 'POST') {
            $authController->handleRegister();
        } else {
            $authController->showRegister();
        }
        break;
    case '/logout':
        $authController->handleLogout();
        break;
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
    case '/create-bot':
        if ($method === 'POST') {
            $botController->handleCreateConfig();
        } else {
            $botController->showCreateBotForm();
        }
        break;
    default:
        // Handle dynamic routes like /bots/{id}
        if (preg_match('/^\/bots\/(\d+)$/', $path, $matches)) {
            $config_id = (int)$matches[1];
            $botController->showBotDetails($config_id);
        } else {
            http_response_code(404);
            echo "<h1>404 Not Found</h1><p>The requested URL was not found on this server.</p>";
        }
        break;
}
