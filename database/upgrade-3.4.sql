-- =====================================================================
-- Upgrade 3.3 → 3.4: production scale – database job queue, worker registry, per-target plan schedules,
-- asynchronous analytics ingestion, shared sessions / cache for multi-server deployments.
-- Safe to run more than once (IF NOT EXISTS / INSERT IGNORE).
-- =====================================================================

-- ---------- Job queue (every monitoring check is one small, retryable, lockable job) ----------
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(24) NOT NULL COMMENT 'website | page | ssl | form | discovery | analytics | notification | report | maintenance',
  `type` VARCHAR(40) NOT NULL COMMENT 'website.check, website.ssl, website.pages, website.discovery, form.test, analytics.rollup, system.<job>',
  `tenant_id` INT UNSIGNED DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `payload` TEXT DEFAULT NULL,
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '0 = highest',
  `status` ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `available_at` DATETIME NOT NULL,
  `locked_by` VARCHAR(80) DEFAULT NULL,
  `locked_at` DATETIME DEFAULT NULL,
  `lease_until` DATETIME DEFAULT NULL,
  `claim_token` CHAR(32) DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `dedupe_key` VARCHAR(64) DEFAULT NULL COMMENT 'one pending/running job per target',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_dedupe` (`dedupe_key`),
  KEY `idx_jobs_claim` (`status`, `queue`, `priority`, `available_at`),
  KEY `idx_jobs_due` (`status`, `queue`, `available_at`),
  KEY `idx_jobs_lease` (`status`, `lease_until`),
  KEY `idx_jobs_token` (`claim_token`),
  KEY `idx_jobs_tenant` (`tenant_id`, `status`),
  KEY `idx_jobs_finished` (`status`, `finished_at`),
  KEY `idx_jobs_target` (`type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Worker registry (heartbeats; a worker whose heartbeat stops is declared dead and its jobs are re-queued) ----------
