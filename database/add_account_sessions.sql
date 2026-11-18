-- Per-device sign-in tokens: the same account can stay signed in on several devices/browsers.
-- session.php creates this table automatically (includes/auth_sessions.php); run this only for manual setups.

CREATE TABLE IF NOT EXISTS `account_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `token` char(64) NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Keep anyone already signed in with the old single token signed in.
INSERT IGNORE INTO `account_sessions` (account_id, token)
  SELECT id, token FROM `accounts` WHERE token IS NOT NULL AND CHAR_LENGTH(token) = 64;
