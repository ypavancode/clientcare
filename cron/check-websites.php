<?php
/**
 * AUTOMATIC WEBSITE MONITORING – schedule every 5 minutes.
 * cPanel (every 5 min): /usr/local/bin/php /home/USER/public_html/crm/cron/check-websites.php > /dev/null 2>&1
 *
 * Checks all due websites CONCURRENTLY (Settings → Monitoring → parallel checks, default 25 at a time),
 * records HTTP status / response time / failure reason, opens or closes incidents and queues alert emails.
 *
 * Very large fleets: run several copies with --shard=N/M (e.g. four cron lines with --shard=1/4 … --shard=4/4),
 * each handles a quarter of the websites. --limit=N caps one run (the rest is picked up next run).
 */
require_once __DIR__ . '/_bootstrap.php';
[$shard, $shards] = cron_shard();
if (!cron_lock('check-websites' . ($shards > 1 ? '-' . $shard : ''))) exit;

$limit = (int) cron_opt('limit', 0);
$sites = Monitor::websitesDue('uptime', cron_force(), $shard, $shards, $limit);
$concurrency = (int) setting('check_concurrency', 25);
cron_out('Website check started: ' . count($sites) . ' website(s) due' . ($shards > 1 ? " (shard $shard/$shards)" : '') . ", $concurrency parallel");
$t0 = microtime(true);
$errors = 0;
$summary = ['up' => 0, 'down' => 0];
foreach (array_chunk($sites, 500) as $chunk) {
    try {
        $r = Monitor::checkWebsitesBatch($chunk, true, function ($w, $res) {
            if (!$res['up']) cron_out(sprintf('  %-40s %-13s %-4s %5dms %s%s', $w['name'], strtoupper($res['status']), $res['http_code'] ?? '---', $res['response_ms'], $res['reason'] ? $res['reason'] . ' – ' : '', $res['error'] ?? ''));
        });
        $summary['up'] += $r['up'];
        $summary['down'] += $r['down'];
    } catch (Throwable $e) {
        $errors++;
        cron_out('  ERROR in batch: ' . $e->getMessage());
        app_log('error', 'Cron website batch failed: ' . $e->getMessage());
    }
}
$secs = round(microtime(true) - $t0, 1);
$q = Mailer::processQueue(100);
data_changed();
cron_done("Website check finished: {$summary['up']} up, {$summary['down']} down in {$secs}s" . ($errors ? ", $errors error(s)" : '') . ". Emails sent: {$q['sent']}, failed: {$q['failed']}", $errors === 0);
