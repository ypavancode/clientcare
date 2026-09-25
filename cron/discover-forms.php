<?php
/**
 * AUTOMATIC FORM DISCOVERY – run-all.php runs it at the end of every 5-minute cycle; it only scans the websites that are
 * due (new / requested / older than Settings → "Re-scan forms every N hours") and stays within a time budget, so the
 * uptime, page, SSL and form checks are never delayed.
 * cPanel (standalone, e.g. every 30 min): /usr/local/bin/php /home/USER/public_html/crm/cron/discover-forms.php > /dev/null 2>&1
 *
 * For every due website: discovers the pages (sitemap / internal links), fetches every page, detects every form
 * (normal, hidden popup/modal, AJAX, WordPress plugin, same-origin iframe, third-party embed), opens popup triggers in
 * headless Chrome when available, fingerprints the forms (no duplicates), registers new forms for monitoring,
 * updates changed ones and marks forms that disappeared as REMOVED (history kept).
 *
 * Options: --force (ignore the schedule), --limit=N websites, --budget=SECONDS (default 150), --website=ID, --http (no browser)
 */
require_once __DIR__ . '/_bootstrap.php';
if (!cron_lock('discover-forms')) exit;

$limit = (int) cron_opt('limit', (int) setting('form_scan_per_run', 3));
$budget = max(30, (int) cron_opt('budget', 150));
$one = (int) cron_opt('website', 0);
$engine = (IS_CLI ? in_array('--http', $GLOBALS['argv'] ?? [], true) : isset($_GET['http'])) ? 'http' : 'auto';
$sites = $one ? DB::fetchAll("SELECT * FROM websites WHERE id = ?", [$one]) : FormDiscovery::due(cron_force(), $limit ?: 0);
cron_out('Form discovery started: ' . count($sites) . ' website(s) due · browser engine: ' . (Browser::available() ? 'available' : 'not available (HTTP inspection only)') . ' · budget ' . $budget . 's');
$t0 = microtime(true);
$sum = ['sites' => 0, 'forms' => 0, 'new' => 0, 'changed' => 0, 'removed' => 0, 'popups' => 0];
$errors = 0;
foreach ($sites as $w) {
    $left = (int) ($budget - (microtime(true) - $t0));
    if ($left < 20) { cron_out('  time budget reached – remaining websites are scanned at the next run'); break; }
    try {
        $r = FormDiscovery::scanWebsite($w, ['budget' => min(240, $left), 'trigger' => 'cron', 'engine' => $engine]);
        $sum['sites']++; $sum['forms'] += $r['forms_found']; $sum['new'] += $r['new']; $sum['changed'] += $r['changed']; $sum['removed'] += $r['removed']; $sum['popups'] += $r['popups'];
        cron_out(sprintf('  %-40s %s', $w['name'], $r['summary']));
    } catch (Throwable $e) {
        $errors++;
        cron_out('  ERROR scanning ' . $w['name'] . ': ' . $e->getMessage());
        app_log('error', 'Form discovery cron failed for #' . $w['id'] . ': ' . $e->getMessage());
    }
}
Browser::shutdownShared();
$secs = round(microtime(true) - $t0, 1);
data_changed();
cron_done("Form discovery finished: {$sum['sites']} website(s) scanned – {$sum['forms']} form(s) in inventory, {$sum['new']} new, {$sum['changed']} changed, {$sum['removed']} removed" . ($sum['popups'] ? ", {$sum['popups']} popup(s) opened" : '') . " in {$secs}s" . ($errors ? ", $errors error(s)" : ''), $errors === 0);
