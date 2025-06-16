<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// --- Environment Variable Loading ---
try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Throwable $e) {
    die("Error: .env file not found. Please create one based on the example.");
}
$dotenv->required(['GEMINI_MODEL_NAME', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'APP_ENCRYPTION_KEY']);

// Load application-level environment variables
$geminiModelName = $_ENV['GEMINI_MODEL_NAME'];
$dbHost = $_ENV['DB_HOST'];
$dbPort = $_ENV['DB_PORT'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];
$appEncryptionKey = $_ENV['APP_ENCRYPTION_KEY'];


use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

class AiTradingBotFutures
{
    // --- Constants ---
    private const BINANCE_FUTURES_PROD_REST_API_BASE_URL = 'https://fapi.binance.com';
    private const BINANCE_FUTURES_TEST_REST_API_BASE_URL = 'https://testnet.binancefuture.com';
    private const BINANCE_FUTURES_PROD_WS_BASE_URL = 'wss://fstream.binance.com';
    private const BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED = 'wss://stream.binancefuture.com';

    private const BINANCE_API_RECV_WINDOW = 5000;
    private const MAX_ORDER_LOG_ENTRIES_FOR_AI_CONTEXT = 10;
    private const MAX_AI_INTERACTIONS_FOR_AI_CONTEXT = 3;
    private const LISTEN_KEY_REFRESH_INTERVAL = 30 * 60;
    private const DEFAULT_TRADE_LOGIC_SOURCE_NAME = 'default_strategy_v1';
    private const BOT_HEARTBEAT_INTERVAL = 10;
    private const ENCRYPTION_CIPHER = 'aes-256-cbc';


    // --- Configuration Properties (Loaded from DB) ---
    private int $botConfigId;
    private int $userId; // The user this bot instance belongs to
    private string $tradingSymbol;
    private string $klineInterval;
    private string $marginAsset;
    private int $defaultLeverage;
    private int $orderCheckIntervalSeconds;
    private int $maxScriptRuntimeSeconds;
    private int $aiUpdateIntervalSeconds;
    private bool $useTestnet;
    private int $pendingEntryOrderCancelTimeoutSeconds;
    private float $initialMarginTargetUsdt;
    private array $historicalKlineIntervalsAIArray;
    private string $primaryHistoricalKlineIntervalAI;
    private float $takeProfitTargetUsdt;
    private int $profitCheckIntervalSeconds;

    // --- User-Specific API Keys (Loaded securely from DB) ---
    private string $binanceApiKey;
    private string $binanceApiSecret;
    private string $geminiApiKey;
    private string $geminiModelName;

    // --- Application Secret ---
    private string $appEncryptionKey; // For decrypting user API keys

    // --- Runtime Base URLs ---
    private string $currentRestApiBaseUrl;
    private string $currentWsBaseUrlCombined;

    // --- Database Properties ---
    private string $dbHost;
    private string $dbPort;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;
    private ?PDO $pdo = null;

    // --- Dependencies ---
    private LoopInterface $loop;
    private Browser $browser;
    private LoggerInterface $logger;
    private ?WebSocket $wsConnection = null;

    // --- Exchange Information Cache ---
    private array $exchangeInfo = [];

    // --- State Properties ---
    private ?float $lastClosedKlinePrice = null;
    private ?string $activeEntryOrderId = null;
    private ?int $activeEntryOrderTimestamp = null;
    private ?string $activeSlOrderId = null;
    private ?string $activeTpOrderId = null;
    private ?array $currentPositionDetails = null;
    private bool $isPlacingOrManagingOrder = false;
    private ?string $listenKey = null;
    private ?\React\EventLoop\TimerInterface $listenKeyRefreshTimer = null;
    private ?\React\EventLoop\TimerInterface $heartbeatTimer = null;
    private bool $isMissingProtectiveOrder = false;
    private ?array $lastAIDecisionResult = null;
    private ?int $botStartTime = null;

    // AI Suggested Parameters
    private float $aiSuggestedEntryPrice;
    private float $aiSuggestedSlPrice;
    private float $aiSuggestedTpPrice;
    private float $aiSuggestedQuantity;
    private string $aiSuggestedSide;
    private int $aiSuggestedLeverage;

    // For AI interaction logging
    private ?array $currentDataForAIForDBLog = null;
    private ?string $currentPromptMD5ForDBLog = null;
    private ?string $currentRawAIResponseForDBLog = null;
    private ?array $currentActiveTradeLogicSource = null;


    public function __construct(
        int $botConfigId,
        string $geminiModelName,
        string $appEncryptionKey,
        string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPassword
    ) {
        $this->botConfigId = $botConfigId;
        $this->geminiModelName = $geminiModelName;
        $this->appEncryptionKey = $appEncryptionKey;
        $this->dbHost = $dbHost; $this->dbPort = $dbPort; $this->dbName = $dbName;
        $this->dbUser = $dbUser; $this->dbPassword = $dbPassword;

        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop);

        // Configure Monolog for stdout logging
        $logFormat = "[%datetime%] [%level_name%] [BotID:{$this->botConfigId}] [UserID:?] %message% %context% %extra%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG); // Log all levels to stdout
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBotFutures'); // Use a more generic name for the logger instance
        $this->logger->pushHandler($streamHandler);

        // Initialize database connection and load configurations
        $this->initializeDatabaseConnection();
        $this->loadBotConfigurationFromDb($this->botConfigId);

        // Update logger with actual UserID after configuration is loaded
        $newLogFormat = str_replace('[UserID:?]', "[UserID:{$this->userId}]", $logFormat);
        $newFormatter = new LineFormatter($newLogFormat, 'Y-m-d H:i:s', true, true);
        $this->logger->getHandlers()[0]->setFormatter($newFormatter); // Apply new formatter to the existing handler

        // Load user API keys and active trade logic
        $this->loadUserAndApiKeys();
        $this->loadActiveTradeLogicSource();

        // Set base URLs based on testnet configuration
        $this->currentRestApiBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_REST_API_BASE_URL : self::BINANCE_FUTURES_PROD_REST_API_BASE_URL;
        $this->currentWsBaseUrlCombined = $this->useTestnet ? self::BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED : self::BINANCE_FUTURES_PROD_WS_BASE_URL;

