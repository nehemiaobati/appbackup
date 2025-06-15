-- P2Profit - User-Based Trading Bot Database Schema
-- Version: 2.0
-- A production-ready, relational schema for a multi-user trading bot system.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!400101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `server_new`
--

-- --------------------------------------------------------

--
-- Table: `ai_interactions_log`
-- Description: Logs all interactions and decisions made by the AI for each bot configuration.
-- Key Columns:
--   - `id`: Unique identifier for each AI interaction log entry.
--   - `user_id`: Links to the user who owns the bot.
--   - `bot_config_id`: Links to the specific bot configuration.
--   - `log_timestamp_utc`: Timestamp of the AI interaction.
--   - `trading_symbol`: The trading pair the AI interacted with.
--   - `executed_action_by_bot`: The action taken by the bot based on AI's decision.
--   - `ai_decision_params_json`: JSON blob of parameters used in AI's decision.
--   - `raw_ai_response_json`: Raw JSON response from the AI.
--
CREATE TABLE `ai_interactions_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `log_timestamp_utc` datetime NOT NULL,
  `trading_symbol` varchar(20) NOT NULL,
  `executed_action_by_bot` varchar(100) NOT NULL,
  `ai_decision_params_json` text DEFAULT NULL,
  `bot_feedback_json` text DEFAULT NULL,
  `full_data_for_ai_json` mediumtext DEFAULT NULL,
  `prompt_text_sent_to_ai_md5` char(32) DEFAULT NULL,
  `raw_ai_response_json` text DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `bot_configurations`
-- Description: Stores the configuration settings for each trading bot created by users.
-- Key Columns:
--   - `id`: Unique identifier for each bot configuration.
--   - `user_id`: Links to the user who owns this configuration.
--   - `user_api_key_id`: Links to the API keys used by this bot.
--   - `name`: User-defined name for the bot.
--   - `symbol`: The primary trading symbol (e.g., BTCUSDT).
--   - `is_active`: Indicates if the bot configuration is currently active.
--
CREATE TABLE `bot_configurations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_api_key_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(20) NOT NULL DEFAULT 'BTCUSDT',
  `kline_interval` varchar(10) NOT NULL DEFAULT '1m',
  `margin_asset` varchar(10) NOT NULL DEFAULT 'USDT',
  `default_leverage` int(11) NOT NULL DEFAULT 10,
  `order_check_interval_seconds` int(11) NOT NULL DEFAULT 45,
  `ai_update_interval_seconds` int(11) NOT NULL DEFAULT 60,
  `use_testnet` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 for testnet, 0 for mainnet',
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT 10.50000000,
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `pending_entry_order_cancel_timeout_seconds` int(11) NOT NULL DEFAULT 180,
  `profit_check_interval_seconds` int(11) NOT NULL DEFAULT 60,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `bot_runtime_status`
