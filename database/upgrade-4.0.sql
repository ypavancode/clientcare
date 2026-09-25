-- =====================================================================
-- v4.0 – Launch audit fixes
--   * analytics tables cascade when a website is deleted (before: orphaned rows stayed forever)
--   * index for the per-dimension report queries (website, dim, day)
-- Safe to run more than once. Orphaned rows are removed first so the constraints can be added.
-- =====================================================================

DELETE FROM `analytics_events`              WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_visitors`            WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_daily`               WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_daily_dims`          WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_daily_pages`         WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_visitors_daily`      WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_sessions_daily`      WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);
DELETE FROM `analytics_page_visitors_daily` WHERE `website_id` NOT IN (SELECT `id` FROM `websites`);

ALTER TABLE `analytics_events`              ADD FOREIGN KEY IF NOT EXISTS `fk_ae_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_visitors`            ADD FOREIGN KEY IF NOT EXISTS `fk_av_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_daily`               ADD FOREIGN KEY IF NOT EXISTS `fk_ad_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_daily_dims`          ADD FOREIGN KEY IF NOT EXISTS `fk_add_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_daily_pages`         ADD FOREIGN KEY IF NOT EXISTS `fk_adp_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_visitors_daily`      ADD FOREIGN KEY IF NOT EXISTS `fk_avd_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_sessions_daily`      ADD FOREIGN KEY IF NOT EXISTS `fk_asd_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;
ALTER TABLE `analytics_page_visitors_daily` ADD FOREIGN KEY IF NOT EXISTS `fk_apvd_site` (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE;

ALTER TABLE `analytics_daily_dims` ADD INDEX IF NOT EXISTS `idx_add_site_dim_day` (`website_id`, `dim`, `day`);
