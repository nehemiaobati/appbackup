<?php
declare(strict_types=1);
session_start();

// --- DEPENDENCIES & CONFIGURATION ---
// This block is run for both API and standard page loads.
require_once __DIR__ . '/vendor/autoload.php';

try {
    \Dotenv\Dotenv::createImmutable(__DIR__)->load();
} catch (\Throwable $e) {
    // A simple, user-friendly error if the .env file is missing.
    http_response_code(500);
    die("<!DOCTYPE html><html><head><title>Configuration Error</title><style>body{font-family:sans-serif;background-color:#f8d7da;color:#721c24;padding:2rem;text-align:center;}div{max-width:600px;margin:auto;background-color:#f5c6cb;border:1px solid #721c24;border-radius:0.25rem;padding:1.5rem;}</style></head><body><div><h1>Configuration Error</h1><p>The <code>.env</code> file is missing or could not be loaded. Please ensure it exists in the root directory and is readable.</p></div></body></html>");
}

// Define application constants from .env or use sensible defaults
define('PHP_EXECUTABLE_PATH', $_ENV['PHP_EXECUTABLE_PATH'] ?? '/usr/bin/php');
define('BOT_SCRIPT_PATH', __DIR__ . '/bot.php');
define('ENCRYPTION_CIPHER', 'aes-256-cbc');

// --- DATABASE & HELPER FUNCTIONS ---

/**
 * Creates and returns a PDO database connection using a static variable to prevent re-connections.
 * @return PDO
 */
function db_connect(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME']);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $options);
    }
    return $pdo;
}

/**
 * Encrypts data using the APP_ENCRYPTION_KEY from the .env file.
 * @param string $data The plaintext data to encrypt.
 * @return string The base64-encoded encrypted data, including the IV.
 * @throws RuntimeException if encryption fails or the key is missing.
 */
function encrypt(string $data): string {
    $key = $_ENV['APP_ENCRYPTION_KEY'];
    if (empty($key)) throw new RuntimeException("APP_ENCRYPTION_KEY is not set in the .env file.");

    $ivLength = openssl_cipher_iv_length(ENCRYPTION_CIPHER);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, ENCRYPTION_CIPHER, $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) throw new RuntimeException("Encryption failed. Check OpenSSL configuration.");

    return base64_encode($iv . $encrypted);
}

/**
 * Calculates a human-readable "time ago" string.
 * @param string|null $datetime_str A UTC datetime string.
 * @return string
 */
