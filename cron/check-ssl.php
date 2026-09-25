<?php
/**
 * AUTOMATIC SSL MONITORING – schedule every 5 minutes (run-all.php already includes it).
 * cPanel (every 5 min): /usr/local/bin/php /home/USER/public_html/crm/cron/check-ssl.php > /dev/null 2>&1
 *
 * For every active HTTPS website: performs a real TLS handshake and validates certificate availability, trust chain,
 * hostname match, validity period and expiry. State machine per website:
 *   VALID → FAILED   one "SSL Certificate Failed" email (admin + client)
 *   FAILED → FAILED  nothing (no duplicate emails while the same problem continues)
 *   FAILED → VALID   one "SSL Certificate Recovered" email with the downtime
 *   expiring soon    one warning per configured threshold (default 30 and 10 days)
 */
require_once __DIR__ . '/_bootstrap.php';
if (!cron_lock('check-ssl')) exit;

$sites = Monitor::websitesDue('ssl', cron_force());
cron_out('SSL check started: ' . count($sites) . ' website(s) due');
$issues = 0; $errors = 0; $failed = 0; $recovered = 0;
foreach ($sites as $w) {
    try {
        $r = Monitor::checkSsl($w, true);
        if ($r['status'] !== 'valid' && $r['status'] !== 'unknown') $issues++;
        if (!empty($r['failed'])) $failed++;
        elseif (in_array($w['ssl_status'], ['expired', 'error'], true) && in_array($r['status'], ['valid', 'expiring_soon'], true)) $recovered++;
        if ($r['status'] !== 'valid') cron_out(sprintf('  %-40s %-14s %s %s', $w['name'], strtoupper($r['status']), $r['days'] !== null ? $r['days'] . 'd' : '', $r['error'] ?? ''));
    } catch (Throwable $e) {
        $errors++;
        cron_out('  ERROR checking SSL for ' . $w['name'] . ': ' . $e->getMessage());
        app_log('error', 'Cron SSL check failed for #' . $w['id'] . ': ' . $e->getMessage());
    }
}
$q = Mailer::processQueue(50);
data_changed();
cron_done("SSL check finished: " . count($sites) . " website(s), $issues issue(s), $failed failing, $recovered recovered" . ($errors ? ", $errors error(s)" : '') . ". Emails sent: {$q['sent']}, failed: {$q['failed']}", $errors === 0);
