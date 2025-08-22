<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use PDOException;
use Exception;
use Throwable;
use App\Services\BotService;
// Removed: use App\Controllers\PaystackController; // No longer needed for balance retrieval

/**
 * BotController.php
 *
 * This Controller handles all bot-related operations for the web interface,
 * including displaying bot configurations, managing bot lifecycle (start/stop),
 * and handling bot configuration and strategy updates. It interacts with the
 * BotService for business logic and database operations, and executes shell
 * commands to manage the `bot.php` process.
 */

class BotController extends BaseController
{
    private const BOT_SCRIPT_PATH = __DIR__ . '/../../bot.php';
    private BotService $botService;
    private string $phpExecutablePath;

    /**
     * BotController constructor.
     * Initializes the PDO database connection and the BotService.
     * Determines the PHP executable path from environment variables or defaults.
     */
    public function __construct()
    {
        parent::__construct(); // Call parent constructor to initialize PDO
        $this->botService = new BotService();
        // Retrieve PHP executable path from .env, defaulting to a common path.
        $this->phpExecutablePath = $_ENV['PHP_EXECUTABLE_PATH'] ?? '/usr/bin/php';
    }

    /**
     * Renders the dashboard page, displaying the user's bot configurations and balance.
     * Fetches user balance from the database and handles potential errors during retrieval.
     *
     * @return void
     */
    public function dashboard(): void
    {
        $this->checkAuth(); // Authenticate user before proceeding.

        $userId = $_SESSION['user_id'] ?? null;
        $balanceInCents = null; // Initialize balance to null.
        $balanceErrorMessage = null; // Initialize error message.

        if ($userId) {
            try {
                // Fetch user balance from the database.
                $stmt = $this->pdo->prepare("SELECT balance_cents FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                if ($user && isset($user['balance_cents'])) {
                    $balanceInCents = $user['balance_cents'];
                } else {
                    $balanceErrorMessage = "Could not retrieve balance for user.";
                }
            } catch (PDOException $e) {
                // Log and handle database errors during balance retrieval.
                $balanceErrorMessage = "Database error retrieving balance: " . $e->getMessage();
                error_log("BotController dashboard balance retrieval error: " . $e->getMessage());
            } catch (Exception $e) {
                // Log and handle any other unexpected errors.
                $balanceErrorMessage = "An unexpected error occurred retrieving balance: " . $e->getMessage();
                error_log("BotController dashboard unexpected error: " . $e->getMessage());
            }
        } else {
            $balanceErrorMessage = "User not logged in.";
        }

        // Prepare data to be passed to the dashboard template.
        $data = [
            'username' => $_SESSION['username'] ?? 'User',
            'balance' => $balanceInCents,
            'balance_error_message' => $balanceErrorMessage,
            'view' => 'dashboard' // Used by layout.php for navigation highlighting.
        ];

        // Render the dashboard template with the prepared data.
        $this->render('dashboard', $data);
    }

    /**
     * Displays the detailed view for a specific bot configuration.
     * Ensures the logged-in user owns the requested bot configuration.
     *
     * @param int $config_id The ID of the bot configuration to display.
     * @return void Redirects to dashboard if configuration is not found or permission is denied.
     */
    public function showBotDetails(int $config_id): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

        // Fetch bot configuration data, ensuring user ownership.
        $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = ? AND user_id = ?");
        $stmt->execute([$config_id, $current_user_id]);
        $config_data = $stmt->fetch();

        if (!$config_data) {
            $_SESSION['error_message'] = "Configuration not found or permission denied.";
            header('Location: /dashboard');
            exit;
        }

