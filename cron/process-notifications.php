<?php
/**
 * EMAIL DELIVERY SAFETY NET – schedule every minute. Since v3.8 alerts are delivered immediately when they are
 * created (Mailer::queue → deliverNow); this job only retries rows that could not be sent at once.
 * cPanel (every minute): /usr/local/bin/php /home/USER/public_html/crm/cron/process-notifications.php > /dev/null 2>&1
 *
 * Sends queued alert emails (the other cron scripts also flush the queue at the end of each run, so this
 * is a safety net that keeps delivery fast), checks that the other monitoring jobs are alive, and does housekeeping.
 */
require_once __DIR__ . '/_bootstrap.php';
if (!cron_lock('process-notifications')) exit;

$pending = (int) DB::value("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'");
$q = ['sent' => 0, 'failed' => 0];
if ($pending > 0) {
    $q = Mailer::processQueue(100);
}

// Keep the dashboard statistics warm so users never wait for the aggregation
try {
    // per workspace (the most recently active ones first – large fleets warm on demand)
    foreach (DB::fetchAll("SELECT id FROM tenants WHERE status = 'active' ORDER BY last_active_at DESC, id ASC LIMIT 100") as $t) {
        Tenant::act((int) $t['id']);
        Stats::dashboard(true); Stats::panels(true); Stats::projects(true);
    }
    Tenant::act(null);
} catch (Throwable $e) { app_log('error', 'Stats warm failed: ' . $e->getMessage()); }

// Webhook deliveries (Business / Agency plans)
try { $wh = Webhooks::deliver(100); if ($wh['sent'] || $wh['failed']) cron_out("Webhooks: {$wh['sent']} delivered, {$wh['failed']} failed"); } catch (Throwable $e) { app_log('error', 'Webhook delivery failed: ' . $e->getMessage()); }

// Self-monitoring: warn (once per hour) if website/form monitoring has stopped running
$status = Monitor::cronStatus();
if ($status['status'] === 'stale') {
    Notifier::cronStale($status);
    Mailer::processQueue(5);
}

// Housekeeping
DB::query("DELETE FROM notifications WHERE is_read = 1 AND created_at < ?", [date('Y-m-d H:i:s', time() - 86400 * 90)]);
DB::query("DELETE FROM alert_log WHERE sent_at < ?", [date('Y-m-d H:i:s', time() - 86400 * 400)]);
DB::query("DELETE FROM remember_tokens WHERE expires_at < NOW()");

cron_done($pending === 0 ? 'Notification queue empty' : "Notification queue processed: {$q['sent']} sent, {$q['failed']} failed");