        // Log successful bot initialization
        $this->logger->info('AiTradingBotFutures instance successfully initialized and configured.', [
            'symbol' => $this->tradingSymbol,
            'ai_model' => $this->geminiModelName,
            'testnet_mode' => $this->useTestnet ? 'Enabled' : 'Disabled',
            'active_trade_logic_source' => $this->currentActiveTradeLogicSource ? ($this->currentActiveTradeLogicSource['source_name'] . ' v' . $this->currentActiveTradeLogicSource['version']) : 'None Loaded (using fallback)',
        ]);
        $this->aiSuggestedLeverage = $this->defaultLeverage;
    }

    /**
     * Decrypts an encrypted string using the application encryption key.
     *
     * @param string $encryptedData Base64 encoded string containing IV and encrypted data.
     * @return string Decrypted plain text.
     * @throws \RuntimeException If decryption fails at any stage.
     */
    private function decrypt(string $encryptedData): string
    {
        $decoded = base64_decode($encryptedData);
        if ($decoded === false) {
            $this->logger->error("Decryption failed: Base64 decode error.", ['encrypted_data_preview' => substr($encryptedData, 0, 50)]);
            throw new \RuntimeException('Failed to base64 decode encrypted key.');
        }
        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);

        if ($iv === false || $encrypted === false || strlen($iv) !== $ivLength) {
            $this->logger->error("Decryption failed: Invalid IV or encrypted data format.", ['iv_length_expected' => $ivLength, 'iv_length_actual' => strlen($iv)]);
            throw new \RuntimeException('Invalid encrypted data format: IV or encrypted data missing/malformed.');
        }

        $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_CIPHER, $this->appEncryptionKey, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            $this->logger->error("Decryption failed: openssl_decrypt returned false. Check APP_ENCRYPTION_KEY and data integrity.", ['cipher' => self::ENCRYPTION_CIPHER]);
            throw new \RuntimeException('Failed to decrypt API key. Check APP_ENCRYPTION_KEY and data integrity.');
        }
        $this->logger->debug("Data successfully decrypted.");
        return $decrypted;
    }

    /**
     * Loads and decrypts user-specific API keys from the database.
     *
     * @throws \RuntimeException If database is not connected, no active keys are found, or decryption fails.
     */
    private function loadUserAndApiKeys(): void
    {
        if (!$this->pdo) {
            $this->logger->critical("Attempted to load API keys but database connection is not established.");
            throw new \RuntimeException("Cannot load API keys: Database not connected.");
        }

        $stmt = $this->pdo->prepare("
            SELECT
                uak.binance_api_key_encrypted,
                uak.binance_api_secret_encrypted,
                uak.gemini_api_key_encrypted
            FROM bot_configurations bc
            JOIN user_api_keys uak ON bc.user_api_key_id = uak.id
            WHERE bc.id = :bot_config_id AND uak.user_id = :user_id AND uak.is_active = TRUE
        ");
        $stmt->execute([':bot_config_id' => $this->botConfigId, ':user_id' => $this->userId]);
        $keys = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keys) {
            $this->logger->critical("No active API keys found for bot configuration ID {$this->botConfigId} and user ID {$this->userId}.");
            throw new \RuntimeException("No active API keys found for bot configuration ID {$this->botConfigId} and user ID {$this->userId}.");
        }

        try {
            $this->binanceApiKey = $this->decrypt($keys['binance_api_key_encrypted']);
            $this->binanceApiSecret = $this->decrypt($keys['binance_api_secret_encrypted']);
            $this->geminiApiKey = $this->decrypt($keys['gemini_api_key_encrypted']);
            $this->logger->info("User API keys loaded and decrypted successfully.");
        } catch (\Throwable $e) {
            $this->logger->critical("FATAL: Failed to decrypt user API keys for user {$this->userId}. " . $e->getMessage(), ['exception' => $e]);
            throw new \RuntimeException("API key decryption failed for user {$this->userId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Initializes the PDO database connection.
     *
     * @throws \RuntimeException If the database connection fails.
     */
    private function initializeDatabaseConnection(): void
    {
        $dsn = "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, $this->dbUser, $this->dbPassword, $options);
            $this->logger->info("Successfully connected to database.", ['db_name' => $this->dbName, 'db_host' => $this->dbHost]);
        } catch (\PDOException $e) {
            $this->pdo = null;
            $this->logger->critical("Database connection failed at startup: " . $e->getMessage(), ['db_host' => $this->dbHost, 'db_name' => $this->dbName, 'exception' => $e]);
            throw new \RuntimeException("Database connection failed at startup: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Loads the bot's configuration from the database.
     *
     * @param int $configId The ID of the bot configuration to load.
     * @throws \RuntimeException If the database is not connected or configuration is not found/active.
     */
    private function loadBotConfigurationFromDb(int $configId): void
    {
        if (!$this->pdo) {
            $this->logger->critical("Cannot load bot configuration: Database connection is not established.");
            throw new \RuntimeException("Cannot load bot configuration: Database not connected.");
        }

        $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = :id AND is_active = TRUE");
        $stmt->execute([':id' => $configId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            $this->logger->critical("Bot configuration with ID {$configId} not found or is not active in the database.");
            throw new \RuntimeException("Bot configuration with ID {$configId} not found or is not active.");
        }

        // Assign configuration values to properties
        $this->userId = (int)$config['user_id'];
        $this->tradingSymbol = strtoupper($config['symbol']);
        $this->klineInterval = $config['kline_interval'];
        $this->marginAsset = strtoupper($config['margin_asset']);
        $this->defaultLeverage = (int)$config['default_leverage'];
        $this->orderCheckIntervalSeconds = (int)$config['order_check_interval_seconds'];
        $this->aiUpdateIntervalSeconds = (int)$config['ai_update_interval_seconds'];
        $this->useTestnet = (bool)$config['use_testnet'];
        $this->pendingEntryOrderCancelTimeoutSeconds = (int)$config['pending_entry_order_cancel_timeout_seconds'];
        $this->initialMarginTargetUsdt = (float)$config['initial_margin_target_usdt'];
        $this->takeProfitTargetUsdt = (float)$config['take_profit_target_usdt'];
        $this->profitCheckIntervalSeconds = (int)$config['profit_check_interval_seconds'];
        $this->maxScriptRuntimeSeconds = 86400; // Hardcoded max runtime

        // Default AI historical kline intervals
        $this->historicalKlineIntervalsAIArray = ['1m', '5m', '15m', '30m', '1h', '6h', '12h', '1d'];
        $this->primaryHistoricalKlineIntervalAI = '5m';

        $this->logger->info("Bot configuration loaded successfully from DB.", [
            'config_id' => $configId,
            'config_name' => $config['name'],
            'user_id' => $this->userId,
            'symbol' => $this->tradingSymbol,
            'testnet' => $this->useTestnet,
        ]);
    }

    /**
     * Updates the bot's runtime status in the database.
     *
     * @param string $status The current status of the bot (e.g., 'running', 'initializing', 'error', 'stopped').
     * @param string|null $errorMessage Optional error message if the status is 'error'.
     * @return bool True on successful update, false otherwise.
     */
    private function updateBotStatus(string $status, ?string $errorMessage = null): bool
    {
        if (!$this->pdo) {
            $this->logger->error("Database connection not available. Cannot update bot runtime status in DB.", ['status_attempted' => $status]);
            return false;
        }

        try {
            $pid = getmypid(); // Get current process ID

            $stmt = $this->pdo->prepare("
                INSERT INTO bot_runtime_status (bot_config_id, status, last_heartbeat, process_id, error_message)
                VALUES (:bot_config_id, :status, NOW(), :process_id, :error_message)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status), last_heartbeat = VALUES(last_heartbeat),
                    process_id = VALUES(process_id), error_message = VALUES(error_message)
            ");
            $stmt->execute([
                ':bot_config_id' => $this->botConfigId,
                ':status' => $status,
                ':process_id' => $pid,
                ':error_message' => $errorMessage
            ]);
            $this->logger->debug("Bot runtime status updated in DB.", ['status' => $status, 'pid' => $pid, 'error_msg' => $errorMessage]);
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to update bot runtime status in DB: " . $e->getMessage(), ['status' => $status, 'exception' => $e]);
            return false;
        }
    }

    private function getDefaultStrategyDirectives(): array
    {
        return [
            'schema_version' => '1.0.0', 'strategy_type' => 'GENERAL_TRADING', 'current_market_bias' => 'NEUTRAL',
            'User prompt' => [],
            'preferred_timeframes_for_entry' => ['1m', '5m', '15m'],
            'key_sr_levels_to_watch' => ['support' => [], 'resistance' => []],
            'risk_parameters' => ['target_risk_per_trade_usdt' => 0.5, 'default_rr_ratio' => 3, 'max_concurrent_positions' => 1],
            'quantity_determination_method' => 'INITIAL_MARGIN_TARGET',
            'entry_conditions_keywords' => ['momentum_confirm', 'breakout_consolidation'],
            'exit_conditions_keywords' => ['momentum_stall', 'target_profit_achieved'],
            'leverage_preference' => ['min' => 100, 'max' => 100, 'preferred' => 100],
            'ai_confidence_threshold_for_trade' => 0.7,
            'ai_learnings_notes' => 'Initial default strategy directives. AI to adapt based on market and trade outcomes.',
            'allow_ai_to_update_self' => false,
            'emergency_hold_justification' => 'Wait for clear market signal or manual intervention.'
        ];
    }

    /**
     * Loads the active trade logic source for the user from the database.
     * If no active source is found, a default one is created and activated.
     */
    private function loadActiveTradeLogicSource(): void
    {
        if (!$this->pdo) {
            $this->logger->warning("Database connection not available. Using hardcoded default trade strategy as fallback.");
            $defaultDirectives = $this->getDefaultStrategyDirectives();
            $this->currentActiveTradeLogicSource = [
                'id' => 0, // Indicate a non-DB source
                'user_id' => $this->userId,
                'source_name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME . '_fallback',
                'is_active' => true,
                'version' => 1,
                'last_updated_by' => 'SYSTEM_FALLBACK',
                'last_updated_at_utc' => gmdate('Y-m-d H:i:s'),
                'strategy_directives_json' => json_encode($defaultDirectives),
                'strategy_directives' => $defaultDirectives,
            ];
            return;
        }

        $sql = "SELECT * FROM trade_logic_source WHERE user_id = :user_id AND is_active = TRUE ORDER BY last_updated_at_utc DESC LIMIT 1";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':user_id' => $this->userId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($source) {
                $this->currentActiveTradeLogicSource = $source;
                $decodedDirectives = json_decode((string)$source['strategy_directives_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->currentActiveTradeLogicSource['strategy_directives'] = $decodedDirectives;
                } else {
                    $this->logger->error("Failed to decode 'strategy_directives_json' from DB for source ID {$source['id']}.", [
                        'json_error' => json_last_error_msg(),
                        'source_id' => $source['id'],
                        'user_id' => $this->userId
                    ]);
                    // Fallback to default directives if decoding fails
                    $this->currentActiveTradeLogicSource['strategy_directives'] = $this->getDefaultStrategyDirectives();
                }
                $this->logger->info("Loaded active trade logic source '{$source['source_name']}' v{$source['version']} from DB.", [
                    'source_id' => $source['id'],
                    'user_id' => $this->userId
                ]);
            } else {
                $this->logger->warning("No active trade logic source found for user ID {$this->userId}. Creating and activating a default one.");
                $defaultDirectives = $this->getDefaultStrategyDirectives();
                $insertSql = "INSERT INTO trade_logic_source (user_id, source_name, is_active, version, last_updated_by, last_updated_at_utc, strategy_directives_json)
                              VALUES (:user_id, :name, TRUE, 1, 'SYSTEM_DEFAULT', :now, :directives)";
                $insertStmt = $this->pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':user_id' => $this->userId,
                    ':name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME,
                    ':now' => gmdate('Y-m-d H:i:s'),
                    ':directives' => json_encode($defaultDirectives)
                ]);
                // Recursively call to load the newly created default source
                $this->loadActiveTradeLogicSource();
            }
        } catch (\PDOException $e) {
            $this->logger->error("Failed to load active trade logic source from DB: " . $e->getMessage() . ". Using hardcoded default as fallback.", ['exception' => $e, 'user_id' => $this->userId]);
            $this->currentActiveTradeLogicSource = null; // Ensure it's null if DB fails completely
        }
    }
    
    /**
     * Updates the trade logic source in the database.
     *
     * @param array $updatedDirectives The new strategy directives to save.
     * @param string $reasonForUpdate A brief reason for the update.
     * @param array|null $currentFullDataForAI Optional snapshot of full AI data at the time of update.
     * @return bool True on success, false on failure.
     */
    private function updateTradeLogicSourceInDb(array $updatedDirectives, string $reasonForUpdate, ?array $currentFullDataForAI): bool
    {
        if (!$this->pdo || !$this->currentActiveTradeLogicSource || !isset($this->currentActiveTradeLogicSource['id']) || $this->currentActiveTradeLogicSource['id'] === 0) {
            $this->logger->error("Cannot update trade logic source: Database not connected or no valid source loaded from DB (ID 0 indicates fallback).", [
                'source_id' => $this->currentActiveTradeLogicSource['id'] ?? 'N/A'
            ]);
            return false;
        }

        $sourceIdToUpdate = (int)$this->currentActiveTradeLogicSource['id'];
        $newVersion = (int)$this->currentActiveTradeLogicSource['version'] + 1;

        $timestampedReason = gmdate('Y-m-d H:i:s') . ' UTC - AI Update (v' . $newVersion . '): ' . $reasonForUpdate;
        // Prepend new reason to existing notes, ensuring it's an array if not already.
        $existingNotes = $updatedDirectives['ai_learnings_notes'] ?? '';
        $updatedDirectives['ai_learnings_notes'] = $timestampedReason . "\n" . $existingNotes;
        $updatedDirectives['schema_version'] = $updatedDirectives['schema_version'] ?? '1.0.0';

        $sql = "UPDATE trade_logic_source SET version = :version, last_updated_by = 'AI', last_updated_at_utc = :now,
                strategy_directives_json = :directives, full_data_snapshot_at_last_update_json = :snapshot
                WHERE id = :id AND user_id = :user_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':version' => $newVersion,
                ':now' => gmdate('Y-m-d H:i:s'),
                ':directives' => json_encode($updatedDirectives, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
                ':snapshot' => $currentFullDataForAI ? json_encode($currentFullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':id' => $sourceIdToUpdate,
                ':user_id' => $this->userId
            ]);
            $this->logger->info("Trade logic source updated to v{$newVersion} by AI.", [
                'source_id' => $sourceIdToUpdate,
                'user_id' => $this->userId,
                'reason' => $reasonForUpdate
            ]);
            // Reload the configuration to ensure the bot uses the latest directives
            $this->loadActiveTradeLogicSource();
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to update trade logic source in DB: " . $e->getMessage(), [
                'source_id' => $sourceIdToUpdate,
                'user_id' => $this->userId,
                'exception' => $e
            ]);
            return false;
        }
    }

    /**
     * Logs an order event to the database.
     *
     * @param string $orderId Binance order ID.
     * @param string $status Status or reason for the log (e.g., FILLED, CANCELED, ENTRY_ATTEMPT).
     * @param string $side BUY/SELL.
     * @param string $assetPair Trading symbol (e.g., BTCUSDT).
     * @param float|null $price Price point of the order/fill.
     * @param float|null $quantity Quantity involved.
     * @param string|null $marginAsset Margin asset (e.g., USDT).
     * @param int $timestamp Unix timestamp of the event.
     * @param float|null $realizedPnl Realized PnL for the trade (if applicable).
     * @param float $commissionUsdt Commission paid in USDT equivalent.
     * @param bool $reduceOnly Whether the order was reduce-only.
     * @return bool True on successful log, false otherwise.
     */
    private function logOrderToDb(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt = 0.0, bool $reduceOnly = false): bool
    {
        if (!$this->pdo) {
            $this->logger->warning("Database connection not available. Cannot log order to DB.", compact('orderId', 'status'));
            return false;
        }
        $sql = "INSERT INTO orders_log (user_id, bot_config_id, order_id_binance, bot_event_timestamp_utc, symbol, side, status_reason, price_point, quantity_involved, margin_asset, realized_pnl_usdt, commission_usdt, reduce_only)
                VALUES (:user_id, :bot_config_id, :order_id_binance, :bot_event_timestamp_utc, :symbol, :side, :status_reason, :price_point, :quantity_involved, :margin_asset, :realized_pnl_usdt, :commission_usdt, :reduce_only)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $success = $stmt->execute([
                ':user_id' => $this->userId,
                ':bot_config_id' => $this->botConfigId,
                ':order_id_binance' => $orderId,
                ':bot_event_timestamp_utc' => gmdate('Y-m-d H:i:s', $timestamp),
                ':symbol' => $assetPair,
                ':side' => $side,
                ':status_reason' => $status,
                ':price_point' => $price,
                ':quantity_involved' => $quantity,
                ':margin_asset' => $marginAsset,
                ':realized_pnl_usdt' => $realizedPnl,
                ':commission_usdt' => $commissionUsdt,
                ':reduce_only' => (int)$reduceOnly
            ]);
            if ($success) {
                $this->logger->debug("Order logged to DB successfully.", compact('orderId', 'status', 'realizedPnl', 'commissionUsdt'));
            } else {
                $this->logger->error("Failed to execute order log statement to DB.", compact('orderId', 'status'));
            }
            return $success;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to log order to DB due to PDOException: " . $e->getMessage(), compact('orderId', 'status', 'e'));
            return false;
        }
    }

    /**
     * Fetches recent order logs from the database for AI context.
     *
     * @param int $limit The maximum number of logs to fetch.
     * @return array An array of recent order logs, or an error entry if DB is not connected.
     */
    private function getRecentOrderLogsFromDb(int $limit): array
    {
        if (!$this->pdo) {
            $this->logger->warning("Database connection not available. Cannot fetch recent order logs from DB.");
            return [['error' => 'Database not connected', 'message' => 'Cannot fetch order logs without a database connection.']];
        }
        $sql = "SELECT order_id_binance as orderId, status_reason as status, side, symbol as assetPair, price_point as price, quantity_involved as quantity, margin_asset as marginAsset, DATE_FORMAT(bot_event_timestamp_utc, '%Y-%m-%d %H:%i:%s UTC') as timestamp, realized_pnl_usdt as realizedPnl, reduce_only as reduceOnly
                FROM orders_log WHERE bot_config_id = :bot_config_id ORDER BY bot_event_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':bot_config_id', $this->botConfigId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->logger->debug("Fetched recent order logs from DB.", ['count' => count($logs)]);
            return array_map(function ($log) {
                $log['price'] = isset($log['price']) ? (float)$log['price'] : null;
                $log['quantity'] = isset($log['quantity']) ? (float)$log['quantity'] : null;
                $log['realizedPnl'] = isset($log['realizedPnl']) ? (float)$log['realizedPnl'] : 0.0;
                $log['reduceOnly'] = (bool)($log['reduceOnly'] ?? false); // Ensure it's a boolean
                return $log;
            }, $logs);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to fetch recent order logs from DB: " . $e->getMessage(), ['exception' => $e]);
            return [['error' => 'Failed to fetch order logs: ' . $e->getMessage()]];
        }
    }

    /**
     * Logs an AI interaction event to the database.
     *
     * @param string $executedAction The action executed by the bot based on AI's decision.
     * @param array|null $aiDecisionParams The raw parameters received from AI's decision.
     * @param array|null $botFeedback Feedback from the bot about the AI's decision or execution.
     * @param array|null $fullDataForAI The full data context sent to AI.
     * @param string|null $promptMd5 MD5 hash of the prompt sent to AI.
     * @param string|null $rawAiResponse Raw JSON response received from AI.
     * @return bool True on successful log, false otherwise.
     */
    private function logAIInteractionToDb(string $executedAction, ?array $aiDecisionParams, ?array $botFeedback, ?array $fullDataForAI, ?string $promptMd5 = null, ?string $rawAiResponse = null): bool
    {
        if (!$this->pdo) {
            $this->logger->warning("Database connection not available. Cannot log AI interaction to DB.", compact('executedAction'));
            return false;
        }
        $sql = "INSERT INTO ai_interactions_log (user_id, bot_config_id, log_timestamp_utc, trading_symbol, executed_action_by_bot, ai_decision_params_json, bot_feedback_json, full_data_for_ai_json, prompt_text_sent_to_ai_md5, raw_ai_response_json)
                VALUES (:user_id, :bot_config_id, :log_timestamp_utc, :trading_symbol, :executed_action_by_bot, :ai_decision_params_json, :bot_feedback_json, :full_data_for_ai_json, :prompt_text_sent_to_ai_md5, :raw_ai_response_json)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $success = $stmt->execute([
                ':user_id' => $this->userId,
                ':bot_config_id' => $this->botConfigId,
                ':log_timestamp_utc' => gmdate('Y-m-d H:i:s'),
                ':trading_symbol' => $this->tradingSymbol,
                ':executed_action_by_bot' => $executedAction,
                ':ai_decision_params_json' => $aiDecisionParams ? json_encode($aiDecisionParams, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':bot_feedback_json' => $botFeedback ? json_encode($botFeedback, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':full_data_for_ai_json' => $fullDataForAI ? json_encode($fullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':prompt_text_sent_to_ai_md5' => $promptMd5,
                ':raw_ai_response_json' => $rawAiResponse ? json_encode(json_decode($rawAiResponse, true), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
            ]);
            if ($success) {
                $this->logger->debug("AI interaction logged to DB successfully.", compact('executedAction', 'promptMd5'));
            } else {
                $this->logger->error("Failed to execute AI interaction log statement to DB.", compact('executedAction', 'promptMd5'));
            }
            return $success;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to log AI interaction to DB due to PDOException: " . $e->getMessage(), compact('executedAction', 'e'));
            return false;
        }
    }

    /**
     * Fetches recent AI interaction logs from the database for AI context.
     *
     * @param int $limit The maximum number of interactions to fetch.
     * @return array An array of recent AI interaction logs, or an error entry if DB is not connected.
     */
    private function getRecentAIInteractionsFromDb(int $limit): array
    {
        if (!$this->pdo) {
            $this->logger->warning("Database connection not available. Cannot fetch recent AI interactions from DB.");
            return [['error' => 'Database not connected', 'message' => 'Cannot fetch AI interactions without a database connection.']];
        }
        $sql = "SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
                FROM ai_interactions_log WHERE bot_config_id = :bot_config_id ORDER BY log_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':bot_config_id', $this->botConfigId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->logger->debug("Fetched recent AI interactions from DB.", ['count' => count($interactions)]);
            return array_map(function ($interaction) {
                $interaction['ai_decision_params'] = isset($interaction['ai_decision_params_json']) ? json_decode($interaction['ai_decision_params_json'], true) : null;
                $interaction['bot_feedback'] = isset($interaction['bot_feedback_json']) ? json_decode($interaction['bot_feedback_json'], true) : null;
                unset($interaction['ai_decision_params_json'], $interaction['bot_feedback_json']);
                return $interaction;
            }, $interactions);
        } catch (\PDOException $e) {
            $this->logger->error("Failed to fetch recent AI interactions from DB: " . $e->getMessage(), ['exception' => $e]);
            return [['error' => 'Failed to fetch AI interactions: ' . $e->getMessage()]];
        }
    }

    public function run(): void
    {
        $this->botStartTime = time();
        $this->logger->info('Starting AI Trading Bot initialization...');
        $this->updateBotStatus('initializing');

        // Use React\Promise\all to concurrently fetch initial data
        \React\Promise\all([
            'exchange_info' => $this->fetchExchangeInfo(), // Fetch exchange info first
            'initial_balance' => $this->getFuturesAccountBalance(),
            'initial_price' => $this->getLatestKlineClosePrice($this->tradingSymbol, $this->klineInterval),
            'initial_position' => $this->getPositionInformation($this->tradingSymbol),
            'listen_key' => $this->startUserDataStream(),
        ])->then(
            function ($results) {
                // Store fetched data
                $this->exchangeInfo = $results['exchange_info'];
                $initialBalance = $results['initial_balance'][$this->marginAsset] ?? ['availableBalance' => 0.0, 'balance' => 0.0];
                $this->lastClosedKlinePrice = (float)($results['initial_price']['price'] ?? 0);
                $this->currentPositionDetails = $this->formatPositionDetails($results['initial_position']);
                $this->listenKey = $results['listen_key']['listenKey'] ?? null;

                // Validate critical initial data
                if ($this->lastClosedKlinePrice <= 0) {
                    $this->logger->critical("Initialization failed: Failed to fetch a valid initial price for {$this->tradingSymbol}.");
                    throw new \RuntimeException("Failed to fetch a valid initial price for {$this->tradingSymbol}.");
                }
                if (!$this->listenKey) {
                    $this->logger->critical("Initialization failed: Failed to obtain a listenKey for User Data Stream.");
                    throw new \RuntimeException("Failed to obtain a listenKey for User Data Stream.");
                }

                $this->logger->info('Bot Initialization Success', [
                    'startup_balance' => $initialBalance,
                    'initial_market_price' => $this->lastClosedKlinePrice,
                    'initial_position' => $this->currentPositionDetails ?? 'No position',
                    'listen_key_obtained' => !empty($this->listenKey),
                ]);

                // Proceed with WebSocket connection and timer setup
                $this->connectWebSocket();
                $this->setupTimers();
                // Trigger initial AI update after a short delay
                $this->loop->addTimer(5, fn() => $this->triggerAIUpdate());
                $this->updateBotStatus('running');
            },
            function (\Throwable $e) {
                // Handle initialization failures
                $errorMessage = 'Bot Initialization failed: ' . $e->getMessage();
                $this->logger->critical($errorMessage, ['exception' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(),0,1000)]);
                $this->updateBotStatus('error', $errorMessage);
                $this->stop(); // Ensure bot stops on critical initialization failure
            }
        );
        $this->logger->info('Starting event loop...');
        $this->loop->run(); // This is the main blocking call for ReactPHP
        $this->logger->info('Event loop finished.');
        $this->updateBotStatus('stopped'); // Update status when loop finishes gracefully
    }

    private function stop(): void
    {
        $this->logger->info('Stopping event loop and resources...');
        if ($this->heartbeatTimer) {
             $this->loop->cancelTimer($this->heartbeatTimer);
             $this->heartbeatTimer = null;
        }
        if ($this->listenKeyRefreshTimer) {
            $this->loop->cancelTimer($this->listenKeyRefreshTimer);
            $this->listenKeyRefreshTimer = null;
        }
        if ($this->listenKey) {
            $this->closeUserDataStream($this->listenKey)->then(
                fn() => $this->logger->info("ListenKey closed successfully."),
                fn($e) => $this->logger->error("Failed to close ListenKey.", ['err' => $e->getMessage()])
            )->finally(fn() => $this->listenKey = null);
        }
        if ($this->wsConnection) {
             try { $this->wsConnection->close(); } catch (\Exception $e) { $this->logger->warning("Exception while closing WebSocket: " . $e->getMessage()); }
             $this->wsConnection = null;
        }
        $this->pdo = null;
        $this->loop->stop();
    }

    private function connectWebSocket(): void
    {
        if (!$this->listenKey) {
            $this->logger->error("Cannot connect WebSocket without a listenKey. Stopping.");
            $this->stop();
            return;
        }
        $klineStream = strtolower($this->tradingSymbol) . '@kline_' . $this->klineInterval;
        $wsUrl = $this->currentWsBaseUrlCombined . '/stream?streams=' . $klineStream . '/' . $this->listenKey;
        $this->logger->info('Connecting to Binance Futures WebSocket', ['url' => $wsUrl]);
        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');
                $conn->on('message', fn($msg) => $this->handleWsMessage((string)$msg));
                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception' => $e->getMessage()]);
                    $this->updateBotStatus('error', "WS Error: " . $e->getMessage());
                    $this->stop();
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->wsConnection = null;
                    $this->updateBotStatus('error', "WS Closed: Code {$code}");
                    $this->stop();
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception' => $e->getMessage()]);
                $this->updateBotStatus('error', "WS Connect Failed: " . $e->getMessage());
                $this->stop();
            }
        );
    }

    private function setupTimers(): void
    {
        $this->heartbeatTimer = $this->loop->addPeriodicTimer(self::BOT_HEARTBEAT_INTERVAL, function () {
            $positionDetailsJson = $this->currentPositionDetails ? json_encode($this->currentPositionDetails, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null;
            $this->updateBotStatus('running', null);
            if ($this->pdo) {
                 try {
                     $stmt = $this->pdo->prepare("UPDATE bot_runtime_status SET current_position_details_json = :json WHERE bot_config_id = :config_id");
                     $stmt->execute([':json' => $positionDetailsJson, ':config_id' => $this->botConfigId]);
                 } catch (\PDOException $e) {
                     $this->logger->error("Failed to update position details in heartbeat: " . $e->getMessage());
                 }
            }
        });
        $this->logger->info('Bot heartbeat timer started.', ['interval' => self::BOT_HEARTBEAT_INTERVAL]);

        $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder && $this->activeEntryOrderTimestamp !== null) {
                $secondsPassed = time() - $this->activeEntryOrderTimestamp;
                if ($secondsPassed > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out. Attempting cancellation.");
                    $this->isPlacingOrManagingOrder = true;
                    $orderIdToCancel = $this->activeEntryOrderId;
                    $this->activeEntryOrderId = null;
                    $this->activeEntryOrderTimestamp = null;

                    $this->cancelFuturesOrder($this->tradingSymbol, $orderIdToCancel)
                        ->then(
                            function ($cancellationData) use ($orderIdToCancel) {
                                $this->logger->info("Pending entry order {$orderIdToCancel} successfully cancelled due to timeout.");
                                $this->addOrderToLog($orderIdToCancel, 'CANCELED_TIMEOUT', $this->aiSuggestedSide, $this->tradingSymbol, $this->aiSuggestedEntryPrice, $this->aiSuggestedQuantity, $this->marginAsset, time(), 0.0);
                                $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order cancelled due to timeout."];
                            },
                            function (\Throwable $e) use ($orderIdToCancel) {
                                $this->logger->error("Failed to cancel timed-out pending entry order {$orderIdToCancel}.", ['exception' => $e->getMessage()]);
                                $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed cancellation for timed-out order."];
                            }
                        )->finally(fn() => $this->isPlacingOrManagingOrder = false);
                    return;
                }
            }
            if ($this->activeEntryOrderId && !$this->isPlacingOrManagingOrder) {
                $this->checkActiveOrderStatus($this->activeEntryOrderId, 'ENTRY');
            }
        });
        $this->logger->info('Fallback order check timer started', ['interval' => $this->orderCheckIntervalSeconds, 'timeout' => $this->pendingEntryOrderCancelTimeoutSeconds]);

        if ($this->maxScriptRuntimeSeconds > 0) {
            $this->loop->addTimer($this->maxScriptRuntimeSeconds, function () {
                $this->logger->warning('Maximum script runtime reached. Stopping.');
                $this->stop();
            });
            $this->logger->info('Max runtime timer started', ['limit' => $this->maxScriptRuntimeSeconds]);
        }

        $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, fn() => $this->triggerAIUpdate());
        $this->logger->info('AI parameter update timer started', ['interval' => $this->aiUpdateIntervalSeconds]);

        if ($this->listenKey) {
            $this->listenKeyRefreshTimer = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function () {
                if ($this->listenKey) {
                    $this->keepAliveUserDataStream($this->listenKey)->then(
                        fn() => $this->logger->info('ListenKey kept alive successfully.'),
                        fn($e) => $this->logger->error('Failed to keep ListenKey alive.', ['err' => $e->getMessage()])
                    );
                }
            });
             $this->logger->info('ListenKey refresh timer started.', ['interval' => self::LISTEN_KEY_REFRESH_INTERVAL]);
        }

        if ($this->takeProfitTargetUsdt > 0 && $this->profitCheckIntervalSeconds > 0) {
            $this->loop->addPeriodicTimer($this->profitCheckIntervalSeconds, fn() => $this->checkProfitTarget());
            $this->logger->info('Profit check timer started.', ['interval' => $this->profitCheckIntervalSeconds, 'target' => $this->takeProfitTargetUsdt]);
        }
    }

    private function handleWsMessage(string $msg): void
    {
        $decoded = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['stream'], $decoded['data'])) {
            return;
        }

        $streamName = $decoded['stream'];
        $data = $decoded['data'];

        if (str_ends_with($streamName, '@kline_' . $this->klineInterval)) {
            if (isset($data['e']) && $data['e'] === 'kline' && ($data['k']['x'] ?? false)) {
                $this->lastClosedKlinePrice = (float)$data['k']['c'];
                $this->logger->debug('Kline update received (closed)', ['price' => $this->lastClosedKlinePrice]);
            }
        } elseif ($streamName === $this->listenKey) {
            $this->handleUserDataStreamEvent($data);
        }
    }

    /**
     * START OF MODIFIED SECTION
     * This function has been significantly updated to use the logic from botoriginal.txt.
     * It now reliably captures P&L and commission from the ORDER_TRADE_UPDATE event.
     */
    private function handleUserDataStreamEvent(array $eventData): void
    {
        $eventType = $eventData['e'] ?? null;

        switch ($eventType) {
            case 'ACCOUNT_UPDATE':
                if (isset($eventData['a']['P'])) {
                    foreach($eventData['a']['P'] as $posData) {
                        if ($posData['s'] === $this->tradingSymbol) {
                            $newPositionDetails = $this->formatPositionDetailsFromEvent($posData);
                            $oldQty = (float)($this->currentPositionDetails['quantity'] ?? 0.0);
                            $newQty = (float)($newPositionDetails['quantity'] ?? 0.0);

                            if ($newQty != 0 && $oldQty == 0) {
                                $this->logger->info("Position opened/updated via ACCOUNT_UPDATE.", $newPositionDetails);
                            } elseif ($newQty == 0 && $oldQty != 0) {
                                $this->logger->info("Position for {$this->tradingSymbol} closed via ACCOUNT_UPDATE. Triggering post-closure logic.");
                                $this->handlePositionClosed(); // Use the robust version
                            }
                            $this->currentPositionDetails = $newPositionDetails;
                        }
                    }
                }
                break;

            case 'ORDER_TRADE_UPDATE':
                $order = $eventData['o'];
                if ($order['s'] !== $this->tradingSymbol) return;

                $orderId = (string)$order['i'];
                $orderStatus = $order['X'];
                $executionType = $order['x'];

                // The key change: Extract commission and P&L directly from the event!
                $commission = (float)($order['n'] ?? 0);
                $commissionAsset = $order['N'] ?? null;
                $realizedPnl = (float)($order['rp'] ?? 0.0);
                $isReduceOnly = (bool)($order['R'] ?? false); // Extract the 'R' field

                $this->getUsdtEquivalent((string)$commissionAsset, $commission)->then(function ($commissionUsdt) use ($order, $orderId, $orderStatus, $executionType, $realizedPnl, $isReduceOnly) {
                    // --- Handling Active Entry Order ---
                    if ($orderId === $this->activeEntryOrderId) {
                        if ($orderStatus === 'FILLED' || ($orderStatus === 'PARTIALLY_FILLED' && $executionType === 'TRADE')) {
                            $this->logger->info("Entry order {$orderStatus}: {$this->activeEntryOrderId}.");
                            // Log the trade with its commission. P&L on entry is usually 0.
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['L'], (float)$order['l'], $this->marginAsset, time(), $realizedPnl, $commissionUsdt, $isReduceOnly);

                            if ($orderStatus === 'FILLED') {
                                $this->activeEntryOrderId = null;
                                $this->activeEntryOrderTimestamp = null;
                                $this->isMissingProtectiveOrder = false;
                                $this->placeSlAndTpOrders();
                            }
                        } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                            $this->logger->warning("Entry order {$this->activeEntryOrderId} ended without fill: {$orderStatus}.");
                            $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['p'], (float)$order['q'], $this->marginAsset, time(), $realizedPnl, $commissionUsdt, $isReduceOnly);
                            $this->resetTradeState();
                            $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Entry order failed: {$orderStatus}."];
                        }
                    }
                    // --- Handling Active SL/TP Orders (The CRITICAL FIX) ---
                    elseif ($orderId === $this->activeSlOrderId || $orderId === $this->activeTpOrderId) {
                        if ($orderStatus === 'FILLED' || ($executionType === 'TRADE' && $orderStatus === 'PARTIALLY_FILLED')) {
                            $isSlFill = ($orderId === $this->activeSlOrderId);
                            $this->logger->info("Protective order " . ($isSlFill ? "SL" : "TP") . " {$orderId} has a fill event. Capturing P&L and Commission.");
                            
                            // This is the most important log call. It uses the PNL and commission from the event.
                            $this->addOrderToLog(
                                $orderId,
                                $orderStatus,
                                $order['S'],
                                $this->tradingSymbol,
                                (float)$order['L'],         // Last Filled Price
                                (float)$order['l'],         // Last Filled Quantity
                                $this->marginAsset,
                                time(),
                                $realizedPnl,               // ** THE REALIZED PNL **
                                $commissionUsdt,            // ** THE COMMISSION **
                                $isReduceOnly               // ** IS REDUCE ONLY **
                            );
                            
                            if ($orderStatus === 'FILLED') {
                                $otherOrderId = $isSlFill ? $this->activeTpOrderId : $this->activeSlOrderId;
                                if ($otherOrderId) $this->cancelOrderAndLog($otherOrderId, "remaining protective order");
                                $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position closed by " . ($isSlFill ? "SL" : "TP") . "."];
                                // The handlePositionClosed will be triggered by the ACCOUNT_UPDATE event, but the primary log is already done.
                            }
                        } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                            $this->logger->warning("SL/TP order {$orderId} ended without fill: {$orderStatus}.");
                            if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                            if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                            if ($this->currentPositionDetails && !$this->activeSlOrderId && !$this->activeTpOrderId) {
                                $this->logger->critical("Position open but BOTH SL/TP orders are gone. Flagging critical state.");
                                $this->isMissingProtectiveOrder = true;
                                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Position unprotected."];
                                $this->triggerAIUpdate(true);
                            }
                        }
                    }
                    // --- Handling other orders like manual or AI-driven market close ---
                    elseif ($order['ot'] === 'MARKET' && ($order['R'] ?? false)) {
                        if ($orderStatus === 'FILLED' || ($executionType === 'TRADE' && $orderStatus === 'PARTIALLY_FILLED')) {
                            $this->logger->info("Reduce-Only Market Order filled. Capturing P&L and Commission.");
                             $this->addOrderToLog($orderId, $orderStatus, $order['S'], $this->tradingSymbol, (float)$order['L'], (float)$order['l'], $this->marginAsset, time(), $realizedPnl, $commissionUsdt, $isReduceOnly);
                        }
                    }
                })->otherwise(fn($e) => $this->logger->error("Failed to process commission for order {$orderId}: " . $e->getMessage()));
                break;

            case 'listenKeyExpired':
                $this->logger->warning("ListenKey expired. Reconnecting.");
                $this->listenKey = null;
                if ($this->wsConnection) { $this->wsConnection->close(); }
                if ($this->listenKeyRefreshTimer) { $this->loop->cancelTimer($this->listenKeyRefreshTimer); }
                $this->startUserDataStream()->then(function ($data) {
                    $this->listenKey = $data['listenKey'] ?? null;
                    if ($this->listenKey) {
                        $this->connectWebSocket();
                        $this->listenKeyRefreshTimer = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function() { /* Re-create timer logic here */ });
                    } else {
                        $this->logger->error("Failed to get new ListenKey. Stopping."); $this->stop();
                    }
                })->otherwise(fn() => $this->stop());
                break;

            case 'MARGIN_CALL':
                $this->logger->critical("MARGIN CALL RECEIVED!", $eventData);
                $this->isMissingProtectiveOrder = true;
                $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "MARGIN CALL!"];
                $this->triggerAIUpdate(true);
                break;
        }
    }
    /**
     * END OF MODIFIED SECTION
     */

    private function formatPositionDetailsFromEvent(?array $posData): ?array {
        if (empty($posData) || $posData['s'] !== $this->tradingSymbol) return null;
        $quantityVal = (float)($posData['pa'] ?? 0);
        if (abs($quantityVal) < 1e-9) return null;

        return [
            'symbol' => $this->tradingSymbol, 'side' => $quantityVal > 0 ? 'LONG' : 'SHORT',
            'entryPrice' => (float)($posData['ep'] ?? 0), 'quantity' => abs($quantityVal),
            'leverage' => (int)($this->currentPositionDetails['leverage'] ?? $this->defaultLeverage),
            'markPrice' => (float)($posData['mp'] ?? $this->lastClosedKlinePrice),
            'unrealizedPnl' => (float)($posData['up'] ?? 0),
            'initialMargin' => (float)($posData['iw'] ?? 0),
            'maintMargin' => (float)($posData['mm'] ?? 0),
            'positionSideBinance' => $posData['ps'] ?? 'BOTH',
            'aiSuggestedSlPrice' => $this->aiSuggestedSlPrice ?? null, 'aiSuggestedTpPrice' => $this->aiSuggestedTpPrice ?? null,
            'activeSlOrderId' => $this->activeSlOrderId, 'activeTpOrderId' => $this->activeTpOrderId,
        ];
    }

    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) return null;
        $positionData = null;
        if (!isset($positionsInput[0]) && isset($positionsInput['symbol'])) { // Single object
            if ($positionsInput['symbol'] === $this->tradingSymbol) $positionData = $positionsInput;
        } else { // Array
            foreach ($positionsInput as $p) {
                if (($p['symbol'] ?? '') === $this->tradingSymbol && abs((float)($p['positionAmt'] ?? 0)) > 1e-9) {
                    $positionData = $p; break;
                }
            }
        }
        if (!$positionData) return null;

        $quantityVal = (float)($positionData['positionAmt'] ?? 0);
        if (abs($quantityVal) < 1e-9) return null;

        return [
            'symbol' => $this->tradingSymbol, 'side' => $quantityVal > 0 ? 'LONG' : 'SHORT',
            'entryPrice' => (float)($positionData['entryPrice'] ?? 0), 'quantity' => abs($quantityVal),
            'leverage' => (int)($positionData['leverage'] ?? $this->defaultLeverage),
            'markPrice' => (float)($positionData['markPrice'] ?? $this->lastClosedKlinePrice),
            'unrealizedPnl' => (float)($positionData['unRealizedProfit'] ?? 0),
            'initialMargin' => (float)($positionData['initialMargin'] ?? $positionData['isolatedMargin'] ?? 0),
            'maintMargin' => (float)($positionData['maintMargin'] ?? 0),
            'isolatedWallet' => (float)($positionData['isolatedWallet'] ?? 0),
            'positionSideBinance' => $positionData['positionSide'] ?? 'BOTH',
            'aiSuggestedSlPrice' => $this->aiSuggestedSlPrice ?? null, 'aiSuggestedTpPrice' => $this->aiSuggestedTpPrice ?? null,
            'activeSlOrderId' => $this->activeSlOrderId, 'activeTpOrderId' => $this->activeTpOrderId,
        ];
    }

    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails || $this->isPlacingOrManagingOrder) {
            return;
        }
        $this->isPlacingOrManagingOrder = true;
        $this->isMissingProtectiveOrder = false;

        $positionSide = $this->currentPositionDetails['side'];
        $quantity = (float)$this->currentPositionDetails['quantity'];
        $orderSideForSlTp = ($positionSide === 'LONG') ? 'SELL' : 'BUY';

        if ($this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0 || $quantity <= 0) {
             $this->logger->critical("Invalid parameters for SL/TP placement.", ['sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice, 'qty' => $quantity]);
             $this->isMissingProtectiveOrder = true; $this->isPlacingOrManagingOrder = false;
             $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Invalid SL/TP from AI."];
             return;
        }

        $slOrderPromise = $this->placeFuturesStopMarketOrder($this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedSlPrice, true)
            ->then(function ($orderData) {
                $this->activeSlOrderId = (string)$orderData['orderId'];
                $this->logger->info("Stop Loss order placement sent.", ['id' => $this->activeSlOrderId, 'price' => $this->aiSuggestedSlPrice]);
                return $orderData;
            })->otherwise(function (\Throwable $e) {
                $this->logger->error("Failed to place Stop Loss order.", ['error' => $e->getMessage()]);
                $this->isMissingProtectiveOrder = true; throw $e;
            });

        $tpOrderPromise = $this->placeFuturesTakeProfitMarketOrder($this->tradingSymbol, $orderSideForSlTp, $quantity, $this->aiSuggestedTpPrice, true)
            ->then(function ($orderData) {
                $this->activeTpOrderId = (string)$orderData['orderId'];
                $this->logger->info("Take Profit order placement sent.", ['id' => $this->activeTpOrderId, 'price' => $this->aiSuggestedTpPrice]);
                return $orderData;
             })->otherwise(function (\Throwable $e) {
                $this->logger->error("Failed to place Take Profit order.", ['error' => $e->getMessage()]);
                $this->isMissingProtectiveOrder = true; throw $e;
             });

        \React\Promise\all([$slOrderPromise, $tpOrderPromise])
            ->then(
                function () {
                    $this->logger->info("SL and TP order placement requests processed by API.");
                    if ($this->isMissingProtectiveOrder) {
                         $this->logger->critical("At least one SL/TP order failed placement!");
                         $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "SL/TP placement failed."];
                    }
                    $this->isPlacingOrManagingOrder = false;
                },
                function (\Throwable $e) {
                    $this->logger->critical("Error during SL/TP order placement sequence.", ['exception' => $e->getMessage()]);
                    $this->isMissingProtectiveOrder = true; $this->isPlacingOrManagingOrder = false;
                    $this->lastAIDecisionResult = ['status' => 'CRITICAL', 'message' => "Failed placing SL/TP orders."];
                }
            );
    }
    
    /**
     * START OF MODIFIED SECTION
     * This function is now a robust fallback. Its main purpose is to find P&L for manual
     * trades or if the WebSocket event was somehow missed.
     */
    private function handlePositionClosed(): void
    {
        $closedPositionDetails = $this->currentPositionDetails;
        $this->logger->info("Position closure cleanup sequence started for {$this->tradingSymbol}.", [
            'details_at_closure' => $closedPositionDetails
        ]);

        if ($closedPositionDetails) {
            // This API call now serves as a fallback to confirm P&L, especially for manual trades.
            $this->getFuturesTradeHistory($this->tradingSymbol, 20)
                ->then(function ($tradeHistory) use ($closedPositionDetails) {
                    // This logic is for finding a closing trade that was NOT logged by the primary WS handler.
                    // This is less critical now but good for manual trade reconciliation.
                    $closingTrade = null;
                    $quantityClosed = $closedPositionDetails['quantity'] ?? 0;
                    $closeSide = $closedPositionDetails['side'] === 'LONG' ? 'SELL' : 'BUY';

                    foreach ($tradeHistory as $trade) {
                        // A simple check for a recent reduce-only trade that matches the closed quantity.
                        if (($trade['reduceOnly'] ?? false) && abs((float)$trade['qty'] - $quantityClosed) < 1e-9 && $trade['side'] === $closeSide) {
                             $closingTrade = $trade;
                             break;
                        }
                    }

                    if ($closingTrade) {
                        $this->logger->info("Fallback Check: Found a potential closing trade in history. Verifying if already logged.", ['trade' => $closingTrade]);
                        // You could add logic here to check if an order with this ID and PNL was already logged to prevent duplicates.
                        // For now, we assume the primary WS handler is the source of truth and this is just for logging/debugging.
                    } else {
                        $this->logger->warning("Fallback Check: Could not find an exact closing trade in recent history. The primary WS handler should have logged the P&L.");
                    }
                })
                ->otherwise(function (\Throwable $e) {
                    $this->logger->error("Fallback Check: Failed to get trade history for post-closure analysis: " . $e->getMessage());
                });
        }
        
        // This part remains crucial: clean up any dangling orders and reset state.
        $cancelPromises = [];
        if ($this->activeSlOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "active SL on position close cleanup");
        if ($this->activeTpOrderId) $cancelPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "active TP on position close cleanup");
        
        $this->resetTradeState();

        if (!empty($cancelPromises)) {
            \React\Promise\all($cancelPromises)->finally(fn() => $this->loop->addTimer(2, fn() => $this->triggerAIUpdate()));
        } else {
            $this->loop->addTimer(2, fn() => $this->triggerAIUpdate());
        }
    }
    /**
     * END OF MODIFIED SECTION
     */

    private function cancelOrderAndLog(string $orderId, string $reasonForCancel): PromiseInterface {
        $deferred = new Deferred();
        $this->cancelFuturesOrder($this->tradingSymbol, $orderId)->then(
            function($data) use ($orderId, $reasonForCancel, $deferred) {
                $this->logger->info("Successfully cancelled order: {$orderId} ({$reasonForCancel}).");
                if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                $deferred->resolve($data);
            },
            function (\Throwable $e) use ($orderId, $reasonForCancel, $deferred) {
                 if (str_contains($e->getMessage(), '-2011')) {
                     $this->logger->info("Attempt to cancel order {$orderId} ({$reasonForCancel}) failed, likely already gone.");
                 } else {
                     $this->logger->error("Failed to cancel order: {$orderId} ({$reasonForCancel}).", ['err' => $e->getMessage()]);
                 }
                if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
                $deferred->resolve(['status' => 'ALREADY_RESOLVED_OR_ERROR']);
            }
        );
        return $deferred->promise();
    }

    private function resetTradeState(): void {
        $this->logger->info("Resetting trade state.");
        $this->activeEntryOrderId = null; $this->activeEntryOrderTimestamp = null;
        $this->activeSlOrderId = null; $this->activeTpOrderId = null;
        $this->currentPositionDetails = null; $this->isPlacingOrManagingOrder = false;
        $this->isMissingProtectiveOrder = false;
    }

    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt = 0.0, bool $reduceOnly = false): void
    {
        $logEntry = compact('orderId', 'status', 'side', 'assetPair', 'price', 'quantity', 'marginAsset', 'timestamp', 'realizedPnl', 'commissionUsdt', 'reduceOnly');
        $this->logger->info('Trade/Order outcome logged:', $logEntry);
        $this->logOrderToDb($orderId, $status, $side, $assetPair, $price, $quantity, $marginAsset, $timestamp, $realizedPnl, $commissionUsdt, $reduceOnly);
    }

    private function attemptOpenPosition(): void
    {
        if ($this->currentPositionDetails || $this->activeEntryOrderId || $this->isPlacingOrManagingOrder) {
            $this->logger->info('Skipping position opening: Precondition not met.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped OPEN_POSITION: Pre-condition not met.'];
            return;
        }

        if ($this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedQuantity <= 0 || !in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) {
            $this->logger->error("CRITICAL: AttemptOpenPosition called with invalid AI parameters.", ['params' => get_object_vars($this)]);
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => 'Internal Error: OPEN_POSITION rejected due to invalid parameters.'];
            return;
        }

        $this->isPlacingOrManagingOrder = true;
        $aiParamsForLog = ['side' => $this->aiSuggestedSide, 'quantity' => $this->aiSuggestedQuantity, 'entry' => $this->aiSuggestedEntryPrice, 'sl' => $this->aiSuggestedSlPrice, 'tp' => $this->aiSuggestedTpPrice, 'leverage' => $this->aiSuggestedLeverage];
        $this->logger->info('Attempting to open new position based on AI.', $aiParamsForLog);

        $this->setLeverage($this->tradingSymbol, $this->aiSuggestedLeverage)
            ->then(fn() => $this->placeFuturesLimitOrder($this->tradingSymbol, $this->aiSuggestedSide, $this->aiSuggestedQuantity, $this->aiSuggestedEntryPrice))
            ->then(function ($orderData) use ($aiParamsForLog) {
                $this->activeEntryOrderId = (string)$orderData['orderId'];
                $this->activeEntryOrderTimestamp = time();
                $this->logger->info("Entry limit order placed successfully. Waiting for fill.", ['orderId' => $this->activeEntryOrderId]);
                $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed entry order {$this->activeEntryOrderId}.", 'decision_executed' => $aiParamsForLog];
                $this->isPlacingOrManagingOrder = false;
            })
            ->catch(function (\Throwable $e) use ($aiParamsForLog) {
                $this->logger->error('Failed to open position.', ['exception' => $e->getMessage(), 'ai_params' => $aiParamsForLog]);
                $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Failed to place entry order: " . $e->getMessage()];
                $this->resetTradeState();
                $this->isPlacingOrManagingOrder = false;
            });
    }

    private function attemptClosePositionByAI(): void
    {
        if (!$this->currentPositionDetails || $this->isPlacingOrManagingOrder){
             $this->logger->info('Skipping AI close: No position exists or operation in progress.');
             $this->lastAIDecisionResult = ['status' => 'WARN', 'message' => 'Skipped CLOSE_POSITION.'];
             return;
        }

        $this->isPlacingOrManagingOrder = true;
        $positionToClose = $this->currentPositionDetails;
        $this->logger->info("AI requests to close current position at market.", ['position' => $positionToClose]);

        $cancellationPromises = [];
        if ($this->activeSlOrderId) $cancellationPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "SL for AI market close");
        if ($this->activeTpOrderId) $cancellationPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "TP for AI market close");

        \React\Promise\all($cancellationPromises)->finally(function() use ($positionToClose) {
            return $this->getPositionInformation($this->tradingSymbol)
                ->then(function($refreshedPositionData){
                    $this->currentPositionDetails = $this->formatPositionDetails($refreshedPositionData);
                    return $this->currentPositionDetails;
                })->then(function($currentPosAfterCancel) use ($positionToClose) {
                    if ($currentPosAfterCancel === null) {
                        $this->logger->info("Position already closed before AI market order could be placed.");
                        $this->isPlacingOrManagingOrder = false;
                        $this->lastAIDecisionResult = ['status' => 'INFO', 'message' => "Position found already closed."];
                        return \React\Promise\resolve(['status' => 'ALREADY_CLOSED']);
                    }
                    $closeSide = $currentPosAfterCancel['side'] === 'LONG' ? 'SELL' : 'BUY';
                    $quantityToClose = $currentPosAfterCancel['quantity'];
                    return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true);
                });
        })->then(function($closeOrderData) {
            if (isset($closeOrderData['orderId'])) {
                $this->logger->info("Market order placed by AI to close position.", ['orderId' => $closeOrderData['orderId']]);
                $this->lastAIDecisionResult = ['status' => 'OK', 'message' => "Placed market close order {$closeOrderData['orderId']}."];
            }
            $this->isPlacingOrManagingOrder = false;
        })->catch(function(\Throwable $e) {
            $this->logger->error("Error during AI-driven position close process.", ['exception' => $e->getMessage()]);
            $this->isMissingProtectiveOrder = true;
            $this->lastAIDecisionResult = ['status' => 'ERROR', 'message' => "Error during AI close: " . $e->getMessage()];
            $this->isPlacingOrManagingOrder = false;
        });
    }

    private function checkProfitTarget(): void {
        if ($this->takeProfitTargetUsdt <= 0 || $this->isPlacingOrManagingOrder || !$this->currentPositionDetails) return;

        $currentPnl = (float)($this->currentPositionDetails['unrealizedPnl'] ?? 0.0);
        if ($currentPnl >= $this->takeProfitTargetUsdt) {
            $this->logger->info("Profit target reached!", ['target' => $this->takeProfitTargetUsdt, 'current_pnl' => $currentPnl]);
            $this->triggerProfitTakingClose();
        }
    }

    private function triggerProfitTakingClose(): void {
        if ($this->isPlacingOrManagingOrder || !$this->currentPositionDetails) return;

        $this->isPlacingOrManagingOrder = true;
        $this->logger->info("Initiating market close for profit target.");

        $cancellationPromises = [];
        if ($this->activeSlOrderId) $cancellationPromises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "SL for Profit Target Close");
        if ($this->activeTpOrderId) $cancellationPromises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "TP for Profit Target Close");

        \React\Promise\all($cancellationPromises)
            ->then(fn() => $this->getPositionInformation($this->tradingSymbol))
            ->then(function($refreshedPositionData) {
                $currentPosAfterCancel = $this->formatPositionDetails($refreshedPositionData);
                if ($currentPosAfterCancel === null) {
                    return \React\Promise\resolve(['status' => 'ALREADY_CLOSED']);
                }
                $closeSide = $currentPosAfterCancel['side'] === 'LONG' ? 'SELL' : 'BUY';
                $quantityToClose = $currentPosAfterCancel['quantity'];
                return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $quantityToClose, true);
            })
            ->then(function($closeOrderData) {
                if (isset($closeOrderData['orderId'])) {
                    $this->logger->info("Market order placed for Profit Target.", ['orderId' => $closeOrderData['orderId']]);
                }
            })
            ->catch(fn($e) => $this->logger->error("Error during profit-taking close.", ['exception' => $e->getMessage()]))
            ->finally(fn() => $this->isPlacingOrManagingOrder = false);
    }

    private function createSignedRequestData(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $params['timestamp'] = round(microtime(true) * 1000);
        $params['recvWindow'] = self::BINANCE_API_RECV_WINDOW;
        ksort($params);
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $queryString, $this->binanceApiSecret);
        $url = $this->currentRestApiBaseUrl . $endpoint;
        $body = null;

        if ($method === 'GET' || $method === 'DELETE') {
            $url .= '?' . $queryString . '&signature=' . $signature;
        } else {
            $body = $queryString . '&signature=' . $signature;
        }
        return ['url' => $url, 'headers' => ['X-MBX-APIKEY' => $this->binanceApiKey], 'postData' => $body];
    }

    private function getUsdtEquivalent(string $asset, float $amount): PromiseInterface
    {
        if (strtoupper($asset) === 'USDT' || empty($asset)) return \React\Promise\resolve($amount);
        $symbol = strtoupper($asset) . 'USDT';
        return $this->getLatestKlineClosePrice($symbol, '1m')
            ->then(function ($klineData) use ($amount) {
                $price = (float)($klineData['price'] ?? 0);
                return $price > 0 ? $amount * $price : 0.0;
            })
            ->otherwise(fn() => 0.0);
    }
    
    private function makeAsyncApiRequest(string $method, string $url, array $headers = [], ?string $body = null, bool $isPublic = false): PromiseInterface
    {
        $finalHeaders = $headers;
        if (!$isPublic && !isset($finalHeaders['X-MBX-APIKEY'])) {
            $finalHeaders['X-MBX-APIKEY'] = $this->binanceApiKey;
        }
        if (in_array($method, ['POST', 'PUT', 'DELETE']) && is_string($body)) {
            $finalHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return $this->browser->request($method, $url, $finalHeaders, $body ?? '')->then(
            function (ResponseInterface $response) use ($url) {
                $body = (string)$response->getBody();
                $statusCode = $response->getStatusCode();
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("JSON decode error: " . json_last_error_msg());
                }
                if (isset($data['code']) && (int)$data['code'] < 0 && (int)$data['code'] !== -2011) {
                    throw new \RuntimeException("Binance API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error'), (int)$data['code']);
                }
                if ($statusCode >= 300) {
                     throw new \RuntimeException("HTTP error {$statusCode} for " . $url, $statusCode);
                }
                return $data;
            },
            function (\Throwable $e) use ($method, $url) {
                $logCtx = ['method' => $method, 'url_path' => parse_url($url, PHP_URL_PATH), 'err_type' => get_class($e), 'err_msg' => $e->getMessage()];
                $binanceCode = 0;
                $runtimeMsg = "API Request failure";

                if ($e instanceof \React\Http\Message\ResponseException) {
                    $response = $e->getResponse();
                    $logCtx['response_status_code'] = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                    $logCtx['response_body_preview'] = substr($responseBody, 0, 500);
                    $responseData = json_decode($responseBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($responseData['code'], $responseData['msg'])) {
                        $logCtx['binance_api_code_from_exception'] = $responseData['code'];
                        $logCtx['binance_api_msg_from_exception'] = $responseData['msg'];
                        $binanceCode = (int)$responseData['code'];
                        $runtimeMsg = "Binance API error via HTTP Exception ({$binanceCode}): {$responseData['msg']}";
                    } else {
                        $runtimeMsg = "HTTP error {$response->getStatusCode()} with unparseable/non-Binance-error body.";
                    }
                } else {
                    $runtimeMsg .= " (Network/Client Error)";
                }
                $this->logger->error($runtimeMsg, $logCtx);
                throw new \RuntimeException($runtimeMsg . " for {$method} " . parse_url($url, PHP_URL_PATH), $binanceCode, $e);
            }
        );
    }


    private function formatPriceByTickSize(string $symbol, float $price): string {
        $symbolInfo = $this->exchangeInfo[strtoupper($symbol)] ?? null;
        if (!$symbolInfo || !isset($symbolInfo['tickSize'])) {
            $this->logger->warning("Tick size not found for {$symbol}. Using default precision.");
            return sprintf('%.8f', $price); // Fallback to a high precision
        }
        $tickSize = (float)$symbolInfo['tickSize'];
        if ($tickSize <= 0) {
            $this->logger->warning("Invalid tick size for {$symbol}. Using default precision.");
            return sprintf('%.8f', $price);
        }
        $roundedPrice = round($price / $tickSize) * $tickSize;
        // Determine the number of decimal places from tickSize
        $decimals = max(0, strlen(explode('.', (string)$tickSize)[1] ?? ''));
        return number_format($roundedPrice, $decimals, '.', '');
    }

    private function formatQuantityByStepSize(string $symbol, float $quantity): string {
        $symbolInfo = $this->exchangeInfo[strtoupper($symbol)] ?? null;
        if (!$symbolInfo || !isset($symbolInfo['stepSize'])) {
            $this->logger->warning("Step size not found for {$symbol}. Using default precision.");
            return sprintf('%.8f', $quantity); // Fallback to a high precision
        }
        $stepSize = (float)$symbolInfo['stepSize'];
        if ($stepSize <= 0) {
            $this->logger->warning("Invalid step size for {$symbol}. Using default precision.");
            return sprintf('%.8f', $quantity);
        }
        $roundedQuantity = round($quantity / $stepSize) * $stepSize;
        // Determine the number of decimal places from stepSize
        $decimals = max(0, strlen(explode('.', (string)$stepSize)[1] ?? ''));
        return number_format($roundedQuantity, $decimals, '.', '');
    }

    private function fetchExchangeInfo(): PromiseInterface {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/exchangeInfo';
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) {
                $exchangeInfo = [];
                foreach ($data['symbols'] as $symbolInfo) {
                    $symbol = $symbolInfo['symbol'];
                    $exchangeInfo[$symbol] = [
                        'pricePrecision' => (int)$symbolInfo['pricePrecision'],
                        'quantityPrecision' => (int)$symbolInfo['quantityPrecision'],
                        'tickSize' => '0.0',
                        'stepSize' => '0.0',
                    ];
                    foreach ($symbolInfo['filters'] as $filter) {
                        if ($filter['filterType'] === 'PRICE_FILTER') {
                            $exchangeInfo[$symbol]['tickSize'] = $filter['tickSize'];
                        } elseif ($filter['filterType'] === 'LOT_SIZE') {
                            $exchangeInfo[$symbol]['stepSize'] = $filter['stepSize'];
                        }
                    }
                }
                $this->logger->info("Exchange information fetched and cached for " . count($exchangeInfo) . " symbols.");
                return $exchangeInfo;
            });
    }

    private function getFuturesAccountBalance(): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v2/balance', [], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                $balances = [];
                foreach ($data as $assetInfo) {
                    $balances[strtoupper($assetInfo['asset'])] = [
                        'balance' => (float)$assetInfo['balance'],
                        'availableBalance' => (float)$assetInfo['availableBalance']
                    ];
                }
                return $balances;
            });
    }

    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/klines?' . http_build_query(['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
             ->then(function ($data) {
                if (!isset($data[0][4])) throw new \RuntimeException("Invalid klines response format.");
                return ['price' => (float)$data[0][4], 'timestamp' => (int)$data[0][0]];
            });
    }

    private function getHistoricalKlines(string $symbol, string $interval, int $limit = 100): PromiseInterface {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/klines?' . http_build_query(['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => $limit]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) {
                return array_map(fn($k) => ['openTime'=>(int)$k[0],'open'=>(string)$k[1],'high'=>(string)$k[2],'low'=>(string)$k[3],'close'=>(string)$k[4],'volume'=>(string)$k[5]], $data);
            });
    }

    private function getPositionInformation(string $symbol): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v2/positionRisk', ['symbol' => strtoupper($symbol)], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                 foreach ($data as $pos) if (isset($pos['symbol']) && $pos['symbol'] === strtoupper($this->tradingSymbol) && abs((float)$pos['positionAmt']) > 1e-9) return $pos;
                 return null;
            });
    }

    private function setLeverage(string $symbol, int $leverage): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/leverage', ['symbol' => strtoupper($symbol), 'leverage' => $leverage], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function getFuturesCommissionRate(string $symbol): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/commissionRate', ['symbol' => strtoupper($symbol)], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    private function placeFuturesLimitOrder(
        string $symbol, string $side, float $quantity, float $price,
        ?string $timeInForce = 'GTC', ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($price <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid price/quantity for limit order. P:{$price} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'LIMIT',
            'quantity' => $this->formatQuantityByStepSize($symbol, $quantity),
            'price' => $this->formatPriceByTickSize($symbol, $price),
            'timeInForce' => $timeInForce,
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $this->logger->debug("Placing Limit Order", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesMarketOrder(
        string $symbol, string $side, float $quantity,
        ?bool $reduceOnly = false, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid quantity for market order. Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'MARKET',
            'quantity' => $this->formatQuantityByStepSize($symbol, $quantity),
        ];
        if ($reduceOnly) $params['reduceOnly'] = 'true';
        $this->logger->debug("Placing Market Order", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesStopMarketOrder(
        string $symbol, string $side, float $quantity, float $stopPrice,
        bool $reduceOnly = true, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for STOP_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'STOP_MARKET',
            'quantity' => $this->formatQuantityByStepSize($symbol, $quantity),
            'stopPrice' => $this->formatPriceByTickSize($symbol, $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false',
            'workingType' => 'MARK_PRICE'
        ];
        $this->logger->debug("Placing Stop Market Order (SL)", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function placeFuturesTakeProfitMarketOrder(
        string $symbol, string $side, float $quantity, float $stopPrice,
        bool $reduceOnly = true, ?string $positionSide = 'BOTH'
    ): PromiseInterface {
        $endpoint = '/fapi/v1/order';
        if ($stopPrice <= 0 || $quantity <= 0) return \React\Promise\reject(new \InvalidArgumentException("Invalid stopPrice/quantity for TAKE_PROFIT_MARKET. SP:{$stopPrice} Q:{$quantity} for {$symbol}"));
        $params = [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
            'positionSide' => strtoupper($positionSide),
            'type' => 'TAKE_PROFIT_MARKET',
            'quantity' => $this->formatQuantityByStepSize($symbol, $quantity),
            'stopPrice' => $this->formatPriceByTickSize($symbol, $stopPrice),
            'reduceOnly' => $reduceOnly ? 'true' : 'false',
            'workingType' => 'MARK_PRICE'
        ];
        $this->logger->debug("Placing Take Profit Market Order (TP)", $params);
        $signedRequestData = $this->createSignedRequestData($endpoint, $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function getFuturesOrderStatus(string $symbol, string $orderId): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', ['symbol' => strtoupper($symbol), 'orderId' => $orderId], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    private function checkActiveOrderStatus(string $orderId, string $orderTypeLabel): void {
        if ($this->isPlacingOrManagingOrder) return;
        $this->getFuturesOrderStatus($this->tradingSymbol, $orderId)
        ->then(function (array $orderStatusData) use ($orderId, $orderTypeLabel) {
             $status = $orderStatusData['status'] ?? 'UNKNOWN';
             if ($orderTypeLabel === 'ENTRY' && $this->activeEntryOrderId === $orderId) {
                 if (in_array($status, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                     $this->logger->warning("Fallback check found entry order {$orderId} as {$status}. Resetting state.");
                     $this->addOrderToLog($orderId, $status.'_FALLBACK', $orderStatusData['side'], $this->tradingSymbol, (float)$orderStatusData['price'], (float)$orderStatusData['origQty'], $this->marginAsset, time(), (float)$orderStatusData['realizedPnl']);
                     $this->resetTradeState();
                 } elseif ($status === 'FILLED') {
                      $this->logger->critical("CRITICAL FALLBACK: Entry order {$orderId} found FILLED. WS missed this! Attempting recovery.");
                      $this->currentPositionDetails = ['symbol'=>$this->tradingSymbol, 'side'=>$orderStatusData['side']==='BUY'?'LONG':'SHORT', 'entryPrice'=>(float)$orderStatusData['avgPrice'], 'quantity'=>(float)$orderStatusData['executedQty'], 'leverage'=>$this->aiSuggestedLeverage];
                      $this->addOrderToLog($orderId, $status.'_FALLBACK', $orderStatusData['side'], $this->tradingSymbol, (float)$orderStatusData['avgPrice'], (float)$orderStatusData['executedQty'], $this->marginAsset, time(), (float)$orderStatusData['realizedPnl']);
                      $this->activeEntryOrderId = null; $this->activeEntryOrderTimestamp = null;
                      $this->placeSlAndTpOrders();
                 }
             }
        })
        ->catch(function (\Throwable $e) use ($orderId) {
             if (str_contains($e->getMessage(), '-2013') || str_contains($e->getMessage(), '-2011')) {
                 if ($this->activeEntryOrderId === $orderId) {
                    $this->logger->warning("Active entry order {$orderId} disappeared. Resetting state.");
                    $this->resetTradeState();
                 }
                 if ($orderId === $this->activeSlOrderId) $this->activeSlOrderId = null;
                 if ($orderId === $this->activeTpOrderId) $this->activeTpOrderId = null;
            } else {
                $this->logger->error("Failed to get order status (fallback check).", ['orderId' => $orderId, 'exception' => $e->getMessage()]);
            }
        });
    }

    private function cancelFuturesOrder(string $symbol, string $orderId): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', ['symbol' => strtoupper($symbol), 'orderId' => $orderId], 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function getFuturesTradeHistory(string $symbol, int $limit = 10): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/userTrades', ['symbol' => strtoupper($symbol), 'limit' => $limit], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    private function startUserDataStream(): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', [], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function keepAliveUserDataStream(string $listenKey): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', ['listenKey' => $listenKey], 'PUT');
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    private function closeUserDataStream(string $listenKey): PromiseInterface {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', ['listenKey' => $listenKey], 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }
    
    private function collectDataForAI(bool $isEmergency = false): PromiseInterface
    {
        $promises = [
            'balance' => $this->getFuturesAccountBalance()
                ->otherwise(fn($e) => ['error_fetch_balance' => substr($e->getMessage(), 0, 150), $this->marginAsset => ['availableBalance' => 'ERROR', 'balance' => 'ERROR']]),
            'position' => $this->getPositionInformation($this->tradingSymbol)
                ->otherwise(fn($e) => ['error_fetch_position' => substr($e->getMessage(), 0, 150), 'raw_binance_position_data' => null]),
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 20)
                ->otherwise(fn($e) => ['error_fetch_trade_history' => substr($e->getMessage(), 0, 150), 'raw_binance_account_trades' => []]),
            'commission_rates' => $this->getFuturesCommissionRate($this->tradingSymbol)
                ->otherwise(fn($e) => ['error_fetch_commission_rates' => substr($e->getMessage(), 0, 150), 'makerCommissionRate' => 'ERROR', 'takerCommissionRate' => 'ERROR']),
        ];

        $multiTfKlinePromises = [];
        foreach ($this->historicalKlineIntervalsAIArray as $interval) {
            $multiTfKlinePromises[$interval] = $this->getHistoricalKlines($this->tradingSymbol, $interval, 20)
                ->otherwise(fn($e) => ['error_fetch_kline_' . $interval => substr($e->getMessage(),0,150), 'data' => []]);
        }
        $promises['historical_klines'] = \React\Promise\all($multiTfKlinePromises);

        $dbOrderLogs = $this->getRecentOrderLogsFromDb(self::MAX_ORDER_LOG_ENTRIES_FOR_AI_CONTEXT);
        $dbRecentAIInteractions = $this->getRecentAIInteractionsFromDb(self::MAX_AI_INTERACTIONS_FOR_AI_CONTEXT);

        return \React\Promise\all($promises)->then(function (array $results) use ($isEmergency, $dbOrderLogs, $dbRecentAIInteractions) {
            $this->currentPositionDetails = $this->formatPositionDetails($results['position']['raw_binance_position_data'] ?? $results['position']);
            
            $activeEntryOrderDetails = null;
            if($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                $activeEntryOrderDetails = ['orderId' => $this->activeEntryOrderId, 'seconds_pending' => time() - $this->activeEntryOrderTimestamp];
            }
            
            $isMissingProtectionNow = ($this->currentPositionDetails && (!$this->activeSlOrderId || !$this->activeTpOrderId) && !$this->isPlacingOrManagingOrder);
            $this->isMissingProtectiveOrder = $isMissingProtectionNow;

            return [
                'bot_metadata' => [
                    'current_timestamp_iso_utc' => gmdate('Y-m-d H:i:s'),
                    'trading_symbol' => $this->tradingSymbol,
                    'is_emergency_update_request' => $isEmergency,
                    'bot_id' => $this->botConfigId,
                    'user_id' => $this->userId
                ],
                'market_data' => [
                    'current_market_price' => $this->lastClosedKlinePrice,
                    'symbol_precision' => [
                        'price_tick_size' => $this->exchangeInfo[$this->tradingSymbol]['tickSize'] ?? '0.0',
                        'quantity_step_size' => $this->exchangeInfo[$this->tradingSymbol]['stepSize'] ?? '0.0',
                        'comment' => 'All price and quantity values in your response MUST be multiples of these sizes and formatted accordingly.'
                    ],
                    'historical_klines_multi_tf' => $results['historical_klines'],
                    'commission_rates' => $results['commission_rates']
                ],
                'account_state' => [
                    'balance_details' => $results['balance'],
                    'current_position_details' => $this->currentPositionDetails,
                    'recent_account_trades' => $results['trade_history']
                ],
                'bot_operational_state' => [
                    'active_pending_entry_order_details' => $activeEntryOrderDetails,
                    'active_sl_order_id' => $this->activeSlOrderId,
                    'active_tp_order_id' => $this->activeTpOrderId,
                    'is_managing_order_lock' => $this->isPlacingOrManagingOrder,
                    'is_position_unprotected' => $this->isMissingProtectiveOrder
                ],
                'historical_bot_performance_and_decisions' => [
                    'last_ai_decision_bot_feedback' => $this->lastAIDecisionResult,
                    'recent_bot_order_log_outcomes' => $dbOrderLogs,
                    'recent_ai_interactions' => $dbRecentAIInteractions
                ],
                'current_guiding_trade_logic_source' => $this->currentActiveTradeLogicSource['strategy_directives_json'] ?? null,
                'bot_configuration_summary_for_ai' => [
                    'initialMarginTargetUsdt' => $this->initialMarginTargetUsdt,
                    'defaultLeverage' => $this->defaultLeverage,
                    'pendingEntryOrderTimeoutSeconds' => $this->pendingEntryOrderCancelTimeoutSeconds
                ],
            ];
        });
    }

    private function constructAIPrompt(array $fullDataForAI): string
    {
        // 1. Determine the bot's operational mode from the strategy directives.
        $strategyDirectives = $fullDataForAI['current_guiding_trade_logic_source']['strategy_directives'] ?? [];
        $quantityMethod = $strategyDirectives['quantity_determination_method'] ?? 'AI_SUGGESTED';
        $allowSelfUpdate = $strategyDirectives['allow_ai_to_update_self'] ?? false;

        // 2. Build the prompt from modular, unbiased blocks.
        $promptParts = [];

        // --- PART A: The Unchanging Core Instructions ---
        $promptParts[] = <<<PROMPT
You are a trading decision model. Your sole function is to analyze the provided JSON context and return a single, valid JSON action. You must operate exclusively within the rules defined in this prompt.

**CRITICAL RULES:**
1.  **Strict JSON Output:** Your entire response MUST be a single, valid JSON object without any markdown, comments, or extraneous text.
2.  **Mandatory Precision:** All `price` and `quantity` values in your response MUST conform to the exact decimal precision specified in `market_data.symbol_precision`. Failure to do so will result in a rejected order. This is a non-negotiable, critical instruction.
PROMPT;

        // --- PART B: Scenario-Specific Instructions based on Strategy ---
        $actionsAndFormats = '';

        // --- SCENARIO 1: Full Autonomy (AI Suggests Quantity & Can Update Strategy) ---
        if ($quantityMethod === 'AI_SUGGESTED' && $allowSelfUpdate) {
            $actionsAndFormats = <<<PROMPT
**OPERATING MODE: ADAPTIVE (AI Quantity, Self-Improving Strategy)**

**AVAILABLE ACTIONS & JSON RESPONSE FORMAT:**

1.  **OPEN_POSITION**: Initiate a new trade.
    - `action`: "OPEN_POSITION"
    - `leverage`: (integer)
    - `side`: "BUY" or "SELL"
    - `entryPrice`: (float, respecting precision)
    - `quantity`: (float, your calculated value, respecting precision)
    - `stopLossPrice`: (float, respecting precision)
    - `takeProfitPrice`: (float, respecting precision)
    - `rationale`: (string, brief justification)
    **Example:**
    ```json
    {
      "action": "OPEN_POSITION",
      "leverage": 10,
      "side": "BUY",
      "entryPrice": 29000.0,
      "quantity": 0.001,
      "stopLossPrice": 28500.0,
      "takeProfitPrice": 29500.0,
      "rationale": "Price action indicates strong bullish momentum after retesting support."
    }
    ```

2.  **CLOSE_POSITION**: Close the existing position at market price.
    - `action`: "CLOSE_POSITION"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "CLOSE_POSITION",
      "rationale": "Reached take profit target and market shows signs of reversal."
    }
    ```

3.  **HOLD_POSITION / DO_NOTHING**: Maintain the current state.
    - `action`: "HOLD_POSITION" or "DO_NOTHING"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "Current position is healthy, and no new clear signals for entry or exit."
    }
    ```

**OPTIONAL STRATEGY UPDATE:**
If your analysis indicates the `current_guiding_trade_logic_source` is flawed, you MAY add the `suggested_strategy_directives_update` key to your response. This does NOT replace the main `action`.
- `suggested_strategy_directives_update`:
    - `reason_for_update`: (string)
    - `updated_directives`: A complete JSON object for the new `strategy_directives`.
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "No immediate trade, but strategy needs refinement.",
      "suggested_strategy_directives_update": {
        "reason_for_update": "Adjusting risk parameters based on recent volatility.",
        "updated_directives": {
          "schema_version": "1.0.0",
          "strategy_type": "GENERAL_TRADING",
          "current_market_bias": "NEUTRAL",
          "User prompt": [],
          "preferred_timeframes_for_entry": ["1m", "5m", "15m"],
          "key_sr_levels_to_watch": {"support": [], "resistance": []},
          "risk_parameters": {"target_risk_per_trade_usdt": 0.75, "default_rr_ratio": 2.5, "max_concurrent_positions": 1},
          "quantity_determination_method": "AI_SUGGESTED",
          "entry_conditions_keywords": ["momentum_confirm", "breakout_consolidation"],
          "exit_conditions_keywords": ["momentum_stall", "target_profit_achieved"],
          "leverage_preference": {"min": 5, "max": 10, "preferred": 10},
          "ai_confidence_threshold_for_trade": 0.7,
          "ai_learnings_notes": "Adjusted risk per trade and R:R ratio.",
          "allow_ai_to_update_self": true,
          "emergency_hold_justification": "Wait for clear market signal or manual intervention."
        }
      }
    }
    ```
PROMPT;
        }
        // --- SCENARIO 2: AI Suggests Quantity, but Strategy is Fixed ---
        elseif ($quantityMethod === 'AI_SUGGESTED' && !$allowSelfUpdate) {
            $actionsAndFormats = <<<PROMPT
**OPERATING MODE: TACTICAL (AI Quantity, Fixed Strategy)**

**AVAILABLE ACTIONS & JSON RESPONSE FORMAT:**

1.  **OPEN_POSITION**: Initiate a new trade.
    - `action`: "OPEN_POSITION"
    - `leverage`: (integer)
    - `side`: "BUY" or "SELL"
    - `entryPrice`: (float, respecting precision)
    - `quantity`: (float, your calculated value, respecting precision)
    - `stopLossPrice`: (float, respecting precision)
    - `takeProfitPrice`: (float, respecting precision)
    - `rationale`: (string, brief justification)
    **Example:**
    ```json
    {
      "action": "OPEN_POSITION",
      "leverage": 10,
      "side": "BUY",
      "entryPrice": 29000.0,
      "quantity": 0.001,
      "stopLossPrice": 28500.0,
      "takeProfitPrice": 29500.0,
      "rationale": "Price action indicates strong bullish momentum after retesting support."
    }
    ```

2.  **CLOSE_POSITION**: Close the existing position at market price.
    - `action`: "CLOSE_POSITION"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "CLOSE_POSITION",
      "rationale": "Reached take profit target and market shows signs of reversal."
    }
    ```

3.  **HOLD_POSITION / DO_NOTHING**: Maintain the current state.
    - `action`: "HOLD_POSITION" or "DO_NOTHING"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "Current position is healthy, and no new clear signals for entry or exit."
    }
    ```

**RESTRICTION:** You are forbidden from suggesting strategy updates.
PROMPT;
        }
        // --- SCENARIO 3: Fixed Quantity Calculation, but Strategy Can Be Updated ---
        elseif ($quantityMethod === 'INITIAL_MARGIN_TARGET' && $allowSelfUpdate) {
             $actionsAndFormats = <<<PROMPT
**OPERATING MODE: MECHANICAL (Fixed Quantity, Self-Improving Strategy)**

**AVAILABLE ACTIONS & JSON RESPONSE FORMAT:**

1.  **OPEN_POSITION**: Initiate a new trade.
    - `action`: "OPEN_POSITION"
    - `leverage`: (integer)
    - `side`: "BUY" or "SELL"
    - `entryPrice`: (float, respecting precision)
    - `quantity`: (float, **MANDATORY CALCULATION**: You must calculate this value using the formula `(bot_configuration_summary_for_ai.initialMarginTargetUsdt * leverage) / entryPrice`, and then format the result to the required precision.)
    - `stopLossPrice`: (float, respecting precision)
    - `takeProfitPrice`: (float, respecting precision)
    - `rationale`: (string, brief justification)
    **Example:**
    ```json
    {
      "action": "OPEN_POSITION",
      "leverage": 10,
      "side": "BUY",
      "entryPrice": 29000.0,
      "quantity": 0.001,
      "stopLossPrice": 28500.0,
      "takeProfitPrice": 29500.0,
      "rationale": "Price action indicates strong bullish momentum after retesting support."
    }
    ```

2.  **CLOSE_POSITION**: Close the existing position at market price.
    - `action`: "CLOSE_POSITION"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "CLOSE_POSITION",
      "rationale": "Reached take profit target and market shows signs of reversal."
    }
    ```

3.  **HOLD_POSITION / DO_NOTHING**: Maintain the current state.
    - `action`: "HOLD_POSITION" or "DO_NOTHING"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "Current position is healthy, and no new clear signals for entry or exit."
    }
    ```

**OPTIONAL STRATEGY UPDATE:**
If your analysis indicates the `current_guiding_trade_logic_source` is flawed, you MAY add the `suggested_strategy_directives_update` key to your response. This does NOT replace the main `action`.
- `suggested_strategy_directives_update`:
    - `reason_for_update`: (string)
    - `updated_directives`: A complete JSON object for the new `strategy_directives`.
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "No immediate trade, but strategy needs refinement.",
      "suggested_strategy_directives_update": {
        "reason_for_update": "Adjusting risk parameters based on recent volatility.",
        "updated_directives": {
          "schema_version": "1.0.0",
          "strategy_type": "GENERAL_TRADING",
          "current_market_bias": "NEUTRAL",
          "User prompt": [],
          "preferred_timeframes_for_entry": ["1m", "5m", "15m"],
          "key_sr_levels_to_watch": {"support": [], "resistance": []},
          "risk_parameters": {"target_risk_per_trade_usdt": 0.75, "default_rr_ratio": 2.5, "max_concurrent_positions": 1},
          "quantity_determination_method": "INITIAL_MARGIN_TARGET",
          "entry_conditions_keywords": ["momentum_confirm", "breakout_consolidation"],
          "exit_conditions_keywords": ["momentum_stall", "target_profit_achieved"],
          "leverage_preference": {"min": 5, "max": 10, "preferred": 10},
          "ai_confidence_threshold_for_trade": 0.7,
          "ai_learnings_notes": "Adjusted risk per trade and R:R ratio.",
          "allow_ai_to_update_self": true,
          "emergency_hold_justification": "Wait for clear market signal or manual intervention."
        }
      }
    }
    ```
PROMPT;
        }
        // --- SCENARIO 4: Most Constrained (Fixed Quantity, Fixed Strategy) ---
        else { // ($quantityMethod === 'INITIAL_MARGIN_TARGET' && !$allowSelfUpdate)
             $actionsAndFormats = <<<PROMPT
**OPERATING MODE: EXECUTOR (Fixed Quantity, Fixed Strategy)**

**AVAILABLE ACTIONS & JSON RESPONSE FORMAT:**

1.  **OPEN_POSITION**: Initiate a new trade.
    - `action`: "OPEN_POSITION"
    - `leverage`: (integer)
    - `side`: "BUY" or "SELL"
    - `entryPrice`: (float, respecting precision)
    - `quantity`: (float, **MANDATORY CALCULATION**: You must calculate this value using the formula `(bot_configuration_summary_for_ai.initialMarginTargetUsdt * leverage) / entryPrice`, and then format the result to the required precision.)
    - `stopLossPrice`: (float, respecting precision)
    - `takeProfitPrice`: (float, respecting precision)
    - `rationale`: (string, brief justification)
    **Example:**
    ```json
    {
      "action": "OPEN_POSITION",
      "leverage": 10,
      "side": "BUY",
      "entryPrice": 29000.0,
      "quantity": 0.001,
      "stopLossPrice": 28500.0,
      "takeProfitPrice": 29500.0,
      "rationale": "Price action indicates strong bullish momentum after retesting support."
    }
    ```

2.  **CLOSE_POSITION**: Close the existing position at market price.
    - `action`: "CLOSE_POSITION"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "CLOSE_POSITION",
      "rationale": "Reached take profit target and market shows signs of reversal."
    }
    ```

3.  **HOLD_POSITION / DO_NOTHING**: Maintain the current state.
    - `action`: "HOLD_POSITION" or "DO_NOTHING"
    - `rationale`: (string)
    **Example:**
    ```json
    {
      "action": "HOLD_POSITION",
      "rationale": "Current position is healthy, and no new clear signals for entry or exit."
    }
    ```

**RESTRICTION:** You are forbidden from suggesting strategy updates.
PROMPT;
        }

        $promptParts[] = $actionsAndFormats;

        // --- PART C: The Context and Final Instruction ---
        $promptParts[] = <<<PROMPT
**CONTEXT (JSON):**
{{CONTEXT_JSON}}

Based on the rules for your current operating mode and the context provided, return your decision as a single JSON object.
PROMPT;


        // 3. Assemble and finalize the prompt payload.
        $finalPromptText = implode("\n\n", $promptParts);
        $contextJson = json_encode($fullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
        $finalPromptText = str_replace('{{CONTEXT_JSON}}', $contextJson, $finalPromptText);

        return json_encode([
            'contents' => [['parts' => [['text' => $finalPromptText]]]],
            'generationConfig' => ['responseMimeType' => 'application/json']
        ]);
    }

    public function triggerAIUpdate(bool $isEmergency = false): void
    {
        if ($this->isPlacingOrManagingOrder && !$isEmergency) return;
        if ($isEmergency) $this->logger->warning('*** EMERGENCY AI UPDATE TRIGGERED ***');
        else $this->logger->info('Starting AI parameter update cycle...');

        $this->currentDataForAIForDBLog = null; $this->currentPromptMD5ForDBLog = null; $this->currentRawAIResponseForDBLog = null;
        $this->loadActiveTradeLogicSource();

        $this->collectDataForAI($isEmergency)
            ->then(function (array $dataForAI) use ($isEmergency) {
                $this->currentDataForAIForDBLog = $dataForAI;
                $promptPayloadJson = $this->constructAIPrompt($dataForAI, $isEmergency);
                $this->currentPromptMD5ForDBLog = md5($promptPayloadJson);
                return $this->sendRequestToAI($promptPayloadJson);
            })
            ->then(fn($rawResponse) => $this->processAIResponse($rawResponse))
            ->catch(function (\Throwable $e) {
                $this->logger->error('AI update cycle failed.', ['exception' => $e->getMessage()]);
                $this->lastAIDecisionResult = ['status' => 'ERROR_CYCLE', 'message' => "AI cycle failed: " . $e->getMessage()];
                $this->logAIInteractionToDb('ERROR_CYCLE', null, $this->lastAIDecisionResult, $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog);
            });
    }

    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = ['Content-Type' => 'application/json'];
        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                if ($response->getStatusCode() >= 300) {
                    throw new \RuntimeException("Gemini API HTTP error: " . $response->getStatusCode() . " Body: " . $body, $response->getStatusCode());
                }
                return $body;
            }
        );
    }

    private function processAIResponse(string $rawResponse): void
    {
        $this->currentRawAIResponseForDBLog = $rawResponse;
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (isset($responseDecoded['promptFeedback']['blockReason'])) {
                throw new \InvalidArgumentException("AI prompt blocked: " . $responseDecoded['promptFeedback']['blockReason']);
            }
            if (!isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                 throw new \InvalidArgumentException("AI response missing text content. Finish Reason: " . ($responseDecoded['candidates'][0]['finishReason'] ?? 'N/A'));
            }
            $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];
            $paramsJson = trim(str_replace(['```json', '```'], '', $aiTextResponse));
            $aiDecisionParams = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode JSON from AI text: " . json_last_error_msg());
            }
            $this->executeAIDecision($aiDecisionParams);
        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response.', ['exception' => $e->getMessage()]);
            $this->lastAIDecisionResult = ['status' => 'ERROR_PROCESSING', 'message' => "Failed processing AI response: " . $e->getMessage()];
            $this->logAIInteractionToDb('ERROR_PROCESSING_AI_RESPONSE', null, $this->lastAIDecisionResult, $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog);
        }
    }

    private function executeAIDecision(array $decision): void
    {
        $actionToExecute = strtoupper($decision['action'] ?? 'UNKNOWN_ACTION');
        $this->logger->info("AI Decision Received", ['action' => $actionToExecute, 'params' => $decision]);

        $originalDecisionForLog = $decision; 
        $overrideReason = null; 
        
        $currentBotContext = [
            'isMissingProtectiveOrder' => $this->isMissingProtectiveOrder,
            'activeEntryOrderId' => $this->activeEntryOrderId,
            'currentPositionExists' => !is_null($this->currentPositionDetails),
            'isPlacingOrManagingOrder' => $this->isPlacingOrManagingOrder,
        ];

        if ($this->isMissingProtectiveOrder) {
            if ($actionToExecute === 'HOLD_POSITION' && !empty(trim($this->currentActiveTradeLogicSource['strategy_directives']['emergency_hold_justification'] ?? ''))) {
                 // Allow hold
            } else if ($actionToExecute !== 'CLOSE_POSITION') {
                $overrideReason = "AI chose '{$actionToExecute}' in CRITICAL state (missing SL/TP). Bot enforces CLOSE for safety.";
                $actionToExecute = 'CLOSE_POSITION';
            }
        }
        elseif ($this->activeEntryOrderId) {
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while an entry order ({$this->activeEntryOrderId}) is already pending. Bot enforces HOLD.";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'CLOSE_POSITION') {
                 $overrideReason = "AI chose CLOSE_POSITION while an entry order is pending (no position to close yet). Bot enforces HOLD.";
                 $actionToExecute = 'HOLD_POSITION';
            }
        }
        elseif ($this->currentPositionDetails) {
            if ($actionToExecute === 'OPEN_POSITION') {
                $overrideReason = "AI chose OPEN_POSITION while a position already exists. Bot enforces HOLD.";
                $actionToExecute = 'HOLD_POSITION';
            } elseif ($actionToExecute === 'DO_NOTHING') {
                 $overrideReason = "AI chose DO_NOTHING while a position is active. Bot enforces HOLD to maintain position management.";
                 $actionToExecute = 'HOLD_POSITION';
            }
        }
        else {
            if ($actionToExecute === 'CLOSE_POSITION') {
                $overrideReason = "AI chose CLOSE_POSITION when no position exists. Bot enforces DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            } elseif ($actionToExecute === 'HOLD_POSITION') {
                $overrideReason = "AI chose HOLD_POSITION when no position exists. Bot interprets as DO_NOTHING.";
                $actionToExecute = 'DO_NOTHING';
            }
        }

        if ($overrideReason) {
            $this->logger->warning("AI Action Overridden by Bot Logic: {$overrideReason}", [
                'original_ai_action' => $originalDecisionForLog['action'] ?? 'N/A',
                'forced_bot_action' => $actionToExecute,
                'bot_context_at_override' => $currentBotContext
            ]);
            $this->lastAIDecisionResult = ['status' => 'WARN_OVERRIDE', 'message' => "Bot Override: " . $overrideReason, 'original_ai_decision' => $originalDecisionForLog, 'executed_action_by_bot' => $actionToExecute];
        }

        $logActionForDB = $actionToExecute . ($overrideReason ? '_BOT_OVERRIDE' : '_AI_DIRECT');

        $aiUpdateSuggestion = $originalDecisionForLog['suggested_strategy_directives_update'] ?? null;
        $allowAIUpdate = ($this->currentActiveTradeLogicSource['strategy_directives']['allow_ai_to_update_self'] ?? false) === true;

        if ($allowAIUpdate && is_array($aiUpdateSuggestion) && isset($aiUpdateSuggestion['updated_directives'])) {
            $updateReason = $aiUpdateSuggestion['reason_for_update'] ?? 'AI suggested update';
            $updateSuccess = $this->updateTradeLogicSourceInDb($aiUpdateSuggestion['updated_directives'], $updateReason, $this->currentDataForAIForDBLog);
            $this->lastAIDecisionResult['message'] = ($updateSuccess ? "[Strategy Updated] " : "[Strategy Update Failed] ") . ($this->lastAIDecisionResult['message'] ?? '');
            $this->lastAIDecisionResult['strategy_update_status'] = $updateSuccess ? 'OK' : 'FAILED';
        }

        switch ($actionToExecute) {
            case 'OPEN_POSITION':
                $this->aiSuggestedLeverage = (int)($decision['leverage'] ?? $this->defaultLeverage);
                $this->aiSuggestedSide = strtoupper($decision['side'] ?? '');
                $this->aiSuggestedEntryPrice = (float)($decision['entryPrice'] ?? 0);
                $this->aiSuggestedSlPrice = (float)($decision['stopLossPrice'] ?? 0);
                $this->aiSuggestedTpPrice = (float)($decision['takeProfitPrice'] ?? 0);
                $tradeRationale = trim($decision['rationale'] ?? '');

                if (($this->currentActiveTradeLogicSource['strategy_directives']['quantity_determination_method'] ?? 'AI_SUGGESTED') === 'INITIAL_MARGIN_TARGET') {
                    if ($this->initialMarginTargetUsdt <= 0 || $this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedLeverage <= 0) {
                        $this->lastAIDecisionResult = ['status' => 'ERROR_VALIDATION', 'message' => "Cannot calculate quantity for INITIAL_MARGIN_TARGET: Invalid inputs provided by AI."];
                        $logActionForDB = 'ERROR_VALIDATION_OPEN_POS';
                        break;
                    }
                    $this->aiSuggestedQuantity = ($this->initialMarginTargetUsdt * $this->aiSuggestedLeverage) / $this->aiSuggestedEntryPrice;
                } else {
                    $this->aiSuggestedQuantity = (float)($decision['quantity'] ?? 0);
                }

                $validationError = null;
                if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) $validationError = "Invalid side: '{$this->aiSuggestedSide}'.";
                elseif ($this->aiSuggestedLeverage <= 0 || $this->aiSuggestedLeverage > 125) $validationError = "Invalid leverage: {$this->aiSuggestedLeverage}.";
                elseif ($this->aiSuggestedEntryPrice <= 0) $validationError = "Invalid entryPrice <= 0.";
                elseif ($this->aiSuggestedQuantity <= 0) $validationError = "Invalid quantity <= 0.";
                elseif ($this->aiSuggestedSlPrice <= 0) $validationError = "Invalid stopLossPrice <= 0.";
                elseif ($this->aiSuggestedTpPrice <= 0) $validationError = "Invalid takeProfitPrice <= 0.";
                elseif (empty($tradeRationale) || strlen($tradeRationale) < 10) $validationError = "Missing or insufficient 'rationale'.";
                // Additional validation for logical relationship between entry, SL, and TP prices
                elseif ($this->aiSuggestedSide === 'BUY' && ($this->aiSuggestedSlPrice >= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice <= $this->aiSuggestedEntryPrice)) {
                    $validationError = "Invalid SL/TP for LONG position. SL must be < Entry, TP must be > Entry.";
                }
                elseif ($this->aiSuggestedSide === 'SELL' && ($this->aiSuggestedSlPrice <= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice >= $this->aiSuggestedEntryPrice)) {
                    $validationError = "Invalid SL/TP for SHORT position. SL must be > Entry, TP must be < Entry.";
                }
                
                if ($validationError) {
                    $this->logger->error("AI OPEN_POSITION parameters FAILED BOT VALIDATION: {$validationError}", ['failed_decision_params' => $decision]);
                    $this->lastAIDecisionResult = ['status' => 'ERROR_VALIDATION', 'message' => "AI OPEN_POSITION params rejected by bot: " . $validationError];
                    $logActionForDB = 'ERROR_VALIDATION_OPEN_POS';
                } else {
                    $this->attemptOpenPosition();
                }
                break;

            case 'CLOSE_POSITION':
                $this->attemptClosePositionByAI();
                break;

            case 'HOLD_POSITION':
            case 'DO_NOTHING':
                 if (!$overrideReason) {
                    $this->lastAIDecisionResult = ['status' => 'OK_ACTION', 'message' => "Bot will {$actionToExecute} as per AI."];
                 }
                 $this->logger->info("Bot action: {$actionToExecute}.", ['reason' => $decision['rationale'] ?? ($overrideReason ?: 'AI request')]);
                 break;

            default:
                $this->lastAIDecisionResult = ['status' => 'ERROR_ACTION', 'message' => "Bot received unknown AI action '{$actionToExecute}'."];
                $this->logger->error("Unknown AI action received.", ['action_received' => $actionToExecute]);
                $logActionForDB = 'ERROR_UNKNOWN_AI_ACTION';
        }

        $this->logAIInteractionToDb(
            $logActionForDB, $originalDecisionForLog, $this->lastAIDecisionResult,
            $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog
        );
    }
}

// --- Script Execution ---
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

$botConfigId = (int)($argv[1] ?? 0);
if ($botConfigId === 0) {
    die("Usage: php " . basename(__FILE__) . " <bot_config_id>\nError: No bot_config_id provided.\n");
}

try {
    $bot = new AiTradingBotFutures(
        botConfigId: $botConfigId,
        geminiModelName: $geminiModelName,
        appEncryptionKey: $appEncryptionKey,
        dbHost: $dbHost,
        dbPort: $dbPort,
        dbName: $dbName,
        dbUser: $dbUser,
        dbPassword: $dbPassword
    );
    $bot->run();
} catch (\Throwable $e) {
    $errorMessage = "CRITICAL: Bot for config ID {$botConfigId} stopped due to unhandled exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    $fullErrorMessage = $errorMessage . "\nStack trace:\n" . $e->getTraceAsString();
    error_log($fullErrorMessage);

    try {
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword);
        $stmt = $pdo->prepare("
            UPDATE bot_runtime_status SET status = 'error', last_heartbeat = NOW(), error_message = :error_message, process_id = NULL WHERE bot_config_id = :bot_config_id
        ");
        // Ensure the error message fits the database column (e.g., VARCHAR(255) or TEXT)
        $dbErrorMessage = substr($fullErrorMessage, 0, 65535); // Assuming error_message is TEXT or similar
        $stmt->execute([':error_message' => $dbErrorMessage, ':bot_config_id' => $botConfigId]);
        error_log("Bot runtime status updated to 'error' in DB for config ID {$botConfigId}.");
    } catch (\PDOException $db_e) {
        error_log("Further DB error when trying to log bot shutdown error: " . $db_e->getMessage());
    }
    exit(1);
}
