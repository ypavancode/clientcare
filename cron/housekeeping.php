<?php
/**
 * DAILY HOUSEKEEPING – schedule once a day (run-all.php runs it automatically).
 * cPanel (daily 3 AM): /usr/local/bin/php /home/USER/public_html/crm/cron/housekeeping.php > /dev/null 2>&1
 *
 * Rolls raw uptime checks into daily totals (website_uptime_daily), then prunes history tables according to the
 * retention settings so the database stays small and fast no matter how many websites are monitored.
 */
require_once __DIR__ . '/_bootstrap.php';
if (!cron_lock('housekeeping')) exit;

cron_out('Housekeeping started');
$t0 = microtime(true);
$roll = Monitor::rollupDaily();
cron_out("  Uptime rollup: {$roll['days']} day(s), {$roll['rows']} website-day rows, {$roll['deleted']} raw checks pruned");
$hk = Monitor::housekeeping();
foreach ($hk as $k => $n) if ($n) cron_out("  Pruned $n row(s) from $k");
data_changed();
cron_done('Housekeeping finished in ' . round(microtime(true) - $t0, 1) . 's');
