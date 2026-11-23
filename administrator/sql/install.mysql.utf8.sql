CREATE TABLE IF NOT EXISTS `#__sdp_settings` (
  `name` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sdp_user_map` (
  `joomla_user_id` INT UNSIGNED NOT NULL,
  `sdp_user_id` VARCHAR(20) NULL DEFAULT NULL,
  `email` VARCHAR(320) NOT NULL DEFAULT '',
  `matched_at` DATETIME NULL DEFAULT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'auto',
  `modified_by` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`joomla_user_id`),
  UNIQUE KEY `idx_sdp_user_id` (`sdp_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sdp_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(50) NOT NULL,
  `sdp_request_id` VARCHAR(20) NULL DEFAULT NULL,
  `approval_id` VARCHAR(20) NULL DEFAULT NULL,
  `module` VARCHAR(20) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `result` VARCHAR(20) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created`),
  KEY `idx_action_created` (`action`, `created`),
  KEY `idx_user_action_created` (`user_id`, `action`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sdp_cache` (
  `user_id` INT UNSIGNED NOT NULL,
  `cache_key` VARCHAR(191) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `cache_key`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
