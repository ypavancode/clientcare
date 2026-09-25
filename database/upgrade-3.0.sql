-- =====================================================================
-- Upgrade 2.0 -> 3.0: MULTI-TENANT SAAS PLATFORM
--   * tenants (customer accounts / workspaces) – every customer-owned record carries tenant_id
--   * plans + subscriptions (billing-ready, no payment processing yet), free trial
--   * user registration with email verification, team roles (owner / admin / manager / viewer / notify-only), invitations
--   * API keys, webhooks, status pages, per-tenant settings, monitoring job locks
--   * existing data is migrated into tenant #1 (the platform owner's own workspace); the first admin becomes platform admin
-- Safe to run more than once.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- plans
CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `tagline` VARCHAR(190) DEFAULT NULL,
  `price_monthly` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = custom pricing (contact sales)',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
  `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `trial_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `max_websites` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  `max_pages` INT UNSIGNED DEFAULT NULL,
  `max_forms` INT UNSIGNED DEFAULT NULL,
  `max_users` INT UNSIGNED DEFAULT NULL,
  `max_status_pages` INT UNSIGNED DEFAULT 0,
  `website_interval` SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'minutes',
  `page_interval` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `form_interval` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `ssl_interval` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  `features` TEXT DEFAULT NULL COMMENT 'JSON feature flags',
  `highlights` TEXT DEFAULT NULL COMMENT 'JSON list of bullet points for the pricing page',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `plans` (`code`, `name`, `tagline`, `price_monthly`, `is_popular`, `is_public`, `trial_days`, `sort_order`, `max_websites`, `max_pages`, `max_forms`, `max_users`, `max_status_pages`, `website_interval`, `page_interval`, `form_interval`, `ssl_interval`, `retention_days`, `features`, `highlights`) VALUES
('free', 'Free', 'For testing and small personal projects', 0, 0, 1, 0, 10, 2, 100, 20, 1, 0, 60, 60, 60, 60, 7,
 '{"form_discovery":1,"popup_discovery":0,"ajax_forms":1,"captcha_detection":1,"hosting":0,"reports":"none","status_pages":0,"white_label":0,"custom_domain":0,"webhooks":0,"api":"none","notification_rules":0,"maintenance_windows":0,"client_contacts":0,"team_permissions":0,"priority_support":0,"incident_history":"basic"}',
 '["2 websites","100 monitored pages","20 forms","1 user","Website monitoring every 60 minutes","Form monitoring every 60 minutes","SSL monitoring","Domain expiry monitoring","Basic email alerts","7-day monitoring history","Basic dashboard"]'),
('starter', 'Starter', 'For freelancers and small businesses', 499, 0, 1, 14, 20, 10, 500, 100, 3, 0, 30, 30, 30, 30, 30,
 '{"form_discovery":1,"popup_discovery":1,"ajax_forms":1,"captcha_detection":1,"hosting":1,"reports":"basic","status_pages":0,"white_label":0,"custom_domain":0,"webhooks":0,"api":"none","notification_rules":0,"maintenance_windows":0,"client_contacts":0,"team_permissions":0,"priority_support":0,"incident_history":"basic"}',
 '["10 websites","500 monitored pages","100 forms","3 users","Website monitoring every 30 minutes","Form monitoring every 30 minutes","SSL monitoring","Domain monitoring","Hosting expiry tracking","Automatic form discovery","Popup form discovery","Email alerts + recovery alerts","30-day monitoring history","Basic reports"]'),
('professional', 'Professional', 'Most popular – for growing businesses and web studios', 999, 1, 1, 14, 30, 30, 2000, 500, 10, 1, 10, 10, 10, 10, 90,
 '{"form_discovery":1,"popup_discovery":1,"ajax_forms":1,"captcha_detection":1,"hosting":1,"reports":"standard","status_pages":1,"white_label":0,"custom_domain":0,"webhooks":0,"api":"basic","notification_rules":1,"maintenance_windows":0,"client_contacts":0,"team_permissions":0,"priority_support":0,"incident_history":"detailed"}',
 '["30 websites","2,000 monitored pages","500 forms","10 users","Website, form and SSL monitoring every 10 minutes","Domain + hosting monitoring","Automatic page & form discovery","Popup + AJAX form monitoring","CAPTCHA detection","Email + recovery alerts","Detailed incident history","90-day data retention","Reports","Custom notification rules","Status page","API access"]'),
