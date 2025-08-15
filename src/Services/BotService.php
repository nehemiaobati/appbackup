<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Database;
use PDO;
use Exception;
use DateTime;
use DateTimeZone;

class BotService
{
    private PDO $pdo;

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
        } catch (Exception $e) {
            return 'N/A';
        }
    }

    public function getBotOverview(int $config_id, int $user_id): array
    {
        // 1. Verify user ownership of the bot config
        $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = ? AND user_id = ?");
        $stmt->execute([$config_id, $user_id]);
        $config_data = $stmt->fetch();
        if (!$config_data) {
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
        $stmt_perf->execute([$config_id, $user_id]);
        $perf_summary = $stmt_perf->fetch();

        // 4. Get recent trades
        $stmt_trades = $this->pdo->prepare("
            SELECT symbol, side, price_point, quantity_involved, bot_event_timestamp_utc, realized_pnl_usdt, commission_usdt, reduce_only
            FROM orders_log WHERE bot_config_id = ? AND user_id = ?
            ORDER BY bot_event_timestamp_utc DESC LIMIT 10");
        $stmt_trades->execute([$config_id, $user_id]);
        $recent_trades = $stmt_trades->fetchAll();

        // 5. Get AI Decisions
        $stmt_ai = $this->pdo->prepare("
            SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
            FROM ai_interactions_log WHERE bot_config_id = ? AND user_id = ?
            ORDER BY log_timestamp_utc DESC LIMIT 10");
        $stmt_ai->execute([$config_id, $user_id]);
        $ai_logs = $stmt_ai->fetchAll();
        
        // 6. Get Active Strategy
        $stmt_strategy = $this->pdo->prepare("SELECT * FROM trade_logic_source WHERE user_id = ? AND is_active = TRUE ORDER BY version DESC LIMIT 1");
        $stmt_strategy->execute([$user_id]);
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
        
        return $data_payload;
    }
}
