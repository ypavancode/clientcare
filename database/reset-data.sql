-- =====================================================================
-- RESET ALL DATA (fresh-install state) – run before going live.
--   mysql -u USER -p DBNAME < database/reset-data.sql
--
-- Removes every customer, website, page, form, incident, check, analytics event, alert, email log, job, session,
-- activity entry and every workspace except the platform owner workspace (tenant 1) with its owner/admin user.
-- KEEPS: plans, global settings, email templates, departments / website types, the system-job registry, queue state.
-- Re-runnable. Passwords of the kept admin user are NOT changed – change them from Team after logging in.
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- monitoring data
TRUNCATE TABLE website_monitoring;
TRUNCATE TABLE website_uptime_daily;
TRUNCATE TABLE website_incidents;
TRUNCATE TABLE website_scans;
TRUNCATE TABLE website_pages;
TRUNCATE TABLE page_monitoring;
TRUNCATE TABLE page_incidents;
TRUNCATE TABLE ssl_monitoring;
TRUNCATE TABLE ssl_incidents;
TRUNCATE TABLE forms;
TRUNCATE TABLE form_tests;
TRUNCATE TABLE form_incidents;
TRUNCATE TABLE form_pages;
TRUNCATE TABLE form_scans;
TRUNCATE TABLE form_status_history;
TRUNCATE TABLE domains;
TRUNCATE TABLE hosting;
TRUNCATE TABLE client_credentials;
TRUNCATE TABLE website_projects;
TRUNCATE TABLE project_status_history;
TRUNCATE TABLE websites;
TRUNCATE TABLE clients;

-- analytics
TRUNCATE TABLE analytics_events;
TRUNCATE TABLE analytics_daily;
TRUNCATE TABLE analytics_daily_dims;
TRUNCATE TABLE analytics_daily_pages;
TRUNCATE TABLE analytics_visitors;
TRUNCATE TABLE analytics_visitors_daily;
TRUNCATE TABLE analytics_sessions_daily;
TRUNCATE TABLE analytics_geo_cache;

-- alerts, email, activity, integrations
TRUNCATE TABLE notifications;
TRUNCATE TABLE alert_log;
TRUNCATE TABLE email_queue;
TRUNCATE TABLE email_logs;
TRUNCATE TABLE activity_logs;
TRUNCATE TABLE api_keys;
TRUNCATE TABLE webhooks;
TRUNCATE TABLE webhook_deliveries;
TRUNCATE TABLE status_pages;

-- scheduler runtime (registry rows in scheduler_jobs / queue_state are kept, only counters reset)
TRUNCATE TABLE jobs;
TRUNCATE TABLE workers;
TRUNCATE TABLE queue_stats;
TRUNCATE TABLE cron_runs;
TRUNCATE TABLE monitor_locks;
UPDATE scheduler_jobs SET status = 'idle', locked_until = NULL, locked_by = NULL, last_started_at = NULL, last_finished_at = NULL, last_duration_ms = NULL, last_message = NULL, run_count = 0, fail_count = 0;
UPDATE queue_state SET last_done_at = NULL, last_failed_at = NULL, last_error = NULL;

-- sessions, logins, cache, registration
TRUNCATE TABLE sessions;
TRUNCATE TABLE remember_tokens;
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE cache_store;
TRUNCATE TABLE email_verifications;
TRUNCATE TABLE team_invitations;

-- workspaces: keep only the platform owner workspace (id 1) and its owner / platform-admin users
DELETE FROM users WHERE NOT (tenant_id = 1 AND (role = 'owner' OR is_platform_admin = 1));
DELETE FROM subscriptions WHERE tenant_id <> 1;
DELETE FROM tenant_settings WHERE tenant_id <> 1;
DELETE FROM tenants WHERE id <> 1;
UPDATE tenants SET status = 'active', last_active_at = NULL WHERE id = 1;
UPDATE users SET last_login_at = NULL WHERE tenant_id = 1;

-- runtime settings written by the scheduler / analytics (global configuration is kept)
DELETE FROM settings WHERE setting_key LIKE 'scheduler_last_%' OR setting_key = 'scheduler_last_run';

SET FOREIGN_KEY_CHECKS = 1;