('business', 'Business', 'For agencies and larger businesses', 2499, 0, 1, 14, 40, 100, 10000, 2000, 25, 5, 5, 10, 5, 10, 365,
 '{"form_discovery":1,"popup_discovery":1,"ajax_forms":1,"captcha_detection":1,"hosting":1,"reports":"advanced","status_pages":1,"white_label":1,"custom_domain":1,"webhooks":1,"api":"advanced","notification_rules":1,"maintenance_windows":1,"client_contacts":1,"team_permissions":1,"priority_support":0,"incident_history":"advanced"}',
 '["Everything in Professional","100 websites","10,000 monitored pages","2,000 forms","25 users","Faster monitoring (every 5 minutes)","Advanced reports","White-label status pages","Multiple status pages + custom domains","Webhooks + advanced API","Advanced notification rules","1-year data retention","Team permissions","Client notification contacts","Maintenance windows","Advanced incident tracking"]'),
('agency', 'Agency / Enterprise', 'For agencies managing many client websites', NULL, 0, 1, 0, 50, NULL, NULL, NULL, NULL, 50, 5, 5, 5, 5, 730,
 '{"form_discovery":1,"popup_discovery":1,"ajax_forms":1,"captcha_detection":1,"hosting":1,"reports":"advanced","status_pages":1,"white_label":1,"custom_domain":1,"webhooks":1,"api":"advanced","notification_rules":1,"maintenance_windows":1,"client_contacts":1,"team_permissions":1,"priority_support":1,"client_portal":1,"white_label_dashboard":1,"white_label_email":1,"incident_history":"advanced"}',
 '["Custom website, page and form limits","Unlimited / negotiated users","Custom monitoring intervals","White-label dashboard + email notifications","Client portals","API access + webhooks","Advanced reports","Custom retention","Dedicated infrastructure options","Priority support"]');