function time_ago(?string $datetime_str): string {
    if ($datetime_str === null) return 'N/A';
    try {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $ago = new DateTime($datetime_str, new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $ago->getTimestamp();

        if ($diff < 60) return 'just now';
        $d = floor($diff / 86400);
        $h = floor(($diff % 86400) / 3600);
        $m = floor(($diff % 3600) / 60);

        $parts = [];
        if ($d > 0) $parts[] = $d . 'd';
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';

        return implode(' ', $parts) . ' ago';
    } catch (\Exception $e) {
        return 'N/A';
    }
}

// --- API ROUTER ---
// Handles asynchronous requests from JavaScript. Returns JSON and exits.
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

    try {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401); // Unauthorized
            throw new Exception('Authentication required. Please refresh and log in.');
        }

        $current_user_id = $_SESSION['user_id'];
        session_write_close(); // Release session lock for long-polling API calls.

        $pdo = db_connect();
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'get_bot_overview':
                $config_id = (int)($_GET['id'] ?? 0);

                // Verify user ownership of the bot config
                $stmt = $pdo->prepare("SELECT * FROM bot_configurations WHERE id = ? AND user_id = ?");
                $stmt->execute([$config_id, $current_user_id]);
                $config_data = $stmt->fetch();
                if (!$config_data) {
                    throw new Exception("Configuration not found or permission denied.");
                }

                // 2. Get runtime status
                $stmt_status = $pdo->prepare("SELECT * FROM bot_runtime_status WHERE bot_config_id = ?");
                $stmt_status->execute([$config_id]);
                $bot_status = $stmt_status->fetch();
                
                // 3. Get performance summary
                $stmt_perf = $pdo->prepare("
                    SELECT
                        SUM(realized_pnl_usdt) as total_pnl,
                        SUM(commission_usdt) as total_commission,
                        COUNT(internal_id) as trades_executed,
                        SUM(CASE WHEN realized_pnl_usdt > 0 THEN 1 ELSE 0 END) as winning_trades,
                        MAX(bot_event_timestamp_utc) as last_trade_time
                    FROM orders_log WHERE bot_config_id = ? AND user_id = ?");
                $stmt_perf->execute([$config_id, $current_user_id]);
                $perf_summary = $stmt_perf->fetch();

                // 4. Get recent trades
                $stmt_trades = $pdo->prepare("
                    SELECT symbol, side, price_point, quantity_involved, bot_event_timestamp_utc, realized_pnl_usdt, commission_usdt, reduce_only
                    FROM orders_log WHERE bot_config_id = ? AND user_id = ?
                    ORDER BY bot_event_timestamp_utc DESC LIMIT 10");
                $stmt_trades->execute([$config_id, $current_user_id]);
                $recent_trades = $stmt_trades->fetchAll();

                // 5. Get AI Decisions
                $stmt_ai = $pdo->prepare("
                    SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
                    FROM ai_interactions_log WHERE bot_config_id = ? AND user_id = ?
                    ORDER BY log_timestamp_utc DESC LIMIT 10");
                $stmt_ai->execute([$config_id, $current_user_id]);
                $ai_logs = $stmt_ai->fetchAll();
                
                // 6. Get Active Strategy
                $stmt_strategy = $pdo->prepare("SELECT * FROM trade_logic_source WHERE user_id = ? AND is_active = TRUE ORDER BY version DESC LIMIT 1");
                $stmt_strategy->execute([$current_user_id]);
                $strategy_data = $stmt_strategy->fetch();

                // 7. Calculate derived data
                $total_profit = (float)($perf_summary['total_pnl'] ?? 0) - (float)($perf_summary['total_commission'] ?? 0);
                $win_rate = !empty($perf_summary['trades_executed']) ? (($perf_summary['winning_trades'] / $perf_summary['trades_executed']) * 100) : 0;
                
                // 8. Assemble the final payload
                $data_payload = [
                    'statusInfo' => $bot_status ?: ['status' => 'shutdown', 'process_id' => null, 'last_heartbeat' => null, 'error_message' => null],
                    'performance' => [
                        'totalProfit' => $total_profit,
                        'tradesExecuted' => (int)($perf_summary['trades_executed'] ?? 0),
                        'winRate' => $win_rate,
                        'lastTradeAgo' => time_ago($perf_summary['last_trade_time'])
                    ],
                    'recentTrades' => $recent_trades,
                    'aiLogs' => $ai_logs,
                    'configuration' => $config_data,
                    'strategy' => $strategy_data ?: ['strategy_directives_json' => '{"error": "No active strategy found for this user."}']
                ];
                
                $response = ['status' => 'success', 'data' => $data_payload];
                break;

            case 'get_statuses':
                $stmt = $pdo->prepare("SELECT bc.id, bc.name, bc.symbol, bc.is_active, brs.status, brs.last_heartbeat, brs.process_id FROM bot_configurations bc LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id WHERE bc.user_id = ? ORDER BY bc.id ASC");
                $stmt->execute([$current_user_id]);
                $response = ['status' => 'success', 'bots' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
                break;

            case 'start_bot':
                $config_id = (int)($_POST['config_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ? AND is_active = TRUE");
                $stmt->execute([$config_id, $current_user_id]);
                if ($stmt->fetch()) {
                    $manager_script = __DIR__ . '/bot_manager.sh';
                    // We capture the output of the script to check for success/error
                    $command = escapeshellcmd($manager_script) . ' start ' . escapeshellarg((string)$config_id);
                    // 2>&1 redirects stderr to stdout so we can capture all output
                    $output = shell_exec($command . ' 2>&1'); 

                    if (strpos($output, 'SUCCESS') !== false) {
                        $response = ['status' => 'success', 'message' => "Bot start command successful. " . trim($output)];
                    } else {
                        http_response_code(500); // Server error
                        $response = ['status' => 'error', 'message' => "Failed to start bot: " . trim($output)];
                    }
                } else {
                    http_response_code(403);
                    $response['message'] = 'Bot is inactive or you do not have permission.';
                }
                break;

            case 'stop_bot':
                // We no longer need the PID from the frontend. The manager script knows.
                $config_id = (int)($_POST['config_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
                $stmt->execute([$config_id, $current_user_id]);
                if ($stmt->fetch()) {
                    $manager_script = __DIR__ . '/bot_manager.sh';
                    $command = escapeshellcmd($manager_script) . ' stop ' . escapeshellarg((string)$config_id);
                    $output = shell_exec($command . ' 2>&1');

                    // Check for "ERROR" because the "INFO" message about orphaned processes is not a failure.
                    if (strpos($output, 'ERROR') === false) {
                        // The bot's shutdown handler is the primary source of truth for updating the DB.
                        $response = ['status' => 'success', 'message' => "Bot stop command processed. " . trim($output)];
                    } else {
                        http_response_code(500);
                        $response = ['status' => 'error', 'message' => "Failed to stop bot: " . trim($output)];
                    }
                } else {
                    http_response_code(403);
                    $response['message'] = 'Invalid Config ID or permission denied.';
                }
                break;
            
            case 'delete_config':
                $config_id = (int)($_POST['config_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT bc.id FROM bot_configurations bc LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id WHERE bc.id = ? AND bc.user_id = ? AND (brs.status IS NULL OR brs.status = 'stopped' OR brs.status = 'error' OR brs.status = 'shutdown')");
                $stmt->execute([$config_id, $current_user_id]);
                if (!$stmt->fetch()) throw new Exception("Cannot delete a running bot. Please stop it first.");
                
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM bot_runtime_status WHERE bot_config_id = ?")->execute([$config_id]);
                $pdo->prepare("DELETE FROM bot_configurations WHERE id = ? AND user_id = ?")->execute([$config_id, $current_user_id]);
                $pdo->commit();
                
                $response = ['status' => 'success', 'message' => 'Bot configuration deleted successfully. Redirecting...'];
                break;

            case 'update_config':
                $config_id = (int)($_POST['config_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
                $stmt->execute([$config_id, $current_user_id]);
                if (!$stmt->fetch()) throw new Exception("Configuration not found or permission denied.");
                
                $update_stmt = $pdo->prepare("
                    UPDATE bot_configurations SET 
                        name = :name, symbol = :symbol, kline_interval = :kline_interval, 
                        margin_asset = :margin_asset, default_leverage = :default_leverage, 
                        order_check_interval_seconds = :order_check_interval_seconds, 
                        ai_update_interval_seconds = :ai_update_interval_seconds, use_testnet = :use_testnet, 
                        initial_margin_target_usdt = :initial_margin_target_usdt, 
                        take_profit_target_usdt = :take_profit_target_usdt, 
                        pending_entry_order_cancel_timeout_seconds = :pending_entry_order_cancel_timeout_seconds, 
                        profit_check_interval_seconds = :profit_check_interval_seconds, is_active = :is_active,
                        quantity_determination_method = :quantity_determination_method,
                        allow_ai_to_update_strategy = :allow_ai_to_update_strategy
                    WHERE id = :id AND user_id = :user_id");
                $update_stmt->execute([
                    ':name' => $_POST['name'], ':symbol' => $_POST['symbol'], 
                    ':kline_interval' => $_POST['kline_interval'], 
                    ':margin_asset' => $_POST['margin_asset'], 
                    ':default_leverage' => (int)$_POST['default_leverage'], 
                    ':order_check_interval_seconds' => (int)$_POST['order_check_interval_seconds'], 
                    ':ai_update_interval_seconds' => (int)$_POST['ai_update_interval_seconds'], 
                    ':use_testnet' => isset($_POST['use_testnet']) ? 1 : 0, 
                    ':initial_margin_target_usdt' => (float)$_POST['initial_margin_target_usdt'], 
                    ':take_profit_target_usdt' => (float)$_POST['take_profit_target_usdt'], 
                    ':pending_entry_order_cancel_timeout_seconds' => (int)$_POST['pending_entry_order_cancel_timeout_seconds'], 
                    ':profit_check_interval_seconds' => (int)$_POST['profit_check_interval_seconds'], 
                    ':is_active' => isset($_POST['is_active']) ? 1 : 0, 
                    ':quantity_determination_method' => $_POST['quantity_determination_method'],
                    ':allow_ai_to_update_strategy' => isset($_POST['allow_ai_to_update_strategy']) ? 1 : 0,
                    ':id' => $config_id, ':user_id' => $current_user_id
                ]);
                
                $response = ['status' => 'success', 'message' => "Configuration '" . htmlspecialchars($_POST['name']) . "' updated successfully."];
                break;

            case 'update_strategy':
                $strategy_json = $_POST['strategy_json'] ?? '';
                $strategy_id = (int)($_POST['strategy_id'] ?? 0);

                // Server-side validation
                $decoded = json_decode($strategy_json);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON format provided for the strategy.");
                }

                // Verify user ownership of the strategy
                $stmt = $pdo->prepare("SELECT id FROM trade_logic_source WHERE id = ? AND user_id = ?");
                $stmt->execute([$strategy_id, $current_user_id]);
                if (!$stmt->fetch()) {
                    throw new Exception("Strategy not found or permission denied.");
                }

                $pdo->beginTransaction();
                $stmt_update = $pdo->prepare("
                    UPDATE trade_logic_source SET 
                        strategy_directives_json = :json,
                        version = version + 1,
                        last_updated_by = :updater,
                        last_updated_at_utc = :now
                    WHERE id = :id AND user_id = :user_id
                ");
                $stmt_update->execute([
                    ':json' => $strategy_json,
                    ':updater' => 'USER_UI',
                    ':now' => gmdate('Y-m-d H:i:s'),
                    ':id' => $strategy_id,
                    ':user_id' => $current_user_id
                ]);
                $pdo->commit();
                $response = ['status' => 'success', 'message' => 'AI strategy directives updated successfully!'];
                break;
        }
    } catch (Throwable $e) {
        if (!headers_sent()) http_response_code(500);
        $response['message'] = 'Server error: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// --- STANDARD PAGE LOAD (Non-API) ---

$pdo = db_connect();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Handle Logout (does not require POST check)
if ($action === 'logout' && isset($_SESSION['user_id'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: dashboard.php?view=login');
    exit;
}

// --- FORM SUBMISSION HANDLING (Full page reloads) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- Actions that DO NOT require a logged-in user ---
        if ($action === 'register') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';

            if (empty($username) || empty($email) || empty($password)) throw new Exception("All fields are required.");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email address format.");
            if ($password !== $password_confirm) throw new Exception("Passwords do not match.");
            if (strlen($password) < 8) throw new Exception("Password must be at least 8 characters long.");

            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) throw new Exception("Username or email is already registered.");

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $password_hash]);
            
            $_SESSION['success_message'] = "Registration successful! You can now log in.";
            header('Location: dashboard.php?view=login');
            exit;
        }

        if ($action === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                 $_SESSION['error_message'] = "Username and password are required.";
                 header('Location: dashboard.php?view=login');
                 exit;
            }

            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: dashboard.php');
                exit;
            } else {
                $_SESSION['error_message'] = "Invalid username or password.";
                header('Location: dashboard.php?view=login');
                exit;
            }
        }
        
        // --- Actions that DO require a logged-in user ---
        if (isset($_SESSION['user_id'])) {
            $current_user_id = $_SESSION['user_id'];
            
            switch ($action) {
                case 'add_key':
                    $key_name = trim($_POST['key_name'] ?? '');
                    $binance_key = trim($_POST['binance_api_key'] ?? '');
                    $binance_secret = trim($_POST['binance_api_secret'] ?? '');
                    $gemini_key = trim($_POST['gemini_api_key'] ?? '');
                    if (empty($key_name) || empty($binance_key) || empty($binance_secret) || empty($gemini_key)) {
                        throw new Exception("All fields are required to add a new key set.");
                    }
                    $stmt = $pdo->prepare("INSERT INTO user_api_keys (user_id, key_name, binance_api_key_encrypted, binance_api_secret_encrypted, gemini_api_key_encrypted) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$current_user_id, $key_name, encrypt($binance_key), encrypt($binance_secret), encrypt($gemini_key)]);
                    $_SESSION['success_message'] = "API Key set '" . htmlspecialchars($key_name) . "' added successfully!";
                    header('Location: dashboard.php?view=api_keys');
                    exit;

                case 'delete_key':
                    $key_id = (int)($_POST['key_id'] ?? 0);
                    $stmt = $pdo->prepare("DELETE FROM user_api_keys WHERE id = ? AND user_id = ?");
                    $stmt->execute([$key_id, $current_user_id]);
                    $_SESSION['success_message'] = "API Key set deleted successfully.";
                    header('Location: dashboard.php?view=api_keys');
                    exit;

                case 'create_config':
                    $api_key_id = (int)($_POST['user_api_key_id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT id FROM user_api_keys WHERE id = ? AND user_id = ?");
                    $stmt->execute([$api_key_id, $current_user_id]);
                    if (!$stmt->fetch()) throw new Exception("Invalid API Key selection or permission denied.");
                    
                    $pdo->beginTransaction();
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO bot_configurations (user_id, user_api_key_id, name, symbol, kline_interval, margin_asset, default_leverage, order_check_interval_seconds, ai_update_interval_seconds, use_testnet, initial_margin_target_usdt, take_profit_target_usdt, pending_entry_order_cancel_timeout_seconds, profit_check_interval_seconds, is_active, quantity_determination_method, allow_ai_to_update_strategy) 
                        VALUES (:user_id, :user_api_key_id, :name, :symbol, :kline_interval, :margin_asset, :default_leverage, :order_check_interval_seconds, :ai_update_interval_seconds, :use_testnet, :initial_margin_target_usdt, :take_profit_target_usdt, :pending_entry_order_cancel_timeout_seconds, :profit_check_interval_seconds, :is_active, :quantity_determination_method, :allow_ai_to_update_strategy)");
                    $insert_stmt->execute([
                        ':user_id' => $current_user_id,
                        ':user_api_key_id' => $api_key_id,
                        ':name' => $_POST['name'],
                        ':symbol' => $_POST['symbol'],
                        ':kline_interval' => $_POST['kline_interval'],
                        ':margin_asset' => $_POST['margin_asset'],
                        ':default_leverage' => (int)$_POST['default_leverage'],
                        ':order_check_interval_seconds' => (int)$_POST['order_check_interval_seconds'],
                        ':ai_update_interval_seconds' => (int)$_POST['ai_update_interval_seconds'],
                        ':use_testnet' => isset($_POST['use_testnet']) ? 1 : 0,
                        ':initial_margin_target_usdt' => (float)$_POST['initial_margin_target_usdt'],
                        ':take_profit_target_usdt' => (float)$_POST['take_profit_target_usdt'],
                        ':pending_entry_order_cancel_timeout_seconds' => (int)$_POST['pending_entry_order_cancel_timeout_seconds'],
                        ':profit_check_interval_seconds' => (int)$_POST['profit_check_interval_seconds'],
                        ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                        ':quantity_determination_method' => $_POST['quantity_determination_method'],
                        ':allow_ai_to_update_strategy' => isset($_POST['allow_ai_to_update_strategy']) ? 1 : 0
                    ]);
                    $new_config_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO bot_runtime_status (bot_config_id, status) VALUES (?, 'stopped')")->execute([$new_config_id]);
                    $pdo->commit();
                    $_SESSION['success_message'] = "Bot configuration '" . htmlspecialchars($_POST['name']) . "' created successfully.";
                    header('Location: dashboard.php');
                    exit;

            }
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException && isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }
}

// --- VIEW ROUTING & DATA FETCHING for HTML Rendering ---
$view = $_GET['view'] ?? 'dashboard';

if (isset($_SESSION['user_id'])) {
    $current_user_id = $_SESSION['user_id'];
    $username_for_header = $_SESSION['username'];
    
    if ($view === 'create_config') {
        $stmt = $pdo->prepare("SELECT id, key_name FROM user_api_keys WHERE user_id = ? AND is_active = TRUE");
        $stmt->execute([$current_user_id]);
        $user_api_keys = $stmt->fetchAll();
    } elseif ($view === 'edit_config') {
        $config_id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM bot_configurations WHERE id = ? AND user_id = ?");
        $stmt->execute([$config_id, $current_user_id]);
        $config_data = $stmt->fetch();
        if (!$config_data) {
            $_SESSION['error_message'] = "Configuration not found or permission denied.";
            header('Location: dashboard.php'); exit;
        }
    } elseif ($view === 'api_keys') {
        $stmt = $pdo->prepare("SELECT id, key_name, is_active, created_at FROM user_api_keys WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$current_user_id]);
        $user_keys = $stmt->fetchAll();
    }
} else {
    $view = ($view === 'register') ? 'register' : 'login';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>P2Profit Bot Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .card { border: none; }
        .status-running { color: #198754; font-weight: bold; }
        .status-stopped, .status-shutdown { color: #dc3545; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .status-initializing { color: #0dcaf0; font-weight: bold; }
        .nav-pills .nav-link.active { background-color: #343a40; }
        .btn-group .btn { white-space: nowrap; }
        .ai-log-entry { font-size: 0.85rem; border-bottom: 1px solid #eee; padding: 0.5rem 0; }
        .ai-log-entry:last-child { border-bottom: none; }
        .placeholder-glow .placeholder { min-height: 1.5rem; }
        #strategy-json-editor {
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            min-height: 400px;
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <header class="d-flex justify-content-between align-items-center p-3 my-3 text-white bg-dark rounded shadow-sm">
        <h4 class="mb-0"><i class="bi bi-robot"></i> P2Profit Bot Dashboard</h4>
        <?php if (isset($current_user_id)): ?>
            <div>
                <span class="me-3">User: <strong><?= htmlspecialchars($username_for_header) ?></strong></span>
                <a href="?action=logout" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        <?php endif; ?>
    </header>

    <div id="alert-container">
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_SESSION['success_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($_SESSION['error_message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php unset($_SESSION['error_message']); endif; ?>
    </div>

    <?php if (isset($current_user_id)): // User is logged in, show protected views ?>
        <ul class="nav nav-pills mb-3">
          <li class="nav-item"><a href="dashboard.php?view=dashboard" class="nav-link <?= in_array($view, ['dashboard', 'create_config']) ? 'active' : '' ?> <?= $view == 'edit_config' ? 'active' : ''?>"><i class="bi bi-gear-wide-connected"></i> Bots Dashboard</a></li>
          <li class="nav-item"><a href="dashboard.php?view=api_keys" class="nav-link <?= $view === 'api_keys' ? 'active' : '' ?>"><i class="bi bi-key-fill"></i> API Keys</a></li>
        </ul>
        <?php switch($view):
            case 'api_keys': ?>
                <div class="row"><div class="col-lg-7">
                <div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-key-fill"></i> Your API Key Sets</h5></div>
                <div class="card-body">
                    <?php if (empty($user_keys)): ?><p class="text-muted">You have not added any API key sets yet.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover align-middle">
                        <thead><tr><th>Name</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($user_keys as $key): ?>
                            <tr><td><?= htmlspecialchars($key['key_name']) ?></td><td><?= date('Y-m-d H:i', strtotime($key['created_at'])) ?></td><td><span class="badge bg-<?= $key['is_active'] ? 'success' : 'secondary' ?>"><?= $key['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><form method="post" action="dashboard.php" onsubmit="return confirm('Are you sure? This cannot be undone.');"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key_id" value="<?= $key['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div></div></div>
                <div class="col-lg-5">
                <div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-plus-circle-fill"></i> Add New API Key Set</h5></div>
                <div class="card-body">
                    <form method="post" action="dashboard.php"><input type="hidden" name="action" value="add_key">
                        <div class="mb-3"><label for="key_name" class="form-label">Key Set Name</label><input type="text" id="key_name" class="form-control" name="key_name" placeholder="e.g., My Mainnet Key" required></div>
                        <div class="mb-3"><label for="binance_api_key" class="form-label">Binance API Key</label><input type="password" id="binance_api_key" class="form-control" name="binance_api_key" required></div>
                        <div class="mb-3"><label for="binance_api_secret" class="form-label">Binance API Secret</label><input type="password" id="binance_api_secret" class="form-control" name="binance_api_secret" required></div>
                        <div class="mb-3"><label for="gemini_api_key" class="form-label">Gemini API Key</label><input type="password" id="gemini_api_key" class="form-control" name="gemini_api_key" required></div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Save Securely</button>
                    </form>
                </div></div></div></div>
                <?php break; ?>

            <?php case 'create_config': ?>
                <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li><li class="breadcrumb-item active">Create Bot</li></ol></nav>
                <div class="card shadow-sm"><div class="card-header"><h5><i class="bi bi-plus-circle-fill"></i> Create New Bot Configuration</h5></div>
                <div class="card-body">
                    <?php if (empty($user_api_keys)): ?>
                        <div class="alert alert-warning"><strong>Action Required:</strong> You must <a href="?view=api_keys">add an API Key set</a> before you can create a bot.</div>
                    <?php else: ?>
                    <form method="post" action="dashboard.php">
                        <input type="hidden" name="action" value="create_config">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Configuration Name</label><input type="text" class="form-control" name="name" value="" placeholder="e.g., My BTC Test Bot" required></div>
                            <div class="col-md-6"><label class="form-label">API Key Set</label><select class="form-select" name="user_api_key_id" required><option value="" disabled selected>-- Select an API Key --</option><?php foreach ($user_api_keys as $key): ?><option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['key_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label">Trading Symbol</label><input type="text" class="form-control" name="symbol" value="BTCUSDT" required></div>
                            <div class="col-md-4"><label class="form-label">Margin Asset</label><input type="text" class="form-control" name="margin_asset" value="USDT" required></div>
                            <div class="col-md-4"><label class="form-label">Kline Interval</label><input type="text" class="form-control" name="kline_interval" value="1m" required></div>
                            <div class="col-md-4"><label class="form-label">Default Leverage</label><input type="number" class="form-control" name="default_leverage" value="100" required></div>
                            <div class="col-md-4"><label class="form-label">AI Update Interval (s)</label><input type="number" class="form-control" name="ai_update_interval_seconds" value="60" required></div>
                            <div class="col-md-4"><label class="form-label">Order Check Interval (s)</label><input type="number" class="form-control" name="order_check_interval_seconds" value="45" required></div>
                            <div class="col-md-4"><label class="form-label">Pending Order Timeout (s)</label><input type="number" class="form-control" name="pending_entry_order_cancel_timeout_seconds" value="60" required></div>
                            <div class="col-md-4"><label class="form-label">Profit Check Interval (s)</label><input type="number" class="form-control" name="profit_check_interval_seconds" value="60" required></div>
                            <div class="col-md-4"><label class="form-label">Initial Margin Target (USDT)</label><input type="text" class="form-control" name="initial_margin_target_usdt" value="1.50" required></div>
                            <div class="col-md-6"><label class="form-label">Auto Take Profit (USDT)</label><input type="text" class="form-control" name="take_profit_target_usdt" value="0.00" required></div>
                            <div class="col-md-6"><label class="form-label">Quantity Calculation Method</label><select class="form-select" name="quantity_determination_method"><option value="INITIAL_MARGIN_TARGET" selected>Fixed (Initial Margin Target)</option><option value="AI_SUGGESTED">Dynamic (AI Suggested)</option></select></div>
                            <div class="col-md-12 pt-3">
                                <div class="form-check form-switch d-inline-block me-4"><input class="form-check-input" type="checkbox" role="switch" name="use_testnet" id="use_testnet" value="1" checked><label class="form-check-label" for="use_testnet">Use Testnet</label></div>
                                <div class="form-check form-switch d-inline-block me-4"><input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" checked><label class="form-check-label" for="is_active">Enable Bot</label></div>
                                <div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" name="allow_ai_to_update_strategy" id="allow_ai_to_update_strategy" value="1"><label class="form-check-label" for="allow_ai_to_update_strategy">Allow AI to Update Strategy</label></div>
                            </div>
                        </div><hr><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Configuration</button><a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                    </form>
                    <?php endif; ?>
                </div></div>
                <?php break; ?>

            <?php case 'edit_config': ?>
                <div id="bot-overview-page" data-config-id="<?= $config_data['id'] ?>">
                <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Overview: <span id="breadcrumb-bot-name"><?= htmlspecialchars($config_data['name']) ?></span></li></ol></nav>
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
                                <form id="update-config-form" method="post" action="dashboard.php?api=true&action=update_config">
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
                <?php break; ?>

            <?php default: // 'dashboard' view ?>
                <div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><h5><i class="bi bi-gear-wide-connected"></i> Bot Configurations & Status</h5><a href="?view=create_config" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle-fill"></i> Create New Bot</a></div>
                <div class="card-body">
                    <div class="table-responsive"><table class="table table-hover align-middle">
                        <thead><tr><th>Name</th><th>Symbol</th><th>Status</th><th>Heartbeat</th><th>PID</th><th>Actions</th></tr></thead>
                        <tbody id="bot-status-table-body">
                            <tr><td colspan="6" class="text-center p-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></td></tr>
                        </tbody>
                    </table></div>
                </div></div>
            <?php endswitch; ?>
    <?php else: // User is NOT logged in, show public views ?>
        <?php switch($view):
            case 'register': ?>
                <div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="card shadow-lg"><div class="card-header text-center"><h4><i class="bi bi-person-plus-fill"></i> Create New Account</h4></div><div class="card-body p-4">
                    <form method="post" action="dashboard.php"><input type="hidden" name="action" value="register"><div class="mb-3"><label for="username_reg" class="form-label">Username</label><input type="text" id="username_reg" class="form-control" name="username" required></div><div class="mb-3"><label for="email_reg" class="form-label">Email Address</label><input type="email" id="email_reg" class="form-control" name="email" required></div><div class="mb-3"><label for="password_reg" class="form-label">Password</label><input type="password" id="password_reg" class="form-control" name="password" required></div><div class="mb-3"><label for="password_confirm_reg" class="form-label">Confirm Password</label><input type="password" id="password_confirm_reg" class="form-control" name="password_confirm" required></div><button type="submit" class="btn btn-primary w-100">Register</button></form>
                </div><div class="card-footer text-center"><a href="dashboard.php?view=login">Already have an account? Login</a></div></div></div></div>
            <?php break; ?>
            <?php default: // 'login' view ?>
                <div class="row justify-content-center"><div class="col-md-6 col-lg-4"><div class="card shadow-lg"><div class="card-header text-center"><h4><i class="bi bi-box-arrow-in-right"></i> Secure Login</h4></div><div class="card-body p-4">
                    <form method="post" action="dashboard.php"><input type="hidden" name="action" value="login"><div class="mb-3"><label for="username_login" class="form-label">Username</label><input type="text" id="username_login" class="form-control" name="username" required></div><div class="mb-3"><label for="password_login" class="form-label">Password</label><input type="password" id="password_login" class="form-control" name="password" required></div><button type="submit" class="btn btn-dark w-100">Login</button></form>
                </div><div class="card-footer text-center"><a href="dashboard.php?view=register">Create a new account</a></div></div></div></div>
            <?php break; ?>
        <?php endswitch; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($current_user_id)): // Only include dashboard JS for logged-in users ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- GLOBAL ELEMENTS & STATE ---
    const alertContainer = document.getElementById('alert-container');
    let appInterval; // Holds the setInterval ID for the current view

    // --- UTILITY FUNCTIONS ---
    const showAlert = (message, type = 'success', isTemp = false) => {
        if (!alertContainer) return;
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
        
        if (isTemp) {
            alertContainer.append(wrapper);
            setTimeout(() => { 
                const alert = wrapper.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    // Wait for fade-out transition before removing
                    setTimeout(() => wrapper.remove(), 150);
                }
            }, 5000);
        } else {
            alertContainer.innerHTML = ''; // Clear existing persistent alerts
            alertContainer.append(wrapper);
        }
    };
    
    // --- ASYNC ACTION HANDLERS ---
    window.handleBotAction = async (event) => {
        event.preventDefault();
        const form = event.target;
        const action = form.querySelector('[name="action"]').value;
        const actionText = action.replace(/_/g, ' ');

        if (!confirm(`Are you sure you want to ${actionText}?`)) return;

        const button = form.querySelector('button[type="submit"]');
        const originalButtonHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working...';

        try {
            const response = await fetch(`dashboard.php?api=true&action=${action}`, { method: 'POST', body: new FormData(form) });
            const data = await response.json();
            
            if (data.status === 'success') {
                showAlert(data.message, 'success', true);
                if (action === 'delete_config') {
                    setTimeout(() => window.location.href = 'dashboard.php', 1500);
                }
            } else {
                showAlert(data.message || 'An unknown error occurred.', 'danger');
            }
        } catch (error) {
            showAlert('Request failed: ' + error.message, 'danger');
        } finally {
            if (action !== 'delete_config') {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        }
    };

    // --- OVERVIEW PAGE SPECIFIC LOGIC ---
    const overviewPage = {
        configId: null,
        elements: { /* cache DOM elements */ },
        
        init() {
            const pageContainer = document.getElementById('bot-overview-page');
            if (!pageContainer) return false;
            
            this.configId = pageContainer.dataset.configId;
            this.cacheElements();
            this.addEventListeners();
            this.runUpdateCycle(); // Initial load
            appInterval = setInterval(() => this.runUpdateCycle(), 7000); // Poll every 7 seconds
            return true;
        },
        
        cacheElements() {
            this.elements = {
                breadcrumbBotName: document.getElementById('breadcrumb-bot-name'),
                statusText: document.getElementById('bot-status-text'),
                pid: document.getElementById('bot-pid'),
                heartbeat: document.getElementById('bot-heartbeat'),
                messagesContainer: document.getElementById('bot-messages-container'),
                controlsContainer: document.getElementById('bot-controls-container'),
                totalProfit: document.getElementById('perf-total-profit'),
                tradesExecuted: document.getElementById('perf-trades-executed'),
                winRate: document.getElementById('perf-win-rate'),
                lastTradeAgo: document.getElementById('perf-last-trade-ago'),
                tradesBody: document.getElementById('recent-trades-body'),
                aiLogsContainer: document.getElementById('ai-logs-container'),
                updateConfigForm: document.getElementById('update-config-form'),
                // New elements for strategy editor
                updateStrategyForm: document.getElementById('update-strategy-form'),
                strategyIdInput: document.getElementById('strategy-id-input'),
                strategyJsonEditor: document.getElementById('strategy-json-editor'),
                strategyNameLabel: document.getElementById('strategy-name-label'),
                strategyVersionLabel: document.getElementById('strategy-version-label'),
                strategyUpdaterLabel: document.getElementById('strategy-updater-label'),
                strategyUpdatedLabel: document.getElementById('strategy-updated-label'),
            };
        },
        
        addEventListeners() {
            this.elements.updateConfigForm.addEventListener('submit', (e) => this.handleConfigUpdate(e));
            this.elements.updateStrategyForm.addEventListener('submit', (e) => this.handleStrategyUpdate(e));
        },
        
        async runUpdateCycle() {
            try {
                const response = await fetch(`dashboard.php?api=true&action=get_bot_overview&id=${this.configId}`);
                if (response.status === 401) {
                    showAlert('Your session has expired. Please log in again.', 'danger');
                    if (appInterval) clearInterval(appInterval);
                    setTimeout(() => window.location.reload(), 3000);
                    return;
                }
                if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
                
                const result = await response.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                this.updateUI(result.data);

            } catch (error) {
                console.error("Overview update failed:", error);
            }
        },
        
        updateUI(data) {
            const placeholders = document.querySelectorAll('.placeholder-glow');
            if (placeholders.length) placeholders.forEach(p => p.classList.remove('placeholder-glow', 'placeholder'));
            
            // Status Card
            const status = (data.statusInfo.status || 'shutdown').toLowerCase();
            this.elements.statusText.className = `status-${status} text-capitalize`;
            this.elements.statusText.textContent = status.replace(/_/g, ' ');
            this.elements.pid.textContent = data.statusInfo.process_id || 'N/A';
            this.elements.heartbeat.textContent = data.statusInfo.last_heartbeat ? new Date(data.statusInfo.last_heartbeat.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
            
            if (data.statusInfo.error_message) {
                this.elements.messagesContainer.innerHTML = `<strong>Bot Messages:</strong><pre class="bg-light border border-danger text-danger p-2 rounded small mt-1">${data.statusInfo.error_message}</pre>`;
            } else {
                this.elements.messagesContainer.innerHTML = '';
            }

            // Controls
            let controlsHtml = '';
            if (status === 'running' || status === 'initializing') {
                controlsHtml = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="stop_bot"><input type="hidden" name="config_id" value="${this.configId}"><input type="hidden" name="pid" value="${data.statusInfo.process_id}"><button type="submit" class="btn btn-danger"><i class="bi bi-stop-circle-fill"></i> Stop Bot</button></form>`;
            } else {
                const isDisabled = data.configuration.is_active == 0 ? 'disabled title="Bot is disabled in config"' : '';
                controlsHtml = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="start_bot"><input type="hidden" name="config_id" value="${this.configId}"><button type="submit" class="btn btn-success" ${isDisabled}><i class="bi bi-play-circle-fill"></i> Start Bot</button></form>`;
            }
            this.elements.controlsContainer.innerHTML = controlsHtml;
            
            // Performance Card
            this.elements.totalProfit.textContent = '$' + data.performance.totalProfit.toFixed(2);
            this.elements.totalProfit.className = data.performance.totalProfit >= 0 ? 'text-success' : 'text-danger';
            this.elements.tradesExecuted.textContent = data.performance.tradesExecuted;
            this.elements.winRate.textContent = data.performance.winRate.toFixed(2) + '%';
            this.elements.lastTradeAgo.textContent = data.performance.lastTradeAgo;

            // Recent Trades Table
            let tradesHtml = '';
            if (data.recentTrades.length === 0) {
                tradesHtml = '<tr><td colspan="7" class="text-center text-muted p-4">No trades recorded yet.</td></tr>';
            } else {
                data.recentTrades.forEach(trade => {
                    const netPnl = trade.realized_pnl_usdt === null ? null : parseFloat(trade.realized_pnl_usdt) - parseFloat(trade.commission_usdt);
                    const pnlText = netPnl === null ? 'N/A' : '$' + netPnl.toFixed(4);
                    const pnlClass = netPnl === null ? '' : (netPnl >= 0 ? 'text-success' : 'text-danger');
                    const tradeInfo = trade.reduce_only ? '<span class="badge bg-info">Reduce</span>' : '<span class="badge bg-secondary">Entry</span>';
                    tradesHtml += `
                        <tr>
                            <td>${trade.symbol}</td>
                            <td><span class="fw-bold text-${trade.side == 'BUY' ? 'success' : 'danger'}">${trade.side}</span></td>
                            <td>${parseFloat(trade.quantity_involved).toString()}</td>
                            <td>$${parseFloat(trade.price_point).toFixed(2)}</td>
                            <td class="fw-bold ${pnlClass}">${pnlText}</td>
                            <td>${new Date(trade.bot_event_timestamp_utc.replace(' ', 'T')+'Z').toLocaleString()}</td>
                            <td>${tradeInfo}</td>
                        </tr>`;
                });
            }
            this.elements.tradesBody.innerHTML = tradesHtml;
            
            // AI Logs
            let aiLogsHtml = '';
            if (data.aiLogs.length === 0) {
                aiLogsHtml = '<p class="text-center text-muted p-4">No AI decisions logged yet.</p>';
            } else {
                data.aiLogs.forEach(log => {
                    const decisionParams = JSON.parse(log.ai_decision_params_json || '{}');
                    const feedback = JSON.parse(log.bot_feedback_json || '{}');

                    let feedbackHtml = '';
                    if (feedback.override_reason) {
                        feedbackHtml = `<span class="text-warning">Bot Override:</span> ${feedback.override_reason}`;
                    } else {
                        feedbackHtml = `<span>${decisionParams.rationale || 'No rationale provided.'}</span>`;
                    }

                    let aiDecisionText = '';
                    if (decisionParams.action) {
                        aiDecisionText = decisionParams.action;
                        if (decisionParams.side) aiDecisionText += ` <strong class="text-${decisionParams.side === 'BUY' ? 'success' : 'danger'}">${decisionParams.side}</strong>`;
                        if (decisionParams.entryPrice) aiDecisionText += ` @ ${decisionParams.entryPrice}`;
                        if (decisionParams.quantity) aiDecisionText += `, Qty: ${decisionParams.quantity}`;
                        if (decisionParams.stopLossPrice) aiDecisionText += `, SL: ${decisionParams.stopLossPrice}`;
                        if (decisionParams.takeProfitPrice) aiDecisionText += `, TP: ${decisionParams.takeProfitPrice}`;
                    } else {
                        aiDecisionText = 'N/A';
                    }
                    
                    aiLogsHtml += `
                        <div class="ai-log-entry mx-2">
                            <div>
                                <span class="text-muted">${new Date(log.log_timestamp_utc.replace(' ', 'T')+'Z').toLocaleString()}</span> - 
                                <strong class="text-primary">${log.executed_action_by_bot}</strong>
                            </div>
                            <div class="ps-2" style="font-size: 0.9em;">
                                <strong>Bot Feedback:</strong> ${feedbackHtml}
                            </div>
                            <div class="ps-2" style="font-size: 0.9em;">
                                <small><strong>Original AI Decision:</strong> ${aiDecisionText}</small>
                            </div>
                        </div>`;
                });
            }
            this.elements.aiLogsContainer.innerHTML = aiLogsHtml;

            // Update strategy editor
            if (data.strategy && document.activeElement !== this.elements.strategyJsonEditor) {
                const prettyJson = JSON.stringify(JSON.parse(data.strategy.strategy_directives_json), null, 2);
                this.elements.strategyJsonEditor.value = prettyJson;
                this.elements.strategyIdInput.value = data.strategy.id;
                this.elements.strategyNameLabel.textContent = data.strategy.source_name || 'N/A';
                this.elements.strategyVersionLabel.textContent = data.strategy.version || 'N/A';
                this.elements.strategyUpdaterLabel.textContent = data.strategy.last_updated_by || 'N/A';
                this.elements.strategyUpdatedLabel.textContent = data.strategy.last_updated_at_utc ? new Date(data.strategy.last_updated_at_utc.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
            }


            this.elements.breadcrumbBotName.textContent = data.configuration.name;
            if(document.activeElement.name !== 'name') {
                 this.elements.updateConfigForm.querySelector('[name="name"]').value = data.configuration.name;
            }
        },

        async handleConfigUpdate(event) {
            event.preventDefault();
            const form = event.target;
            const button = form.querySelector('button[type="submit"]');
            const originalButtonHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

            try {
                const response = await fetch('dashboard.php?api=true&action=update_config', { method: 'POST', body: new FormData(form) });
                const data = await response.json();
                showAlert(data.message, data.status, true);
            } catch (error) {
                showAlert('Request failed: ' + error.message, 'danger', true);
            } finally {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        },

        async handleStrategyUpdate(event) {
            event.preventDefault();
            const form = event.target;
            const button = form.querySelector('button[type="submit"]');
            
            // Client-side JSON validation
            try {
                JSON.parse(this.elements.strategyJsonEditor.value);
            } catch (e) {
                showAlert('Invalid JSON format. Please correct it before saving.', 'danger', true);
                return;
            }

            const originalButtonHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...';

            try {
                const response = await fetch('dashboard.php?api=true&action=update_strategy', { method: 'POST', body: new FormData(form) });
                const data = await response.json();
                showAlert(data.message, data.status, true);
                if (data.status === 'success') {
                    // Trigger an immediate data refresh to show the new version number etc.
                    this.runUpdateCycle();
                }
            } catch (error) {
                showAlert('Request failed: ' + error.message, 'danger', true);
            } finally {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            }
        }
    };

    // --- MAIN DASHBOARD PAGE SPECIFIC LOGIC ---
    const mainDashboardPage = {
        elements: {
            tableBody: document.getElementById('bot-status-table-body')
        },
        init() {
            if (!this.elements.tableBody) return false;
            this.updateBotStatuses();
            appInterval = setInterval(() => this.updateBotStatuses(), 3500);
            return true;
        },
        async updateBotStatuses() {
            try {
                const response = await fetch('dashboard.php?api=true&action=get_statuses');
                if (!response.ok) return;
                const data = await response.json();
                if (data.status !== 'success') return;
                
                this.elements.tableBody.innerHTML = '';
                if (data.bots.length === 0) {
                    this.elements.tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted p-4">No bot configurations found.</td></tr>';
                    return;
                }
                data.bots.forEach(bot => {
                    const status = (bot.status || 'stopped').toLowerCase();
                    const heartbeat = bot.last_heartbeat ? new Date(bot.last_heartbeat.replace(' ', 'T') + 'Z').toLocaleString() : 'N/A';
                    let actionButtons = '';
                    if (status === 'running' || status === 'initializing') {
                        actionButtons = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="stop_bot"><input type="hidden" name="config_id" value="${bot.id}"><input type="hidden" name="pid" value="${bot.process_id}"><button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-stop-circle"></i> Stop</button></form>`;
                    } else {
                        actionButtons = `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="start_bot"><input type="hidden" name="config_id" value="${bot.id}"><button type="submit" class="btn btn-sm btn-success" ${!bot.is_active ? 'disabled' : ''}><i class="bi bi-play-circle"></i> Start</button></form>`;
                    }
                    actionButtons += `<a href="?view=edit_config&id=${bot.id}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Overview</a>`;
                    if (status !== 'running' && status !== 'initializing') {
                        actionButtons += `<form class="d-inline" onsubmit="return handleBotAction(event);"><input type="hidden" name="action" value="delete_config"><input type="hidden" name="config_id" value="${bot.id}"><button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form>`;
                    }
                    
                    const rowHtml = `
                        <tr>
                            <td><strong><a href="?view=edit_config&id=${bot.id}" class="text-decoration-none">${bot.name}</a></strong></td>
                            <td>${bot.symbol}</td>
                            <td class="status-${status} text-capitalize">${status}</td>
                            <td>${heartbeat}</td>
                            <td>${bot.process_id || 'N/A'}</td>
                            <td><div class="btn-group">${actionButtons}</div></td>
                        </tr>`;
                    this.elements.tableBody.insertAdjacentHTML('beforeend', rowHtml);
                });
            } catch (error) {
                console.error('Main dashboard update failed:', error);
            }
        }
    };
    
    // --- APP INITIALIZATION ---
    if (!overviewPage.init()) {
        mainDashboardPage.init();
    }
});
</script>
<?php endif; ?>

</body>
</html>
