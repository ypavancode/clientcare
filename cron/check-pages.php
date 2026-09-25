<?php
/**
 * AUTOMATIC PAGE-LEVEL MONITORING – schedule every 5 minutes (run-all.php runs it automatically).
 * cPanel (every 5 min): /usr/local/bin/php /home/USER/public_html/crm/cron/check-pages.php > /dev/null 2>&1
 *
 * For every active website: (re)discovers the internal pages when the list is missing or stale
 * (sitemap.xml / WordPress wp-sitemap.xml / robots.txt / internal links), then checks EVERY monitored page
 * (HTTP status, connection, response time, timeout, server errors, SSL errors, redirects, placeholder pages),
 * stores the per-page results, opens/closes page incidents and queues ONE alert email per failed page
 * (plus one recovery email when it works again). Website page totals / health are updated for the CRM.
 *
 * Options: --force (ignore the interval), --limit=N, --shard=N/M, --no-discovery (only check known pages)
 */
require_once __DIR__ . '/_bootstrap.php';
[$shard, $shards] = cron_shard();
if (!cron_lock('check-pages' . ($shards > 1 ? '-' . $shard : ''))) exit;

$limit = (int) cron_opt('limit', 0);
$sites = Monitor::pageScansDue(cron_force(), $shard, $shards, $limit);
$discover = !(IS_CLI ? in_array('--no-discovery', $GLOBALS['argv'] ?? [], true) : isset($_GET['no_discovery']));
cron_out('Page scan started: ' . count($sites) . ' website(s) due' . ($shards > 1 ? " (shard $shard/$shards)" : '') . ', ' . (int) setting('page_check_concurrency', 15) . ' parallel requests');
$t0 = microtime(true);
$errors = 0;
$sum = ['sites' => 0, 'pages' => 0, 'ok' => 0, 'failed' => 0, 'discovered' => 0, 'alerts' => 0, 'recoveries' => 0];
// Batches keep memory bounded: up to 20 websites (≈ 1,000 page requests) per concurrent pass
foreach (array_chunk($sites, 20) as $chunk) {
    try {
        $r = Monitor::scanPagesBatch($chunk, true, $discover, function ($w, $res) {
            if ($res['failed'] > 0) {
                cron_out(sprintf('  %-40s %-8s %d/%d pages working', $w['name'], strtoupper($res['health']), $res['ok'], $res['total']));
                foreach ($res['failed_pages'] as $f) cron_out(sprintf('      FAILED %-4s %-30s %s%s', $f['http_code'] ?? '---', truncate($f['title'], 30), $f['url'], $f['reason'] ? ' – ' . $f['reason'] : ''));
            }
        });
        foreach ($r as $k => $v) $sum[$k] += $v;
    } catch (Throwable $e) {
        $errors++;
        cron_out('  ERROR in page batch: ' . $e->getMessage());
        app_log('error', 'Cron page batch failed: ' . $e->getMessage());
    }
}
$secs = round(microtime(true) - $t0, 1);
$q = Mailer::processQueue(100);
data_changed();
cron_done("Page scan finished: {$sum['sites']} website(s), {$sum['pages']} page(s) checked – {$sum['ok']} working, {$sum['failed']} failed in {$secs}s"
    . ($sum['discovered'] ? ", pages re-discovered for {$sum['discovered']} site(s)" : '') . ($sum['alerts'] ? ", {$sum['alerts']} new page alert(s)" : '') . ($sum['recoveries'] ? ", {$sum['recoveries']} recovered" : '')
    . ($errors ? ", $errors error(s)" : '') . ". Emails sent: {$q['sent']}, failed: {$q['failed']}", $errors === 0);