-- ---------------------------------------------------------------- tenants
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL COMMENT 'Company / workspace name',
  `slug` VARCHAR(60) NOT NULL,
  `owner_user_id` INT UNSIGNED DEFAULT NULL,
  `plan_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('pending','active','suspended','cancelled') NOT NULL DEFAULT 'pending' COMMENT 'pending until the owner verifies the email',
  `subscription_status` ENUM('free','trial','active','past_due','cancelled','expired') NOT NULL DEFAULT 'free',
  `trial_ends_at` DATETIME DEFAULT NULL,
  `billing_email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `country` VARCHAR(60) DEFAULT NULL,
  `timezone` VARCHAR(60) DEFAULT NULL,
  `alert_emails` TEXT DEFAULT NULL COMMENT 'Extra alert recipients (comma separated)',
  `logo_file` VARCHAR(190) DEFAULT NULL,
  `white_label_name` VARCHAR(120) DEFAULT NULL,
  `white_label_from_name` VARCHAR(120) DEFAULT NULL,
  `white_label_from_email` VARCHAR(190) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL COMMENT 'Platform admin notes',
  `last_active_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`),
  KEY `idx_tenants_status` (`status`),
  KEY `idx_tenants_plan` (`plan_id`),
  KEY `idx_tenants_trial` (`subscription_status`, `trial_ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `status` ENUM('trial','active','past_due','cancelled','expired') NOT NULL DEFAULT 'trial',
  `started_at` DATETIME NOT NULL,
  `trial_ends_at` DATETIME DEFAULT NULL,
  `current_period_start` DATETIME DEFAULT NULL,
  `current_period_end` DATETIME DEFAULT NULL,
  `renews_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `amount` INT UNSIGNED DEFAULT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'INR',
  `billing_interval` ENUM('month','year') NOT NULL DEFAULT 'month',
  `provider` VARCHAR(30) DEFAULT NULL COMMENT 'razorpay / stripe / manual – added later',
  `provider_customer_ref` VARCHAR(120) DEFAULT NULL,
  `provider_subscription_ref` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subs_tenant` (`tenant_id`, `status`),
  KEY `idx_subs_plan` (`plan_id`),
  CONSTRAINT `fk_subs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tenant_settings` (
  `tenant_id` INT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `setting_key`),
  CONSTRAINT `fk_tsettings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- users: tenant, roles, verification, 2FA-ready
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `is_platform_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`,
  ADD COLUMN IF NOT EXISTS `email_verified_at` DATETIME DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` DATETIME DEFAULT NULL AFTER `email_verified_at`,
  ADD COLUMN IF NOT EXISTS `invited_by` INT UNSIGNED DEFAULT NULL AFTER `terms_accepted_at`,
  ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) DEFAULT NULL COMMENT '2FA (architecture ready)' AFTER `invited_by`,
  ADD COLUMN IF NOT EXISTS `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`,
  ADD COLUMN IF NOT EXISTS `job_title` VARCHAR(120) DEFAULT NULL AFTER `phone`;
ALTER TABLE `users` MODIFY `role` ENUM('owner','admin','manager','staff','viewer','notify') NOT NULL DEFAULT 'viewer';
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_tenant` (`tenant_id`, `status`);

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `purpose` ENUM('verify','change_email') NOT NULL DEFAULT 'verify',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ev_token` (`token_hash`),
  KEY `idx_ev_user` (`user_id`),
  CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_invitations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `name` VARCHAR(120) DEFAULT NULL,
  `role` ENUM('admin','manager','viewer','notify') NOT NULL DEFAULT 'viewer',
  `token_hash` CHAR(64) NOT NULL,
  `invited_by` INT UNSIGNED DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inv_tenant` (`tenant_id`, `accepted_at`),
  KEY `idx_inv_token` (`token_hash`),
  CONSTRAINT `fk_inv_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- tenant_id on every customer-owned table
ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_clients_tenant` (`tenant_id`, `status`);
ALTER TABLE `websites` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_websites_tenant` (`tenant_id`, `status`);
ALTER TABLE `forms` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_forms_tenant` (`tenant_id`, `status`);
ALTER TABLE `domains` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_domains_tenant` (`tenant_id`);
ALTER TABLE `hosting` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_hosting_tenant` (`tenant_id`);
ALTER TABLE `client_credentials` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_cred_tenant` (`tenant_id`);
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_notif_tenant` (`tenant_id`, `is_read`, `id`);
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_activity_tenant` (`tenant_id`, `id`);
ALTER TABLE `email_logs` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_emails_tenant` (`tenant_id`, `id`);
ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_queue_tenant` (`tenant_id`);
ALTER TABLE `website_pages` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_pages_tenant` (`tenant_id`, `is_active`);
ALTER TABLE `website_projects` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_projects_tenant` (`tenant_id`, `status`);
ALTER TABLE `departments` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = platform default' AFTER `id`;
ALTER TABLE `website_types` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = platform default' AFTER `id`;
ALTER TABLE `form_scans` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`;
ALTER TABLE `website_scans` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`;
ALTER TABLE `website_incidents` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_wi_tenant` (`tenant_id`, `resolved_at`);
ALTER TABLE `page_incidents` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_pi_tenant` (`tenant_id`, `resolved_at`);
ALTER TABLE `form_incidents` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_fi_tenant` (`tenant_id`, `resolved_at`);
ALTER TABLE `ssl_incidents` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`, ADD INDEX IF NOT EXISTS `idx_si_tenant` (`tenant_id`, `resolved_at`);
ALTER TABLE `alert_log` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `alert_key`;

-- ---------------------------------------------------------------- API keys, webhooks, status pages, locks
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(80) NOT NULL,
  `key_prefix` CHAR(10) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `scopes` VARCHAR(60) NOT NULL DEFAULT 'read' COMMENT 'read | read,write',
  `rate_limit_per_min` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME DEFAULT NULL,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_hash` (`key_hash`),
  KEY `idx_api_tenant` (`tenant_id`, `status`),
  CONSTRAINT `fk_api_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhooks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `secret` VARCHAR(64) DEFAULT NULL,
  `events` TEXT DEFAULT NULL COMMENT 'JSON list, empty = all events',
  `status` ENUM('active','paused','failed') NOT NULL DEFAULT 'active',
  `failures` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_status_code` SMALLINT UNSIGNED DEFAULT NULL,
  `last_delivered_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_webhooks_tenant` (`tenant_id`, `status`),
  CONSTRAINT `fk_webhooks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id` INT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(40) NOT NULL,
  `payload` TEXT NOT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `response_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wd_status` (`status`, `id`),
  KEY `idx_wd_tenant` (`tenant_id`, `id`),
  CONSTRAINT `fk_wd_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `status_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(60) NOT NULL,
  `description` VARCHAR(300) DEFAULT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `custom_domain` VARCHAR(190) DEFAULT NULL,
  `website_ids` TEXT DEFAULT NULL COMMENT 'JSON list, empty = all websites',
  `show_forms` TINYINT(1) NOT NULL DEFAULT 1,
  `show_ssl` TINYINT(1) NOT NULL DEFAULT 1,
  `show_incidents` TINYINT(1) NOT NULL DEFAULT 1,
  `show_uptime` TINYINT(1) NOT NULL DEFAULT 1,
  `theme_color` VARCHAR(7) NOT NULL DEFAULT '#FCAF17',
  `logo_file` VARCHAR(190) DEFAULT NULL,
  `footer_text` VARCHAR(300) DEFAULT NULL,
  `hide_branding` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_status_slug` (`slug`),
  KEY `idx_status_tenant` (`tenant_id`),
  CONSTRAINT `fk_status_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_locks` (
  `lock_key` VARCHAR(80) NOT NULL,
  `locked_until` DATETIME NOT NULL,
  `owner` VARCHAR(60) DEFAULT NULL,
  PRIMARY KEY (`lock_key`),
  KEY `idx_locks_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- migrate existing data into tenant #1
INSERT INTO `tenants` (`id`, `name`, `slug`, `plan_id`, `status`, `subscription_status`, `created_at`)
SELECT 1, IFNULL((SELECT setting_value FROM settings WHERE setting_key = 'company_name'), 'Outline Media'), 'outline-media', (SELECT id FROM plans WHERE code = 'agency'), 'active', 'active', NOW()
WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE id = 1);
INSERT INTO `subscriptions` (`tenant_id`, `plan_id`, `status`, `started_at`, `notes`)
SELECT 1, (SELECT id FROM plans WHERE code = 'agency'), 'active', NOW(), 'Platform owner workspace' WHERE NOT EXISTS (SELECT 1 FROM subscriptions WHERE tenant_id = 1);