-- Description: Stores the current runtime status and operational details of each bot.
-- Key Columns:
--   - `id`: Unique identifier for the runtime status entry.
--   - `bot_config_id`: Links to the bot configuration this status belongs to.
--   - `status`: Current operational status of the bot (e.g., 'running', 'stopped', 'error').
--   - `last_heartbeat`: Timestamp of the last known activity from the bot process.
--   - `error_message`: Details of any errors encountered by the bot.
--   - `current_position_details_json`: JSON blob of current open positions.
--
CREATE TABLE `bot_runtime_status` (
  `id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL COMMENT 'Current operational status (e.g., running, stopped, error)',
  `last_heartbeat` datetime DEFAULT NULL,
  `process_id` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `orders_log`
-- Description: Records all trading orders executed or attempted by the bots.
-- Key Columns:
--   - `internal_id`: Unique internal identifier for each order log entry.
--   - `user_id`: Links to the user who initiated the order.
--   - `bot_config_id`: Links to the bot configuration that placed the order.
--   - `order_id_binance`: The order ID provided by the exchange (e.g., Binance).
--   - `symbol`: The trading pair of the order.
--   - `side`: The order side (e.g., 'BUY', 'SELL').
--   - `status_reason`: The status or reason for the order entry.
--   - `realized_pnl_usdt`: Profit/Loss in USDT for closed orders.
--
CREATE TABLE `orders_log` (
  `internal_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `order_id_binance` varchar(50) DEFAULT NULL,
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `side` varchar(10) NOT NULL COMMENT 'Order side (e.g., BUY, SELL)',
  `status_reason` varchar(100) NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `commission_usdt` decimal(20,8) DEFAULT NULL,
  `reduce_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if order reduces position, 0 otherwise',
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `trade_logic_source`
-- Description: Stores different AI trading strategies or logic sources that bots can use.
-- Key Columns:
--   - `id`: Unique identifier for each trade logic source.
--   - `user_id`: Links to the user who created/owns this logic.
--   - `source_name`: A descriptive name for the trading logic.
--   - `is_active`: Indicates if this logic source is currently active and usable.
--   - `strategy_directives_json`: JSON blob containing the detailed strategy directives for the AI.
--
CREATE TABLE `trade_logic_source` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if the trade logic is active, 0 otherwise',
  `version` int(11) NOT NULL DEFAULT 1,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text NOT NULL,
  `full_data_snapshot_at_last_update_json` mediumtext DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `users`
-- Description: Stores user account information for the trading bot platform.
-- Key Columns:
--   - `id`: Unique identifier for each user.
--   - `username`: Unique username for login.
--   - `password_hash`: Hashed password for security.
--   - `email`: User's email address.
--   - `role`: User's role (e.g., 'admin', 'user').
--
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user' COMMENT 'e.g., admin, user',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table: `user_api_keys`
-- Description: Stores encrypted API keys for various exchanges (e.g., Binance, Gemini) for each user.
-- Key Columns:
--   - `id`: Unique identifier for each API key entry.
--   - `user_id`: Links to the user who owns these API keys.
--   - `key_name`: A user-friendly name for the API key set.
--   - `binance_api_key_encrypted`: Encrypted Binance API key.
--   - `binance_api_secret_encrypted`: Encrypted Binance API secret.
--   - `gemini_api_key_encrypted`: Encrypted Gemini API key.
--   - `is_active`: Indicates if the API key set is currently active.
--
CREATE TABLE `user_api_keys` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL COMMENT 'e.g., "My Main Binance Key", "Testnet Key"',
  `binance_api_key_encrypted` text NOT NULL,
  `binance_api_secret_encrypted` text NOT NULL,
  `gemini_api_key_encrypted` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 if the API key is active, 0 otherwise',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_interactions_log`
--
ALTER TABLE `ai_interactions_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_bot_config_id` (`bot_config_id`),
  ADD KEY `idx_symbol_timestamp_action` (`trading_symbol`,`log_timestamp_utc`);

--
-- Indexes for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_config_name` (`user_id`,`name`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_user_api_key_id` (`user_api_key_id`);

--
-- Indexes for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bot_config_id` (`bot_config_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `orders_log`
--
ALTER TABLE `orders_log`
  ADD PRIMARY KEY (`internal_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_bot_config_id` (`bot_config_id`),
  ADD KEY `idx_symbol_timestamp` (`symbol`,`bot_event_timestamp_utc`),
  ADD KEY `idx_order_id_binance` (`order_id_binance`);

--
-- Indexes for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_source_name` (`user_id`,`source_name`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_api_keys`
--
ALTER TABLE `user_api_keys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_interactions_log`
--
ALTER TABLE `ai_interactions_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders_log`
--
ALTER TABLE `orders_log`
  MODIFY `internal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_api_keys`
--
ALTER TABLE `user_api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_interactions_log`
--
ALTER TABLE `ai_interactions_log`
  ADD CONSTRAINT `fk_ai_log_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ai_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  ADD CONSTRAINT `fk_config_api_key` FOREIGN KEY (`user_api_key_id`) REFERENCES `user_api_keys` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_config_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  ADD CONSTRAINT `fk_status_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders_log`
--
ALTER TABLE `orders_log`
  ADD CONSTRAINT `fk_order_log_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_order_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  ADD CONSTRAINT `fk_trade_logic_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_api_keys`
--
ALTER TABLE `user_api_keys`
  ADD CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- SAMPLE DATA INSERTION
-- --------------------------------------------------------

-- Insert a default user
INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `email`, `role`) VALUES
(1, 'testuser', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/lK.', 'test@example.com', 'user');

-- Insert sample (dummy) encrypted API keys for the test user.
-- IN A REAL SCENARIO, a dashboard would handle the encryption and insertion.
-- These values are just placeholders and will not decrypt without the correct APP_ENCRYPTION_KEY.
INSERT IGNORE INTO `user_api_keys` (`id`, `user_id`, `key_name`, `binance_api_key_encrypted`, `binance_api_secret_encrypted`, `gemini_api_key_encrypted`, `is_active`) VALUES
(1, 1, 'My Default Keys', 'U2FsdGVkX1+vGvG6t8Rj0b0v9kZ/n8Nn7hUa3wQpY4s=', 'U2FsdGVkX1/aBcDeFgHiJkLmNoPqRsTuVwXyZ1a2b3c=', 'U2FsdGVkX1/sOmIsTrUeWgHjIkLmNoPqRsTuVwXyZ1a2b3c=', 1);

-- Insert a default bot configuration for the test user, linking to their API keys.
INSERT IGNORE INTO `bot_configurations` (`id`, `user_id`, `user_api_key_id`, `name`, `symbol`, `is_active`) VALUES
(1, 1, 1, 'Default Testnet Bot', 'BTCUSDT', 1);

-- Insert a default runtime status for the default bot config
INSERT IGNORE INTO `bot_runtime_status` (`id`, `bot_config_id`, `status`) VALUES
(1, 1, 'stopped');

-- Insert a default trade logic source for the test user
INSERT IGNORE INTO `trade_logic_source` (`id`, `user_id`, `source_name`, `is_active`, `version`, `strategy_directives_json`) VALUES
(1, 1, 'Default AI Strategy', TRUE, 1, '{"schema_version": "1.0.0", "strategy_type": "GENERAL_TRADING", "current_market_bias": "NEUTRAL", "preferred_timeframes_for_entry": ["1m", "5m", "15m"], "key_sr_levels_to_watch": {"support": [], "resistance": []}, "risk_parameters": {"target_risk_per_trade_usdt": 0.5, "default_rr_ratio": 3, "max_concurrent_positions": 1}, "quantity_determination_method": "INITIAL_MARGIN_TARGET", "entry_conditions_keywords": ["momentum_confirm", "breakout_consolidation"], "exit_conditions_keywords": ["momentum_stall", "target_profit_achieved"], "leverage_preference": {"min": 5, "max": 10, "preferred": 10}, "ai_confidence_threshold_for_trade": 0.7, "ai_learnings_notes": "Initial default strategy directives. AI to adapt based on market and trade outcomes.", "allow_ai_to_update_self": true, "emergency_hold_justification": "Wait for clear market signal or manual intervention."}');


COMMIT;
