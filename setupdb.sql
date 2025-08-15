-- Insert a default user
INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `email`, `role`) VALUES
(1, 'testuser', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/lK.', 'test@example.com', 'user');

-- Insert sample (dummy) encrypted API keys for the test user.
INSERT IGNORE INTO `user_api_keys` (`id`, `user_id`, `key_name`, `binance_api_key_encrypted`, `binance_api_secret_encrypted`, `gemini_api_key_encrypted`, `is_active`) VALUES
(1, 1, 'My Default Keys', 'U2FsdGVkX1+vGvG6t8Rj0b0v9kZ/n8Nn7hUa3wQpY4s=', 'U2FsdGVkX1/aBcDeFgHiJkLmNoPqRsTuVwXyZ1a2b3c=', 'U2FsdGVkX1/sOmIsTrUeWgHjIkLmNoPqRsTuVwXyZ1a2b3c=', 1);

-- Insert a default bot configuration for the test user, linking to their API keys.
-- Note the new columns: `quantity_determination_method` and `allow_ai_to_update_strategy`.
INSERT IGNORE INTO `bot_configurations` (`id`, `user_id`, `user_api_key_id`, `name`, `symbol`, `is_active`, `quantity_determination_method`, `allow_ai_to_update_strategy`) VALUES
(1, 1, 1, 'Default Testnet Bot', 'BTCUSDT', 1, 'INITIAL_MARGIN_TARGET', 0);

-- Insert a default runtime status for the default bot config
INSERT IGNORE INTO `bot_runtime_status` (`id`, `bot_config_id`, `status`) VALUES
(1, 1, 'stopped');

-- Insert a default trade logic source for the test user.
-- Note that `quantity_determination_method` and `allow_ai_to_update_self` have been REMOVED from the JSON.
INSERT IGNORE INTO `trade_logic_source` (`id`, `user_id`, `source_name`, `is_active`, `version`, `strategy_directives_json`) VALUES
(1, 1, 'Default AI Strategy', TRUE, 1, '{"schema_version": "1.0.0", "strategy_type": "GENERAL_TRADING", "current_market_bias": "NEUTRAL", "preferred_timeframes_for_entry": ["1m", "5m", "15m"], "key_sr_levels_to_watch": {"support": [], "resistance": []}, "risk_parameters": {"target_risk_per_trade_usdt": 0.5, "default_rr_ratio": 3, "max_concurrent_positions": 1}, "entry_conditions_keywords": ["momentum_confirm", "breakout_consolidation"], "exit_conditions_keywords": ["momentum_stall", "target_profit_achieved"], "leverage_preference": {"min": 5, "max": 10, "preferred": 10}, "ai_confidence_threshold_for_trade": 0.7, "ai_learnings_notes": "Initial default strategy directives. AI to adapt based on market and trade outcomes.", "emergency_hold_justification": "Wait for clear market signal or manual intervention."}');


-- --------------------------------------------------------
--
-- PAYSTACK SPECIFIC TABLES AND CONFIGURATIONS
--
-- --------------------------------------------------------

--
-- Table: `paystack_transactions`
--
CREATE TABLE `paystack_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_kes` decimal(20,2) NOT NULL,
  `status` enum('pending','success','failed','abandoned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `channel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paystack_response_at_verification` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table: `paystack_transfer_recipients`
--
CREATE TABLE `paystack_transfer_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `recipient_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KES',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipient_code` (`recipient_code`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- AUTO_INCREMENT for table `paystack_transactions`
--
ALTER TABLE `paystack_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paystack_transfer_recipients`
--
ALTER TABLE `paystack_transfer_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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


COMMIT;
