-- =====================================================================
-- Upgrade 1.5 -> 1.6
--   * Sender identity moves to clientcare@outlinestudio.in
--   * SSL monitoring every 5 minutes with incidents (failure / recovery emails, no duplicates)
--   * Real form testing: popup / AJAX / WordPress forms, browser engine, incidents with downtime
--   * Form monitoring interval 10 minutes by default (10 / 15 / 20 selectable)
--   * DKIM signing settings, cron health endpoint settings
-- Safe to run more than once.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- sender
UPDATE `settings` SET `setting_value` = 'clientcare@outlinestudio.in' WHERE `setting_key` IN ('from_email', 'reply_to_email') AND (`setting_value` = '' OR `setting_value` IS NULL OR `setting_value` = 'clientcare@outlinemedia.in');
UPDATE `settings` SET `setting_value` = REPLACE(`setting_value`, 'clientcare@outlinemedia.in', 'clientcare@outlinestudio.in') WHERE `setting_key` = 'notification_email' AND `setting_value` LIKE '%outlinemedia.in%';
UPDATE `settings` SET `setting_value` = '10' WHERE `setting_key` = 'form_check_interval' AND `setting_value` IN ('', '5');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('from_email', 'clientcare@outlinestudio.in'),
('reply_to_email', 'clientcare@outlinestudio.in'),
('form_check_interval', '10'),
('ssl_check_interval', '5'),
('form_confirm_retry', '1'),
('form_wait_seconds', '20'),
('browser_path', ''),
('browser_enabled', '1'),
('dkim_domain', ''),
('dkim_selector', ''),
('dkim_private_key', ''),
('retention_form_screenshots_days', '30');

-- ---------------------------------------------------------------- forms
ALTER TABLE `forms`
  ADD COLUMN IF NOT EXISTS `form_kind` ENUM('normal','popup','ajax','wordpress') NOT NULL DEFAULT 'normal' AFTER `form_type`,
  ADD COLUMN IF NOT EXISTS `engine` ENUM('auto','http','browser') NOT NULL DEFAULT 'auto' AFTER `form_kind`,
  ADD COLUMN IF NOT EXISTS `wp_plugin` VARCHAR(30) DEFAULT NULL COMMENT 'Detected WordPress form plugin' AFTER `engine`,
  ADD COLUMN IF NOT EXISTS `popup_trigger` VARCHAR(255) DEFAULT NULL COMMENT 'CSS selector (or text=...) of the element that opens the popup' AFTER `wp_plugin`,
  ADD COLUMN IF NOT EXISTS `popup_selector` VARCHAR(255) DEFAULT NULL AFTER `popup_trigger`,
  ADD COLUMN IF NOT EXISTS `form_selector` VARCHAR(255) DEFAULT NULL AFTER `popup_selector`,
  ADD COLUMN IF NOT EXISTS `submit_selector` VARCHAR(255) DEFAULT NULL AFTER `form_selector`,
  ADD COLUMN IF NOT EXISTS `test_name` VARCHAR(120) DEFAULT NULL AFTER `test_payload`,
  ADD COLUMN IF NOT EXISTS `test_email` VARCHAR(190) DEFAULT NULL AFTER `test_name`,
  ADD COLUMN IF NOT EXISTS `test_phone` VARCHAR(40) DEFAULT NULL AFTER `test_email`,
  ADD COLUMN IF NOT EXISTS `test_message` VARCHAR(500) DEFAULT NULL AFTER `test_phone`,
  ADD COLUMN IF NOT EXISTS `test_email_recipient` VARCHAR(190) DEFAULT NULL COMMENT 'Mailbox that should receive the form email (verified over IMAP when configured)' AFTER `test_message`,
  ADD COLUMN IF NOT EXISTS `verify_email` TINYINT(1) NOT NULL DEFAULT 0 AFTER `test_email_recipient`,
  ADD COLUMN IF NOT EXISTS `expect_response` VARCHAR(255) DEFAULT NULL COMMENT 'Text / JSON fragment expected in the HTTP or AJAX response' AFTER `expect_http_code`,
  ADD COLUMN IF NOT EXISTS `last_failed_at` DATETIME DEFAULT NULL AFTER `last_success_at`,
  ADD COLUMN IF NOT EXISTS `total_failures` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_failed_at`,
  ADD COLUMN IF NOT EXISTS `total_success` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_failures`,
  ADD COLUMN IF NOT EXISTS `last_engine` ENUM('http','browser') DEFAULT NULL AFTER `total_success`,
  ADD COLUMN IF NOT EXISTS `last_final_url` VARCHAR(500) DEFAULT NULL AFTER `last_engine`,
  ADD COLUMN IF NOT EXISTS `last_ajax_status` SMALLINT UNSIGNED DEFAULT NULL AFTER `last_final_url`,
  ADD COLUMN IF NOT EXISTS `last_response_text` VARCHAR(500) DEFAULT NULL AFTER `last_ajax_status`;
