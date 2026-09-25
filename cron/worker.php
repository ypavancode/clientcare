<?php
/**
 * PERSISTENT BACKGROUND WORKER (v3.4). Since v3.9 the execution engine (includes/Engine.php) starts and restarts this
 * process itself whenever the web server may spawn processes – nothing has to be launched by hand. Manual / supervisor
 * use remains possible:
 *
 *   php cron/worker.php                              all queues, restarts itself every 6 h to release memory
 *   php cron/worker.php --queues=website,ssl,page    dedicated worker for some queues (run several processes / servers)
 *   php cron/worker.php --once                       process everything that is due, then exit (tests / one-off runs)
 *   nohup php cron/worker.php > logs/worker.log 2>&1 &
 *   systemd / supervisor: command = /usr/bin/php /path/crm/cron/worker.php, autorestart = true  (see README 5k)
 *
 * Loop: heartbeat (workers table) → dispatcher (leader lock, once a minute, cheap) → claim due jobs from the queue
 * (atomic, leased) → run them → mark done / retry with back-off / dead-letter → sleep briefly when idle.
 * Any number of workers on any number of servers can run at the same time; a worker that dies loses its lease and
 * its jobs are re-queued automatically (Queue::reap / Scheduler::reapWorkers).
 *
 * Env / options: WORKER_MAX_SECONDS (21600), WORKER_IDLE_SLEEP (5), WORKER_MAX_JOBS (0 = unlimited), --queues=, --once, --id=
 */
require_once __DIR__ . '/../includes/init.php';
if (!IS_CLI) { http_response_code(403); exit('CLI only'); }
set_time_limit(0);
ini_set('memory_limit', '512M');

$opts = getopt('', ['queues::', 'once', 'id::', 'max-jobs::']);
$queues = !empty($opts['queues']) ? array_values(array_intersect(array_map('trim', explode(',', $opts['queues'])), Queue::QUEUES)) : Scheduler::QUEUE_ORDER;
if (!$queues) { fwrite(STDERR, "No valid queues. Available: " . implode(', ', Queue::QUEUES) . "\n"); exit(2); }
// keep the platform-wide priority order even when a subset is given
$queues = array_values(array_filter(Scheduler::QUEUE_ORDER, fn($q) => in_array($q, $queues, true)));
$once = isset($opts['once']);
$maxSeconds = (int) (getenv('WORKER_MAX_SECONDS') ?: 6 * 3600);
$idleSleep = max(1, (int) (getenv('WORKER_IDLE_SLEEP') ?: 5));
$maxJobs = (int) ($opts['max-jobs'] ?? (getenv('WORKER_MAX_JOBS') ?: 0));
$workerId = (string) ($opts['id'] ?? ('worker@' . gethostname() . '#' . getmypid()));
$batch = max(1, (int) setting('queue_claim_batch', 50));

$stop = false;
if (function_exists('pcntl_signal')) { pcntl_async_signals(true); foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) pcntl_signal($sig, function () use (&$stop) { $stop = true; }); }
$log = function (string $m) { echo '[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL; };

Scheduler::registerWorker($workerId, $queues);
$log("Worker $workerId started · queues: " . implode(', ', $queues) . ($once ? ' · single pass' : ''));
$t0 = time(); $lastBeat = 0; $lastDispatch = 0; $totalDone = 0; $totalFailed = 0; $processed = 0;

while (!$stop && time() - $t0 < $maxSeconds) {
    try {
        if (time() - $lastBeat >= 15) { Scheduler::heartbeat($workerId, 'idle'); $lastBeat = time(); }
        // leader election is implicit: dispatch() takes a DB lock and skips when another runner dispatched < 45 s ago
        if (time() - $lastDispatch >= 30) { $r = Scheduler::dispatch($workerId); $lastDispatch = time(); if (empty($r['skipped'])) { $n = array_sum(array_intersect_key($r, array_flip(['website', 'ssl', 'page', 'form', 'discovery', 'analytics', 'system']))); if ($n) $log("dispatched $n job(s) in {$r['seconds']}s"); } }

        $claimed = null;
        foreach ($queues as $q) {
            $jobs = Queue::claim([$q], min($batch, Queue::BATCH[$q] ?? 1), $workerId);
            if ($jobs) { $claimed = $jobs; break; }
        }
        if (!$claimed) {
            if ($once) break;
            for ($i = 0; $i < $idleSleep && !$stop; $i++) sleep(1);
            continue;
        }
        Scheduler::heartbeat($workerId, 'busy', (int) $claimed[0]['id']);
        $lastBeat = time();
        $ts = microtime(true);
        [$done, $failed] = Scheduler::runBatch($claimed, $workerId);
        $totalDone += $done; $totalFailed += $failed; $processed += count($claimed);
        Scheduler::heartbeat($workerId, 'idle', null, $done, $failed);
        $lastBeat = time();
        $log(sprintf('%-12s %d job(s) · %d ok, %d failed · %.1fs · mem %d MB', $claimed[0]['queue'], count($claimed), $done, $failed, microtime(true) - $ts, round(memory_get_usage(true) / 1048576)));
        if ($maxJobs && $processed >= $maxJobs) { $log("max jobs reached ($maxJobs)"); break; }
        if (memory_get_usage(true) > 400 * 1048576) { $log('memory high – restarting'); break; }
    } catch (Throwable $e) {
        $log('ERROR ' . $e->getMessage());
        app_log('error', 'Worker loop failed: ' . $e->getMessage());
        try { Scheduler::heartbeat($workerId, 'idle', null, 0, 0, $e->getMessage()); } catch (Throwable $ignored) {}
        if ($once) { $totalFailed++; break; }
        sleep(3);
    }
}
Browser::shutdownShared();
Scheduler::stopWorker($workerId);
$log("Worker stopped · $totalDone ok, $totalFailed failed" . ($once ? '' : ' (supervisor should restart it)'));
exit(0);
