-- Upgrade 1.0 -> 1.1: parking/placeholder page detection and expected-content check
ALTER TABLE `websites`
  MODIFY `status` ENUM('unknown','online','down','redirecting','ssl_error','server_error','timeout','parked','content_error','paused') NOT NULL DEFAULT 'unknown',
  ADD COLUMN `expect_text` VARCHAR(190) DEFAULT NULL COMMENT 'Optional text that must appear on the homepage' AFTER `monitoring_enabled`;
