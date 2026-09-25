<?php
/**
 * AUTOMATIC DOMAIN / HOSTING EXPIRY MONITORING – schedule once a day (run-all.php runs it automatically).
 * cPanel (daily 8 AM): /usr/local/bin/php /home/USER/public_html/crm/cron/check-expiry.php > /dev/null 2>&1
 *
 * Calculates (expiry date - today) for every domain and hosting record and queues admin + client emails at the
 * configured thresholds (default 30 and 10 days) and when expired. Each notification is sent once and tracked.
 */
require_once __DIR__ . '/_bootstrap.php';
if (!cron_lock('check-expiry')) exit;

cron_out('Expiry check started');
$r = Monitor::checkExpiry();
$q = Mailer::processQueue(50);
cron_done("Expiry check finished: {$r['domain']} domain alert(s), {$r['hosting']} hosting alert(s). Emails sent: {$q['sent']}, failed: {$q['failed']}");
