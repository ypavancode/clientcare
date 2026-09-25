-- =====================================================================
-- Upgrade 1.9 -> 2.0: AUTOMATIC FORM DISCOVERY & MONITORING
--   * forms gain discovery metadata (source auto/manual, fingerprint, page link, fields, technology, iframe, removed state)
--   * form_pages      – every page a form was seen on (one record per form, many pages)
--   * form_scans      – log of website form-discovery scans
--   * form_status_history – WORKING / FAILED / CAPTCHA BLOCKED / REMOVED transitions per form
--   * websites gain form counters + scan schedule; website_pages gain clean_url (".php" → clean URL when rewriting works)
--   * sender address corrected to clientcare@outlinestudio.in
-- Safe to run more than once.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `forms`
  ADD COLUMN IF NOT EXISTS `source` ENUM('manual','auto') NOT NULL DEFAULT 'manual' COMMENT 'auto = discovered by the crawler' AFTER `website_id`,
  ADD COLUMN IF NOT EXISTS `page_id` INT UNSIGNED DEFAULT NULL COMMENT 'website_pages.id of the primary page' AFTER `page_url`,
  ADD COLUMN IF NOT EXISTS `page_title` VARCHAR(190) DEFAULT NULL AFTER `page_id`,
  ADD COLUMN IF NOT EXISTS `fingerprint` CHAR(40) DEFAULT NULL COMMENT 'sha1 of stable form identity (dedupe)' AFTER `page_title`,
  ADD COLUMN IF NOT EXISTS `form_title` VARCHAR(190) DEFAULT NULL COMMENT 'Title detected on the page / popup' AFTER `fingerprint`,
  ADD COLUMN IF NOT EXISTS `form_dom_id` VARCHAR(120) DEFAULT NULL AFTER `form_title`,
  ADD COLUMN IF NOT EXISTS `technology` VARCHAR(40) DEFAULT NULL COMMENT 'Contact Form 7, WPForms, Elementor, Custom PHP, JotForm…' AFTER `form_dom_id`,
  ADD COLUMN IF NOT EXISTS `fields_json` TEXT DEFAULT NULL COMMENT '[{name,type,label,required}]' AFTER `technology`,
  ADD COLUMN IF NOT EXISTS `field_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `fields_json`,
  ADD COLUMN IF NOT EXISTS `submit_label` VARCHAR(120) DEFAULT NULL AFTER `field_count`,
  ADD COLUMN IF NOT EXISTS `action_url` VARCHAR(500) DEFAULT NULL COMMENT 'Form action discovered on the page (informational – tests always use the live page action)' AFTER `submit_label`,
  ADD COLUMN IF NOT EXISTS `in_iframe` TINYINT(1) NOT NULL DEFAULT 0 AFTER `submit_label`,
  ADD COLUMN IF NOT EXISTS `iframe_src` VARCHAR(500) DEFAULT NULL AFTER `in_iframe`,
  ADD COLUMN IF NOT EXISTS `discovery_status` ENUM('active','removed') NOT NULL DEFAULT 'active' AFTER `iframe_src`,
  ADD COLUMN IF NOT EXISTS `discovered_by` ENUM('http','browser') DEFAULT NULL AFTER `discovery_status`,
  ADD COLUMN IF NOT EXISTS `first_discovered_at` DATETIME DEFAULT NULL AFTER `discovered_by`,
  ADD COLUMN IF NOT EXISTS `last_discovered_at` DATETIME DEFAULT NULL AFTER `first_discovered_at`,
  ADD COLUMN IF NOT EXISTS `removed_at` DATETIME DEFAULT NULL AFTER `last_discovered_at`,
  ADD COLUMN IF NOT EXISTS `missed_scans` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `removed_at`,
  ADD COLUMN IF NOT EXISTS `captcha_seen_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `captcha_detected`,
  ADD COLUMN IF NOT EXISTS `total_downtime` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'seconds, accumulated from resolved incidents' AFTER `total_success`;

ALTER TABLE `forms`
  MODIFY `status` ENUM('failed','captcha_blocked','not_tested','working','disabled','removed') NOT NULL DEFAULT 'not_tested';

ALTER TABLE `forms` ADD INDEX IF NOT EXISTS `idx_forms_fingerprint` (`website_id`, `fingerprint`);
ALTER TABLE `forms` ADD INDEX IF NOT EXISTS `idx_forms_source` (`source`, `discovery_status`);

CREATE TABLE IF NOT EXISTS `form_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `page_id` INT UNSIGNED DEFAULT NULL,
  `page_url` VARCHAR(500) NOT NULL,
  `url_hash` CHAR(32) NOT NULL,
  `page_title` VARCHAR(190) DEFAULT NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_page` (`form_id`, `url_hash`),
  KEY `idx_form_pages_page` (`page_id`),
  CONSTRAINT `fk_form_pages_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_scans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `engine` ENUM('http','browser') NOT NULL DEFAULT 'http',
  `trigger_by` VARCHAR(20) NOT NULL DEFAULT 'cron',
  `pages_scanned` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `browser_pages` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `forms_found` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `forms_new` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `forms_changed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `forms_removed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `popups_found` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('running','done','partial','failed') NOT NULL DEFAULT 'running',
  `summary` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form_scans_site` (`website_id`, `id`),
  CONSTRAINT `fk_form_scans_site` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `reason` VARCHAR(190) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fsh_form` (`form_id`, `changed_at`),
  CONSTRAINT `fk_fsh_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `form_discovery_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `page_monitoring_enabled`,
  ADD COLUMN IF NOT EXISTS `forms_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `page_health`,
  ADD COLUMN IF NOT EXISTS `forms_working` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_total`,
  ADD COLUMN IF NOT EXISTS `forms_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_working`,
  ADD COLUMN IF NOT EXISTS `forms_blocked` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_failed`,
  ADD COLUMN IF NOT EXISTS `forms_removed` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_blocked`,
  ADD COLUMN IF NOT EXISTS `forms_normal` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_removed`,
  ADD COLUMN IF NOT EXISTS `forms_popup` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_normal`,
  ADD COLUMN IF NOT EXISTS `forms_ajax` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `forms_popup`,
  ADD COLUMN IF NOT EXISTS `last_form_scan_at` DATETIME DEFAULT NULL AFTER `forms_ajax`,
  ADD COLUMN IF NOT EXISTS `form_scan_requested` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = full (browser) discovery scan due at the next cron run' AFTER `last_form_scan_at`;

ALTER TABLE `website_pages`
  ADD COLUMN IF NOT EXISTS `clean_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL without .php when the site serves it (NULL = not checked, empty = no clean URL)' AFTER `url`;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('form_discovery_enabled', '1'),
('form_scan_interval_hours', '24'),
('form_scan_max_pages', '60'),
('form_scan_browser_pages', '12'),
('form_scan_per_run', '3');

UPDATE `settings` SET `setting_value` = 'clientcare@outlinestudio.in' WHERE `setting_key` IN ('from_email', 'reply_to_email') AND `setting_value` LIKE '%outlinemedia.in';
UPDATE `settings` SET `setting_value` = '10' WHERE `setting_key` = 'form_check_interval' AND `setting_value` IN ('', '5');
