-- =====================================================================
-- Upgrade 3.1 → 3.2: Visitor analytics (CDN tracking snippet) + compact UI. Safe to run more than once.
-- =====================================================================

-- Per-website tracking key / switch
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `analytics_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `form_discovery_enabled`,
  ADD COLUMN IF NOT EXISTS `analytics_key` CHAR(16) DEFAULT NULL AFTER `analytics_enabled`,
  ADD COLUMN IF NOT EXISTS `analytics_last_event_at` DATETIME DEFAULT NULL AFTER `analytics_key`,
  ADD COLUMN IF NOT EXISTS `analytics_any_origin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `analytics_last_event_at`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_websites_akey` (`analytics_key`);

-- Plan limit: pageviews per calendar month (NULL = unlimited)
ALTER TABLE `plans` ADD COLUMN IF NOT EXISTS `max_pageviews_month` INT UNSIGNED DEFAULT NULL AFTER `max_status_pages`;
UPDATE `plans` SET `max_pageviews_month` = 5000    WHERE `code` = 'free'         AND `max_pageviews_month` IS NULL;
UPDATE `plans` SET `max_pageviews_month` = 50000   WHERE `code` = 'starter'      AND `max_pageviews_month` IS NULL;
UPDATE `plans` SET `max_pageviews_month` = 250000  WHERE `code` = 'professional' AND `max_pageviews_month` IS NULL;
UPDATE `plans` SET `max_pageviews_month` = 1000000 WHERE `code` = 'business'     AND `max_pageviews_month` IS NULL;
-- feature flag: none | basic (visitors, pages, devices) | full (+ countries/cities, referrers, realtime, 12-month history)
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.analytics', 'basic', '$.analytics_retention', 30)  WHERE `code` = 'free'         AND JSON_EXTRACT(`features`, '$.analytics') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.analytics', 'basic', '$.analytics_retention', 90)  WHERE `code` = 'starter'      AND JSON_EXTRACT(`features`, '$.analytics') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 365) WHERE `code` = 'professional' AND JSON_EXTRACT(`features`, '$.analytics') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 730) WHERE `code` = 'business'     AND JSON_EXTRACT(`features`, '$.analytics') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 730) WHERE `code` = 'agency'       AND JSON_EXTRACT(`features`, '$.analytics') IS NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(`highlights`, '$', 'Visitor analytics · 5,000 pageviews / month')    WHERE `code` = 'free'         AND JSON_SEARCH(`highlights`, 'one', 'Visitor analytics%') IS NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(`highlights`, '$', 'Visitor analytics · 50,000 pageviews / month')   WHERE `code` = 'starter'      AND JSON_SEARCH(`highlights`, 'one', 'Visitor analytics%') IS NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(`highlights`, '$', 'Full visitor analytics · 250,000 pageviews / month') WHERE `code` = 'professional' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(`highlights`, '$', 'Full visitor analytics · 1M pageviews / month')  WHERE `code` = 'business'     AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(`highlights`, '$', 'Unlimited visitor analytics')                    WHERE `code` = 'agency'       AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NULL;

-- Raw pageview events (kept for the plan's analytics retention, pruned by housekeeping; realtime + recent visitors)
CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(32) NOT NULL,
  `session_hash` CHAR(32) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `title` VARCHAR(190) DEFAULT NULL,
  `referrer_host` VARCHAR(190) DEFAULT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'direct' COMMENT 'direct | search | social | referral | campaign',
  `country` CHAR(2) DEFAULT NULL,
  `region` VARCHAR(80) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `device` VARCHAR(10) NOT NULL DEFAULT 'desktop',
  `browser` VARCHAR(30) DEFAULT NULL,
  `os` VARCHAR(30) DEFAULT NULL,
  `screen_w` SMALLINT UNSIGNED DEFAULT NULL,
  `lang` VARCHAR(10) DEFAULT NULL,
  `is_new_device` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ae_site_time` (`website_id`, `created_at`),
  KEY `idx_ae_tenant_time` (`tenant_id`, `created_at`),
  KEY `idx_ae_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every device is counted once (unique visitors) – one row per device per website
CREATE TABLE IF NOT EXISTS `analytics_visitors` (
  `website_id` INT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(32) NOT NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `visits` INT UNSIGNED NOT NULL DEFAULT 1,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 1,
  `country` CHAR(2) DEFAULT NULL,
  `device` VARCHAR(10) DEFAULT NULL,
  PRIMARY KEY (`website_id`, `visitor_hash`),
  KEY `idx_av_last` (`website_id`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which devices were seen on which day (daily unique visitors)
CREATE TABLE IF NOT EXISTS `analytics_visitors_daily` (
  `website_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `visitor_hash` CHAR(32) NOT NULL,
  PRIMARY KEY (`website_id`, `day`, `visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily totals per website (the tables the dashboards read – tiny, indexed, kept ~2 years)
CREATE TABLE IF NOT EXISTS `analytics_daily` (
  `website_id` INT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors` INT UNSIGNED NOT NULL DEFAULT 0,
  `new_visitors` INT UNSIGNED NOT NULL DEFAULT 0,
  `sessions` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`),
  KEY `idx_ad_tenant_day` (`tenant_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily breakdowns: dim = country | city | device | browser | os | source | referrer | lang
CREATE TABLE IF NOT EXISTS `analytics_daily_dims` (
  `website_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `dim` VARCHAR(12) NOT NULL,
  `value` VARCHAR(120) NOT NULL,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`, `dim`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily per-page views
CREATE TABLE IF NOT EXISTS `analytics_daily_pages` (
  `website_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `path_hash` CHAR(32) NOT NULL,
  `title` VARCHAR(190) DEFAULT NULL,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
  `entries` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sessions that started on this page',
  PRIMARY KEY (`website_id`, `day`, `path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions seen per day (for session counts and entry pages)
CREATE TABLE IF NOT EXISTS `analytics_sessions_daily` (
  `website_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `session_hash` CHAR(32) NOT NULL,
  PRIMARY KEY (`website_id`, `day`, `session_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP → location cache (no personal data is stored with events; the IP itself is only kept here, hashed)
CREATE TABLE IF NOT EXISTS `analytics_geo_cache` (
  `ip_hash` CHAR(32) NOT NULL,
  `country` CHAR(2) DEFAULT NULL,
  `region` VARCHAR(80) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `looked_up_at` DATETIME NOT NULL,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_geo_time` (`looked_up_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('analytics_enabled', '1'),
('analytics_geo_lookup', '1'),
('analytics_trust_proxy', '0'),
('analytics_raw_retention_days', '90');

-- Plan defaults per the product spec (Super Admin can change them under Platform → Plans)
UPDATE `plans` SET `max_pageviews_month` = 1000,    `features` = JSON_SET(`features`, '$.analytics', 'basic', '$.analytics_retention', 7)   WHERE `code` = 'free';
UPDATE `plans` SET `max_pageviews_month` = 25000,   `features` = JSON_SET(`features`, '$.analytics', 'basic', '$.analytics_retention', 30)  WHERE `code` = 'starter';
UPDATE `plans` SET `max_pageviews_month` = 250000,  `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 90)  WHERE `code` = 'professional';
UPDATE `plans` SET `max_pageviews_month` = 2000000, `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 180) WHERE `code` = 'business';
UPDATE `plans` SET `max_pageviews_month` = NULL,    `features` = JSON_SET(`features`, '$.analytics', 'full',  '$.analytics_retention', 730) WHERE `code` = 'agency';
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(JSON_REMOVE(`highlights`, JSON_UNQUOTE(JSON_SEARCH(`highlights`, 'one', '%isitor analytics%'))), '$', 'Basic visitor analytics · 1,000 page views / month · 7-day history') WHERE `code` = 'free' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NOT NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(JSON_REMOVE(`highlights`, JSON_UNQUOTE(JSON_SEARCH(`highlights`, 'one', '%isitor analytics%'))), '$', 'Visitor analytics · 25,000 page views / month · 30-day history') WHERE `code` = 'starter' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NOT NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(JSON_REMOVE(`highlights`, JSON_UNQUOTE(JSON_SEARCH(`highlights`, 'one', '%isitor analytics%'))), '$', 'Full analytics · 250,000 page views / month · 90-day history · real-time') WHERE `code` = 'professional' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NOT NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(JSON_REMOVE(`highlights`, JSON_UNQUOTE(JSON_SEARCH(`highlights`, 'one', '%isitor analytics%'))), '$', 'Advanced analytics · 2M page views / month · 180-day history · export') WHERE `code` = 'business' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NOT NULL;
UPDATE `plans` SET `highlights` = JSON_ARRAY_APPEND(JSON_REMOVE(`highlights`, JSON_UNQUOTE(JSON_SEARCH(`highlights`, 'one', '%isitor analytics%'))), '$', 'Custom analytics limits & retention') WHERE `code` = 'agency' AND JSON_SEARCH(`highlights`, 'one', '%isitor analytics%') IS NOT NULL;
