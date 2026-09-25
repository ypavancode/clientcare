<?php
/**
 * CRON HEALTH CHECK – lets the CRM (and an external uptime service) detect when its own monitoring has stopped.
 *
 *   CLI  (independent cron, e.g. every 10 min on the same or ANOTHER server):
 *        /usr/local/bin/php /home/USER/public_html/crm/cron/health-check.php
 *        → prints the status of every job, exits 0 when everything runs on schedule, 1 when a job is stale,
 *          and emails the admin ("Monitoring System Warning – cron not running") once per hour while it stays stale.
 *
 *   HTTP (external monitor such as UptimeRobot / Better Uptime / cron-job.org):
 *        https://yourdomain.com/crm/cron/health-check.php?key=YOUR_CRON_KEY
 *        → HTTP 200 + JSON {"status":"running"} while healthy, HTTP 503 when monitoring is stale or has never run.
 *          Point an external monitor at this URL: if the server cron dies, the external service alerts you even though
 *          the CRM itself can no longer send email from cron.
 */
require_once __DIR__ . '/_bootstrap.php';

$status = Monitor::cronStatus();
$healthy = $status['status'] === 'running';
if (!$healthy) {
    try {
        Notifier::cronStale($status);
        Mailer::processQueue(5); // cron is dead – deliver the warning from here
    } catch (Throwable $e) {
        app_log('error', 'health-check notify failed: ' . $e->getMessage());
    }
}
$jobs = array_map(fn($j) => ['job' => $j['name'], 'label' => $j['label'], 'status' => $j['status'], 'last_run' => $j['last'], 'next_run' => $j['next'], 'minutes_since' => $j['minutes_since'], 'interval_minutes' => $j['interval'], 'last_result' => $j['last_status'], 'message' => $j['last_message']], $status['jobs']);

if (IS_CLI) {
    cron_out('Cron health: ' . strtoupper($status['status']) . ' – ' . $status['message']);
    foreach ($jobs as $j) cron_out(sprintf('  %-24s %-8s last %-20s every %3d min  %s', $j['label'], strtoupper($j['status']), $j['last_run'] ?: 'never', $j['interval_minutes'], $j['last_result'] ? '(' . $j['last_result'] . ')' : ''));
    exit($healthy ? 0 : 1);
}
http_response_code($healthy ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['status' => $status['status'], 'healthy' => $healthy, 'message' => $status['message'], 'checked_at' => date('c'), 'jobs' => $jobs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