ALTER TABLE `forms` MODIFY `last_failure_reason` VARCHAR(120) DEFAULT NULL;
ALTER TABLE `forms` ADD INDEX IF NOT EXISTS `idx_forms_kind_status` (`form_kind`, `status`);
ALTER TABLE `forms` ADD INDEX IF NOT EXISTS `idx_forms_failed_at` (`last_failed_at`);
-- Forms that were already flagged as AJAX keep working with the new engine
UPDATE `forms` SET `form_kind` = 'ajax' WHERE `ajax` = 1 AND `form_kind` = 'normal';

ALTER TABLE `form_tests`
  MODIFY `test_mode` ENUM('availability','submission','popup') NOT NULL DEFAULT 'submission',
  MODIFY `failure_reason` VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `engine` ENUM('http','browser') NOT NULL DEFAULT 'http' AFTER `test_mode`,
  ADD COLUMN IF NOT EXISTS `final_url` VARCHAR(500) DEFAULT NULL AFTER `response_text`,
  ADD COLUMN IF NOT EXISTS `ajax_status` SMALLINT UNSIGNED DEFAULT NULL AFTER `final_url`,
  ADD COLUMN IF NOT EXISTS `steps` TEXT DEFAULT NULL COMMENT 'JSON list of test steps' AFTER `ajax_status`,
  ADD COLUMN IF NOT EXISTS `screenshot` VARCHAR(190) DEFAULT NULL COMMENT 'uploads/form-tests/... (browser engine, failures only)' AFTER `steps`;

CREATE TABLE IF NOT EXISTS `form_incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `duration_seconds` INT UNSIGNED DEFAULT NULL,
  `failure_reason` VARCHAR(120) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `failed_tests` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_tested_at` DATETIME DEFAULT NULL,
  `alert_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_sent` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_fi_form` (`form_id`, `started_at`),
  KEY `idx_fi_website` (`website_id`),
  KEY `idx_fi_open` (`resolved_at`),
  CONSTRAINT `fk_fi_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- SSL
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `ssl_last_valid_at` DATETIME DEFAULT NULL AFTER `ssl_checked_at`,
  ADD COLUMN IF NOT EXISTS `ssl_failed_checks` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ssl_last_valid_at`;

CREATE TABLE IF NOT EXISTS `ssl_incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `duration_seconds` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `failed_checks` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_checked_at` DATETIME DEFAULT NULL,
  `alert_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `recovery_sent` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_si_site` (`website_id`, `started_at`),
  KEY `idx_si_open` (`resolved_at`),
  CONSTRAINT `fk_si_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email templates that were customised with the old sender keep working; new defaults are in Mailer::templateDefinitions()
DELETE FROM `email_templates` WHERE `type` IN ('ssl_expiry') AND `body` LIKE '%outlinemedia.in%';
