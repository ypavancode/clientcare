<?php
/**
 * OPTIONAL EXTERNAL TRIGGER (v3.4 – lightweight heartbeat). Since v3.9 the application runs its own execution engine
 * (includes/Engine.php: auto-spawned worker + self-perpetuating request chain + inbound-request watchdog), so NO cron
 * job has to be configured. This script remains for hosts that want an additional external trigger.
 *
 * If used, schedule every 1–5 minutes. Each run only:
 *   1. dispatches the targets that are DUE (websites / pages / SSL / forms / discovery, per customer plan interval) into the job queue,
 *   2. queues the system jobs whose interval elapsed (alert delivery, expiry, housekeeping),
 *   3. processes the queue INLINE only when no persistent worker is alive (small installs / shared hosting).
 * In production run `php cron/worker.php` under systemd / supervisor instead – then cron is optional and only a safety net.
 *
 *   cPanel:   * /5 * * * * /usr/local/bin/php /home/USER/public_html/crm/cron/run-all.php > /dev/null 2>&1   (remove the space in "* /5")
 *   Windows:  Task Scheduler running cron\run-task.cmd every 5 minutes (register with cron\windows-task.bat)
 *   HTTP:     https://yourdomain.com/crm/cron/run-all.php?key=YOUR_CRON_KEY
 */
require_once __DIR__ . '/_bootstrap.php';

/*
 * v3.1: the job list, intervals and locks live in includes/Scheduler.php (shared with cron/worker.php and the web heartbeat).
 * This script is now a thin entry point: it runs every due job once, each in its own PHP process, within a 280 s budget.
 */
if (!cron_lock('run-all')) exit;
$r = Scheduler::tick(280, 'cron', cron_force());
$d = $r['dispatched'] ?? []; $queued = array_sum(array_intersect_key($d, array_flip(['website', 'ssl', 'page', 'form', 'discovery', 'analytics', 'system'])));
cron_done('run-all: dispatched ' . $queued . ' job(s)' . (!empty($d['skipped']) ? ' (dispatcher ' . $d['skipped'] . ')' : '') . ($r['inline'] ? ', processed inline: ' . $r['processed'] . ' ok, ' . $r['failed'] . ' failed' : ', workers online – queue left to them') . ' in ' . $r['seconds'] . 's');
