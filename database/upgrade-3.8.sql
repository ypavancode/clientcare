-- =====================================================================
-- v3.8 – Email template studio + immediate delivery
--   * email_templates gains sender / CTA / design / status fields (all optional – existing rows keep working)
--   * email_queue carries per-message sender overrides (from the template) so retries send identically
--   * alert delivery job runs every minute (safety net only – messages are now delivered immediately)
-- Safe to run more than once.
-- =====================================================================

ALTER TABLE `email_templates`
  ADD COLUMN IF NOT EXISTS `from_name`    VARCHAR(150) DEFAULT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `from_email`   VARCHAR(190) DEFAULT NULL AFTER `from_name`,
  ADD COLUMN IF NOT EXISTS `reply_to`     VARCHAR(190) DEFAULT NULL AFTER `from_email`,
  ADD COLUMN IF NOT EXISTS `preheader`    VARCHAR(190) DEFAULT NULL AFTER `reply_to`,
  ADD COLUMN IF NOT EXISTS `cta_label`    VARCHAR(80)  DEFAULT NULL AFTER `preheader`,
  ADD COLUMN IF NOT EXISTS `cta_url`      VARCHAR(500) DEFAULT NULL AFTER `cta_label`,
  ADD COLUMN IF NOT EXISTS `header_image` VARCHAR(500) DEFAULT NULL AFTER `cta_url`,
  ADD COLUMN IF NOT EXISTS `footer_text`  VARCHAR(500) DEFAULT NULL AFTER `header_image`,
  ADD COLUMN IF NOT EXISTS `status`       ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER `footer_text`,
  ADD COLUMN IF NOT EXISTS `updated_by`   INT UNSIGNED DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `created_at`   DATETIME DEFAULT NULL AFTER `updated_by`;

ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `from_name`  VARCHAR(150) DEFAULT NULL AFTER `bcc`,
  ADD COLUMN IF NOT EXISTS `from_email` VARCHAR(190) DEFAULT NULL AFTER `from_name`,
  ADD COLUMN IF NOT EXISTS `reply_to`   VARCHAR(190) DEFAULT NULL AFTER `from_email`,
  ADD COLUMN IF NOT EXISTS `template`   VARCHAR(40)  DEFAULT NULL AFTER `category`;

ALTER TABLE `email_logs`
  ADD INDEX IF NOT EXISTS `idx_email_logs_template_time` (`template`, `sent_at`);

UPDATE `scheduler_jobs` SET `interval_minutes` = 1 WHERE `name` = 'process-notifications' AND `interval_minutes` > 1;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('email_send_immediately', '1');