UPDATE `users` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
UPDATE `users` SET `email_verified_at` = IFNULL(`email_verified_at`, `created_at`) WHERE `tenant_id` = 1;
UPDATE `users` SET `role` = 'viewer' WHERE `role` = 'staff';
UPDATE `users` SET `is_platform_admin` = 1, `role` = 'owner' WHERE `id` = (SELECT id FROM (SELECT MIN(id) AS id FROM users WHERE role IN ('admin','owner') AND tenant_id = 1) t);
UPDATE `tenants` SET `owner_user_id` = (SELECT MIN(id) FROM users WHERE tenant_id = 1 AND role = 'owner') WHERE id = 1 AND owner_user_id IS NULL;

UPDATE `clients` SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE `websites` w JOIN clients c ON c.id = w.client_id SET w.tenant_id = c.tenant_id WHERE w.tenant_id IS NULL;
UPDATE `forms` f JOIN websites w ON w.id = f.website_id SET f.tenant_id = w.tenant_id WHERE f.tenant_id IS NULL;
UPDATE `domains` d JOIN clients c ON c.id = d.client_id SET d.tenant_id = c.tenant_id WHERE d.tenant_id IS NULL;
UPDATE `hosting` h JOIN clients c ON c.id = h.client_id SET h.tenant_id = c.tenant_id WHERE h.tenant_id IS NULL;
UPDATE `client_credentials` cc JOIN clients c ON c.id = cc.client_id SET cc.tenant_id = c.tenant_id WHERE cc.tenant_id IS NULL;
UPDATE `website_pages` p JOIN websites w ON w.id = p.website_id SET p.tenant_id = w.tenant_id WHERE p.tenant_id IS NULL;
UPDATE `website_projects` p JOIN clients c ON c.id = p.client_id SET p.tenant_id = c.tenant_id WHERE p.tenant_id IS NULL;
UPDATE `form_scans` s JOIN websites w ON w.id = s.website_id SET s.tenant_id = w.tenant_id WHERE s.tenant_id IS NULL;
UPDATE `website_scans` s JOIN websites w ON w.id = s.website_id SET s.tenant_id = w.tenant_id WHERE s.tenant_id IS NULL;
UPDATE `website_incidents` i JOIN websites w ON w.id = i.website_id SET i.tenant_id = w.tenant_id WHERE i.tenant_id IS NULL;
UPDATE `page_incidents` i JOIN websites w ON w.id = i.website_id SET i.tenant_id = w.tenant_id WHERE i.tenant_id IS NULL;
UPDATE `form_incidents` i JOIN websites w ON w.id = i.website_id SET i.tenant_id = w.tenant_id WHERE i.tenant_id IS NULL;
UPDATE `ssl_incidents` i JOIN websites w ON w.id = i.website_id SET i.tenant_id = w.tenant_id WHERE i.tenant_id IS NULL;
UPDATE `notifications` SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE `activity_logs` SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE `email_logs` SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE `email_queue` SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE `alert_log` SET tenant_id = 1 WHERE tenant_id IS NULL;

-- ---------------------------------------------------------------- platform settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('platform_name', 'Outline Monitor'),
('platform_tagline', 'Website, page, form, SSL, domain and hosting monitoring – on autopilot'),
('registration_enabled', '1'),
('trial_plan', 'professional'),
('trial_days', '14'),
('verification_token_hours', '48'),
('log_retention_days', '30'),
('api_rate_limit_per_min', '60'),
('manual_check_cooldown_seconds', '60');
UPDATE `settings` SET `setting_value` = '1' WHERE `setting_key` = 'form_scan_interval_hours';
UPDATE `settings` SET `setting_value` = '30' WHERE `setting_key` IN ('retention_email_logs_days','retention_activity_days','retention_notifications_days','retention_page_history_days','retention_form_tests_days','retention_form_screenshots_days') AND CAST(`setting_value` AS UNSIGNED) > 30;

-- tenant 1 keeps the global alert recipients as its own workspace setting (other tenants configure their own)
INSERT IGNORE INTO tenant_settings (tenant_id, setting_key, setting_value) SELECT 1, 'notification_email', setting_value FROM settings WHERE setting_key = 'notification_email' AND setting_value <> '';
