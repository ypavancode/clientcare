-- Upgrade 1.4 -> 1.5
--   * Task module removed completely (table, columns, templates, alerts)
--   * Page-level website monitoring (website_pages, page_monitoring, page_incidents, website_scans)
--   * Client login credentials (encrypted) and WordPress login fields on websites
-- Import once via phpMyAdmin or:  mysql -u user -p dbname < database/upgrade-1.5.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Remove tasks
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tasks`;
ALTER TABLE `notifications` DROP COLUMN `task_id`;
ALTER TABLE `activity_logs` DROP COLUMN `task_id`;
DELETE FROM `email_templates` WHERE `type` = 'task_notification';
DELETE FROM `notifications` WHERE `category` IN ('task_overdue', 'task_assigned');
DELETE FROM `alert_log` WHERE `alert_key` LIKE 'task_overdue:%';
DELETE FROM `email_queue` WHERE `category` IN ('task_overdue', 'task_assigned') AND `status` = 'pending';

-- ---------------------------------------------------------------------
-- Websites: WordPress login + page monitoring summary
-- ---------------------------------------------------------------------
ALTER TABLE `websites`
  ADD COLUMN `wp_login_url` VARCHAR(255) DEFAULT NULL AFTER `admin_url`,
  ADD COLUMN `wp_username` VARCHAR(190) DEFAULT NULL AFTER `wp_login_url`,
  ADD COLUMN `wp_password` VARCHAR(500) DEFAULT NULL COMMENT 'AES-256 encrypted with APP_KEY' AFTER `wp_username`,
  ADD COLUMN `page_monitoring_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `monitoring_enabled`,
  ADD COLUMN `max_pages` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global setting' AFTER `page_monitoring_enabled`,
  ADD COLUMN `pages_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ssl_checked_at`,
  ADD COLUMN `pages_ok` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pages_total`,
  ADD COLUMN `pages_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pages_ok`,
  ADD COLUMN `page_health` ENUM('unknown','good','warning','failed') NOT NULL DEFAULT 'unknown' AFTER `pages_failed`,
  ADD COLUMN `last_scan_at` DATETIME DEFAULT NULL AFTER `page_health`,
  ADD COLUMN `last_scan_duration` INT UNSIGNED DEFAULT NULL COMMENT 'milliseconds' AFTER `last_scan_at`,
  ADD COLUMN `pages_discovered_at` DATETIME DEFAULT NULL AFTER `last_scan_duration`,
  ADD INDEX `idx_websites_page_health` (`page_health`),
  ADD INDEX `idx_websites_mon_scan` (`monitoring_enabled`, `page_monitoring_enabled`, `last_scan_at`);

-- ---------------------------------------------------------------------
-- Page-level monitoring
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `website_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `url_hash` CHAR(32) NOT NULL,
  `path` VARCHAR(255) NOT NULL DEFAULT '/',
  `title` VARCHAR(150) DEFAULT NULL,
  `source` ENUM('home','sitemap','links','manual') NOT NULL DEFAULT 'links',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `ignored` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'removed by a user – never re-added by discovery',
  `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `status` ENUM('down','server_error','timeout','ssl_error','parked','content_error','redirecting','unknown','paused','online') NOT NULL DEFAULT 'unknown',
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time` INT UNSIGNED DEFAULT NULL COMMENT 'milliseconds',
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `failed_checks` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'consecutive failures',
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_success_at` DATETIME DEFAULT NULL,
  `last_failed_at` DATETIME DEFAULT NULL,
  `discovered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME DEFAULT NULL COMMENT 'last discovery run that found this URL',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pages_site_url` (`website_id`, `url_hash`),
  KEY `idx_pages_site_active` (`website_id`, `is_active`, `priority`),
  KEY `idx_pages_status` (`status`),
  KEY `idx_pages_site_status` (`website_id`, `status`),
  CONSTRAINT `fk_pages_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `page_monitoring` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time` INT UNSIGNED DEFAULT NULL,
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pagemon_page_time` (`page_id`, `checked_at`),
  KEY `idx_pagemon_site_time` (`website_id`, `checked_at`),
  KEY `idx_pagemon_time` (`checked_at`),
  CONSTRAINT `fk_pagemon_page` FOREIGN KEY (`page_id`) REFERENCES `website_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pagemon_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `page_incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `duration_seconds` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL,
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  `status_code` SMALLINT UNSIGNED DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `failed_checks` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_checked_at` DATETIME DEFAULT NULL,
  `alert_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_sent` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pageinc_page` (`page_id`, `started_at`),
  KEY `idx_pageinc_site_open` (`website_id`, `resolved_at`),
  KEY `idx_pageinc_started` (`started_at`),
  CONSTRAINT `fk_pageinc_page` FOREIGN KEY (`page_id`) REFERENCES `website_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pageinc_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_scans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `scanned_at` DATETIME NOT NULL,
  `pages_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_ok` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `health` ENUM('unknown','good','warning','failed') NOT NULL DEFAULT 'unknown',
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `failed_pages` TEXT DEFAULT NULL COMMENT 'JSON list of failed pages in this scan',
  PRIMARY KEY (`id`),
  KEY `idx_scans_site_time` (`website_id`, `scanned_at`),
  KEY `idx_scans_time` (`scanned_at`),
  CONSTRAINT `fk_scans_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Client login credentials (hosting, domain, cPanel, FTP, email, other)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_credentials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL,
  `type` ENUM('hosting','domain','cpanel','ftp','email','database','other') NOT NULL DEFAULT 'other',
  `label` VARCHAR(120) NOT NULL,
  `login_url` VARCHAR(255) DEFAULT NULL,
  `username` VARCHAR(190) DEFAULT NULL,
  `password` VARCHAR(1000) DEFAULT NULL COMMENT 'AES-256 encrypted with APP_KEY',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cred_client` (`client_id`, `type`),
  KEY `idx_cred_website` (`website_id`),
  CONSTRAINT `fk_cred_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cred_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('page_monitoring_enabled', '1'),
('page_max_pages', '50'),
('page_discovery_hours', '24'),
('page_check_concurrency', '15'),
('page_alert_threshold', '3'),
('page_ignore_patterns', ''),
('retention_page_history_days', '30');

SET FOREIGN_KEY_CHECKS = 1;
