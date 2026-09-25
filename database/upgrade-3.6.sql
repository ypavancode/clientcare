-- =====================================================================
-- v3.6 – SMTP / email reliability, email diagnostics, Super Admin audit log
--   mysql -u USER -p DBNAME < database/upgrade-3.6.sql   (re-runnable)
-- =====================================================================

-- Email log: why an email failed (kind), how long the SMTP conversation took, which queue row / attempt produced it
ALTER TABLE `email_logs`
  ADD COLUMN IF NOT EXISTS `error_kind`  VARCHAR(30)      DEFAULT NULL AFTER `error`,
  ADD COLUMN IF NOT EXISTS `duration_ms` INT UNSIGNED     DEFAULT NULL AFTER `error_kind`,
  ADD COLUMN IF NOT EXISTS `queue_id`    INT UNSIGNED     DEFAULT NULL AFTER `duration_ms`,
  ADD COLUMN IF NOT EXISTS `attempt`     TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `queue_id`,
  ADD COLUMN IF NOT EXISTS `from_email`  VARCHAR(190)     DEFAULT NULL AFTER `bcc`,
  ADD INDEX IF NOT EXISTS `idx_email_logs_kind` (`error_kind`);

-- Queue: retry scheduling (exponential back-off) instead of hammering a dead SMTP server on every tick
ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `next_attempt_at` DATETIME DEFAULT NULL AFTER `attempts`,
  ADD INDEX IF NOT EXISTS `idx_queue_due` (`status`, `next_attempt_at`, `id`);

-- Real SMTP service state (single row) – written by every send / test, read by the dashboard and the Email module
CREATE TABLE IF NOT EXISTS `email_service_state` (
  `id`                   TINYINT UNSIGNED NOT NULL,
  `status`               ENUM('unknown','connected','failed','incomplete','disabled') NOT NULL DEFAULT 'unknown',
  `last_check_at`        DATETIME DEFAULT NULL,
  `last_check_ok`        TINYINT(1) DEFAULT NULL,
  `last_check_ms`        INT UNSIGNED DEFAULT NULL,
  `last_check_by`        VARCHAR(120) DEFAULT NULL,
  `last_success_at`      DATETIME DEFAULT NULL,
  `last_failure_at`      DATETIME DEFAULT NULL,
  `last_error`           VARCHAR(1000) DEFAULT NULL,
  `last_error_kind`      VARCHAR(30) DEFAULT NULL,
  `last_response`        VARCHAR(500) DEFAULT NULL,
  `last_email_sent_at`   DATETIME DEFAULT NULL,
  `last_email_failed_at` DATETIME DEFAULT NULL,
  `last_email_error`     VARCHAR(1000) DEFAULT NULL,
  `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `backoff_until`        DATETIME DEFAULT NULL,
  `updated_at`           DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `email_service_state` (`id`, `status`) VALUES (1, 'unknown');

-- Super Admin audit trail: target + result on the existing activity log, flagged so the platform view stays cheap
ALTER TABLE `activity_logs`
  ADD COLUMN IF NOT EXISTS `is_platform` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `target`      VARCHAR(190) DEFAULT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `result`      VARCHAR(20)  DEFAULT NULL AFTER `target`,
  ADD INDEX IF NOT EXISTS `idx_activity_platform` (`is_platform`, `id`);
UPDATE `activity_logs` SET `is_platform` = 1 WHERE `action` LIKE 'platform\_%' AND `is_platform` = 0;

-- SMTP settings that did not exist before (enable switch, certificate verification, split timeouts, back-off)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('smtp_enabled', '1'),
  ('smtp_verify_peer', '1'),
  ('smtp_connect_timeout', '10'),
  ('smtp_timeout', '20'),
  ('smtp_backoff_minutes', '5');
