-- =====================================================================
-- Upgrade 3.0 → 3.1: premium UI release – scheduler/worker layer, platform analytics, large-data indexes
-- Safe to run more than once (IF NOT EXISTS / INSERT IGNORE).
-- =====================================================================

-- Background job registry shared by cron/run-all.php, cron/worker.php and the web heartbeat (includes/Scheduler.php)
CREATE TABLE IF NOT EXISTS `scheduler_jobs` (
  `name` VARCHAR(40) NOT NULL,
  `label` VARCHAR(80) NOT NULL,
  `interval_minutes` INT UNSIGNED NOT NULL DEFAULT 5,
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('idle','running','ok','failed') NOT NULL DEFAULT 'idle',
  `locked_until` DATETIME DEFAULT NULL,
  `locked_by` VARCHAR(120) DEFAULT NULL,
  `last_started_at` DATETIME DEFAULT NULL,
  `last_finished_at` DATETIME DEFAULT NULL,
  `last_duration_ms` INT UNSIGNED DEFAULT NULL,
  `last_message` VARCHAR(500) DEFAULT NULL,
  `run_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`name`),
  KEY `idx_sched_lock` (`enabled`, `locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `scheduler_jobs` (`name`, `label`, `interval_minutes`, `priority`, `enabled`, `created_at`) VALUES
('process-notifications', 'Alert delivery', 2, 0, 1, NOW()),
('check-websites', 'Website monitoring', 5, 1, 1, NOW()),
('check-pages', 'Page monitoring', 5, 2, 1, NOW()),
('check-ssl', 'SSL monitoring', 5, 3, 1, NOW()),
('check-forms', 'Form testing', 5, 4, 1, NOW()),
('check-expiry', 'Domain / hosting expiry', 1380, 5, 1, NOW()),
('discover-forms', 'Form discovery', 5, 6, 1, NOW()),
('housekeeping', 'Housekeeping', 1380, 7, 1, NOW());

-- Platform settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('web_heartbeat_enabled', '1'),
('maintenance_mode', '0'),
('maintenance_message', '');

-- ---------------------------------------------------------------------
-- Indexes for very large datasets (tenant + time based listings, dashboards, charts, platform analytics)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_last_login` (`last_login_at`),
  ADD INDEX IF NOT EXISTS `idx_users_created` (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_users_role` (`role`, `status`);
ALTER TABLE `tenants`
  ADD INDEX IF NOT EXISTS `idx_tenants_created` (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_tenants_active` (`last_active_at`);
ALTER TABLE `subscriptions`
  ADD INDEX IF NOT EXISTS `idx_subs_started` (`tenant_id`, `started_at`);
ALTER TABLE `websites`
  ADD INDEX IF NOT EXISTS `idx_websites_tenant_created` (`tenant_id`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_websites_tenant_ssl` (`tenant_id`, `ssl_status`),
  ADD INDEX IF NOT EXISTS `idx_websites_tenant_updated` (`tenant_id`, `updated_at`);
ALTER TABLE `website_pages`
  ADD INDEX IF NOT EXISTS `idx_pages_tenant_status` (`tenant_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_pages_tenant_failed` (`tenant_id`, `last_failed_at`);
ALTER TABLE `forms`
  ADD INDEX IF NOT EXISTS `idx_forms_tenant_tested` (`tenant_id`, `last_tested_at`),
  ADD INDEX IF NOT EXISTS `idx_forms_tenant_created` (`tenant_id`, `created_at`);
ALTER TABLE `form_tests`
  ADD INDEX IF NOT EXISTS `idx_tests_result_time` (`result`, `tested_at`);
ALTER TABLE `website_monitoring`
  ADD INDEX IF NOT EXISTS `idx_monitoring_time` (`checked_at`),
  ADD INDEX IF NOT EXISTS `idx_monitoring_status_time` (`status`, `checked_at`);
ALTER TABLE `website_uptime_daily`
  ADD INDEX IF NOT EXISTS `idx_uptime_day_site` (`day`, `website_id`);
ALTER TABLE `website_incidents`
  ADD INDEX IF NOT EXISTS `idx_wi_tenant_started` (`tenant_id`, `started_at`);
ALTER TABLE `page_incidents`
  ADD INDEX IF NOT EXISTS `idx_pi_tenant_started` (`tenant_id`, `started_at`);
ALTER TABLE `form_incidents`
  ADD INDEX IF NOT EXISTS `idx_fi_tenant_started` (`tenant_id`, `started_at`);
ALTER TABLE `ssl_incidents`
  ADD INDEX IF NOT EXISTS `idx_si_tenant_started` (`tenant_id`, `started_at`);
ALTER TABLE `notifications`
  ADD INDEX IF NOT EXISTS `idx_notif_tenant_created` (`tenant_id`, `created_at`);
ALTER TABLE `activity_logs`
  ADD INDEX IF NOT EXISTS `idx_activity_tenant_time` (`tenant_id`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_activity_action_time` (`action`, `created_at`);
ALTER TABLE `email_logs`
  ADD INDEX IF NOT EXISTS `idx_email_logs_tenant_time` (`tenant_id`, `sent_at`);
ALTER TABLE `clients`
  ADD INDEX IF NOT EXISTS `idx_clients_tenant_created` (`tenant_id`, `created_at`);
ALTER TABLE `domains`
  ADD INDEX IF NOT EXISTS `idx_domains_tenant_expiry` (`tenant_id`, `expiry_date`);
ALTER TABLE `hosting`
  ADD INDEX IF NOT EXISTS `idx_hosting_tenant_expiry` (`tenant_id`, `expiry_date`);
ALTER TABLE `login_attempts`
  ADD INDEX IF NOT EXISTS `idx_attempts_time` (`attempted_at`);