CREATE TABLE IF NOT EXISTS `workers` (
  `id` VARCHAR(80) NOT NULL,
  `host` VARCHAR(120) DEFAULT NULL,
  `pid` INT UNSIGNED DEFAULT NULL,
  `queues` VARCHAR(190) DEFAULT NULL,
  `status` ENUM('idle','busy','stopped','dead') NOT NULL DEFAULT 'idle',
  `current_job_id` BIGINT UNSIGNED DEFAULT NULL,
  `jobs_done` INT UNSIGNED NOT NULL DEFAULT 0,
  `jobs_failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `memory_mb` SMALLINT UNSIGNED DEFAULT NULL,
  `last_error` VARCHAR(300) DEFAULT NULL,
  `version` VARCHAR(20) DEFAULT NULL,
  `started_at` DATETIME NOT NULL,
  `heartbeat_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_workers_beat` (`status`, `heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Hourly throughput per queue (for the Scheduler Health page; tiny table) ----------
CREATE TABLE IF NOT EXISTS `queue_stats` (
  `hour` DATETIME NOT NULL,
  `queue` VARCHAR(24) NOT NULL,
  `processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`hour`, `queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Shared sessions + cache (multi-server: set SESSION_DRIVER / CACHE_DRIVER = 'database' in config.php) ----------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` VARCHAR(128) NOT NULL,
  `data` MEDIUMBLOB DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `tenant_id` INT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `last_activity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_activity` (`last_activity`),
  KEY `idx_sessions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_store` (
  `k` VARCHAR(190) NOT NULL,
  `v` MEDIUMBLOB DEFAULT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`k`),
  KEY `idx_cache_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Per-target schedule (plan interval → next_*_at; the dispatcher only reads rows that are due) ----------
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `next_check_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `last_checked_at`,
  ADD COLUMN IF NOT EXISTS `next_ssl_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `ssl_checked_at`,
  ADD COLUMN IF NOT EXISTS `next_scan_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `last_scan_at`,
  ADD COLUMN IF NOT EXISTS `next_discovery_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `last_form_scan_at`,
  ADD COLUMN IF NOT EXISTS `check_job_id` BIGINT UNSIGNED DEFAULT NULL AFTER `next_check_at`,
  ADD COLUMN IF NOT EXISTS `check_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `check_job_id`,
  ADD INDEX IF NOT EXISTS `idx_websites_due_check` (`monitoring_enabled`, `next_check_at`),
  ADD INDEX IF NOT EXISTS `idx_websites_due_ssl` (`monitoring_enabled`, `next_ssl_at`),
  ADD INDEX IF NOT EXISTS `idx_websites_due_scan` (`monitoring_enabled`, `page_monitoring_enabled`, `next_scan_at`),
  ADD INDEX IF NOT EXISTS `idx_websites_due_discovery` (`monitoring_enabled`, `form_discovery_enabled`, `next_discovery_at`),
  ADD INDEX IF NOT EXISTS `idx_websites_tenant_next` (`tenant_id`, `next_check_at`);

ALTER TABLE `forms`
  ADD COLUMN IF NOT EXISTS `next_test_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `last_tested_at`,
  ADD COLUMN IF NOT EXISTS `test_job_id` BIGINT UNSIGNED DEFAULT NULL AFTER `next_test_at`,
  ADD INDEX IF NOT EXISTS `idx_forms_due_test` (`auto_test`, `status`, `next_test_at`);

-- existing rows: keep the current cadence (last + 5 min) so an upgrade never causes a burst of checks
UPDATE `websites` SET `next_check_at` = IFNULL(DATE_ADD(`last_checked_at`, INTERVAL 5 MINUTE), NOW()) WHERE `next_check_at` IS NULL OR `next_check_at` = '0000-00-00 00:00:00';
UPDATE `websites` SET `next_ssl_at` = IFNULL(DATE_ADD(`ssl_checked_at`, INTERVAL 5 MINUTE), NOW()) WHERE `next_ssl_at` IS NULL;
UPDATE `websites` SET `next_scan_at` = IFNULL(DATE_ADD(`last_scan_at`, INTERVAL 5 MINUTE), NOW()) WHERE `next_scan_at` IS NULL;
UPDATE `websites` SET `next_discovery_at` = IFNULL(DATE_ADD(`last_form_scan_at`, INTERVAL 24 HOUR), NOW()) WHERE `next_discovery_at` IS NULL;
UPDATE `forms` SET `next_test_at` = IFNULL(DATE_ADD(`last_tested_at`, INTERVAL 10 MINUTE), NOW()) WHERE `next_test_at` IS NULL;

-- ---------- Analytics ingestion: raw events are appended by the beacon and rolled up by the analytics queue ----------
ALTER TABLE `analytics_events`
  ADD COLUMN IF NOT EXISTS `processed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_new_device`,
  ADD COLUMN IF NOT EXISTS `raw` TEXT DEFAULT NULL AFTER `processed`,
  ADD INDEX IF NOT EXISTS `idx_events_unprocessed` (`processed`, `id`);

-- ---------- Scheduler registry: only lightweight SYSTEM jobs remain here; monitoring runs through the queue ----------
DELETE FROM `scheduler_jobs` WHERE `name` IN ('check-websites', 'check-pages', 'check-ssl', 'check-forms', 'discover-forms');
INSERT IGNORE INTO `scheduler_jobs` (`name`, `label`, `interval_minutes`, `priority`, `enabled`, `created_at`) VALUES
('dispatch', 'Dispatcher (due targets to queue)', 1, 0, 1, NOW()),
('process-notifications', 'Alert delivery', 2, 1, 1, NOW()),
('check-expiry', 'Domain / hosting expiry', 1380, 5, 1, NOW()),
('housekeeping', 'Housekeeping', 1380, 7, 1, NOW());
UPDATE `scheduler_jobs` SET `interval_minutes` = 1, `priority` = 0 WHERE `name` = 'dispatch';

-- ---------- Settings ----------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('dispatch_batch_limit', '2000'),
('queue_claim_batch', '50'),
('worker_lease_seconds', '300'),
('worker_stale_seconds', '120'),
('browser_max_concurrent', '2'),
('analytics_async', '1'),
('job_max_attempts', '3'),
('inline_fallback_enabled', '1');

-- ---------- Per-queue liveness (one tiny row per queue, updated when a job completes) ----------
CREATE TABLE IF NOT EXISTS `queue_state` (
  `queue` VARCHAR(24) NOT NULL,
  `last_done_at` DATETIME DEFAULT NULL,
  `last_failed_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(300) DEFAULT NULL,
  PRIMARY KEY (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `queue_state` (`queue`) VALUES ('notification'), ('website'), ('ssl'), ('page'), ('form'), ('discovery'), ('analytics'), ('report'), ('maintenance');

-- claim index walks (priority, available_at) inside a queue – re-created for upgrades from the first 3.4 build
ALTER TABLE `jobs` DROP INDEX IF EXISTS `idx_jobs_claim`;
ALTER TABLE `jobs` ADD INDEX IF NOT EXISTS `idx_jobs_claim` (`status`, `queue`, `priority`, `available_at`), ADD INDEX IF NOT EXISTS `idx_jobs_due` (`status`, `queue`, `available_at`);
