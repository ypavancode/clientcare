-- Upgrade 1.3 -> 1.4: performance & scalability (indexes, full-text search, daily uptime rollups, retention settings)

-- Composite indexes for the list pages, dashboard counters and cron queries
ALTER TABLE `clients`
  ADD INDEX `idx_clients_status_name` (`status`, `name`),
  ADD INDEX `idx_clients_created` (`created_at`),
  ADD INDEX `idx_clients_updated` (`updated_at`),
  ADD INDEX `idx_clients_email` (`email`);

ALTER TABLE `websites`
  ADD INDEX `idx_websites_client_status` (`client_id`, `status`),
  ADD INDEX `idx_websites_mon_checked` (`monitoring_enabled`, `last_checked_at`),
  ADD INDEX `idx_websites_mon_ssl` (`monitoring_enabled`, `ssl_checked_at`),
  ADD INDEX `idx_websites_name` (`name`),
  ADD INDEX `idx_websites_ssl_expiry` (`ssl_expires_at`);

ALTER TABLE `forms`
  ADD INDEX `idx_forms_website_status` (`website_id`, `status`),
  ADD INDEX `idx_forms_auto_tested` (`auto_test`, `status`, `last_tested_at`),
  ADD INDEX `idx_forms_name` (`name`);

ALTER TABLE `tasks`
  ADD INDEX `idx_tasks_status_due` (`status`, `due_date`),
  ADD INDEX `idx_tasks_status_priority` (`status`, `priority`),
  ADD INDEX `idx_tasks_created` (`created_at`);

ALTER TABLE `notifications`
  ADD INDEX `idx_notif_created` (`created_at`),
  ADD INDEX `idx_notif_category` (`category`);

ALTER TABLE `activity_logs`
  ADD INDEX `idx_activity_form` (`form_id`);

ALTER TABLE `email_logs`
  ADD INDEX `idx_email_logs_status_time` (`status`, `sent_at`),
  ADD INDEX `idx_email_logs_to` (`to_email`),
  ADD INDEX `idx_email_logs_category` (`category`);

ALTER TABLE `email_queue`
  ADD INDEX `idx_queue_ref` (`category`, `ref_id`);

ALTER TABLE `domains`
  ADD INDEX `idx_domains_name` (`domain_name`);

ALTER TABLE `hosting`
  ADD INDEX `idx_hosting_provider` (`provider`);

ALTER TABLE `website_incidents`
  ADD INDEX `idx_incidents_started` (`started_at`);

ALTER TABLE `form_tests`
  ADD INDEX `idx_tests_time` (`tested_at`);

-- Full-text indexes for the global search box (InnoDB FULLTEXT, MySQL 5.6+ / MariaDB 10+)
ALTER TABLE `clients`  ADD FULLTEXT `ft_clients` (`name`, `company`, `email`);
ALTER TABLE `websites` ADD FULLTEXT `ft_websites` (`name`, `url`);
ALTER TABLE `forms`    ADD FULLTEXT `ft_forms` (`name`, `page_url`, `recipient_email`);
ALTER TABLE `tasks`    ADD FULLTEXT `ft_tasks` (`title`, `description`);
ALTER TABLE `domains`  ADD FULLTEXT `ft_domains` (`domain_name`, `registrar`);

-- Daily uptime rollups: raw checks are kept for a short window, long-range uptime comes from this table
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

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('check_concurrency', '25'),
('retention_monitoring_days', '7'),
('retention_form_tests_days', '90'),
('retention_activity_days', '365'),
('retention_email_logs_days', '180'),
('retention_notifications_days', '90');

-- Status ENUMs re-ordered by severity so "ORDER BY status" (indexed) replaces FIELD() sorting on large lists.
-- Values are matched by name, existing data is preserved.
ALTER TABLE `websites` MODIFY `status` ENUM('down','server_error','timeout','ssl_error','parked','content_error','redirecting','unknown','paused','online') NOT NULL DEFAULT 'unknown';
ALTER TABLE `forms` MODIFY `status` ENUM('failed','not_tested','working','disabled') NOT NULL DEFAULT 'not_tested';

ALTER TABLE `websites` ADD INDEX `idx_websites_status_name` (`status`, `name`);
ALTER TABLE `forms` ADD INDEX `idx_forms_status_tested` (`status`, `last_tested_at`);
