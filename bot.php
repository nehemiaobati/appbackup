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

    // --- State Machine Constants ---
    private const STATE_INITIALIZING = 'INITIALIZING';
    private const STATE_IDLE = 'IDLE';
    private const STATE_EVALUATING = 'EVALUATING';
    private const STATE_ORDER_PENDING = 'ORDER_PENDING';
    private const STATE_POSITION_ACTIVE = 'POSITION_ACTIVE';
    private const STATE_POSITION_UNPROTECTED = 'POSITION_UNPROTECTED';
    private const STATE_CLOSING = 'CLOSING';
    private const STATE_SHUTDOWN = 'SHUTDOWN';
    private const STATE_ERROR = 'ERROR';

    // --- Configuration Properties (Loaded from DB) ---
    private int $botConfigId;
    private int $userId;
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
    private string $appEncryptionKey;

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
    private array $periodicTimers = [];

    // --- Exchange Information Cache ---
    private array $exchangeInfo = [];

    // --- State Management ---
    private string $botState;
    private ?float $lastClosedKlinePrice = null;
    private ?string $activeEntryOrderId = null;
    private ?int $activeEntryOrderTimestamp = null;
    private ?string $activeSlOrderId = null;
    private ?string $activeTpOrderId = null;
    private ?array $currentPositionDetails = null;
    private ?string $listenKey = null;
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

    /**
     * Constructor for the AiTradingBotFutures class.
     * Initializes all dependencies, configurations, and the event loop.
     *
     * @param int $botConfigId The ID of the bot configuration from the database.
     * @param string $geminiModelName The name of the Gemini AI model to use.
     * @param string $appEncryptionKey The key for decrypting sensitive data.
     * @param string $dbHost Database host.
     * @param string $dbPort Database port.
     * @param string $dbName Database name.
     * @param string $dbUser Database user.
     * @param string $dbPassword Database password.
     */
    public function __construct(
        int $botConfigId,
        string $geminiModelName,
        string $appEncryptionKey,
        string $dbHost,
        string $dbPort,
        string $dbName,
        string $dbUser,
        string $dbPassword
    ) {
        $this->botState = self::STATE_INITIALIZING;
        $this->botConfigId = $botConfigId;
        $this->geminiModelName = $geminiModelName;
        $this->appEncryptionKey = $appEncryptionKey;
        $this->dbHost = $dbHost;
        $this->dbPort = $dbPort;
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPassword = $dbPassword;

        $this->loop = Loop::get();
        $this->browser = new Browser($this->loop);

        $logFormat = "[%datetime%] [%level_name%] [BotID:{$this->botConfigId}] [UserID:?] [%extra.state%] %message% %context%\n";
        $formatter = new LineFormatter($logFormat, 'Y-m-d H:i:s', true, true);
        $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $streamHandler->setFormatter($formatter);
        $this->logger = new Logger('AiTradingBotFutures');
        $this->logger->pushHandler($streamHandler);
        $this->logger->pushProcessor(function ($record) {
            $record['extra']['state'] = $this->botState;
            return $record;
        });

        $this->initializeDatabaseConnection();
        $this->loadBotConfigurationFromDb($this->botConfigId);

        $newLogFormat = str_replace('[UserID:?]', "[UserID:{$this->userId}]", $logFormat);
        $newFormatter = new LineFormatter($newLogFormat, 'Y-m-d H:i:s', true, true);
        $this->logger->getHandlers()[0]->setFormatter($newFormatter);

        $this->loadUserAndApiKeys();
        $this->loadActiveTradeLogicSource();

        $this->currentRestApiBaseUrl = $this->useTestnet ? self::BINANCE_FUTURES_TEST_REST_API_BASE_URL : self::BINANCE_FUTURES_PROD_REST_API_BASE_URL;
        $this->currentWsBaseUrlCombined = $this->useTestnet ? self::BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED : self::BINANCE_FUTURES_PROD_WS_BASE_URL;

        $this->logger->info('AiTradingBotFutures instance successfully initialized and configured.');
        $this->aiSuggestedLeverage = $this->defaultLeverage;
    }

    // =================================================================================
    // --- State Machine Component ---
    // =================================================================================

    /**
     * Transitions the bot to a new state and logs the change.
     * This is the ONLY method that should modify $this->botState.
     *
     * @param string $newState The target state (one of the STATE_* constants).
     * @param array $context Optional context for logging the state change.
     * @return void
     */
    private function transitionToState(string $newState, array $context = []): void
    {
        $oldState = $this->botState;
        if ($oldState === $newState) {
            return;
        }

        $this->botState = $newState;
        $this->logger->info("State Transition: {$oldState} -> {$newState}", $context);

        // Reset state-specific properties on certain transitions
        if ($newState === self::STATE_IDLE) {
            $this->resetTradeState();
        }
    }

    // =================================================================================
    // --- Core Lifecycle ---
    // =================================================================================

    /**
     * The main entry point to start the bot's execution and event loop.
     * @return void
     */
    public function run(): void
    {
        $this->botStartTime = time();
        $this->logger->info('Starting AI Trading Bot initialization...');
        $this->updateBotStatus('initializing');

        \React\Promise\all([
            'exchange_info' => $this->fetchExchangeInfo(),
            'initial_balance' => $this->getFuturesAccountBalance(),
            'initial_price' => $this->getLatestKlineClosePrice($this->tradingSymbol, $this->klineInterval),
            'initial_position' => $this->getPositionInformation($this->tradingSymbol),
            'listen_key' => $this->startUserDataStream(),
        ])->then(
            function ($results) {
                $this->exchangeInfo = $results['exchange_info'];
                $this->lastClosedKlinePrice = (float)($results['initial_price']['price'] ?? 0);
                $this->currentPositionDetails = $this->formatPositionDetails($results['initial_position']);
                $this->listenKey = $results['listen_key']['listenKey'] ?? null;

                if ($this->lastClosedKlinePrice <= 0 || !$this->listenKey) {
                    throw new \RuntimeException("Initialization failed: Invalid initial price or listenKey.");
                }

                $this->logger->info('Bot Initialization Success', [
                    'initial_market_price' => $this->lastClosedKlinePrice,
                    'initial_position' => $this->currentPositionDetails ?? 'No position',
                ]);

                if ($this->currentPositionDetails) {
                    $this->transitionToState(self::STATE_POSITION_UNPROTECTED, ['reason' => 'Initial state check found an existing position.']);
                } else {
                    $this->transitionToState(self::STATE_IDLE);
                }

                $this->connectWebSocket();
                $this->setupTimers();
                $this->updateBotStatus('running');
                $this->loop->addTimer(5, fn() => $this->triggerAIUpdate());
            },
            function (\Throwable $e) {
                $errorMessage = 'Bot Initialization failed: ' . $e->getMessage();
                $this->logger->critical($errorMessage, ['exception' => $e]);
                $this->transitionToState(self::STATE_ERROR, ['reason' => $errorMessage]);
                $this->updateBotStatus('error', $errorMessage);
                $this->stop();
            }
        );
        $this->loop->addSignal(SIGINT, fn() => $this->stop());
        $this->loop->addSignal(SIGTERM, fn() => $this->stop());

        $this->logger->info('Starting event loop...');
        $this->loop->run();
        $this->logger->info('Event loop finished.');
        $this->updateBotStatus('stopped');
    }

    /**
     * Gracefully stops the bot and all its active components.
     * @return void
     */
    private function stop(): void
    {
        if ($this->botState === self::STATE_SHUTDOWN) {
            return;
        }
        $this->transitionToState(self::STATE_SHUTDOWN);
        $this->updateBotStatus('shutdown');
        $this->logger->info('Stopping event loop and resources...');

        // Cancel all tracked periodic timers
        foreach ($this->periodicTimers as $key => $timer) {
            if ($timer) {
                $this->loop->cancelTimer($timer);
                $this->logger->debug("Cancelled periodic timer: {$key}");
            }
        }
        $this->periodicTimers = []; // Clear the array

        $stopPromises = [];
        if ($this->listenKey) {
            $stopPromises[] = $this->closeUserDataStream($this->listenKey)->then(
                fn() => $this->logger->info("ListenKey closed successfully."),
                fn($e) => $this->logger->error("Failed to close ListenKey.", ['err' => $e->getMessage()])
            );
        }

        \React\Promise\all($stopPromises)->finally(function () {
            if ($this->wsConnection) {
                try {
                    $this->wsConnection->close();
                } catch (\Exception $e) { /* ignore */
                }
            }
            $this->pdo = null;
            $this->loop->stop();
        });
    }

    // =================================================================================
    // --- Security & Configuration Component ---
    // =================================================================================

    /**
     * Decrypts an encrypted string using the application encryption key.
     *
     * @param string $encryptedData Base64 encoded string containing IV and encrypted data.
     * @return string Decrypted plain text.
     * @throws \RuntimeException If decryption fails.
     */
    private function decrypt(string $encryptedData): string
    {
        $decoded = base64_decode($encryptedData, true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to base64 decode encrypted key.');
        }

        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        if (strlen($decoded) < $ivLength) {
            throw new \RuntimeException('Invalid encrypted data format: too short for IV.');
        }
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);

        if ($iv === false || $encrypted === false) {
            throw new \RuntimeException('Invalid encrypted data format: IV or data missing.');
        }

        $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_CIPHER, $this->appEncryptionKey, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt API key. Check APP_ENCRYPTION_KEY and data integrity.');
        }
        return $decrypted;
    }

    /**
     * Loads and decrypts user-specific API keys from the database.
     *
     * @throws \RuntimeException If keys are not found or decryption fails.
     * @return void
     */
    private function loadUserAndApiKeys(): void
    {
        if (!$this->pdo) {
            throw new \RuntimeException("Cannot load API keys: Database not connected.");
        }

        $stmt = $this->pdo->prepare("
            SELECT uak.binance_api_key_encrypted, uak.binance_api_secret_encrypted, uak.gemini_api_key_encrypted
            FROM bot_configurations bc JOIN user_api_keys uak ON bc.user_api_key_id = uak.id
            WHERE bc.id = :bot_config_id AND uak.user_id = :user_id AND uak.is_active = TRUE
        ");
        $stmt->execute([':bot_config_id' => $this->botConfigId, ':user_id' => $this->userId]);
        $keys = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keys) {
            throw new \RuntimeException("No active API keys found for bot configuration ID {$this->botConfigId} and user ID {$this->userId}.");
        }

        try {
            $this->binanceApiKey = $this->decrypt($keys['binance_api_key_encrypted']);
            $this->binanceApiSecret = $this->decrypt($keys['binance_api_secret_encrypted']);
            $this->geminiApiKey = $this->decrypt($keys['gemini_api_key_encrypted']);
            $this->logger->info("User API keys loaded and decrypted successfully.");
        } catch (\Throwable $e) {
            throw new \RuntimeException("API key decryption failed for user {$this->userId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validates the loaded bot configuration to ensure it's safe to run.
     *
     * @param array $config The configuration array loaded from the DB.
     * @throws \InvalidArgumentException If any configuration value is invalid.
     * @return void
     */
    private function validateConfiguration(array $config): void
    {
        if (($config['default_leverage'] ?? 0) <= 0 || ($config['default_leverage'] ?? 0) > 125) {
            throw new \InvalidArgumentException("Default leverage must be between 1 and 125.");
        }
        if (($config['ai_update_interval_seconds'] ?? 0) < 5) {
            throw new \InvalidArgumentException("AI update interval must be at least 5 seconds.");
        }
        if (empty($config['symbol']) || empty($config['kline_interval']) || empty($config['margin_asset'])) {
            throw new \InvalidArgumentException("Symbol, kline_interval, and margin_asset must not be empty.");
        }
        $this->logger->debug("Bot configuration validated successfully.");
    }

    // =================================================================================
    // --- Database Component ---
    // =================================================================================

    /**
     * Initializes the PDO database connection.
     *
     * @throws \RuntimeException If the database connection fails.
     * @return void
     */
    private function initializeDatabaseConnection(): void
    {
        $dsn = "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $this->pdo = new PDO($dsn, $this->dbUser, $this->dbPassword, $options);
        } catch (\PDOException $e) {
            $this->pdo = null;
            throw new \RuntimeException("Database connection failed at startup: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Loads the bot's configuration from the database and validates it.
     *
     * @param int $configId The ID of the bot configuration to load.
     * @throws \RuntimeException If configuration is not found or is invalid.
     * @return void
     */
    private function loadBotConfigurationFromDb(int $configId): void
    {
        if (!$this->pdo) {
            throw new \RuntimeException("Cannot load bot configuration: Database not connected.");
        }

        $stmt = $this->pdo->prepare("SELECT * FROM bot_configurations WHERE id = :id AND is_active = TRUE");
        $stmt->execute([':id' => $configId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            throw new \RuntimeException("Bot configuration with ID {$configId} not found or is not active.");
        }

        $this->validateConfiguration($config);

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
        $this->maxScriptRuntimeSeconds = 604800; // Hardcoded max runtime
        $this->historicalKlineIntervalsAIArray = ['1m', '5m', '15m', '30m', '1h', '6h', '12h', '1d'];
        $this->primaryHistoricalKlineIntervalAI = '5m';

        $this->logger->info("Bot configuration loaded successfully from DB.", ['config_name' => $config['name']]);
    }

    /**
     * Updates the bot's runtime status in the database.
     *
     * @param string $status The current status of the bot.
     * @param string|null $errorMessage Optional error message.
     * @return bool True on success, false otherwise.
     */
    private function updateBotStatus(string $status, ?string $errorMessage = null): bool
    {
        if (!$this->pdo) {
            return false;
        }

        try {
            $pid = getmypid() ?: null;

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
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to update bot runtime status in DB: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns a hardcoded default array of AI strategy directives.
     * @return array
     */
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
     * @return void
     */
    private function loadActiveTradeLogicSource(): void
    {
        if (!$this->pdo) {
            $defaultDirectives = $this->getDefaultStrategyDirectives();
            $this->currentActiveTradeLogicSource = [
                'id' => 0, 'user_id' => $this->userId, 'source_name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME . '_fallback',
                'is_active' => true, 'version' => 1, 'last_updated_by' => 'SYSTEM_FALLBACK',
                'last_updated_at_utc' => gmdate('Y-m-d H:i:s'), 'strategy_directives_json' => json_encode($defaultDirectives),
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
                $this->currentActiveTradeLogicSource['strategy_directives'] = (json_last_error() === JSON_ERROR_NONE) ? $decodedDirectives : $this->getDefaultStrategyDirectives();
            } else {
                $defaultDirectives = $this->getDefaultStrategyDirectives();
                $insertSql = "INSERT INTO trade_logic_source (user_id, source_name, is_active, version, last_updated_by, last_updated_at_utc, strategy_directives_json)
                              VALUES (:user_id, :name, TRUE, 1, 'SYSTEM_DEFAULT', :now, :directives)";
                $insertStmt = $this->pdo->prepare($insertSql);
                $insertStmt->execute([':user_id' => $this->userId, ':name' => self::DEFAULT_TRADE_LOGIC_SOURCE_NAME, ':now' => gmdate('Y-m-d H:i:s'), ':directives' => json_encode($defaultDirectives)]);
                $this->loadActiveTradeLogicSource();
            }
        } catch (\PDOException $e) {
            $this->currentActiveTradeLogicSource = null;
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
            return false;
        }

        $sourceIdToUpdate = (int)$this->currentActiveTradeLogicSource['id'];
        $newVersion = (int)$this->currentActiveTradeLogicSource['version'] + 1;

        $timestampedReason = gmdate('Y-m-d H:i:s') . ' UTC - AI Update (v' . $newVersion . '): ' . $reasonForUpdate;
        $existingNotes = $updatedDirectives['ai_learnings_notes'] ?? '';
        $updatedDirectives['ai_learnings_notes'] = $timestampedReason . "\n" . $existingNotes;
        $updatedDirectives['schema_version'] = $updatedDirectives['schema_version'] ?? '1.0.0';

        $sql = "UPDATE trade_logic_source SET version = :version, last_updated_by = 'AI', last_updated_at_utc = :now,
                strategy_directives_json = :directives, full_data_snapshot_at_last_update_json = :snapshot
                WHERE id = :id AND user_id = :user_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':version' => $newVersion, ':now' => gmdate('Y-m-d H:i:s'),
                ':directives' => json_encode($updatedDirectives, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
                ':snapshot' => $currentFullDataForAI ? json_encode($currentFullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':id' => $sourceIdToUpdate, ':user_id' => $this->userId
            ]);
            $this->loadActiveTradeLogicSource();
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Logs an order event to the database.
     * @return bool True on success.
     */
    private function logOrderToDb(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt = 0.0, bool $reduceOnly = false): bool
    {
        if (!$this->pdo) {
            return false;
        }
        $sql = "INSERT INTO orders_log (user_id, bot_config_id, order_id_binance, bot_event_timestamp_utc, symbol, side, status_reason, price_point, quantity_involved, margin_asset, realized_pnl_usdt, commission_usdt, reduce_only)
                VALUES (:user_id, :bot_config_id, :order_id_binance, :bot_event_timestamp_utc, :symbol, :side, :status_reason, :price_point, :quantity_involved, :margin_asset, :realized_pnl_usdt, :commission_usdt, :reduce_only)";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':user_id' => $this->userId, ':bot_config_id' => $this->botConfigId,
                ':order_id_binance' => $orderId, ':bot_event_timestamp_utc' => gmdate('Y-m-d H:i:s', $timestamp),
                ':symbol' => $assetPair, ':side' => $side, ':status_reason' => $status,
                ':price_point' => $price, ':quantity_involved' => $quantity,
                ':margin_asset' => $marginAsset, ':realized_pnl_usdt' => $realizedPnl,
                ':commission_usdt' => $commissionUsdt, ':reduce_only' => (int)$reduceOnly
            ]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Fetches recent order logs from the database for AI context.
     * @param int $limit The maximum number of logs to fetch.
     * @return array
     */
    private function getRecentOrderLogsFromDb(int $limit): array
    {
        if (!$this->pdo) {
            return [['error' => 'Database not connected']];
        }
        $sql = "SELECT order_id_binance as orderId, status_reason as status, side, symbol as assetPair, price_point as price, quantity_involved as quantity, margin_asset as marginAsset, DATE_FORMAT(bot_event_timestamp_utc, '%Y-%m-%d %H:%i:%s UTC') as timestamp, realized_pnl_usdt as realizedPnl, reduce_only as reduceOnly
                FROM orders_log WHERE bot_config_id = :bot_config_id ORDER BY bot_event_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':bot_config_id', $this->botConfigId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($log) {
                $log['price'] = isset($log['price']) ? (float)$log['price'] : null;
                $log['quantity'] = isset($log['quantity']) ? (float)$log['quantity'] : null;
                $log['realizedPnl'] = isset($log['realizedPnl']) ? (float)$log['realizedPnl'] : 0.0;
                $log['reduceOnly'] = (bool)($log['reduceOnly'] ?? false);
                return $log;
            }, $logs);
        } catch (\PDOException $e) {
            return [['error' => 'Failed to fetch order logs: ' . $e->getMessage()]];
        }
    }

    /**
     * Logs an AI interaction event to the database.
     * @return bool True on successful log, false otherwise.
     */
    private function logAIInteractionToDb(string $executedAction, ?array $aiDecisionParams, ?array $botFeedback, ?array $fullDataForAI, ?string $promptMd5 = null, ?string $rawAiResponse = null): bool
    {
        if (!$this->pdo) {
            return false;
        }
        $sql = "INSERT INTO ai_interactions_log (user_id, bot_config_id, log_timestamp_utc, trading_symbol, executed_action_by_bot, ai_decision_params_json, bot_feedback_json, full_data_for_ai_json, prompt_text_sent_to_ai_md5, raw_ai_response_json)
                VALUES (:user_id, :bot_config_id, :log_timestamp_utc, :trading_symbol, :executed_action_by_bot, :ai_decision_params_json, :bot_feedback_json, :full_data_for_ai_json, :prompt_text_sent_to_ai_md5, :raw_ai_response_json)";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':user_id' => $this->userId, ':bot_config_id' => $this->botConfigId, ':log_timestamp_utc' => gmdate('Y-m-d H:i:s'),
                ':trading_symbol' => $this->tradingSymbol, ':executed_action_by_bot' => $executedAction,
                ':ai_decision_params_json' => $aiDecisionParams ? json_encode($aiDecisionParams, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':bot_feedback_json' => $botFeedback ? json_encode($botFeedback, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':full_data_for_ai_json' => $fullDataForAI ? json_encode($fullDataForAI, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
                ':prompt_text_sent_to_ai_md5' => $promptMd5,
                ':raw_ai_response_json' => $rawAiResponse ? json_encode(json_decode($rawAiResponse, true), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE) : null,
            ]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Fetches recent AI interaction logs from the database for AI context.
     * @param int $limit The maximum number of interactions to fetch.
     * @return array
     */
    private function getRecentAIInteractionsFromDb(int $limit): array
    {
        if (!$this->pdo) {
            return [['error' => 'Database not connected']];
        }
        $sql = "SELECT log_timestamp_utc, executed_action_by_bot, ai_decision_params_json, bot_feedback_json
                FROM ai_interactions_log WHERE bot_config_id = :bot_config_id ORDER BY log_timestamp_utc DESC LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':bot_config_id', $this->botConfigId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($interaction) {
                $interaction['ai_decision_params'] = isset($interaction['ai_decision_params_json']) ? json_decode($interaction['ai_decision_params_json'], true) : null;
                $interaction['bot_feedback'] = isset($interaction['bot_feedback_json']) ? json_decode($interaction['bot_feedback_json'], true) : null;
                unset($interaction['ai_decision_params_json'], $interaction['bot_feedback_json']);
                return $interaction;
            }, $interactions);
        } catch (\PDOException $e) {
            return [['error' => 'Failed to fetch AI interactions: ' . $e->getMessage()]];
        }
    }

    // =================================================================================
    // --- WebSocket & Timers Component ---
    // =================================================================================

    /**
     * Establishes the WebSocket connection to Binance.
     * @return void
     */
    private function connectWebSocket(): void
    {
        if (!$this->listenKey) {
            $this->logger->error("Cannot connect WebSocket without a listenKey. Stopping.");
            $this->stop();
            return;
        }
        $klineStream = strtolower($this->tradingSymbol) . '@kline_' . $this->klineInterval;
        $wsUrl = $this->currentWsBaseUrlCombined . '/stream?streams=' . $klineStream . '/' . $this->listenKey;
        $wsConnector = new WsConnector($this->loop);
        $wsConnector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('WebSocket connected successfully.');
                $conn->on('message', fn($msg) => $this->handleWsMessage((string)$msg));
                $conn->on('error', function (\Throwable $e) {
                    $this->logger->error('WebSocket error', ['exception' => $e->getMessage()]);
                    $this->stop();
                });
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', ['code' => $code, 'reason' => $reason]);
                    if ($this->botState !== self::STATE_SHUTDOWN) {
                        $this->stop();
                    }
                });
            },
            function (\Throwable $e) {
                $this->logger->error('WebSocket connection failed', ['exception' => $e->getMessage()]);
                $this->stop();
            }
        );
    }

    /**
     * Sets up all periodic timers for the bot's operation.
     * @return void
     */
    private function setupTimers(): void
    {
        // Heartbeat Timer
        $this->periodicTimers['heartbeat'] = $this->loop->addPeriodicTimer(self::BOT_HEARTBEAT_INTERVAL, function () {
            $this->updateBotStatus('running');
            if ($this->pdo) {
                try {
                    $stmt = $this->pdo->prepare("UPDATE bot_runtime_status SET current_position_details_json = :json WHERE bot_config_id = :config_id");
                    $stmt->execute([':json' => json_encode($this->currentPositionDetails), ':config_id' => $this->botConfigId]);
                } catch (\PDOException $e) {
                    $this->logger->error("Failed to update position details in heartbeat: " . $e->getMessage());
                }
            }
        });

        // Order Check Timer (for timeouts)
        $this->periodicTimers['order_check'] = $this->loop->addPeriodicTimer($this->orderCheckIntervalSeconds, function () {
            if ($this->botState === self::STATE_ORDER_PENDING && $this->activeEntryOrderTimestamp !== null) {
                if (time() - $this->activeEntryOrderTimestamp > $this->pendingEntryOrderCancelTimeoutSeconds) {
                    $this->logger->warning("Pending entry order {$this->activeEntryOrderId} timed out. Cancelling.");
                    $this->cancelOrderAndLog($this->activeEntryOrderId, "CANCELED_TIMEOUT")
                        ->finally(fn() => $this->transitionToState(self::STATE_IDLE, ['reason' => 'Entry order timed out']));
                }
            }
        });

        // AI Update Timer
        $this->periodicTimers['ai_update'] = $this->loop->addPeriodicTimer($this->aiUpdateIntervalSeconds, fn() => $this->triggerAIUpdate());

        // ListenKey Refresh Timer
        if ($this->listenKey) {
            $this->periodicTimers['listen_key'] = $this->loop->addPeriodicTimer(self::LISTEN_KEY_REFRESH_INTERVAL, function () {
                if ($this->listenKey) {
                    $this->keepAliveUserDataStream($this->listenKey);
                }
            });
        }

        // Profit Check Timer
        if ($this->takeProfitTargetUsdt > 0 && $this->profitCheckIntervalSeconds > 0) {
            $this->periodicTimers['profit_check'] = $this->loop->addPeriodicTimer($this->profitCheckIntervalSeconds, fn() => $this->checkProfitTarget());
        }

        $this->logger->info('All periodic timers started.');
    }

    /**
     * Main WebSocket message handler.
     * @param string $msg The raw message from the WebSocket.
     * @return void
     */
    private function handleWsMessage(string $msg): void
    {
        $decoded = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['stream'], $decoded['data'])) {
            return;
        }

        if (str_ends_with($decoded['stream'], '@kline_' . $this->klineInterval)) {
            if (($decoded['data']['k']['x'] ?? false)) {
                $this->lastClosedKlinePrice = (float)$decoded['data']['k']['c'];
            }
        } elseif ($decoded['stream'] === $this->listenKey) {
            $this->handleUserDataStreamEvent($decoded['data']);
        }
    }

    /**
     * Dispatches user data stream events to specific handlers.
     * @param array $eventData The decoded event data from Binance.
     * @return void
     */
    private function handleUserDataStreamEvent(array $eventData): void
    {
        switch ($eventData['e'] ?? null) {
            case 'ACCOUNT_UPDATE':
                $this->onAccountUpdate($eventData);
                break;
            case 'ORDER_TRADE_UPDATE':
                $this->onOrderTradeUpdate($eventData['o']);
                break;
            case 'listenKeyExpired':
                $this->onListenKeyExpired();
                break;
            case 'MARGIN_CALL':
                $this->logger->critical("MARGIN CALL RECEIVED!", $eventData);
                $this->transitionToState(self::STATE_POSITION_UNPROTECTED, ['reason' => 'MARGIN_CALL']);
                $this->triggerAIUpdate(true);
                break;
        }
    }

    /**
     * Handles account update events, primarily for detecting external position changes.
     * @param array $eventData The full ACCOUNT_UPDATE event data.
     * @return void
     */
    private function onAccountUpdate(array $eventData): void
    {
        if (!isset($eventData['a']['P'])) {
            return;
        }

        foreach ($eventData['a']['P'] as $posData) {
            if ($posData['s'] === $this->tradingSymbol) {
                $newPositionDetails = $this->formatPositionDetailsFromEvent($posData);
                $oldQty = (float)($this->currentPositionDetails['quantity'] ?? 0.0);
                $newQty = (float)($newPositionDetails['quantity'] ?? 0.0);

                if ($newQty == 0 && $oldQty != 0 && !in_array($this->botState, [self::STATE_IDLE, self::STATE_CLOSING])) {
                    $this->logger->warning("Position for {$this->tradingSymbol} closed via external action. Reconciling state.");
                    $this->handlePositionClosed();
                }
                $this->currentPositionDetails = $newPositionDetails;
            }
        }
    }

    /**
     * Handles order update events, the primary driver of state transitions.
     * @param array $orderData The 'o' object from the ORDER_TRADE_UPDATE event.
     * @return void
     */
    private function onOrderTradeUpdate(array $orderData): void
    {
        if ($orderData['s'] !== $this->tradingSymbol) {
            return;
        }

        $orderId = (string)$orderData['i'];
        $orderStatus = $orderData['X'];

        $this->getUsdtEquivalent((string)($orderData['N'] ?? null), (float)($orderData['n'] ?? 0))
            ->then(function ($commissionUsdt) use ($orderData, $orderId, $orderStatus) {
                if ($orderId === $this->activeEntryOrderId && $this->botState === self::STATE_ORDER_PENDING) {
                    if ($orderStatus === 'FILLED') {
                        $this->addOrderToLog($orderId, $orderStatus, $orderData['S'], $this->tradingSymbol, (float)$orderData['L'], (float)$orderData['l'], $this->marginAsset, time(), (float)($orderData['rp'] ?? 0.0), $commissionUsdt);

                        // FIX: Reset the active entry order ID to prevent trying to cancel it later.
                        $this->activeEntryOrderId = null;
                        $this->activeEntryOrderTimestamp = null;

                        $this->getPositionInformation($this->tradingSymbol)->then(function ($posData) {
                            $this->currentPositionDetails = $this->formatPositionDetails($posData);
                            $this->transitionToState(self::STATE_POSITION_UNPROTECTED, ['reason' => 'Entry order filled']);
                            $this->placeSlAndTpOrders();
                        });
                    } elseif (in_array($orderStatus, ['CANCELED', 'EXPIRED', 'REJECTED'])) {
                        $this->addOrderToLog($orderId, $orderStatus, $orderData['S'], $this->tradingSymbol, (float)$orderData['p'], (float)$orderData['q'], $this->marginAsset, time(), 0.0, $commissionUsdt);
                        $this->transitionToState(self::STATE_IDLE, ['reason' => "Entry order failed: {$orderStatus}"]);
                    }
                } elseif (($orderData['R'] ?? false) && $orderStatus === 'FILLED') { // Is a Reduce-Only order that filled
                    $this->addOrderToLog($orderId, $orderStatus, $orderData['S'], $this->tradingSymbol, (float)$orderData['L'], (float)$orderData['l'], $this->marginAsset, time(), (float)($orderData['rp'] ?? 0.0), $commissionUsdt, (bool)$orderData['R']);
                    $this->handlePositionClosed();
                }
            });
    }

    /**
     * Handles the expiration of the user data stream listen key.
     * @return void
     */
    private function onListenKeyExpired(): void
    {
        $this->logger->warning("ListenKey expired. Reconnecting...");
        if ($this->wsConnection) {
            $this->wsConnection->close();
        }

        $this->startUserDataStream()->then(function ($data) {
            $this->listenKey = $data['listenKey'] ?? null;
            if ($this->listenKey) {
                $this->connectWebSocket();
            } else {
                $this->logger->error("Failed to get new ListenKey after expiration. Stopping.");
                $this->stop();
            }
        })->otherwise(fn() => $this->stop());
    }

    // =================================================================================
    // --- Position & Order Management Component ---
    // =================================================================================

    /**
     * Places SL and TP orders after a position is opened.
     * @return void
     */
    private function placeSlAndTpOrders(): void
    {
        if (!$this->currentPositionDetails || $this->botState !== self::STATE_POSITION_UNPROTECTED) {
            return;
        }

        $orderSide = ($this->currentPositionDetails['side'] === 'LONG') ? 'SELL' : 'BUY';

        $slPromise = $this->placeFuturesStopMarketOrder($this->tradingSymbol, $orderSide, $this->currentPositionDetails['quantity'], $this->aiSuggestedSlPrice);
        $tpPromise = $this->placeFuturesTakeProfitMarketOrder($this->tradingSymbol, $orderSide, $this->currentPositionDetails['quantity'], $this->aiSuggestedTpPrice);

        \React\Promise\all([$slPromise, $tpPromise])->then(
            function (array $orders) {
                $this->activeSlOrderId = (string)$orders[0]['orderId'];
                $this->activeTpOrderId = (string)$orders[1]['orderId'];
                $this->transitionToState(self::STATE_POSITION_ACTIVE);
            },
            function (\Throwable $e) {
                $this->logger->critical("Failed to place protective orders: " . $e->getMessage() . ". Attempting to close position for safety.");
                $this->cancelAllOpenOrdersForSymbol()->finally(fn() => $this->attemptClosePositionByAI(true));
            }
        );
    }

    /**
     * Handles the full sequence of closing a position.
     * @return void
     */
    private function handlePositionClosed(): void
    {
        $this->transitionToState(self::STATE_CLOSING);
        $this->cancelAllOpenOrdersForSymbol()->finally(function () {
            $this->transitionToState(self::STATE_IDLE, ['reason' => 'Position closed and all orders cancelled.']);
            $this->loop->addTimer(5, fn() => $this->triggerAIUpdate());
        });
    }

    /**
     * Resets all trade-related state properties.
     * This is automatically handled by transitioning to STATE_IDLE.
     * @return void
     */
    private function resetTradeState(): void
    {
        $this->activeEntryOrderId = null;
        $this->activeEntryOrderTimestamp = null;
        $this->activeSlOrderId = null;
        $this->activeTpOrderId = null;
        $this->currentPositionDetails = null;
        $this->logger->debug("Trade-specific state variables have been reset.");
    }

    /**
     * Attempts to open a new position based on AI parameters.
     * @return void
     */
    private function attemptOpenPosition(): void
    {
        $this->setLeverage($this->tradingSymbol, $this->aiSuggestedLeverage)
            ->then(fn() => $this->placeFuturesLimitOrder($this->tradingSymbol, $this->aiSuggestedSide, $this->aiSuggestedQuantity, $this->aiSuggestedEntryPrice))
            ->then(
                function ($orderData) {
                    $this->activeEntryOrderId = (string)$orderData['orderId'];
                    $this->activeEntryOrderTimestamp = time();
                    $this->transitionToState(self::STATE_ORDER_PENDING, ['orderId' => $this->activeEntryOrderId]);
                },
                function (\Throwable $e) {
                    $this->logger->error('Failed to place entry order.', ['exception' => $e->getMessage()]);
                    $this->transitionToState(self::STATE_IDLE, ['reason' => 'Entry order placement failed']);
                }
            );
    }

    /**
     * Attempts to close the current position at market price.
     * @param bool $isEmergency Force close, bypassing some state checks.
     * @return void
     */
    private function attemptClosePositionByAI(bool $isEmergency = false): void
    {
        if (!$this->currentPositionDetails && !$isEmergency) {
            $this->transitionToState(self::STATE_IDLE, ['reason' => 'Attempted to close non-existent position.']);
            return;
        }
        $this->transitionToState(self::STATE_CLOSING, ['reason' => 'AI/Bot decision to close']);

        $this->cancelAllOpenOrdersForSymbol()->finally(function () {
            $this->getPositionInformation($this->tradingSymbol)->then(function ($posData) {
                $refreshedPosition = $this->formatPositionDetails($posData);
                if ($refreshedPosition) {
                    $closeSide = $refreshedPosition['side'] === 'LONG' ? 'SELL' : 'BUY';
                    return $this->placeFuturesMarketOrder($this->tradingSymbol, $closeSide, $refreshedPosition['quantity'], true);
                }
                return \React\Promise\resolve(null);
            })->then(
                function ($closeOrderData) {
                    // The onOrderTradeUpdate event will ultimately trigger handlePositionClosed
                },
                function (\Throwable $e) {
                    $this->logger->critical("Failed to place market close order: " . $e->getMessage() . ". Retrying AI update cycle.");
                    $this->transitionToState(self::STATE_POSITION_UNPROTECTED, ['reason' => 'Market close failed']);
                    $this->triggerAIUpdate(true);
                }
            );
        });
    }

    /**
     * Cancels a single order and logs the action.
     * @return PromiseInterface
     */
    private function cancelOrderAndLog(string $orderId, string $reasonForCancel): PromiseInterface
    {
        return $this->cancelFuturesOrder($this->tradingSymbol, $orderId)->then(
            function ($data) use ($orderId, $reasonForCancel) {
                $this->logger->info("Successfully cancelled order: {$orderId} ({$reasonForCancel}).");
            },
            function (\Throwable $e) use ($orderId, $reasonForCancel) {
                if (str_contains($e->getMessage(), '-2011')) { // "Unknown order sent."
                    $this->logger->info("Attempt to cancel order {$orderId} ({$reasonForCancel}) failed, it was likely already filled or cancelled.");
                } else {
                    $this->logger->error("Failed to cancel order: {$orderId} ({$reasonForCancel}).", ['err' => $e->getMessage()]);
                }
            }
        );
    }

    /**
     * A utility to cancel all open orders for the current symbol.
     * @return PromiseInterface
     */
    private function cancelAllOpenOrdersForSymbol(): PromiseInterface
    {
        $promises = [];
        if ($this->activeSlOrderId) {
            $promises[] = $this->cancelOrderAndLog($this->activeSlOrderId, "general cleanup");
            $this->activeSlOrderId = null;
        }
        if ($this->activeTpOrderId) {
            $promises[] = $this->cancelOrderAndLog($this->activeTpOrderId, "general cleanup");
            $this->activeTpOrderId = null;
        }
        if ($this->activeEntryOrderId) {
            $promises[] = $this->cancelOrderAndLog($this->activeEntryOrderId, "general cleanup");
            $this->activeEntryOrderId = null;
        }
        return \React\Promise\all($promises);
    }

    /**
     * Periodically checks if the profit target has been met.
     * @return void
     */
    private function checkProfitTarget(): void
    {
        if ($this->takeProfitTargetUsdt <= 0 || $this->botState !== self::STATE_POSITION_ACTIVE) {
            return;
        }
        if ($this->currentPositionDetails) {
            $currentPnl = (float)($this->currentPositionDetails['unrealizedPnl'] ?? 0.0);
            if ($currentPnl >= $this->takeProfitTargetUsdt) {
                $this->logger->info("Profit target reached!", ['target' => $this->takeProfitTargetUsdt, 'current_pnl' => $currentPnl]);
                $this->attemptClosePositionByAI();
            }
        }
    }

    /**
     * A wrapper function that logs order outcomes to the console and DB.
     * @return void
     */
    private function addOrderToLog(string $orderId, string $status, string $side, string $assetPair, ?float $price, ?float $quantity, ?string $marginAsset, int $timestamp, ?float $realizedPnl, ?float $commissionUsdt = 0.0, bool $reduceOnly = false): void
    {
        $logEntry = compact('orderId', 'status', 'side', 'assetPair', 'price', 'quantity', 'marginAsset', 'timestamp', 'realizedPnl', 'commissionUsdt', 'reduceOnly');
        $this->logger->info('Trade/Order outcome logged:', $logEntry);
        $this->logOrderToDb($orderId, $status, $side, $assetPair, $price, $quantity, $marginAsset, $timestamp, $realizedPnl, $commissionUsdt, $reduceOnly);
    }

    /**
     * Formats position details from a WebSocket event.
     * @return array|null
     */
    private function formatPositionDetailsFromEvent(?array $posData): ?array
    {
        if (empty($posData) || $posData['s'] !== $this->tradingSymbol) {
            return null;
        }
        $quantityVal = (float)($posData['pa'] ?? 0);
        if (abs($quantityVal) < 1e-9) {
            return null;
        }

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

    /**
     * Formats position details from a REST API response.
     * @return array|null
     */
    private function formatPositionDetails(?array $positionsInput): ?array
    {
        if (empty($positionsInput)) {
            return null;
        }
        $positionData = null;
        if (!isset($positionsInput[0]) && isset($positionsInput['symbol'])) { // Single object
            if ($positionsInput['symbol'] === $this->tradingSymbol) {
                $positionData = $positionsInput;
            }
        } else { // Array
            foreach ($positionsInput as $p) {
                if (($p['symbol'] ?? '') === $this->tradingSymbol && abs((float)($p['positionAmt'] ?? 0)) > 1e-9) {
                    $positionData = $p;
                    break;
                }
            }
        }
        if (!$positionData) {
            return null;
        }

        $quantityVal = (float)($positionData['positionAmt'] ?? 0);
        if (abs($quantityVal) < 1e-9) {
            return null;
        }

        return [
            'symbol' => $this->tradingSymbol, 'side' => $quantityVal > 0 ? 'LONG' : 'SHORT',
            'entryPrice' => (float)($positionData['entryPrice'] ?? 0), 'quantity' => abs($quantityVal),
            'leverage' => (int)($positionData['leverage'] ?? $this->defaultLeverage),
            'markPrice' => (float)($positionData['markPrice'] ?? $this->lastClosedKlinePrice),
            'unrealizedPnl' => (float)($positionData['unRealizedProfit'] ?? 0),
            'initialMargin' => (float)($positionData['initialMargin'] ?? $positionData['isolatedMargin'] ?? 0),
            'maintMargin' => (float)($positionData['maintMargin'] ?? 0),
            'positionSideBinance' => $positionData['positionSide'] ?? 'BOTH',
            'activeSlOrderId' => $this->activeSlOrderId, 'activeTpOrderId' => $this->activeTpOrderId,
        ];
    }

    // =================================================================================
    // --- AI Service Component ---
    // =================================================================================

    /**
     * Triggers the full AI decision-making cycle.
     * @param bool $isEmergency Whether to trigger an emergency update.
     * @return void
     */
    public function triggerAIUpdate(bool $isEmergency = false): void
    {
        if ($this->botState === self::STATE_EVALUATING && !$isEmergency) {
            return;
        }

        if ($isEmergency) {
            $this->logger->warning('*** EMERGENCY AI UPDATE TRIGGERED ***');
        }

        $this->transitionToState(self::STATE_EVALUATING, ['emergency' => $isEmergency]);
        
        $this->currentDataForAIForDBLog = null;
        $this->currentPromptMD5ForDBLog = null;
        $this->currentRawAIResponseForDBLog = null;
        $this->loadActiveTradeLogicSource();

        $this->collectDataForAI($isEmergency)
            ->then(function (array $data) {
                $this->currentDataForAIForDBLog = $data;
                $prompt = $this->constructAIPrompt($data);
                $this->currentPromptMD5ForDBLog = md5($prompt);
                return $this->sendRequestToAI($prompt);
             })
            ->then(function (string $response) {
                $this->currentRawAIResponseForDBLog = $response;
                $this->processAIResponse($response);
            })
            ->catch(function (\Throwable $e) {
                $this->logger->error('AI update cycle failed.', ['exception' => $e->getMessage()]);
                $this->lastAIDecisionResult = ['status' => 'ERROR_CYCLE', 'message' => "AI cycle failed: " . $e->getMessage()];
                $this->logAIInteractionToDb('ERROR_CYCLE', null, $this->lastAIDecisionResult, $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog);
                
                $previousState = $this->currentPositionDetails ? self::STATE_POSITION_UNPROTECTED : self::STATE_IDLE;
                $this->transitionToState($previousState, ['reason' => 'AI cycle failed']);
            });
    }

    /**
     * Gathers all necessary data for the AI prompt.
     * @param bool $isEmergency Indicates if this is an emergency data collection.
     * @return PromiseInterface
     */
    private function collectDataForAI(bool $isEmergency = false): PromiseInterface
    {
        $promises = [
            'balance' => $this->getFuturesAccountBalance()->otherwise(fn() => ['error' => 'failed']),
            'position' => $this->getPositionInformation($this->tradingSymbol)->otherwise(fn() => null),
            'trade_history' => $this->getFuturesTradeHistory($this->tradingSymbol, 20)->otherwise(fn() => []),
            'commission_rates' => $this->getFuturesCommissionRate($this->tradingSymbol)->otherwise(fn() => []),
        ];

        $multiTfKlinePromises = [];
        foreach ($this->historicalKlineIntervalsAIArray as $interval) {
            $multiTfKlinePromises[$interval] = $this->getHistoricalKlines($this->tradingSymbol, $interval, 20)->otherwise(fn() => []);
        }
        $promises['historical_klines'] = \React\Promise\all($multiTfKlinePromises);

        return \React\Promise\all($promises)->then(function (array $results) use ($isEmergency) {
            $this->currentPositionDetails = $this->formatPositionDetails($results['position']);

            $activeEntryOrderDetails = null;
            if ($this->activeEntryOrderId && $this->activeEntryOrderTimestamp) {
                $activeEntryOrderDetails = ['orderId' => $this->activeEntryOrderId, 'seconds_pending' => time() - $this->activeEntryOrderTimestamp];
            }

            return [
                'bot_metadata' => [
                    'current_timestamp_iso_utc' => gmdate('Y-m-d H:i:s'), 'trading_symbol' => $this->tradingSymbol,
                    'is_emergency_update_request' => $isEmergency, 'bot_id' => $this->botConfigId
                ],
                'market_data' => [
                    'current_market_price' => $this->lastClosedKlinePrice,
                    'symbol_precision' => ['price_tick_size' => $this->exchangeInfo[$this->tradingSymbol]['tickSize'] ?? '0.0', 'quantity_step_size' => $this->exchangeInfo[$this->tradingSymbol]['stepSize'] ?? '0.0'],
                    'historical_klines_multi_tf' => $results['historical_klines'], 'commission_rates' => $results['commission_rates']
                ],
                'account_state' => ['balance_details' => $results['balance'], 'current_position_details' => $this->currentPositionDetails, 'recent_account_trades' => $results['trade_history']],
                'bot_operational_state' => [
                    'current_bot_state' => $this->botState, 'active_pending_entry_order_details' => $activeEntryOrderDetails,
                    'active_sl_order_id' => $this->activeSlOrderId, 'active_tp_order_id' => $this->activeTpOrderId,
                ],
                'historical_bot_performance_and_decisions' => [
                    'last_ai_decision_bot_feedback' => $this->lastAIDecisionResult,
                    'recent_bot_order_log_outcomes' => $this->getRecentOrderLogsFromDb(self::MAX_ORDER_LOG_ENTRIES_FOR_AI_CONTEXT),
                    'recent_ai_interactions' => $this->getRecentAIInteractionsFromDb(self::MAX_AI_INTERACTIONS_FOR_AI_CONTEXT)
                ],
                'current_guiding_trade_logic_source' => json_decode($this->currentActiveTradeLogicSource['strategy_directives_json'] ?? 'null', true),
                'bot_configuration_summary_for_ai' => ['initialMarginTargetUsdt' => $this->initialMarginTargetUsdt, 'defaultLeverage' => $this->defaultLeverage]
            ];
        });
    }

    /**
     * Constructs the JSON prompt to be sent to the Gemini AI.
     * @param array $fullDataForAI The complete data context.
     * @return string The JSON-encoded prompt payload.
     */
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

    /**
     * Sends the prepared payload to the Gemini AI API.
     * @param string $jsonPayload The JSON payload for the AI.
     * @return PromiseInterface
     */
    private function sendRequestToAI(string $jsonPayload): PromiseInterface
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModelName . ':generateContent?key=' . $this->geminiApiKey;
        $headers = ['Content-Type' => 'application/json'];
        return $this->browser->post($url, $headers, $jsonPayload)->then(
            function (ResponseInterface $response) {
                return (string)$response->getBody();
            }
        );
    }

    /**
     * Parses the AI's raw response and dispatches it for execution.
     * @param string $rawResponse The raw JSON string from the AI.
     * @return void
     */
    private function processAIResponse(string $rawResponse): void
    {
        try {
            $responseDecoded = json_decode($rawResponse, true);
            if (isset($responseDecoded['promptFeedback']['blockReason'])) {
                throw new \InvalidArgumentException("AI prompt blocked: " . $responseDecoded['promptFeedback']['blockReason']);
            }
            if (!isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \InvalidArgumentException("AI response missing text content.");
            }
            $aiTextResponse = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];
            $aiDecisionParams = json_decode($aiTextResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Failed to decode JSON from AI text: " . json_last_error_msg());
            }
            $this->executeAIDecision($aiDecisionParams);
        } catch (\Throwable $e) {
            $this->logger->error('Error processing AI response.', ['exception' => $e->getMessage()]);
            $this->lastAIDecisionResult = ['status' => 'ERROR_PROCESSING', 'message' => "Failed processing AI response: " . $e->getMessage()];
            $this->logAIInteractionToDb('ERROR_PROCESSING_AI_RESPONSE', null, $this->lastAIDecisionResult, $this->currentDataForAIForDBLog, $this->currentPromptMD5ForDBLog, $this->currentRawAIResponseForDBLog);

            $previousState = $this->currentPositionDetails ? self::STATE_POSITION_UNPROTECTED : self::STATE_IDLE;
            $this->transitionToState($previousState, ['reason' => 'AI response processing failed']);
        }
    }

    /**
     * Central dispatcher for executing an AI decision based on the current bot state.
     *
     * @param array $decision The validated decision parameters from the AI.
     * @return void
     */
    private function executeAIDecision(array $decision): void
    {
        $originalAction = strtoupper($decision['action'] ?? 'UNKNOWN');
        $executedAction = $originalAction; // Assume no override initially
        $overrideReason = null;
        
        $stateBeforeEvaluation = ($this->botState === self::STATE_EVALUATING) ? ($this->currentPositionDetails ? self::STATE_POSITION_ACTIVE : self::STATE_IDLE) : $this->botState;
        
        switch ($stateBeforeEvaluation) {
            case self::STATE_IDLE:
                if ($originalAction !== 'OPEN_POSITION') {
                    $executedAction = 'DO_NOTHING';
                    $overrideReason = "AI suggested {$originalAction} in IDLE state. Bot will do nothing.";
                }
                break;
            case self::STATE_POSITION_ACTIVE:
            case self::STATE_POSITION_UNPROTECTED:
                if ($originalAction === 'OPEN_POSITION') {
                    $executedAction = 'HOLD_POSITION';
                    $overrideReason = "AI suggested OPEN_POSITION while a position exists. Bot will hold.";
                }
                break;
        }

        if ($this->botState === self::STATE_POSITION_UNPROTECTED && $executedAction !== 'CLOSE_POSITION') {
             $executedAction = 'CLOSE_POSITION';
             $overrideReason = "Bot is in UNPROTECTED state. Overriding AI action to CLOSE_POSITION for safety.";
        }
        
        if ($overrideReason) {
             $this->logger->warning($overrideReason, ['original_action' => $originalAction, 'executed_action' => $executedAction]);
        }
        
        $this->lastAIDecisionResult = ['original' => $originalAction, 'executed' => $executedAction, 'override_reason' => $overrideReason];
        
        switch ($executedAction) {
            case 'OPEN_POSITION':
                if ($this->validateOpenPositionParams($decision)) {
                    $this->attemptOpenPosition();
                } else {
                    $this->logger->error("AI OPEN_POSITION parameters failed validation.");
                    $this->transitionToState(self::STATE_IDLE, ['reason' => 'Invalid AI parameters']);
                }
                break;
            case 'CLOSE_POSITION':
                $this->attemptClosePositionByAI();
                break;
            case 'HOLD_POSITION':
            case 'DO_NOTHING':
                $targetState = $this->currentPositionDetails ? self::STATE_POSITION_ACTIVE : self::STATE_IDLE;
                $this->transitionToState($targetState, ['reason' => 'AI decided to wait']);
                break;
        }

        // --- FIX: Restore the logging call ---
        $this->logAIInteractionToDb(
            $executedAction . ($overrideReason ? '_BOT_OVERRIDE' : '_AI_DIRECT'),
            $decision,
            $this->lastAIDecisionResult,
            $this->currentDataForAIForDBLog,
            $this->currentPromptMD5ForDBLog,
            $this->currentRawAIResponseForDBLog
        );
    }

    /**
     * Performs strict validation on all parameters required to open a position.
     * @param array $params The decision parameters from the AI.
     * @return bool True if valid, false otherwise.
     */
    private function validateOpenPositionParams(array $params): bool
    {
        $this->aiSuggestedSide = strtoupper($params['side'] ?? '');
        $this->aiSuggestedEntryPrice = (float)($params['entryPrice'] ?? 0);
        $this->aiSuggestedSlPrice = (float)($params['stopLossPrice'] ?? 0);
        $this->aiSuggestedTpPrice = (float)($params['takeProfitPrice'] ?? 0);
        $this->aiSuggestedQuantity = (float)($params['quantity'] ?? 0);
        $this->aiSuggestedLeverage = (int)($params['leverage'] ?? 0);

        if (!in_array($this->aiSuggestedSide, ['BUY', 'SELL'])) {
            return false;
        }
        if ($this->aiSuggestedEntryPrice <= 0 || $this->aiSuggestedSlPrice <= 0 || $this->aiSuggestedTpPrice <= 0 || $this->aiSuggestedQuantity <= 0 || $this->aiSuggestedLeverage <= 0) {
            return false;
        }

        if ($this->aiSuggestedSide === 'BUY' && ($this->aiSuggestedSlPrice >= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice <= $this->aiSuggestedEntryPrice)) {
            return false;
        }
        if ($this->aiSuggestedSide === 'SELL' && ($this->aiSuggestedSlPrice <= $this->aiSuggestedEntryPrice || $this->aiSuggestedTpPrice >= $this->aiSuggestedEntryPrice)) {
            return false;
        }

        return true;
    }

    // =================================================================================
    // --- Binance API Component ---
    // =================================================================================

    /**
     * Creates a signed request data array for authenticated Binance API calls.
     * @return array
     */
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

    /**
     * Converts an amount of a given asset to its USDT equivalent.
     * @return PromiseInterface
     */
    private function getUsdtEquivalent(string $asset, float $amount): PromiseInterface
    {
        if (strtoupper($asset) === 'USDT' || empty($asset)) {
            return \React\Promise\resolve($amount);
        }
        $symbol = strtoupper($asset) . 'USDT';
        return $this->getLatestKlineClosePrice($symbol, '1m')
            ->then(function ($klineData) use ($amount) {
                $price = (float)($klineData['price'] ?? 0);
                return $price > 0 ? $amount * $price : 0.0;
            })
            ->otherwise(fn() => 0.0);
    }

    /**
     * Makes a generic asynchronous API request.
     * @return PromiseInterface
     */
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
            function (ResponseInterface $response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("JSON decode error: " . json_last_error_msg());
                }
                if (isset($data['code']) && (int)$data['code'] < 0) {
                    throw new \RuntimeException("Binance API error ({$data['code']}): " . ($data['msg'] ?? 'Unknown error'), (int)$data['code']);
                }
                return $data;
            }
        );
    }

    /**
     * Formats a price to conform to the symbol's tick size.
     * @return string
     */
    private function formatPriceByTickSize(string $symbol, float $price): string
    {
        $symbolInfo = $this->exchangeInfo[strtoupper($symbol)] ?? null;
        if (!$symbolInfo || !isset($symbolInfo['tickSize']) || (float)$symbolInfo['tickSize'] == 0) {
            return rtrim(rtrim(sprintf('%.8f', $price), '0'), '.');
        }
        $tickSize = (float)$symbolInfo['tickSize'];
        $roundedPrice = floor($price / $tickSize) * $tickSize;
        $decimals = strlen(substr(strrchr((string)$tickSize, "."), 1));
        return number_format($roundedPrice, $decimals, '.', '');
    }

    /**
     * Formats a quantity to conform to the symbol's step size.
     * @return string
     */
    private function formatQuantityByStepSize(string $symbol, float $quantity): string
    {
        $symbolInfo = $this->exchangeInfo[strtoupper($symbol)] ?? null;
        if (!$symbolInfo || !isset($symbolInfo['stepSize']) || (float)$symbolInfo['stepSize'] == 0) {
            return rtrim(rtrim(sprintf('%.8f', $quantity), '0'), '.');
        }
        $stepSize = (float)$symbolInfo['stepSize'];
        $roundedQuantity = floor($quantity / $stepSize) * $stepSize;
        $decimals = strlen(substr(strrchr((string)$stepSize, "."), 1));
        return number_format($roundedQuantity, $decimals, '.', '');
    }

    /**
     * Fetches and caches exchange information (precisions, filters).
     * @return PromiseInterface
     */
    private function fetchExchangeInfo(): PromiseInterface
    {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/exchangeInfo';
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) {
                $exchangeInfo = [];
                foreach ($data['symbols'] as $symbolInfo) {
                    $symbol = $symbolInfo['symbol'];
                    $exchangeInfo[$symbol] = ['pricePrecision' => (int)$symbolInfo['pricePrecision'], 'quantityPrecision' => (int)$symbolInfo['quantityPrecision'], 'tickSize' => '0.0', 'stepSize' => '0.0'];
                    foreach ($symbolInfo['filters'] as $filter) {
                        if ($filter['filterType'] === 'PRICE_FILTER') {
                            $exchangeInfo[$symbol]['tickSize'] = $filter['tickSize'];
                        } elseif ($filter['filterType'] === 'LOT_SIZE') {
                            $exchangeInfo[$symbol]['stepSize'] = $filter['stepSize'];
                        }
                    }
                }
                return $exchangeInfo;
            });
    }

    /**
     * Retrieves the user's futures account balance.
     * @return PromiseInterface
     */
    private function getFuturesAccountBalance(): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v2/balance', [], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                $balances = [];
                foreach ($data as $assetInfo) {
                    $balances[strtoupper($assetInfo['asset'])] = ['balance' => (float)$assetInfo['balance'], 'availableBalance' => (float)$assetInfo['availableBalance']];
                }
                return $balances;
            });
    }

    /**
     * Fetches the latest closed Kline price.
     * @return PromiseInterface
     */
    private function getLatestKlineClosePrice(string $symbol, string $interval): PromiseInterface
    {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/klines?' . http_build_query(['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => 1]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) {
                if (!isset($data[0][4])) {
                    throw new \RuntimeException("Invalid klines response format.");
                }
                return ['price' => (float)$data[0][4], 'timestamp' => (int)$data[0][0]];
            });
    }

    /**
     * Fetches historical Kline data.
     * @return PromiseInterface
     */
    private function getHistoricalKlines(string $symbol, string $interval, int $limit = 100): PromiseInterface
    {
        $url = $this->currentRestApiBaseUrl . '/fapi/v1/klines?' . http_build_query(['symbol' => strtoupper($symbol), 'interval' => $interval, 'limit' => $limit]);
        return $this->makeAsyncApiRequest('GET', $url, [], null, true)
            ->then(function ($data) {
                return array_map(fn($k) => ['openTime' => (int)$k[0], 'open' => (string)$k[1], 'high' => (string)$k[2], 'low' => (string)$k[3], 'close' => (string)$k[4], 'volume' => (string)$k[5]], $data);
            });
    }

    /**
     * Retrieves detailed information about the user's current open position.
     * @return PromiseInterface
     */
    private function getPositionInformation(string $symbol): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v2/positionRisk', ['symbol' => strtoupper($symbol)], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers'])
            ->then(function ($data) {
                foreach ($data as $pos) {
                    if (isset($pos['symbol']) && $pos['symbol'] === strtoupper($this->tradingSymbol) && abs((float)$pos['positionAmt']) > 1e-9) {
                        return $pos;
                    }
                }
                return null;
            });
    }

    /**
     * Sets the leverage for a given trading symbol.
     * @return PromiseInterface
     */
    private function setLeverage(string $symbol, int $leverage): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/leverage', ['symbol' => strtoupper($symbol), 'leverage' => $leverage], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Retrieves the commission rates for a trading symbol.
     * @return PromiseInterface
     */
    private function getFuturesCommissionRate(string $symbol): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/commissionRate', ['symbol' => strtoupper($symbol)], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    /**
     * Places a LIMIT order on Binance Futures.
     * @return PromiseInterface
     */
    private function placeFuturesLimitOrder(string $symbol, string $side, float $quantity, float $price): PromiseInterface
    {
        $params = ['symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => 'BOTH', 'type' => 'LIMIT', 'quantity' => $this->formatQuantityByStepSize($symbol, $quantity), 'price' => $this->formatPriceByTickSize($symbol, $price), 'timeInForce' => 'GTC'];
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Places a MARKET order on Binance Futures.
     * @return PromiseInterface
     */
    private function placeFuturesMarketOrder(string $symbol, string $side, float $quantity, bool $reduceOnly = false): PromiseInterface
    {
        $params = ['symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => 'BOTH', 'type' => 'MARKET', 'quantity' => $this->formatQuantityByStepSize($symbol, $quantity)];
        if ($reduceOnly) {
            $params['reduceOnly'] = 'true';
        }
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Places a STOP_MARKET order (used for Stop Loss).
     * @return PromiseInterface
     */
    private function placeFuturesStopMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice): PromiseInterface
    {
        $params = ['symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => 'BOTH', 'type' => 'STOP_MARKET', 'quantity' => $this->formatQuantityByStepSize($symbol, $quantity), 'stopPrice' => $this->formatPriceByTickSize($symbol, $stopPrice), 'reduceOnly' => 'true', 'workingType' => 'MARK_PRICE'];
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Places a TAKE_PROFIT_MARKET order (used for Take Profit).
     * @return PromiseInterface
     */
    private function placeFuturesTakeProfitMarketOrder(string $symbol, string $side, float $quantity, float $stopPrice): PromiseInterface
    {
        $params = ['symbol' => strtoupper($symbol), 'side' => strtoupper($side), 'positionSide' => 'BOTH', 'type' => 'TAKE_PROFIT_MARKET', 'quantity' => $this->formatQuantityByStepSize($symbol, $quantity), 'stopPrice' => $this->formatPriceByTickSize($symbol, $stopPrice), 'reduceOnly' => 'true', 'workingType' => 'MARK_PRICE'];
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', $params, 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Cancels a specific order on Binance Futures.
     * @return PromiseInterface
     */
    private function cancelFuturesOrder(string $symbol, string $orderId): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/order', ['symbol' => strtoupper($symbol), 'orderId' => $orderId], 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Retrieves recent trade history for a given symbol.
     * @return PromiseInterface
     */
    private function getFuturesTradeHistory(string $symbol, int $limit = 10): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/userTrades', ['symbol' => strtoupper($symbol), 'limit' => $limit], 'GET');
        return $this->makeAsyncApiRequest('GET', $signedRequestData['url'], $signedRequestData['headers']);
    }

    /**
     * Starts a new user data stream and returns the listen key.
     * @return PromiseInterface
     */
    private function startUserDataStream(): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', [], 'POST');
        return $this->makeAsyncApiRequest('POST', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Keeps a user data stream alive.
     * @return PromiseInterface
     */
    private function keepAliveUserDataStream(string $listenKey): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', ['listenKey' => $listenKey], 'PUT');
        return $this->makeAsyncApiRequest('PUT', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
    }

    /**
     * Closes a user data stream.
     * @return PromiseInterface
     */
    private function closeUserDataStream(string $listenKey): PromiseInterface
    {
        $signedRequestData = $this->createSignedRequestData('/fapi/v1/listenKey', ['listenKey' => $listenKey], 'DELETE');
        return $this->makeAsyncApiRequest('DELETE', $signedRequestData['url'], $signedRequestData['headers'], $signedRequestData['postData']);
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
    $errorMessage = "FATAL_ERROR for config ID {$botConfigId}: " . $e->getMessage() . " in " . basename($e->getFile()) . " on line " . $e->getLine();
    error_log($errorMessage);

    try {
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare("
            UPDATE bot_runtime_status SET status = 'error', last_heartbeat = NOW(), error_message = :error_message, process_id = NULL 
            WHERE bot_config_id = :bot_config_id
        ");
        $dbErrorMessage = substr($errorMessage . "\n" . $e->getTraceAsString(), 0, 65535);
        $stmt->execute([':error_message' => $dbErrorMessage, ':bot_config_id' => $botConfigId]);
        error_log("Bot runtime status updated to 'error' in DB for config ID {$botConfigId}.");
    } catch (\PDOException $db_e) {
        error_log("CRITICAL: Could not connect to DB to log the bot's fatal error: " . $db_e->getMessage());
    }
    exit(1);
}
