-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 24, 2025 at 03:35 PM
-- Server version: 8.0.42-0ubuntu0.24.04.1
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `server_prod2`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions_log`
--

CREATE TABLE `ai_interactions_log` (
  `id` int NOT NULL,
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
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_configurations`
--

CREATE TABLE `bot_configurations` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `user_api_key_id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BTCUSDT',
  `kline_interval` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1m',
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USDT',
  `default_leverage` int NOT NULL DEFAULT '10',
  `order_check_interval_seconds` int NOT NULL DEFAULT '45',
  `ai_update_interval_seconds` int NOT NULL DEFAULT '60',
  `use_testnet` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 for testnet, 0 for mainnet',
  `quantity_determination_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INITIAL_MARGIN_TARGET' COMMENT 'How trade quantity is calculated (e.g., INITIAL_MARGIN_TARGET, AI_SUGGESTED)',
  `allow_ai_to_update_strategy` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if AI is allowed to update its own strategy directives',
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT '10.50000000',
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `pending_entry_order_cancel_timeout_seconds` int NOT NULL DEFAULT '180',
  `profit_check_interval_seconds` int NOT NULL DEFAULT '60',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_runtime_status`
--

CREATE TABLE `bot_runtime_status` (
  `id` int NOT NULL,
  `bot_config_id` int NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Current operational status (e.g., running, stopped, error)',
  `last_heartbeat` datetime DEFAULT NULL,
  `process_id` int DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders_log`
--

CREATE TABLE `orders_log` (
  `internal_id` int NOT NULL,
  `user_id` int NOT NULL,
  `bot_config_id` int NOT NULL,
  `order_id_binance` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trade_id_binance` bigint DEFAULT NULL COMMENT 'The unique trade ID from the exchange for a specific fill event.',
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `side` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Order side (e.g., BUY, SELL)',
  `status_reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `commission_usdt` decimal(20,8) DEFAULT NULL,
  `reduce_only` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if order reduces position, 0 otherwise',
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paystack_transactions`
--

CREATE TABLE `paystack_transactions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_kes` decimal(20,2) NOT NULL,
  `status` enum('pending','success','failed','abandoned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `channel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paystack_response_at_verification` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paystack_transfer_recipients`
--

CREATE TABLE `paystack_transfer_recipients` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `recipient_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KES',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_logic_source`
--

CREATE TABLE `trade_logic_source` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `source_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if the trade logic is active, 0 otherwise',
  `version` int NOT NULL DEFAULT '1',
  `last_updated_by` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_data_snapshot_at_last_update_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'user' COMMENT 'e.g., admin, user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `balance_cents` BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_api_keys`
--

CREATE TABLE `user_api_keys` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `key_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., "My Main Binance Key", "Testnet Key"',
  `binance_api_key_encrypted` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `binance_api_secret_encrypted` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `gemini_api_key_encrypted` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 if the API key is active, 0 otherwise',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  ADD UNIQUE KEY `uk_order_trade` (`order_id_binance`,`trade_id_binance`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_bot_config_id` (`bot_config_id`),
  ADD KEY `idx_symbol_timestamp` (`symbol`,`bot_event_timestamp_utc`),
  ADD KEY `idx_order_id_binance` (`order_id_binance`);

--
-- Indexes for table `paystack_transactions`
--
ALTER TABLE `paystack_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference` (`reference`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `paystack_transfer_recipients`
--
ALTER TABLE `paystack_transfer_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `recipient_code` (`recipient_code`),
  ADD KEY `user_id` (`user_id`);

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_configurations`
--
ALTER TABLE `bot_configurations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bot_runtime_status`
--
ALTER TABLE `bot_runtime_status`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders_log`
--
ALTER TABLE `orders_log`
  MODIFY `internal_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paystack_transactions`
--
ALTER TABLE `paystack_transactions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paystack_transfer_recipients`
--
ALTER TABLE `paystack_transfer_recipients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_logic_source`
--
ALTER TABLE `trade_logic_source`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_api_keys`
--
ALTER TABLE `user_api_keys`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

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
-- Constraints for table `paystack_transactions`
--
ALTER TABLE `paystack_transactions`
  ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `paystack_transfer_recipients`
--
ALTER TABLE `paystack_transfer_recipients`
  ADD CONSTRAINT `fk_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
