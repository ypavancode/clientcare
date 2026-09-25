<?php
/**
 * PRODUCTION RESET – removes every piece of test / demo / operational data and leaves only the platform configuration
 * and the Super Admin account. Run it ONCE, from the command line, after the final pre-launch tests:
 *
 *   php database/production-reset.php --confirm=RESET [--keep-user=admin@example.com] [--keep-tenant=1] [--no-backup]
 *
 * What it does, in order:
 *   1. Takes a full mysqldump backup into database/backups/ (unless --no-backup).
 *   2. Deletes all clients (cascades websites, pages, forms, tests, incidents, monitoring history, domains, hosting,
 *      credentials, projects, notifications), all workspaces except --keep-tenant (cascades subscriptions, API keys,
 *      status pages, invitations, webhooks), every user except --keep-user, and every operational / log table
 *      (analytics, alerts, email logs + queue, activity & audit logs, jobs, workers, sessions, caches, locks).
 *   3. Keeps: settings, tenant_settings of the kept workspace, plans, email_templates, scheduler_jobs, departments,
 *      website_types, the kept tenant row and the kept user. Resets runtime counters (engine_state, queue_state,
 *      email_service_state) so the production server starts clean, and truncates the log files.
 *   4. Verifies: the kept user can still authenticate (password hash intact), settings and plans are present, no
 *      orphaned rows remain, and prints the final row counts.
 *
 * Nothing here touches the database structure. It never runs over HTTP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$opts = getopt('', ['confirm::', 'keep-user::', 'keep-tenant::', 'no-backup', 'database::']);
// --database=<name> runs the reset against another database (a rehearsal copy) instead of the configured one
if (!empty($opts['database']) && preg_match('~^[A-Za-z0-9_]+$~', $opts['database'])) define('DB_NAME', $opts['database']);
require_once __DIR__ . '/../includes/init.php';
if (($opts['confirm'] ?? '') !== 'RESET') { fwrite(STDERR, "Refusing to run without --confirm=RESET (this deletes every client, website, form, analytics row, alert and log).\n"); exit(2); }
$keepUserEmail = strtolower(trim((string) ($opts['keep-user'] ?? 'admin@example.com')));
$keepTenant = (int) ($opts['keep-tenant'] ?? 1);
$out = fn(string $m) => print('[' . date('H:i:s') . '] ' . $m . PHP_EOL);

$user = DB::fetch("SELECT * FROM users WHERE LOWER(email) = ?", [$keepUserEmail]);
if (!$user) { fwrite(STDERR, "User $keepUserEmail not found – pass --keep-user=<email of the Super Admin to keep>.\n"); exit(2); }
if (empty($user['is_platform_admin'])) { fwrite(STDERR, "User $keepUserEmail is not a Super Admin (users.is_platform_admin = 0). Refusing – you would lock yourself out of the platform.\n"); exit(2); }
$tenant = DB::fetch("SELECT * FROM tenants WHERE id = ?", [$keepTenant]);
if (!$tenant) { fwrite(STDERR, "Tenant #$keepTenant not found.\n"); exit(2); }

// 1. backup
if (!isset($opts['no-backup'])) {
    $dir = __DIR__ . '/backups'; if (!is_dir($dir)) mkdir($dir, 0750, true);
    $file = $dir . '/pre-reset-' . date('Ymd-His') . '.sql';
    $dump = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\..\\mysql\\bin\\mysqldump.exe' : 'mysqldump';
    if (PHP_OS_FAMILY === 'Windows' && !is_file($dump)) $dump = 'E:\\xampp\\mysql\\bin\\mysqldump.exe';
    $cmd = escapeshellarg($dump) . ' -h ' . escapeshellarg(DB_HOST) . ' -u ' . escapeshellarg(DB_USER) . (DB_PASS !== '' ? ' -p' . escapeshellarg(DB_PASS) : '') . ' --single-transaction --routines --triggers ' . escapeshellarg(DB_NAME) . ' > ' . escapeshellarg($file);
    exec($cmd . ' 2>&1', $o, $code);
    if ($code !== 0 || !is_file($file) || filesize($file) < 1000) { fwrite(STDERR, "Backup failed (exit $code): " . implode("\n", $o) . "\nAborting – nothing was deleted. Use --no-backup only if you have taken a backup yourself.\n"); exit(1); }
    $out('Backup written: ' . $file . ' (' . round(filesize($file) / 1048576, 2) . ' MB)');
}

$before = [];
foreach (DB::fetchAll("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) $before[$r['t']] = (int) DB::value("SELECT COUNT(*) FROM `{$r['t']}`");

// 2. delete operational data (FK cascades do most of the work; order matters for tables without FKs)
$del = function (string $sql, array $p = []) use ($out) { $n = DB::query($sql, $p)->rowCount(); if ($n) $out(sprintf('%6d  %s', $n, preg_replace('~\s+~', ' ', substr($sql, 0, 90)))); };
$del("DELETE FROM clients");                                                     // → websites, forms, pages, tests, incidents, monitoring, domains, hosting, credentials, projects, notifications
$del("DELETE FROM tenants WHERE id <> ?", [$keepTenant]);                        // → subscriptions, api_keys, status_pages, team_invitations, tenant_settings, webhooks
$del("DELETE FROM users WHERE id <> ?", [(int) $user['id']]);                    // → email_verifications, remember_tokens
$del("DELETE FROM email_verifications"); $del("DELETE FROM remember_tokens"); $del("DELETE FROM login_attempts");
$del("DELETE FROM api_keys"); $del("DELETE FROM status_pages"); $del("DELETE FROM team_invitations"); $del("DELETE FROM webhooks"); $del("DELETE FROM webhook_deliveries");
$del("DELETE FROM subscriptions WHERE tenant_id <> ? OR id <> (SELECT id FROM (SELECT MAX(id) AS id FROM subscriptions WHERE tenant_id = ?) x)", [$keepTenant, $keepTenant]); // keep only the current subscription of the kept workspace
foreach (['website_projects', 'project_status_history', 'website_pages', 'website_scans', 'website_uptime_daily', 'website_monitoring', 'website_incidents', 'page_incidents', 'page_monitoring', 'ssl_monitoring', 'ssl_incidents',
          'forms', 'form_tests', 'form_incidents', 'form_pages', 'form_scans', 'form_status_history', 'domains', 'hosting', 'client_credentials',
          'analytics_events', 'analytics_daily', 'analytics_daily_dims', 'analytics_daily_pages', 'analytics_page_visitors_daily', 'analytics_sessions_daily', 'analytics_visitors', 'analytics_visitors_daily', 'analytics_geo_cache',
          'alerts', 'alert_log', 'notifications', 'email_logs', 'email_queue', 'activity_logs', 'cron_runs', 'jobs', 'queue_stats', 'workers', 'monitor_locks', 'sessions', 'cache_store'] as $t) {
    if (isset($before[$t])) $del("DELETE FROM `$t`");
}
// 3. reset runtime state (structure and configuration stay)
DB::query("UPDATE engine_state SET token = NULL, base_url = NULL, last_hop_at = NULL, last_hop_ms = NULL, last_hop_summary = NULL, last_hop_error = NULL, hops_total = 0, last_fire_at = NULL, last_fire_error = NULL, last_spawn_at = NULL, last_spawn_error = NULL, last_kick_at = NULL, last_kick_source = NULL, updated_at = NULL WHERE id = 1");
DB::query("UPDATE queue_state SET last_done_at = NULL, last_failed_at = NULL, last_error = NULL");
try { DB::query("UPDATE email_service_state SET status = 'unknown', last_check_at = NULL, last_check_ok = NULL, last_check_ms = NULL, last_check_by = NULL, last_success_at = NULL, last_failure_at = NULL, last_error = NULL, last_error_kind = NULL, last_response = NULL, last_email_sent_at = NULL, last_email_failed_at = NULL, last_email_error = NULL, consecutive_failures = 0, backoff_until = NULL WHERE id = 1"); } catch (Throwable $e) {}
DB::query("UPDATE scheduler_jobs SET locked_until = NULL, locked_by = NULL, status = 'idle', last_started_at = NULL, last_finished_at = NULL, last_message = NULL, last_duration_ms = NULL, run_count = 0, fail_count = 0");
DB::query("UPDATE tenants SET last_active_at = NULL WHERE id = ?", [$keepTenant]);
DB::query("UPDATE users SET last_login_at = NULL, last_login_ip = NULL WHERE id = ?", [(int) $user['id']]);
foreach (DB::fetchAll("SELECT setting_key FROM settings WHERE setting_key LIKE 'scheduler_last_%'") as $r) DB::query("DELETE FROM settings WHERE setting_key = ?", [$r['setting_key']]);
Cache::flush();
if (empty($opts['database'])) { // file-system cleanup only for the real run, never during a rehearsal on a copy
    foreach (glob(LOG_PATH . '/*.log') ?: [] as $f) @file_put_contents($f, '');
    foreach (glob(LOG_PATH . '/*.lock') ?: [] as $f) @unlink($f);
    foreach (glob(ROOT_PATH . '/uploads/screenshots/*') ?: [] as $f) if (is_file($f)) @unlink($f);
    $out('Runtime state, caches, logs and screenshots reset.');
} else {
    $out('Runtime state and caches reset (rehearsal on ' . DB_NAME . ' – log files and screenshots left alone).');
}

// 4. verify
$after = [];
foreach (array_keys($before) as $t) $after[$t] = (int) DB::value("SELECT COUNT(*) FROM `$t`");
$problems = [];
if ((int) DB::value("SELECT COUNT(*) FROM users") !== 1) $problems[] = 'more than one user remains';
if (empty(password_get_info((string) ($user['password'] ?? ''))['algo'])) $problems[] = 'kept user has no valid password hash';
if ((int) DB::value("SELECT COUNT(*) FROM plans") < 1) $problems[] = 'plans table is empty';
if ((int) DB::value("SELECT COUNT(*) FROM settings") < 10) $problems[] = 'settings table looks empty';
foreach (['websites' => 'clients', 'forms' => 'websites', 'website_pages' => 'websites', 'notifications' => 'clients'] as $child => $parent) if ($after[$child] ?? 0) $problems[] = "$child still has rows although $parent is empty";
$orphanUsers = (int) DB::value("SELECT COUNT(*) FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.tenant_id IS NOT NULL AND t.id IS NULL");
if ($orphanUsers) $problems[] = "$orphanUsers user(s) point to a deleted workspace";
$out('');
$out('Remaining rows (only non-empty tables):');
foreach ($after as $t => $n) if ($n) $out(sprintf('  %-32s %6d   (was %d)', $t, $n, $before[$t]));
$out('');
if ($problems) { foreach ($problems as $p) $out('PROBLEM: ' . $p); exit(1); }
$out('OK – production data set: workspace "' . $tenant['name'] . '" (#' . $keepTenant . ') with Super Admin ' . $user['email'] . ', ' . (int) DB::value("SELECT COUNT(*) FROM plans") . ' plans, ' . (int) DB::value("SELECT COUNT(*) FROM settings") . ' settings.');
$out('Next: change the Super Admin password on first login, set the real SMTP settings (Super Admin → SMTP / Email → Test SMTP), and open the application once so the execution engine starts.');
