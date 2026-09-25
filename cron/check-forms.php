<?php
/**
 * AUTOMATIC FORM MONITORING – schedule every 5 minutes (run-all.php already includes it); each form is tested when its
 * own interval has elapsed (global default 10 minutes, 10 / 15 / 20 selectable in Settings → Monitoring, per-form override).
 * cPanel (every 5 min): /usr/local/bin/php /home/USER/public_html/crm/cron/check-forms.php > /dev/null 2>&1
 *
 * Every test REALLY submits the form (HTTP engine, or the headless-browser engine for popup / AJAX / JavaScript forms:
 * open page → click popup trigger → wait for popup → fill test data → click submit → inspect AJAX / redirect / message),
 * records the result and the exact failure reason, and queues alert emails only on state changes:
 *   WORKING → FAILED   failure email     FAILED → FAILED   no duplicate     FAILED → WORKING   recovery email
 * Large fleets: --shard=N/M splits the forms across several cron processes; --limit=N caps one run.
 */
require_once __DIR__ . '/_bootstrap.php';
[$shard, $shards] = cron_shard();
if (!cron_lock('check-forms' . ($shards > 1 ? '-' . $shard : ''))) exit;

$limit = (int) cron_opt('limit', 0);
$forms = Monitor::formsDue(cron_force(), $shard, $shards, $limit);
cron_out('Form testing started: ' . count($forms) . ' form(s) due' . ($shards > 1 ? " (shard $shard/$shards)" : '') . ' · browser engine: ' . (Browser::available() ? 'available' : 'not available'));
$t0 = microtime(true);
$ok = 0; $fail = 0; $blocked = 0; $errors = 0; $newFail = 0; $recovered = 0;
foreach ($forms as $f) {
    try {
        $r = Monitor::testForm($f, true);
        if ($r['success']) $ok++; elseif (!empty($r['blocked'])) $blocked++; else $fail++;
        if (!$r['success'] && empty($r['blocked']) && $r['previous_status'] !== 'failed') $newFail++;
        if ($r['success'] && $r['previous_status'] === 'failed') $recovered++;
        if (!$r['success']) cron_out(sprintf('  #%-4d %-30s %-8s %-4s [%s] %s%s', $f['id'], $f['name'], !empty($r['blocked']) ? 'BLOCKED' : ($r['outcome'] === 'config_error' ? 'CONFIG' : 'FAILED'), $r['http_code'] ?? '---', $r['engine'], $r['reason'] ? $r['reason'] . ' – ' : '', $r['error'] ?? ''));
    } catch (Throwable $e) {
        $errors++;
        cron_out('  ERROR testing form ' . $f['name'] . ': ' . $e->getMessage());
        app_log('error', 'Cron form test failed for #' . $f['id'] . ': ' . $e->getMessage());
    }
}
Browser::shutdownShared();
$secs = round(microtime(true) - $t0, 1);
$q = Mailer::processQueue(100);
data_changed();
cron_done("Form testing finished: " . count($forms) . " tested – $ok working, $fail failed, $blocked blocked by CAPTCHA/anti-bot ($newFail new failure(s), $recovered recovered) in {$secs}s" . ($errors ? ", $errors error(s)" : '') . ". Emails sent: {$q['sent']}, failed: {$q['failed']}", $errors === 0);