        $this->render('bot_detail', ['config_data' => $config_data]);
    }

    /**
     * Displays the form for creating a new bot configuration.
     * Fetches active API keys associated with the current user to populate the form.
     *
     * @return void
     */
    public function showCreateBotForm(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

        // Fetch active API keys for the current user.
        $stmt = $this->pdo->prepare("SELECT id, key_name FROM user_api_keys WHERE user_id = ? AND is_active = TRUE");
        $stmt->execute([$current_user_id]);
        $user_api_keys = $stmt->fetchAll();

        $this->render('create_config', ['user_api_keys' => $user_api_keys]);
    }

    /**
     * Handles the creation of a new bot configuration via POST request.
     * Validates the selected API key, inserts the new configuration into the database,
     * and initializes its runtime status. Uses a database transaction for atomicity.
     *
     * @param array $_POST Expected bot configuration parameters.
     * @return void Redirects to dashboard on success, or back with an error message.
     */
    public function handleCreateConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

        try {
            $api_key_id = (int)($_POST['user_api_key_id'] ?? 0);
            // Verify that the selected API key belongs to the current user.
            $stmt = $this->pdo->prepare("SELECT id FROM user_api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$api_key_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Invalid API Key selection or permission denied.");
            }
            
            $this->pdo->beginTransaction(); // Start transaction.
            $insert_stmt = $this->pdo->prepare("
                INSERT INTO bot_configurations (user_id, user_api_key_id, name, symbol, kline_interval, margin_asset, default_leverage, order_check_interval_seconds, ai_update_interval_seconds, use_testnet, initial_margin_target_usdt, take_profit_target_usdt, pending_entry_order_cancel_timeout_seconds, profit_check_interval_seconds, is_active, quantity_determination_method, allow_ai_to_update_strategy) 
                VALUES (:user_id, :user_api_key_id, :name, :symbol, :kline_interval, :margin_asset, :default_leverage, :order_check_interval_seconds, :ai_update_interval_seconds, :use_testnet, :initial_margin_target_usdt, :take_profit_target_usdt, :pending_entry_order_cancel_timeout_seconds, :profit_check_interval_seconds, :is_active, :quantity_determination_method, :allow_ai_to_update_strategy)");
            $insert_stmt->execute([
                ':user_id' => $current_user_id,
                ':user_api_key_id' => $api_key_id,
                ':name' => trim(htmlspecialchars($_POST['name'] ?? '')),
                ':symbol' => trim(htmlspecialchars($_POST['symbol'] ?? '')),
                ':kline_interval' => trim(htmlspecialchars($_POST['kline_interval'] ?? '')),
                ':margin_asset' => trim(htmlspecialchars($_POST['margin_asset'] ?? '')),
                ':default_leverage' => (int)($_POST['default_leverage'] ?? 0),
                ':order_check_interval_seconds' => (int)($_POST['order_check_interval_seconds'] ?? 0),
                ':ai_update_interval_seconds' => (int)($_POST['ai_update_interval_seconds'] ?? 0),
                ':use_testnet' => isset($_POST['use_testnet']) ? 1 : 0,
                ':initial_margin_target_usdt' => (float)($_POST['initial_margin_target_usdt'] ?? 0.0),
                ':take_profit_target_usdt' => (float)($_POST['take_profit_target_usdt'] ?? 0.0),
                ':pending_entry_order_cancel_timeout_seconds' => (int)($_POST['pending_entry_order_cancel_timeout_seconds'] ?? 0),
                ':profit_check_interval_seconds' => (int)($_POST['profit_check_interval_seconds'] ?? 0),
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':quantity_determination_method' => trim(htmlspecialchars($_POST['quantity_determination_method'] ?? '')),
                ':allow_ai_to_update_strategy' => isset($_POST['allow_ai_to_update_strategy']) ? 1 : 0
            ]);
            $new_config_id = $this->pdo->lastInsertId();
            // Initialize bot runtime status as 'stopped'.
            $this->pdo->prepare("INSERT INTO bot_runtime_status (bot_config_id, status) VALUES (?, 'stopped')")->execute([$new_config_id]);
            $this->pdo->commit(); // Commit transaction on success.
            $_SESSION['success_message'] = "Bot configuration '" . htmlspecialchars($_POST['name']) . "' created successfully.";
            header('Location: /dashboard');
            exit;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack(); // Rollback on database error.
            error_log("Bot Create Config Database Error: " . $e->getMessage()); // Log for debugging.
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            header('Location: /dashboard');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * Handles updating an existing bot configuration via POST request.
     * Ensures the logged-in user owns the configuration being updated.
     * Returns a JSON response indicating success or failure.
     *
     * @param array $_POST Expected updated bot configuration parameters.
     * @return void Echoes JSON response and exits.
     */
    public function handleUpdateConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            // Verify user ownership of the configuration.
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
            $stmt->execute([$config_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Configuration not found or permission denied.");
            }
            
            // Update bot configuration using a prepared statement.
            $update_stmt = $this->pdo->prepare("
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
                ':name' => trim(htmlspecialchars($_POST['name'] ?? '')), 
                ':symbol' => trim(htmlspecialchars($_POST['symbol'] ?? '')), 
                ':kline_interval' => trim(htmlspecialchars($_POST['kline_interval'] ?? '')), 
                ':margin_asset' => trim(htmlspecialchars($_POST['margin_asset'] ?? '')), 
                ':default_leverage' => (int)($_POST['default_leverage'] ?? 0), 
                ':order_check_interval_seconds' => (int)($_POST['order_check_interval_seconds'] ?? 0), 
                ':ai_update_interval_seconds' => (int)($_POST['ai_update_interval_seconds'] ?? 0), 
                ':use_testnet' => isset($_POST['use_testnet']) ? 1 : 0, 
                ':initial_margin_target_usdt' => (float)($_POST['initial_margin_target_usdt'] ?? 0.0), 
                ':take_profit_target_usdt' => (float)($_POST['take_profit_target_usdt'] ?? 0.0), 
                ':pending_entry_order_cancel_timeout_seconds' => (int)($_POST['pending_entry_order_cancel_timeout_seconds'] ?? 0), 
                ':profit_check_interval_seconds' => (int)($_POST['profit_check_interval_seconds'] ?? 0), 
                ':is_active' => isset($_POST['is_active']) ? 1 : 0, 
                ':quantity_determination_method' => trim(htmlspecialchars($_POST['quantity_determination_method'] ?? '')),
                ':allow_ai_to_update_strategy' => isset($_POST['allow_ai_to_update_strategy']) ? 1 : 0,
                ':id' => $config_id, ':user_id' => $current_user_id
            ]);
            
            $response = ['status' => 'success', 'message' => "Configuration '" . htmlspecialchars($_POST['name']) . "' updated successfully."];
        } catch (Throwable $e) {
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Update Config Error: " . $e->getMessage()); // Log for debugging.
        }
        echo json_encode($response);
        exit;
    }

    /**
     * Handles the deletion of a bot configuration via POST request.
     * A bot can only be deleted if it is not currently running.
     * Uses a database transaction for atomicity.
     * Returns a JSON response indicating success or failure.
     *
     * @param int $_POST['config_id'] The ID of the bot configuration to delete.
     * @return void Echoes JSON response and exits.
     */
    public function handleDeleteConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            // Verify user ownership and ensure the bot is not running.
            $stmt = $this->pdo->prepare("SELECT bc.id FROM bot_configurations bc LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id WHERE bc.id = ? AND bc.user_id = ? AND (brs.status IS NULL OR brs.status = 'stopped' OR brs.status = 'error' OR brs.status = 'shutdown')");
            $stmt->execute([$config_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Cannot delete a running bot. Please stop it first.");
            }
            
            $this->pdo->beginTransaction(); // Start transaction.
            // Delete runtime status and then the configuration.
            $this->pdo->prepare("DELETE FROM bot_runtime_status WHERE bot_config_id = ?")->execute([$config_id]);
            $this->pdo->prepare("DELETE FROM bot_configurations WHERE id = ? AND user_id = ?")->execute([$config_id, $current_user_id]);
            $this->pdo->commit(); // Commit transaction.
            
            $response = ['status' => 'success', 'message' => 'Bot configuration deleted successfully. Redirecting...'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack(); // Rollback on error.
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Delete Config Error: " . $e->getMessage()); // Log for debugging.
        }
        echo json_encode($response);
        exit;
    }

    /**
     * Handles updating a bot's trading strategy (AI directives) via POST request.
     * Validates the incoming JSON and ensures user ownership of the strategy.
     * Increments the strategy version on update.
     * Returns a JSON response indicating success or failure.
     *
     * @param string $_POST['strategy_json'] The new strategy directives in JSON format.
     * @param int $_POST['strategy_id'] The ID of the strategy to update.
     * @return void Echoes JSON response and exits.
     */
    public function handleUpdateStrategy(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $strategy_json = $_POST['strategy_json'] ?? '';
            $strategy_id = (int)($_POST['strategy_id'] ?? 0);

            // Validate JSON format.
            $decoded = json_decode($strategy_json);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON format provided for the strategy.");
            }

            // Verify user ownership of the strategy.
            $stmt = $this->pdo->prepare("SELECT id FROM trade_logic_source WHERE id = ? AND user_id = ?");
            $stmt->execute([$strategy_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Strategy not found or permission denied.");
            }

            $this->pdo->beginTransaction(); // Start transaction.
            $stmt_update = $this->pdo->prepare("
                UPDATE trade_logic_source SET 
                    strategy_directives_json = :json,
                    version = version + 1,
                    last_updated_by = :updater,
                    last_updated_at_utc = :now
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt_update->execute([
                ':json' => $strategy_json,
                ':updater' => 'USER_UI', // Indicate update source.
                ':now' => gmdate('Y-m-d H:i:s'), // UTC timestamp.
                ':id' => $strategy_id,
                ':user_id' => $current_user_id
            ]);
            $this->pdo->commit(); // Commit transaction.
            $response = ['status' => 'success', 'message' => 'AI strategy directives updated successfully!'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack(); // Rollback on error.
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Update Strategy Error: " . $e->getMessage()); // Log for debugging.
        }
        echo json_encode($response);
        exit;
    }

    /**
     * Retrieves a detailed overview of a specific bot configuration and its performance via API.
     * Uses BotService to fetch data.
     * Returns a JSON response.
     *
     * @param int $config_id The ID of the bot configuration.
     * @return void Echoes JSON response and exits.
     */
    public function getBotOverviewApi(int $config_id): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            session_write_close(); // Close session to prevent blocking other requests.
            $data_payload = $this->botService->getBotOverview($config_id, $current_user_id);
            $response = ['status' => 'success', 'data' => $data_payload];
        } catch (Throwable $e) {
            if (!headers_sent()) http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Overview API Error: " . $e->getMessage()); // Log for debugging.
        }

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Retrieves the current runtime statuses of all active bots for the logged-in user via API.
     * Includes basic configuration details and total profit.
     * Returns a JSON response.
     *
     * @return void Echoes JSON response and exits.
     */
    public function getBotStatusesApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            session_write_close(); // Close session to prevent blocking.
            $stmt = $this->pdo->prepare("
                SELECT
                    bc.id, bc.name, bc.symbol, bc.is_active,
                    brs.status, brs.last_heartbeat, brs.process_id,
                    (SELECT SUM(realized_pnl_usdt - commission_usdt) FROM orders_log WHERE bot_config_id = bc.id) as total_profit
                FROM bot_configurations bc
                LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id
                WHERE bc.user_id = ?
                ORDER BY bc.id ASC
            ");
            $stmt->execute([$current_user_id]);
            $response = ['status' => 'success', 'bots' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) {
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Statuses API Error: " . $e->getMessage()); // Log for debugging.
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Initiates the start process for a specific bot configuration via API.
     * Executes the `bot_manager.sh` script to start the bot process.
     * Returns a JSON response.
     *
     * @param int $_POST['config_id'] The ID of the bot configuration to start.
     * @return void Echoes JSON response and exits.
     */
    public function startBotApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            // Verify bot is active and belongs to the user.
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ? AND is_active = TRUE");
            $stmt->execute([$config_id, $current_user_id]);
            if ($stmt->fetch()) {
                $manager_script = __DIR__ . '/../../bot_manager.sh';
                // Construct and execute the shell command to start the bot.
                $command = escapeshellcmd($manager_script) . ' start ' . escapeshellarg((string)$config_id);
                $output = shell_exec($command . ' 2>&1'); // Capture both stdout and stderr.

                if (strpos($output, 'SUCCESS') !== false) {
                    $response = ['status' => 'success', 'message' => "Bot start command successful. " . trim($output)];
                } else {
                    http_response_code(500);
                    $response = ['status' => 'error', 'message' => "Failed to start bot: " . trim($output)];
                }
            } else {
                http_response_code(403);
                $response['message'] = 'Bot is inactive or you do not have permission.';
            }
        } catch (Throwable $e) {
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Start API Error: " . $e->getMessage()); // Log for debugging.
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Initiates the stop process for a running bot via API.
     * Executes the `bot_manager.sh` script to stop the bot process.
     * Returns a JSON response.
     *
     * @param int $_POST['config_id'] The ID of the bot configuration to stop.
     * @return void Echoes JSON response and exits.
     */
    public function stopBotApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            // Verify user ownership of the configuration.
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
            $stmt->execute([$config_id, $current_user_id]);
            if ($stmt->fetch()) {
                $manager_script = __DIR__ . '/../../bot_manager.sh';
                // Construct and execute the shell command to stop the bot.
                $command = escapeshellcmd($manager_script) . ' stop ' . escapeshellarg((string)$config_id);
                $output = shell_exec($command . ' 2>&1'); // Capture both stdout and stderr.

                if (strpos($output, 'ERROR') === false) {
                    $response = ['status' => 'success', 'message' => "Bot stop command processed. " . trim($output)];
                } else {
                    http_response_code(500);
                    $response = ['status' => 'error', 'message' => "Failed to stop bot: " . trim($output)];
                }
            } else {
                http_response_code(403);
                $response['message'] = 'Invalid Config ID or permission denied.';
            }
        } catch (Throwable $e) {
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
            error_log("Bot Stop API Error: " . $e->getMessage()); // Log for debugging.
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
