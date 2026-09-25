-- =====================================================================
-- Upgrade 1.8 -> 1.9
--   * Design / reference URLs per website (Figma, Adobe XD, HTML demo, other reference)
--   * Form monitoring: CAPTCHA / anti-bot handling – detection, "CAPTCHA Blocked" status (no failure alerts),
--     owner-approved test configuration, precise test outcomes (working / failed / blocked / timeout / config error…)
-- Safe to run more than once.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `figma_url` VARCHAR(500) DEFAULT NULL COMMENT 'Figma design URL' AFTER `admin_url`,
  ADD COLUMN IF NOT EXISTS `xd_url` VARCHAR(500) DEFAULT NULL COMMENT 'Adobe XD design URL' AFTER `figma_url`,
  ADD COLUMN IF NOT EXISTS `demo_url` VARCHAR(500) DEFAULT NULL COMMENT 'HTML / static demo URL' AFTER `xd_url`,
  ADD COLUMN IF NOT EXISTS `reference_url` VARCHAR(500) DEFAULT NULL COMMENT 'Other reference URL' AFTER `demo_url`;

ALTER TABLE `forms`
  ADD COLUMN IF NOT EXISTS `captcha_type` ENUM('none','recaptcha','hcaptcha','turnstile','other') NOT NULL DEFAULT 'none' COMMENT 'CAPTCHA / anti-bot protection configured by the admin' AFTER `verify_email`,
  ADD COLUMN IF NOT EXISTS `captcha_mode` ENUM('detect','test_url','test_field') NOT NULL DEFAULT 'detect' COMMENT 'detect = report Blocked by CAPTCHA (never bypass); test_url = owner-approved test/staging page without CAPTCHA; test_field = owner-approved test parameter' AFTER `captcha_type`,
  ADD COLUMN IF NOT EXISTS `captcha_test_url` VARCHAR(255) DEFAULT NULL COMMENT 'Owner-approved test / staging page used for submission tests' AFTER `captcha_mode`,
  ADD COLUMN IF NOT EXISTS `captcha_test_field` VARCHAR(255) DEFAULT NULL COMMENT 'Owner-approved test parameter(s) name=value added to test submissions' AFTER `captcha_test_url`,
  ADD COLUMN IF NOT EXISTS `captcha_detected` VARCHAR(30) DEFAULT NULL COMMENT 'CAPTCHA type detected during the last test' AFTER `captcha_test_field`,
  ADD COLUMN IF NOT EXISTS `last_outcome` VARCHAR(30) DEFAULT NULL COMMENT 'working / email_unknown / failed / captcha_blocked / interference / timeout / config_error / js_error / network_error' AFTER `last_failure_reason`,
  ADD COLUMN IF NOT EXISTS `last_email_received` ENUM('yes','no','unknown') DEFAULT NULL AFTER `last_outcome`;

-- Status order stays severity-first: failed, captcha_blocked, not_tested, working, disabled (values are remapped by name)
ALTER TABLE `forms`
  MODIFY `status` ENUM('failed','captcha_blocked','not_tested','working','disabled') NOT NULL DEFAULT 'not_tested',
  MODIFY `last_result` ENUM('success','failed','blocked') DEFAULT NULL;

ALTER TABLE `form_tests`
  MODIFY `result` ENUM('success','failed','blocked') NOT NULL,
  ADD COLUMN IF NOT EXISTS `outcome` VARCHAR(30) DEFAULT NULL AFTER `result`,
  ADD COLUMN IF NOT EXISTS `captcha_type` VARCHAR(30) DEFAULT NULL COMMENT 'CAPTCHA / anti-bot mechanism detected' AFTER `outcome`,
  ADD COLUMN IF NOT EXISTS `interference` VARCHAR(190) DEFAULT NULL COMMENT 'Third-party widget / script that interfered' AFTER `captcha_type`;

UPDATE `form_tests` SET `outcome` = IF(`result` = 'success', 'working', 'failed') WHERE `outcome` IS NULL;
UPDATE `forms` SET `last_outcome` = IF(`last_result` = 'success', 'working', 'failed') WHERE `last_outcome` IS NULL AND `last_result` IS NOT NULL;
