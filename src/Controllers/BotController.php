<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use PDO;
use PDOException;
use Exception;
use Throwable;
use App\Services\BotService;
use App\Controllers\PaystackController; // Added import for PaystackController

class BotController
{
    private PDO $pdo;
    private const BOT_SCRIPT_PATH = __DIR__ . '/../../bot.php'; // Defined in original dashboard.php
    private BotService $botService;
    private string $phpExecutablePath;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->botService = new BotService();
        $this->phpExecutablePath = $_ENV['PHP_EXECUTABLE_PATH'] ?? '/usr/bin/php'; // Default to common path if not set
    }

    private function checkAuth(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Renders the dashboard page, including bot configurations and user balance.
     *
     * @return void
     */
    public function dashboard(): void
    {
        $this->checkAuth(); // Ensure user is logged in

        // Instantiate PaystackController to get the balance
        $paystackController = new PaystackController();
        $totalBalanceInCents = null; // Initialize to null
        $balanceErrorMessage = null;

        try {
            $totalBalanceInCents = $paystackController->getTotalSuccessfulBalance();
            // Formatting can be done in the template or here if preferred.
            // For now, passing the raw cents value.
        } catch (\Exception $e) {
            // Handle potential errors in balance calculation
            $balanceErrorMessage = "Could not retrieve balance: " . $e->getMessage();
            // Optionally log the error
            error_log("Dashboard balance retrieval error: " . $e->getMessage());
        }

        // Data to be passed to the dashboard template
        $data = [
            'username' => $_SESSION['username'] ?? 'User',
            'balance' => $totalBalanceInCents, // Pass the balance to the template
            'balance_error_message' => $balanceErrorMessage, // Pass error message if any
            'view' => 'dashboard' // For layout highlighting
        ];

        // Render the dashboard template
        $this->render('dashboard', $data);
    }

    public function showBotDetails(int $config_id): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

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

    public function showCreateBotForm(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

        $stmt = $this->pdo->prepare("SELECT id, key_name FROM user_api_keys WHERE user_id = ? AND is_active = TRUE");
        $stmt->execute([$current_user_id]);
        $user_api_keys = $stmt->fetchAll();

        $this->render('create_config', ['user_api_keys' => $user_api_keys]);
    }

    public function handleCreateConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];

        try {
            $api_key_id = (int)($_POST['user_api_key_id'] ?? 0);
            $stmt = $this->pdo->prepare("SELECT id FROM user_api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$api_key_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Invalid API Key selection or permission denied.");
            }
            
            $this->pdo->beginTransaction();
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
            $this->pdo->prepare("INSERT INTO bot_runtime_status (bot_config_id, status) VALUES (?, 'stopped')")->execute([$new_config_id]);
            $this->pdo->commit();
            $_SESSION['success_message'] = "Bot configuration '" . htmlspecialchars($_POST['name']) . "' created successfully.";
            header('Location: /dashboard');
            exit;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            header('Location: /dashboard'); // Redirect to dashboard or create_config form
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /dashboard'); // Redirect to dashboard or create_config form
            exit;
        }
    }

    public function handleUpdateConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
            $stmt->execute([$config_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Configuration not found or permission denied.");
            }
            
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
        }
        echo json_encode($response);
        exit;
    }

    public function handleDeleteConfig(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            $stmt = $this->pdo->prepare("SELECT bc.id FROM bot_configurations bc LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id WHERE bc.id = ? AND bc.user_id = ? AND (brs.status IS NULL OR brs.status = 'stopped' OR brs.status = 'error' OR brs.status = 'shutdown')");
            $stmt->execute([$config_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Cannot delete a running bot. Please stop it first.");
            }
            
            $this->pdo->beginTransaction();
            $this->pdo->prepare("DELETE FROM bot_runtime_status WHERE bot_config_id = ?")->execute([$config_id]);
            $this->pdo->prepare("DELETE FROM bot_configurations WHERE id = ? AND user_id = ?")->execute([$config_id, $current_user_id]);
            $this->pdo->commit();
            
            $response = ['status' => 'success', 'message' => 'Bot configuration deleted successfully. Redirecting...'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }

    public function handleUpdateStrategy(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $strategy_json = $_POST['strategy_json'] ?? '';
            $strategy_id = (int)($_POST['strategy_id'] ?? 0);

            $decoded = json_decode($strategy_json);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON format provided for the strategy.");
            }

            $stmt = $this->pdo->prepare("SELECT id FROM trade_logic_source WHERE id = ? AND user_id = ?");
            $stmt->execute([$strategy_id, $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("Strategy not found or permission denied.");
            }

            $this->pdo->beginTransaction();
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
                ':updater' => 'USER_UI',
                ':now' => gmdate('Y-m-d H:i:s'),
                ':id' => $strategy_id,
                ':user_id' => $current_user_id
            ]);
            $this->pdo->commit();
            $response = ['status' => 'success', 'message' => 'AI strategy directives updated successfully!'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }

    public function getBotOverviewApi(int $config_id): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            session_write_close();
            $data_payload = $this->botService->getBotOverview($config_id, $current_user_id);
            $response = ['status' => 'success', 'data' => $data_payload];
        } catch (Throwable $e) {
            if (!headers_sent()) http_response_code(500);
            $response['message'] = 'Server error: ' . $e->getMessage();
        }

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    public function getBotStatusesApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            session_write_close();
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
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    public function startBotApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ? AND is_active = TRUE");
            $stmt->execute([$config_id, $current_user_id]);
            if ($stmt->fetch()) {
                $manager_script = __DIR__ . '/../../bot_manager.sh';
                $command = escapeshellcmd($this->phpExecutablePath) . ' ' . escapeshellcmd($manager_script) . ' start ' . escapeshellarg((string)$config_id);
                $output = shell_exec($command . ' 2>&1'); 

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
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    public function stopBotApi(): void
    {
        $this->checkAuth();
        $current_user_id = $_SESSION['user_id'];
        $response = ['status' => 'error', 'message' => 'Invalid action or permission denied.'];

        try {
            $config_id = (int)($_POST['config_id'] ?? 0);
            $stmt = $this->pdo->prepare("SELECT id FROM bot_configurations WHERE id = ? AND user_id = ?");
            $stmt->execute([$config_id, $current_user_id]);
            if ($stmt->fetch()) {
                $manager_script = __DIR__ . '/../../bot_manager.sh';
                $command = escapeshellcmd($this->phpExecutablePath) . ' ' . escapeshellcmd($manager_script) . ' stop ' . escapeshellarg((string)$config_id);
                $output = shell_exec($command . ' 2>&1');

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
        }
        header('Content-Type: application/json');
        echo json_encode($response);
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
