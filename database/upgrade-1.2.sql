-- Upgrade 1.1 -> 1.2: automatic monitoring improvements
-- (failure reasons, form test settings, cron health tracking, client alerts by default, 10-day expiry alerts)

ALTER TABLE `websites`
  ADD COLUMN `failure_reason` VARCHAR(80) DEFAULT NULL AFTER `error_message`;

ALTER TABLE `website_monitoring`
  ADD COLUMN `failure_reason` VARCHAR(80) DEFAULT NULL AFTER `status`;

ALTER TABLE `website_incidents`
  ADD COLUMN `failure_reason` VARCHAR(80) DEFAULT NULL AFTER `status`,
  ADD COLUMN `failed_checks` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `error_message`,
  ADD COLUMN `last_checked_at` DATETIME DEFAULT NULL AFTER `failed_checks`;

ALTER TABLE `forms`
  ADD COLUMN `test_interval` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Minutes between automatic tests. 0 = use global setting' AFTER `auto_test`,
  ADD COLUMN `expect_http_code` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Expected HTTP response code (blank = any 2xx/3xx)' AFTER `success_match`,
  ADD COLUMN `expect_redirect` VARCHAR(255) DEFAULT NULL COMMENT 'Expected redirect URL (or fragment) after submission' AFTER `expect_http_code`,
  ADD COLUMN `last_failure_reason` VARCHAR(80) DEFAULT NULL AFTER `last_error`,
  ADD COLUMN `failed_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failed tests' AFTER `last_failure_reason`;

ALTER TABLE `form_tests`
  ADD COLUMN `failure_reason` VARCHAR(80) DEFAULT NULL AFTER `result`;

ALTER TABLE `clients`
  MODIFY `notify_client` TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `cron_runs` (
  `name` VARCHAR(40) NOT NULL,
  `last_started_at` DATETIME DEFAULT NULL,
  `last_finished_at` DATETIME DEFAULT NULL,
  `last_status` ENUM('running','success','failed') DEFAULT NULL,
  `last_message` VARCHAR(500) DEFAULT NULL,
  `last_duration` INT UNSIGNED DEFAULT NULL COMMENT 'seconds',
  `run_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Form checks are now every N minutes (was hours). Expiry / SSL alerts fire at 30 and 10 days (and when expired).
UPDATE `settings` SET `setting_value` = '5'  WHERE `setting_key` = 'form_check_interval';
UPDATE `settings` SET `setting_value` = '1'  WHERE `setting_key` = 'notify_clients';
UPDATE `settings` SET `setting_value` = '30,10' WHERE `setting_key` IN ('domain_alert_days','hosting_alert_days','ssl_alert_days');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('cron_stale_minutes', '12');
