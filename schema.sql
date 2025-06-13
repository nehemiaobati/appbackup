-- phpMyAdmin SQL Dump
-- version 5.2.2deb1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 13, 2025 at 02:33 PM
-- Server version: 11.8.1-MariaDB-2
-- PHP Version: 8.4.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `server_new`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_interactions_log`
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
-- Table structure for table `bot_configurations`
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
  `use_testnet` tinyint(1) NOT NULL DEFAULT 1,
  `initial_margin_target_usdt` decimal(20,8) NOT NULL DEFAULT 10.50000000,
  `take_profit_target_usdt` decimal(20,8) NOT NULL DEFAULT 0.00000000,
  `pending_entry_order_cancel_timeout_seconds` int(11) NOT NULL DEFAULT 180,
  `profit_check_interval_seconds` int(11) NOT NULL DEFAULT 60,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_runtime_status`
--

CREATE TABLE `bot_runtime_status` (
  `id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `last_heartbeat` datetime DEFAULT NULL,
  `process_id` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `current_position_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders_log`
--

CREATE TABLE `orders_log` (
  `internal_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bot_config_id` int(11) NOT NULL,
  `order_id_binance` varchar(50) DEFAULT NULL,
  `bot_event_timestamp_utc` datetime NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `side` varchar(10) NOT NULL,
  `status_reason` varchar(100) NOT NULL,
  `price_point` decimal(20,8) DEFAULT NULL,
  `quantity_involved` decimal(20,8) DEFAULT NULL,
  `margin_asset` varchar(10) DEFAULT NULL,
  `realized_pnl_usdt` decimal(20,8) DEFAULT NULL,
  `commission_usdt` decimal(20,8) DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp(),
  `reduce_only` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_logic_source`
--

CREATE TABLE `trade_logic_source` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `version` int(11) NOT NULL DEFAULT 1,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at_utc` datetime DEFAULT NULL,
  `strategy_directives_json` text NOT NULL,
  `full_data_snapshot_at_last_update_json` mediumtext DEFAULT NULL,
  `created_at_db` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
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
-- Table structure for table `user_api_keys`
--

CREATE TABLE `user_api_keys` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL COMMENT 'e.g., "My Main Binance Key", "Testnet Key"',
  `binance_api_key_encrypted` text NOT NULL,
  `binance_api_secret_encrypted` text NOT NULL,
  `gemini_api_key_encrypted` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
