-- =====================================================================
-- v3.7 – Analytics engagement metrics (time on page, bounce rate, per-page visitors, UTM + region dimensions, custom
--        events), per-site tracking URL, form page-vs-submission status, Owner (product creator) platform role.
--   mysql -u USER -p DBNAME < database/upgrade-3.7.sql   (re-runnable)
-- =====================================================================

-- Daily totals: bounces (single-page sessions) and engagement time
ALTER TABLE `analytics_daily`
  ADD COLUMN IF NOT EXISTS `bounces`      INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sessions`,
  ADD COLUMN IF NOT EXISTS `duration_sum` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `bounces`,
  ADD COLUMN IF NOT EXISTS `duration_n`   INT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration_sum`,
  ADD COLUMN IF NOT EXISTS `events`       INT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration_n`;

-- Per page: unique visitors, bounces (sessions that entered here and never went further), time on page
ALTER TABLE `analytics_daily_pages`
  ADD COLUMN IF NOT EXISTS `visitors`     INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pageviews`,
  ADD COLUMN IF NOT EXISTS `bounces`      INT UNSIGNED NOT NULL DEFAULT 0 AFTER `entries`,
  ADD COLUMN IF NOT EXISTS `duration_sum` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `bounces`,
  ADD COLUMN IF NOT EXISTS `duration_n`   INT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration_sum`;

-- Session day-set: how many pages the session viewed and where it entered (for bounce accounting)
ALTER TABLE `analytics_sessions_daily`
  ADD COLUMN IF NOT EXISTS `pageviews`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `landing_hash` CHAR(32) DEFAULT NULL;

-- Per-page unique visitors per day (small, pruned after 3 days like the other day-sets)
CREATE TABLE IF NOT EXISTS `analytics_page_visitors_daily` (
  `website_id`   INT UNSIGNED NOT NULL,
  `day`          DATE NOT NULL,
  `path_hash`    CHAR(32) NOT NULL,
  `visitor_hash` CHAR(32) NOT NULL,
  PRIMARY KEY (`website_id`, `day`, `path_hash`, `visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dimension names grew (utm_campaign, region, event)
ALTER TABLE `analytics_daily_dims` MODIFY `dim` VARCHAR(16) NOT NULL;

-- Custom events (button click, download, CTA…) share the raw event stream; page views stay event = 'pageview'
ALTER TABLE `analytics_events`
  ADD COLUMN IF NOT EXISTS `event` VARCHAR(40) NOT NULL DEFAULT 'pageview' AFTER `title`;

-- Tracking status facts
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `analytics_first_event_at` DATETIME DEFAULT NULL AFTER `analytics_last_event_at`,
  MODIFY `analytics_key` VARCHAR(24) DEFAULT NULL;

-- Form monitoring: page availability vs submission availability are recorded separately
ALTER TABLE `forms`
  ADD COLUMN IF NOT EXISTS `last_page_ok`    TINYINT(1) DEFAULT NULL AFTER `last_outcome`,
  ADD COLUMN IF NOT EXISTS `last_form_found` TINYINT(1) DEFAULT NULL AFTER `last_page_ok`;

-- Owner / product creator: platform role above Super Admin for plans, pricing and product configuration
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_platform_owner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_platform_admin`;
UPDATE `users` SET `is_platform_owner` = 1 WHERE `is_platform_admin` = 1 AND `tenant_id` = 1 AND `role` = 'owner' AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM `users` WHERE `is_platform_owner` = 1 LIMIT 1) x);

-- Plan feature flags for the analytics tier (existing plans keep working: missing keys fall back to sensible defaults)
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.realtime', 0, '$.geo', 'country', '$.max_events_month', 0)              WHERE `code` = 'free'         AND JSON_EXTRACT(`features`, '$.geo') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.realtime', 1, '$.geo', 'region',  '$.max_events_month', 10000)          WHERE `code` = 'starter'      AND JSON_EXTRACT(`features`, '$.geo') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.realtime', 1, '$.geo', 'city',    '$.max_events_month', 100000)         WHERE `code` = 'professional' AND JSON_EXTRACT(`features`, '$.geo') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.realtime', 1, '$.geo', 'city',    '$.max_events_month', 1000000)        WHERE `code` = 'business'     AND JSON_EXTRACT(`features`, '$.geo') IS NULL;
UPDATE `plans` SET `features` = JSON_SET(`features`, '$.realtime', 1, '$.geo', 'city',    '$.max_events_month', 0)              WHERE `code` = 'agency'       AND JSON_EXTRACT(`features`, '$.geo') IS NULL;
