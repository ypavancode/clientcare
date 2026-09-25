-- Upgrade 1.2 -> 1.3: Client Care email system (templates, richer logs, reply-to, CC/BCC in queue)

ALTER TABLE `email_queue`
  ADD COLUMN `cc` VARCHAR(255) DEFAULT NULL AFTER `to_name`,
  ADD COLUMN `bcc` VARCHAR(255) DEFAULT NULL AFTER `cc`;

ALTER TABLE `email_logs`
  ADD COLUMN `cc` VARCHAR(255) DEFAULT NULL AFTER `to_email`,
  ADD COLUMN `bcc` VARCHAR(255) DEFAULT NULL AFTER `cc`,
  ADD COLUMN `template` VARCHAR(40) DEFAULT NULL AFTER `category`,
  ADD COLUMN `message_id` VARCHAR(190) DEFAULT NULL AFTER `status`,
  ADD COLUMN `smtp_response` VARCHAR(500) DEFAULT NULL AFTER `message_id`,
  ADD COLUMN `ref_id` INT UNSIGNED DEFAULT NULL AFTER `smtp_response`;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `type` VARCHAR(40) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(250) NOT NULL,
  `body` TEXT NOT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = 'clientcare@outlinemedia.in' WHERE `setting_key` = 'from_email' AND (`setting_value` = '' OR `setting_value` IS NULL);
UPDATE `settings` SET `setting_value` = 'Outline Media Client Care' WHERE `setting_key` = 'from_name' AND (`setting_value` = '' OR `setting_value` = 'Outline Media CRM' OR `setting_value` IS NULL);
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('reply_to_email', 'clientcare@outlinemedia.in');
