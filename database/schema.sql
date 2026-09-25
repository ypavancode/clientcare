-- =====================================================================
--  Outline Media – Client Website Monitoring & Management CRM
--  MySQL / MariaDB schema  (import via phpMyAdmin or: mysql -u user -p dbname < schema.sql)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users & authentication
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','manager','staff') NOT NULL DEFAULT 'staff',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `phone` VARCHAR(30) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `reset_token` VARCHAR(64) DEFAULT NULL,
  `reset_expires` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login` VARCHAR(190) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip` (`ip_address`,`attempted_at`),
  KEY `idx_attempts_login` (`login`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `selector` VARCHAR(24) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `company` VARCHAR(150) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `whatsapp` VARCHAR(30) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `monitoring_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_client` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clients_status` (`status`),
  KEY `idx_clients_assigned` (`assigned_user_id`),
  KEY `idx_clients_name` (`name`),
  KEY `idx_clients_status_name` (`status`, `name`),
  KEY `idx_clients_created` (`created_at`),
  KEY `idx_clients_updated` (`updated_at`),
  KEY `idx_clients_email` (`email`),
  FULLTEXT KEY `ft_clients` (`name`, `company`, `email`),
  CONSTRAINT `fk_clients_assigned` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Websites
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `websites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `admin_url` VARCHAR(255) DEFAULT NULL,
  `figma_url` VARCHAR(500) DEFAULT NULL COMMENT 'Figma design URL',
  `xd_url` VARCHAR(500) DEFAULT NULL COMMENT 'Adobe XD design URL',
  `demo_url` VARCHAR(500) DEFAULT NULL COMMENT 'HTML / static demo URL',
  `reference_url` VARCHAR(500) DEFAULT NULL COMMENT 'Other reference URL',
  `wp_login_url` VARCHAR(255) DEFAULT NULL,
  `wp_username` VARCHAR(190) DEFAULT NULL,
  `wp_password` VARCHAR(500) DEFAULT NULL COMMENT 'AES-256 encrypted with APP_KEY',
  `technology` VARCHAR(40) NOT NULL DEFAULT 'PHP',
  `hosting_login_ref` VARCHAR(255) DEFAULT NULL,
  `monitoring_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `page_monitoring_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `max_pages` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global setting',
  `expect_text` VARCHAR(190) DEFAULT NULL COMMENT 'Optional text that must appear on the homepage',
  -- uptime
  `status` ENUM('down','server_error','timeout','ssl_error','parked','content_error','redirecting','unknown','paused','online') NOT NULL DEFAULT 'unknown',
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time` INT UNSIGNED DEFAULT NULL COMMENT 'milliseconds',
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_success_at` DATETIME DEFAULT NULL,
  `last_failed_at` DATETIME DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  -- ssl
  `ssl_status` ENUM('unknown','valid','expiring_soon','expired','error') NOT NULL DEFAULT 'unknown',
  `ssl_expires_at` DATETIME DEFAULT NULL,
  `ssl_days_left` INT DEFAULT NULL,
  `ssl_issuer` VARCHAR(190) DEFAULT NULL,
  `ssl_error` VARCHAR(500) DEFAULT NULL,
  `ssl_checked_at` DATETIME DEFAULT NULL,
  -- page-level monitoring summary
  `pages_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_ok` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pages_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `page_health` ENUM('unknown','good','warning','failed') NOT NULL DEFAULT 'unknown',
  `last_scan_at` DATETIME DEFAULT NULL,
  `last_scan_duration` INT UNSIGNED DEFAULT NULL COMMENT 'milliseconds',
  `pages_discovered_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_websites_client` (`client_id`),
  KEY `idx_websites_page_health` (`page_health`),
  KEY `idx_websites_mon_scan` (`monitoring_enabled`, `page_monitoring_enabled`, `last_scan_at`),
  KEY `idx_websites_status` (`status`),
  KEY `idx_websites_ssl` (`ssl_status`),
  KEY `idx_websites_checked` (`last_checked_at`),
  KEY `idx_websites_client_status` (`client_id`, `status`),
  KEY `idx_websites_mon_checked` (`monitoring_enabled`, `last_checked_at`),
  KEY `idx_websites_mon_ssl` (`monitoring_enabled`, `ssl_checked_at`),
  KEY `idx_websites_name` (`name`),
  KEY `idx_websites_ssl_expiry` (`ssl_expires_at`),
  KEY `idx_websites_status_name` (`status`, `name`),
  FULLTEXT KEY `ft_websites` (`name`, `url`),
  CONSTRAINT `fk_websites_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_monitoring` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time` INT UNSIGNED DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_monitoring_site_time` (`website_id`,`checked_at`),
  CONSTRAINT `fk_monitoring_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
  KEY `idx_incidents_site` (`website_id`,`started_at`),
  KEY `idx_incidents_open` (`resolved_at`),
  KEY `idx_incidents_started` (`started_at`),
  CONSTRAINT `fk_incidents_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ssl_monitoring` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `expiry_date` DATETIME DEFAULT NULL,
  `days_remaining` INT DEFAULT NULL,
  `issuer` VARCHAR(190) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ssl_site_time` (`website_id`,`checked_at`),
  CONSTRAINT `fk_ssl_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `website_uptime_daily` (
  `website_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `checks` INT UNSIGNED NOT NULL DEFAULT 0,
  `up_checks` INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_response` INT UNSIGNED DEFAULT NULL,
  `max_response` INT UNSIGNED DEFAULT NULL,
  `incidents` INT UNSIGNED NOT NULL DEFAULT 0,
  `downtime_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`),
  KEY `idx_uptime_day` (`day`),
  CONSTRAINT `fk_uptime_daily_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `type` ENUM('wordpress','domain','hosting','other','cpanel','ftp','email','database') NOT NULL DEFAULT 'other',
  `label` VARCHAR(120) NOT NULL,
  `provider` VARCHAR(120) DEFAULT NULL COMMENT 'Registrar / hosting provider / service name',
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
-- Domains & hosting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domains` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL,
  `domain_name` VARCHAR(190) NOT NULL,
  `registrar` VARCHAR(120) DEFAULT NULL,
  `registration_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 0,
  `login_ref` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_domains_client` (`client_id`),
  KEY `idx_domains_website` (`website_id`),
  KEY `idx_domains_expiry` (`expiry_date`),
  KEY `idx_domains_name` (`domain_name`),
  FULLTEXT KEY `ft_domains` (`domain_name`, `registrar`),
  CONSTRAINT `fk_domains_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_domains_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hosting` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL,
  `provider` VARCHAR(120) NOT NULL,
  `server_ip` VARCHAR(100) DEFAULT NULL,
  `plan` VARCHAR(120) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `renewal_status` ENUM('auto','manual','cancelled') NOT NULL DEFAULT 'manual',
  `login_ref` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hosting_client` (`client_id`),
  KEY `idx_hosting_website` (`website_id`),
  KEY `idx_hosting_expiry` (`expiry_date`),
  KEY `idx_hosting_provider` (`provider`),
  CONSTRAINT `fk_hosting_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hosting_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Forms & tests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `forms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `page_url` VARCHAR(255) NOT NULL,
  `form_url` VARCHAR(255) DEFAULT NULL COMMENT 'Action/handler URL. Blank = same as page URL',
  `form_type` VARCHAR(40) NOT NULL DEFAULT 'Contact',
  `method` ENUM('POST','GET') NOT NULL DEFAULT 'POST',
  `ajax` TINYINT(1) NOT NULL DEFAULT 0,
  `recipient_email` VARCHAR(255) DEFAULT NULL,
  `cc_email` VARCHAR(255) DEFAULT NULL,
  `bcc_email` VARCHAR(255) DEFAULT NULL,
  `smtp_provider` VARCHAR(120) DEFAULT NULL,
  `auto_test` TINYINT(1) NOT NULL DEFAULT 1,
  `test_interval` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Minutes between automatic tests. 0 = use global setting',
  `test_payload` TEXT DEFAULT NULL COMMENT 'field=value per line; supports {{test_email}} {{token}} {{name}} {{phone}} {{message}}',
  `success_match` VARCHAR(255) DEFAULT NULL COMMENT 'Text or URL fragment expected on success',
  `expect_http_code` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Expected HTTP response code (blank = any 2xx/3xx)',
  `expect_redirect` VARCHAR(255) DEFAULT NULL COMMENT 'Expected redirect URL (or fragment) after submission',
  `captcha_type` ENUM('none','recaptcha','hcaptcha','turnstile','other') NOT NULL DEFAULT 'none' COMMENT 'CAPTCHA / anti-bot protection configured by the admin',
  `captcha_mode` ENUM('detect','test_url','test_field') NOT NULL DEFAULT 'detect' COMMENT 'detect = report Blocked by CAPTCHA (never bypass); test_url = owner-approved test page; test_field = owner-approved test parameter',
  `captcha_test_url` VARCHAR(255) DEFAULT NULL,
  `captcha_test_field` VARCHAR(255) DEFAULT NULL,
  `captcha_detected` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('failed','captcha_blocked','not_tested','working','disabled') NOT NULL DEFAULT 'not_tested',
  `last_tested_at` DATETIME DEFAULT NULL,
  `last_result` ENUM('success','failed','blocked') DEFAULT NULL,
  `last_response_code` SMALLINT UNSIGNED DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `last_failure_reason` VARCHAR(80) DEFAULT NULL,
  `last_outcome` VARCHAR(30) DEFAULT NULL,
  `last_email_received` ENUM('yes','no','unknown') DEFAULT NULL,
  `failed_tests` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive failed tests',
  `last_success_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forms_website` (`website_id`),
  KEY `idx_forms_status` (`status`),
  KEY `idx_forms_tested` (`last_tested_at`),
  KEY `idx_forms_website_status` (`website_id`, `status`),
  KEY `idx_forms_auto_tested` (`auto_test`, `status`, `last_tested_at`),
  KEY `idx_forms_name` (`name`),
  KEY `idx_forms_status_tested` (`status`, `last_tested_at`),
  FULLTEXT KEY `ft_forms` (`name`, `page_url`, `recipient_email`),
  CONSTRAINT `fk_forms_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_tests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `tested_at` DATETIME NOT NULL,
  `test_mode` ENUM('availability','submission') NOT NULL DEFAULT 'availability',
  `result` ENUM('success','failed','blocked') NOT NULL,
  `outcome` VARCHAR(30) DEFAULT NULL,
  `captcha_type` VARCHAR(30) DEFAULT NULL,
  `interference` VARCHAR(190) DEFAULT NULL,
  `failure_reason` VARCHAR(80) DEFAULT NULL,
  `http_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time` INT UNSIGNED DEFAULT NULL,
  `response_text` VARCHAR(500) DEFAULT NULL,
  `email_received` ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
  `error` VARCHAR(500) DEFAULT NULL,
  `test_token` VARCHAR(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tests_form_time` (`form_id`,`tested_at`),
  KEY `idx_tests_time` (`tested_at`),
  CONSTRAINT `fk_tests_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notifications, email, alerts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('critical','warning','recovery','info') NOT NULL DEFAULT 'info',
  `category` VARCHAR(40) NOT NULL,
  `title` VARCHAR(250) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL,
  `form_id` INT UNSIGNED DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_read` (`is_read`,`created_at`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_client` (`client_id`),
  KEY `idx_notif_website` (`website_id`),
  KEY `idx_notif_created` (`created_at`),
  KEY `idx_notif_category` (`category`),
  CONSTRAINT `fk_notif_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(190) NOT NULL,
  `to_name` VARCHAR(150) DEFAULT NULL,
  `cc` VARCHAR(255) DEFAULT NULL,
  `bcc` VARCHAR(255) DEFAULT NULL,
  `subject` VARCHAR(250) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'general',
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(1000) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_status` (`status`,`created_at`),
  KEY `idx_queue_ref` (`category`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(190) NOT NULL,
  `cc` VARCHAR(255) DEFAULT NULL,
  `bcc` VARCHAR(255) DEFAULT NULL,
  `subject` VARCHAR(250) NOT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'general',
  `template` VARCHAR(40) DEFAULT NULL,
  `status` ENUM('sent','failed') NOT NULL,
  `message_id` VARCHAR(190) DEFAULT NULL,
  `smtp_response` VARCHAR(500) DEFAULT NULL,
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `error` VARCHAR(1000) DEFAULT NULL,
  `sent_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_logs_time` (`sent_at`),
  KEY `idx_email_logs_status_time` (`status`, `sent_at`),
  KEY `idx_email_logs_to` (`to_email`),
  KEY `idx_email_logs_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `type` VARCHAR(40) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(250) NOT NULL,
  `body` TEXT NOT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_log` (
  `alert_key` VARCHAR(190) NOT NULL,
  `sent_at` DATETIME NOT NULL,
  PRIMARY KEY (`alert_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Activity log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(60) NOT NULL,
  `description` VARCHAR(1000) NOT NULL,
  `client_id` INT UNSIGNED DEFAULT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL,
  `form_id` INT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_time` (`created_at`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_client` (`client_id`),
  KEY `idx_activity_website` (`website_id`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Website projects (project management module)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`),
  KEY `idx_departments_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: type belongs to this department; NULL = any department',
  `name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_types_department` (`department_id`, `status`, `sort_order`),
  KEY `idx_types_name` (`name`),
  CONSTRAINT `fk_types_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL COMMENT 'Linked monitoring website record (shared with the monitoring module)',
  `project_name` VARCHAR(150) NOT NULL,
  `website_name` VARCHAR(150) DEFAULT NULL,
  `website_url` VARCHAR(255) DEFAULT NULL,
  `project_kind` ENUM('new','existing') NOT NULL DEFAULT 'new',
  `status` ENUM('design','development','testing','client_review','changes_required','ready_for_launch','live','on_hold','cancelled') NOT NULL DEFAULT 'design',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `website_type_id` INT UNSIGNED DEFAULT NULL,
  `technology` VARCHAR(40) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `expected_launch_date` DATE DEFAULT NULL,
  `actual_launch_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_projects_client` (`client_id`),
  KEY `idx_projects_website` (`website_id`),
  KEY `idx_projects_status` (`status`, `project_name`),
  KEY `idx_projects_kind` (`project_kind`),
  KEY `idx_projects_department` (`department_id`),
  KEY `idx_projects_type` (`website_type_id`),
  KEY `idx_projects_assigned` (`assigned_user_id`),
  KEY `idx_projects_start` (`start_date`),
  KEY `idx_projects_launch` (`expected_launch_date`),
  KEY `idx_projects_name` (`project_name`),
  FULLTEXT KEY `ft_projects` (`project_name`, `website_name`, `website_url`),
  CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_type` FOREIGN KEY (`website_type_id`) REFERENCES `website_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `changed_by` INT UNSIGNED DEFAULT NULL,
  `changed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_history_project` (`project_id`, `changed_at`),
  CONSTRAINT `fk_history_project` FOREIGN KEY (`project_id`) REFERENCES `website_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
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

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Default data
-- =====================================================================

-- Default admin:  admin@example.com  /  Admin@123   (change immediately after first login)
INSERT INTO `users` (`name`, `email`, `username`, `password`, `role`, `status`) VALUES
('Administrator', 'admin@example.com', 'admin', '$2y$10$NVaCkiVqNpxo8EgZ9dpxe.Gkcs/2TJ01mwPA3VZZmEybMAxBZN.dm', 'admin', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Outline Media'),
('timezone', 'Asia/Kolkata'),
('date_format', 'd-M-Y'),
('website_check_interval', '5'),
('form_check_interval', '10'),
('check_timeout', '15'),
('retry_attempts', '2'),
('ssl_warning_days', '30'),
('ssl_alert_days', '30,10'),
('domain_alert_days', '30,10'),
('hosting_alert_days', '30,10'),
('cron_stale_minutes', '12'),
('check_concurrency', '25'),
('retention_monitoring_days', '7'),
('retention_form_tests_days', '90'),
('retention_activity_days', '365'),
('retention_email_logs_days', '180'),
('retention_notifications_days', '90'),
('form_test_email', 'website-test@example.com'),
('form_stale_hours', '48'),
('page_monitoring_enabled', '1'),
('page_max_pages', '50'),
('page_discovery_hours', '24'),
('page_check_concurrency', '15'),
('page_alert_threshold', '3'),
('page_ignore_patterns', ''),
('retention_page_history_days', '30'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_encryption', 'tls'),
('from_email', 'clientcare@outlinestudio.in'),
('from_name', 'Outline Media Client Care'),
('reply_to_email', 'clientcare@outlinestudio.in'),
('notification_email', ''),
('notify_clients', '1'),
('notify_system_errors', '1')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- Default departments (editable in Website Projects → Departments & Types)
INSERT IGNORE INTO `departments` (`name`, `sort_order`) VALUES
('Education', 10), ('Hospital', 20), ('Healthcare', 30), ('Real Estate', 40), ('Finance', 50), ('Jewellery', 60), ('Construction', 70),
('Hospitality', 80), ('Restaurant', 90), ('E-commerce', 100), ('IT', 110), ('Manufacturing', 120), ('Corporate', 130), ('Travel', 140),
('Tourism', 150), ('Legal', 160), ('Automobile', 170), ('NGO', 180), ('Government', 190), ('Other', 999);

-- Default website types (grouped by department where it makes sense)
INSERT IGNORE INTO `website_types` (`department_id`, `name`, `sort_order`)
SELECT d.id, t.name, t.so FROM (
  SELECT 'Education' AS dept, 'School' AS name, 10 AS so UNION ALL SELECT 'Education', 'College', 20 UNION ALL SELECT 'Education', 'University', 30 UNION ALL SELECT 'Education', 'Coaching Institute', 40 UNION ALL SELECT 'Education', 'Educational Platform', 50
  UNION ALL SELECT 'Healthcare', 'Hospital', 10 UNION ALL SELECT 'Healthcare', 'Clinic', 20 UNION ALL SELECT 'Healthcare', 'Diagnostic Center', 30 UNION ALL SELECT 'Healthcare', 'Pharmacy', 40
  UNION ALL SELECT 'Hospital', 'Multi-speciality Hospital', 10 UNION ALL SELECT 'Hospital', 'Speciality Hospital', 20
  UNION ALL SELECT 'Real Estate', 'Villas', 10 UNION ALL SELECT 'Real Estate', 'Apartments', 20 UNION ALL SELECT 'Real Estate', 'Commercial', 30 UNION ALL SELECT 'Real Estate', 'Property Listing', 40 UNION ALL SELECT 'Real Estate', 'Villa Project', 50
  UNION ALL SELECT 'Construction', 'Construction Company', 10 UNION ALL SELECT 'Construction', 'Builders & Developers', 20
  UNION ALL SELECT 'E-commerce', 'Fashion', 10 UNION ALL SELECT 'E-commerce', 'Jewellery Store', 20 UNION ALL SELECT 'E-commerce', 'Electronics', 30 UNION ALL SELECT 'E-commerce', 'Grocery', 40 UNION ALL SELECT 'E-commerce', 'Marketplace', 50
  UNION ALL SELECT 'Jewellery', 'Jewellery Showroom', 10 UNION ALL SELECT 'Jewellery', 'Jewellery Brand', 20
  UNION ALL SELECT 'Hospitality', 'Hotel', 10 UNION ALL SELECT 'Hospitality', 'Resort', 20
  UNION ALL SELECT 'Restaurant', 'Restaurant', 10 UNION ALL SELECT 'Restaurant', 'Cafe', 20 UNION ALL SELECT 'Restaurant', 'Cloud Kitchen', 30
  UNION ALL SELECT 'Corporate', 'Corporate Website', 10 UNION ALL SELECT 'Corporate', 'Landing Page', 20 UNION ALL SELECT 'Corporate', 'Portfolio', 30
  UNION ALL SELECT 'IT', 'Software Company', 10 UNION ALL SELECT 'IT', 'SaaS Product', 20
  UNION ALL SELECT 'Finance', 'Bank / NBFC', 10 UNION ALL SELECT 'Finance', 'Financial Advisor', 20
  UNION ALL SELECT 'Travel', 'Travel Agency', 10 UNION ALL SELECT 'Tourism', 'Tourism Portal', 10
  UNION ALL SELECT 'Automobile', 'Car Dealer', 10 UNION ALL SELECT 'Automobile', 'Auto Service', 20
  UNION ALL SELECT 'Manufacturing', 'Manufacturer', 10 UNION ALL SELECT 'Legal', 'Law Firm', 10 UNION ALL SELECT 'NGO', 'NGO / Trust', 10 UNION ALL SELECT 'Government', 'Government Portal', 10
  UNION ALL SELECT 'Other', 'Other', 999
) t JOIN departments d ON d.name = t.dept;
