-- =====================================================================
-- Upgrade 1.7 -> 1.8: Client login management
--   * Login types: WordPress / Domain / Hosting / Other (existing cPanel, FTP, Email, Database types are kept)
--   * Provider field (domain registrar, hosting provider, service name) on every login record
-- Safe to run more than once.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `client_credentials`
  MODIFY `type` ENUM('wordpress','domain','hosting','other','cpanel','ftp','email','database') NOT NULL DEFAULT 'other',
  ADD COLUMN IF NOT EXISTS `provider` VARCHAR(120) DEFAULT NULL COMMENT 'Registrar / hosting provider / service name' AFTER `label`;
