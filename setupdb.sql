
--- START OF FILE p2profit_user_based_db.sql ---

-- P2Profit - User-Based Trading Bot Database Schema
-- Version: 2.0
-- A production-ready, relational schema for a multi-user trading bot system.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `p2profit_bot_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
-- The central table for all users of the platform.
--
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'user' COMMENT 'e.g., admin, user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_api_keys`
-- Securely stores encrypted API keys for each user. A user can have multiple sets.
--
CREATE TABLE IF NOT EXISTS `user_api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `key_name` VARCHAR(100) NOT NULL COMMENT 'e.g., "My Main Binance Key", "Testnet Key"',
  `binance_api_key_encrypted` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
  `binance_api_secret_encrypted` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
  `gemini_api_key_encrypted` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `bot_configurations`
-- Each bot configuration belongs to a specific user and uses one of their API key sets.
--
CREATE TABLE IF NOT EXISTS `bot_configurations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `user_api_key_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BTCUSDT',
  `kline_interval` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1m',
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USDT',
  `default_leverage` int NOT NULL DEFAULT '10',
  `order_check_interval_seconds` int NOT NULL DEFAULT '45',
  `ai_update_interval_seconds` int NOT NULL DEFAULT '60',
  `use_testnet` tinyint(1) NOT NULL DEFAULT '1',
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT '10.50000000',
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `pending_entry_order_cancel_timeout_seconds` int NOT NULL DEFAULT '180',
  `profit_check_interval_seconds` int NOT NULL DEFAULT '60',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_config_name` (`user_id`, `name`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_api_key_id` (`user_api_key_id`),
  CONSTRAINT `fk_config_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_config_api_key` FOREIGN KEY (`user_api_key_id`) REFERENCES `user_api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_runtime_status`
-- Tracks the live status of each bot process. Linked via bot_config_id.
--
CREATE TABLE IF NOT EXISTS `bot_runtime_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bot_config_id` int NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_heartbeat` datetime DEFAULT NULL,
  `process_id` int DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bot_config_id` (`bot_config_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_status_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders_log`
-- Logs all order events, clearly tagged with a user_id for frontend queries.
--
CREATE TABLE IF NOT EXISTS `orders_log` (
  `internal_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `bot_config_id` int NOT NULL,
  `order_id_binance` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `side` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `commission_usdt` decimal(20,8) DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`internal_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_bot_config_id` (`bot_config_id`),
  KEY `idx_symbol_timestamp` (`symbol`,`bot_event_timestamp_utc`),
  KEY `idx_order_id_binance` (`order_id_binance`),
  CONSTRAINT `fk_order_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_log_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions_log`
-- Logs all AI interactions, tagged with user_id.
--
CREATE TABLE IF NOT EXISTS `ai_interactions_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `bot_config_id` int NOT NULL,
  `log_timestamp_utc` datetime NOT NULL,
  `trading_symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `executed_action_by_bot` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ai_decision_params_json` text COLLATE utf8mb4_unicode_ci,
  `bot_feedback_json` text COLLATE utf8mb4_unicode_ci,
  `full_data_for_ai_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `prompt_text_sent_to_ai_md5` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_ai_response_json` text COLLATE utf8mb4_unicode_ci,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_bot_config_id` (`bot_config_id`),
  KEY `idx_symbol_timestamp_action` (`trading_symbol`,`log_timestamp_utc`),
  CONSTRAINT `fk_ai_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_log_config` FOREIGN KEY (`bot_config_id`) REFERENCES `bot_configurations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_logic_source`
-- Trading strategies, now owned by users.
--
CREATE TABLE IF NOT EXISTS `trade_logic_source` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `source_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `version` int NOT NULL DEFAULT '1',
  `last_updated_by` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_data_snapshot_at_last_update_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_source_name` (`user_id`, `source_name`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_trade_logic_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
--- START OF FILE .env ---

APP_ENCRYPTION_KEY="base64:..."

GEMINI_MODEL_NAME="gemini-1.5-pro-latest"

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=server_new
DB_USER=root
DB_PASSWORD=root

fix it , so that the user can create an API key, then create a bot, and run it.