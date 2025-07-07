<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use PDO;
use PDOException;
use Exception;

class BotController
{
    private PDO $pdo;
    private const PHP_EXECUTABLE_PATH = '/usr/bin/php'; // Defined in original dashboard.php
    private const BOT_SCRIPT_PATH = __DIR__ . '/../../bot.php'; // Defined in original dashboard.php

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Calculates a human-readable "time ago" string.
     * @param string|null $datetime_str A UTC datetime string.
     * @return string
     */
    private function time_ago(?string $datetime_str): string
    {
        if ($datetime_str === null) return 'N/A';
        try {
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $ago = new \DateTime($datetime_str, new \DateTimeZone('UTC'));
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

    private function checkAuth(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    public function showDashboard(): void
    {
        $this->checkAuth();
        $this->render('dashboard');
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
            session_write_close(); // Release session lock for long-polling API calls.

            // 1. Verify user ownership of the bot config
            $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = ? AND user_id = ?");
            $stmt->execute([$config_id, $current_user_id]);
            $config_data = $stmt->fetch();
            if (!$config_data) {
                http_response_code(403);
                throw new Exception("Configuration not found or permission denied.");
            }

            // 2. Get runtime status
            $stmt_status = $this->pdo->prepare("SELECT * FROM bot_runtime_status WHERE bot_config_id = ?");
            $stmt_status->execute([$config_id]);
            $bot_status = $stmt_status->fetch();
            
            // 3. Get performance summary
            $stmt_perf = $this->pdo->prepare("
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
            $stmt_trades = $this->pdo->prepare("
                SELECT symbol, side, price_point, quantity_involved, bot_event_timestamp_utc, realized_pnl_usdt, commission_usdt, reduce_only
                FROM orders_log WHERE bot_config_id = ? AND user_id = ?
                ORDER BY bot_event_timestamp_utc DESC LIMIT 10");
            $stmt_trades->execute([$config_id, $current_user_id]);
            $recent_trades = $stmt_trades->fetchAll();

            // 5. Get AI Decisions
            $stmt_ai = $this->pdo->prepare("
                SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
                FROM ai_interactions_log WHERE bot_config_id = ? AND user_id = ?
                ORDER BY log_timestamp_utc DESC LIMIT 10");
            $stmt_ai->execute([$config_id, $current_user_id]);
            $ai_logs = $stmt_ai->fetchAll();
            
            // 6. Get Active Strategy
            $stmt_strategy = $this->pdo->prepare("SELECT * FROM trade_logic_source WHERE user_id = ? AND is_active = TRUE ORDER BY version DESC LIMIT 1");
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
                    'lastTradeAgo' => $this->time_ago($perf_summary['last_trade_time'])
                ],
                'recentTrades' => $recent_trades,
                'aiLogs' => $ai_logs,
                'configuration' => $config_data,
                'strategy' => $strategy_data ?: ['strategy_directives_json' => '{"error": "No active strategy found for this user."}']
            ];
            
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
            $stmt = $this->pdo->prepare("SELECT bc.id, bc.name, bc.symbol, bc.is_active, brs.status, brs.last_heartbeat, brs.process_id FROM bot_configurations bc LEFT JOIN bot_runtime_status brs ON bc.id = brs.bot_config_id WHERE bc.user_id = ? ORDER BY bc.id ASC");
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
                $command = escapeshellcmd($manager_script) . ' start ' . escapeshellarg((string)$config_id);
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
                $command = escapeshellcmd($manager_script) . ' stop ' . escapeshellarg((string)$config_id);
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
