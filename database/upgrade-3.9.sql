-- =====================================================================
-- v3.9 – Autonomous execution engine (no cPanel cron) + alert state / history
--   * engine_state   runtime state of the self-perpetuating request chain / auto-spawned worker
--   * alerts         one row per problem: detected → notified → (still failing, silent) → recovered → recovery notified
--   * settings       engine switches; plan interval is the source of truth (global values become floors only)
-- Safe to run more than once.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `engine_state` (
  `id`               TINYINT UNSIGNED NOT NULL,
  `token`            VARCHAR(64)  DEFAULT NULL COMMENT 'secret of the loopback hop URL – generated, never typed',
  `base_url`         VARCHAR(255) DEFAULT NULL COMMENT 'remembered from web requests so CLI / worker can fire hops',
  `last_hop_at`      DATETIME DEFAULT NULL,
  `last_hop_ms`      INT UNSIGNED DEFAULT NULL,
  `last_hop_summary` VARCHAR(500) DEFAULT NULL,
  `last_hop_error`   VARCHAR(500) DEFAULT NULL,
  `hops_total`       INT UNSIGNED NOT NULL DEFAULT 0,
  `last_fire_at`     DATETIME DEFAULT NULL,
  `last_fire_error`  VARCHAR(500) DEFAULT NULL,
  `last_spawn_at`    DATETIME DEFAULT NULL,
  `last_spawn_error` VARCHAR(500) DEFAULT NULL,
  `last_kick_at`     DATETIME DEFAULT NULL,
  `last_kick_source` VARCHAR(120) DEFAULT NULL,
  `updated_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `engine_state` (`id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `alerts` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`            INT UNSIGNED DEFAULT NULL,
  `client_id`            INT UNSIGNED DEFAULT NULL,
  `website_id`           INT UNSIGNED DEFAULT NULL,
  `kind`                 ENUM('website','page','form','ssl','domain','hosting','smtp','system') NOT NULL,
  `target_id`            INT UNSIGNED DEFAULT NULL COMMENT 'form / page / domain / hosting id',
  `incident_id`          INT UNSIGNED DEFAULT NULL,
  `alert_key`            VARCHAR(190) NOT NULL COMMENT 'same key as alert_log – one row per problem',
  `status`               ENUM('open','recovered','info') NOT NULL DEFAULT 'open',
  `severity`             ENUM('critical','warning','info') NOT NULL DEFAULT 'critical',
  `title`                VARCHAR(250) NOT NULL,
  `target_label`         VARCHAR(250) DEFAULT NULL,
  `error_type`           VARCHAR(120) DEFAULT NULL,
  `error_message`        VARCHAR(1000) DEFAULT NULL,
  `previous_status`      VARCHAR(40) DEFAULT NULL,
  `current_status`       VARCHAR(40) DEFAULT NULL,
  `detected_at`          DATETIME NOT NULL,
  `notified_at`          DATETIME DEFAULT NULL,
  `notification_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `checks_while_failing` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_seen_at`         DATETIME DEFAULT NULL,
  `recovered_at`         DATETIME DEFAULT NULL,
  `recovery_notified_at` DATETIME DEFAULT NULL,
  `downtime_seconds`     INT UNSIGNED DEFAULT NULL,
  `link`                 VARCHAR(255) DEFAULT NULL,
  `created_at`           DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alerts_key` (`alert_key`),
  KEY `idx_alerts_tenant` (`tenant_id`, `status`, `detected_at`),
  KEY `idx_alerts_website` (`website_id`, `detected_at`),
  KEY `idx_alerts_kind` (`kind`, `status`),
  KEY `idx_alerts_time` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('engine_enabled', '1'),
  ('engine_hop_seconds', '55'),
  ('worker_autostart', '1'),
  ('php_cli_path', ''),
  ('monitor_expired_subscriptions', '0'),
  ('monitoring_min_interval', '1');

ALTER TABLE `alerts` ADD COLUMN IF NOT EXISTS `recovery_key` VARCHAR(190) DEFAULT NULL AFTER `alert_key`, ADD INDEX IF NOT EXISTS `idx_alerts_recovery` (`recovery_key`);
