<?php
/**
 * Scheduler (v3.4) – plan-based dispatching + queue execution.
 *
 *   dispatcher (cheap, every minute)        workers (any number, any server)
 *   ───────────────────────────────────     ────────────────────────────────────────────
 *   websites.next_check_at <= NOW()  ──►    jobs (queue = website)   ──► Monitor::checkWebsitesBatch  ──► results, incidents, email queue
 *   websites.next_ssl_at             ──►    jobs (ssl)               ──► Monitor::checkSsl
 *   websites.next_scan_at            ──►    jobs (page)              ──► Monitor::scanPagesBatch
 *   forms.next_test_at               ──►    jobs (form)              ──► Monitor::testForm  (browser slot limited)
 *   websites.next_discovery_at       ──►    jobs (discovery)         ──► FormDiscovery::scanWebsite
 *   analytics_events.processed = 0   ──►    jobs (analytics)         ──► Analytics::processPending
 *   scheduler_jobs (system)          ──►    jobs (notification / maintenance) ──► cron scripts (alert delivery, expiry, housekeeping)
 *
 * The dispatcher only ever reads rows that are DUE (indexed next_*_at), in bounded batches, and sets the provisional
 * next_*_at from the tenant's PLAN interval before the job even runs – so the same target is never queued twice and
 * every customer is checked at their own cadence, whether or not anybody is logged in.
 *
 * Three ways to keep it alive (any one is enough, several are fine – DB locks and leases prevent double work):
 *   1. cron/worker.php          persistent worker(s): dispatch + process jobs continuously (recommended in production)
 *   2. cron/run-all.php         cron every 1–5 min: dispatch + system jobs; processes the queue INLINE only when no worker is alive
 *   3. web heartbeat            when neither has run recently, a visitor's heartbeat runs one short tick after the response
 */
class Scheduler
{
    /** Lightweight SYSTEM jobs (registry in scheduler_jobs). Monitoring itself is per-target work in the queue. */
    public const JOBS = [
        'dispatch'              => ['interval' => 1,    'label' => 'Dispatcher (due targets to queue)', 'priority' => 0, 'queue' => null],
        'process-notifications' => ['interval' => 1,    'script' => 'process-notifications.php', 'label' => 'Alert delivery',            'priority' => 1, 'queue' => 'notification'],
        'check-expiry'          => ['interval' => 1380, 'script' => 'check-expiry.php',          'label' => 'Domain / hosting expiry',   'priority' => 5, 'queue' => 'maintenance'],
        'housekeeping'          => ['interval' => 1380, 'script' => 'housekeeping.php',          'label' => 'Housekeeping',              'priority' => 7, 'queue' => 'maintenance'],
    ];
    /** Queues in processing priority order (alerts first, heavy browser work last). */
    public const QUEUE_ORDER = ['notification', 'website', 'ssl', 'page', 'form', 'analytics', 'discovery', 'maintenance', 'report'];

    /* =====================================================================
     * Registry of system jobs
     * ===================================================================== */

    public static function ensureRows(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach (self::JOBS as $name => $j) {
            DB::query("INSERT IGNORE INTO scheduler_jobs (name, label, interval_minutes, priority, enabled, created_at) VALUES (?,?,?,?,1,NOW())", [$name, $j['label'], $j['interval'], $j['priority']]);
        }
    }

    /** Atomic lock on a system job row: only one runner (any server) executes it at a time. */
    public static function lock(string $name, int $seconds, string $owner): bool
    {
        return DB::query("UPDATE scheduler_jobs SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), locked_by = ?, last_started_at = NOW(), status = 'running', run_count = run_count + 1 WHERE name = ? AND (locked_until IS NULL OR locked_until < NOW())", [$seconds, $owner, $name])->rowCount() === 1;
    }

    public static function finish(string $name, bool $ok, string $message = ''): void
    {
        DB::query("UPDATE scheduler_jobs SET locked_until = NULL, locked_by = NULL, status = ?, last_finished_at = NOW(), last_message = ?, last_duration_ms = TIMESTAMPDIFF(MICROSECOND, last_started_at, NOW()) DIV 1000, fail_count = IF(?, 0, fail_count + 1) WHERE name = ?", [$ok ? 'ok' : 'failed', mb_substr($message, 0, 500), $ok ? 1 : 0, $name]);
    }

    /* =====================================================================
     * Dispatcher – due targets → queue (index-bounded, batched, plan-aware)
     * ===================================================================== */

    /** Run the dispatcher once (locked; at most one runner per minute across all servers). */
    public static function dispatch(string $owner = 'dispatch', bool $force = false): array
    {
        self::ensureRows();
        $row = DB::fetch("SELECT * FROM scheduler_jobs WHERE name = 'dispatch'");
        if ($row && !$row['enabled'] && !$force) return ['skipped' => 'disabled'];
        if (!$force && $row && $row['last_started_at'] && strtotime($row['last_started_at']) > time() - 45 && (!$row['locked_until'] || strtotime($row['locked_until']) < time())) return ['skipped' => 'recent'];
        if (!self::lock('dispatch', 120, $owner)) return ['skipped' => 'locked'];
        $t0 = microtime(true);
        $out = ['website' => 0, 'ssl' => 0, 'page' => 0, 'form' => 0, 'discovery' => 0, 'analytics' => 0, 'system' => 0, 'reaped' => 0];
        try {
            $reap = Queue::reap(); $out['reaped'] = $reap['requeued'] + $reap['dead'];
            self::reapWorkers();
            $limit = max(100, (int) setting('dispatch_batch_limit', 2000));
            // each kind has its own short lock, so several runners (workers on different servers) can dispatch
            // different kinds at the same time when the fleet is large; a kind already being dispatched is skipped
            $each = function (string $kind, callable $fn) use (&$out) { if (!Tenant::lock('dispatch:' . $kind, 110)) return; try { $out[$kind === 'check' ? 'website' : ($kind === 'pages' ? 'page' : $kind)] = $fn(); } finally { Tenant::unlock('dispatch:' . $kind); } };
            $each('check', fn() => self::dispatchWebsites('check', $limit));
            $each('ssl', fn() => self::dispatchWebsites('ssl', $limit));
            if (setting('page_monitoring_enabled', 1)) $each('pages', fn() => self::dispatchWebsites('pages', $limit));
            $each('form', fn() => self::dispatchForms($limit));
            if (setting('form_discovery_enabled', 1)) $each('discovery', fn() => self::dispatchWebsites('discovery', max(20, intdiv($limit, 10))));
            $out['analytics'] = self::dispatchAnalytics();
            $out['system'] = self::dispatchSystemJobs($force);
            if (random_int(1, 20) === 1) Queue::prune();
            $summary = implode(', ', array_filter(array_map(fn($k, $v) => $v ? "$v $k" : null, array_keys($out), $out))) ?: 'nothing due';
            self::finish('dispatch', true, $summary);
        } catch (Throwable $e) {
            self::finish('dispatch', false, $e->getMessage());
            app_log('error', 'Dispatcher failed: ' . $e->getMessage());
            throw $e;
        }
        $out['seconds'] = round(microtime(true) - $t0, 2);
        self::recordRunner(explode('@', $owner)[0] ?: 'cron');
        return $out;
    }

    /**
     * Websites due for one kind of check → jobs. $kind = check | ssl | pages | discovery.
     * Provisional next_*_at is set immediately from the tenant's plan so the target cannot be dispatched twice.
     */
    private static function dispatchWebsites(string $kind, int $limit): int
    {
        $map = [
            'check'     => ['col' => 'next_check_at',     'queue' => 'website',   'type' => 'website.check',     'extra' => '',                                   'key' => 'wc', 'interval' => 'website'],
            'ssl'       => ['col' => 'next_ssl_at',       'queue' => 'ssl',       'type' => 'website.ssl',       'extra' => " AND w.url LIKE 'https://%'",         'key' => 'ws', 'interval' => 'ssl'],
            'pages'     => ['col' => 'next_scan_at',      'queue' => 'page',      'type' => 'website.pages',     'extra' => ' AND w.page_monitoring_enabled = 1', 'key' => 'wp', 'interval' => 'page'],
            'discovery' => ['col' => 'next_discovery_at', 'queue' => 'discovery', 'type' => 'website.discovery', 'extra' => ' AND w.form_discovery_enabled = 1',  'key' => 'wd', 'interval' => null],
        ];
        $m = $map[$kind];
        $due = $kind === 'discovery' ? "(w.form_scan_requested = 1 OR w.{$m['col']} <= NOW())" : "w.{$m['col']} <= NOW()";
        $rows = DB::fetchAll("SELECT w.id, w.tenant_id FROM websites w FORCE INDEX (" . ["check" => "idx_websites_due_check", "ssl" => "idx_websites_due_ssl", "pages" => "idx_websites_due_scan", "discovery" => "idx_websites_due_discovery"][$kind] . ") STRAIGHT_JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id
                              WHERE w.monitoring_enabled = 1 AND $due{$m['extra']} AND c.status = 'active' AND c.monitoring_enabled = 1 AND " . self::tenantActiveSql() . "
                              ORDER BY w.{$m['col']} ASC LIMIT " . (int) $limit);
        if (!$rows) return 0;
        $rows = self::withinPlanLimits($rows);
        if (!$rows) return 0;
        $items = []; $byInterval = [];
        foreach ($rows as $r) {
            $tid = $r['tenant_id'] ? (int) $r['tenant_id'] : null;
            $items[] = [$m['queue'], $m['type'], (int) $r['id'], $tid, [], 5, 0, $m['key'] . ':' . $r['id']];
            $minutes = $kind === 'discovery' ? max(1, (int) setting('form_scan_interval_hours', 24)) * 60 : Tenant::interval($m['interval'], $tid);
            $byInterval[$minutes][] = (int) $r['id'];
        }
        Queue::pushMany($items);
        foreach ($byInterval as $minutes => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', $chunk);
                $set = "{$m['col']} = DATE_ADD(NOW(), INTERVAL " . (int) $minutes . " MINUTE)";
                if ($kind === 'discovery') $set .= ', form_scan_requested = 0';
                DB::query("UPDATE websites SET $set WHERE id IN ($in)");
            }
        }
        return count($rows);
    }

    private static function dispatchForms(int $limit): int
    {
        $rows = DB::fetchAll("SELECT f.id, f.tenant_id, f.test_interval FROM forms f FORCE INDEX (idx_forms_due_test) STRAIGHT_JOIN websites w ON w.id = f.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id
                              WHERE f.auto_test = 1 AND f.status NOT IN ('disabled','removed') AND f.next_test_at <= NOW() AND w.monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND " . self::tenantActiveSql() . "
                              ORDER BY f.next_test_at ASC LIMIT " . (int) $limit);
        if (!$rows) return 0;
        $items = []; $byInterval = [];
        foreach ($rows as $r) {
            $tid = $r['tenant_id'] ? (int) $r['tenant_id'] : null;
            // the plan interval is the cadence; a per-form interval can only make a form SLOWER than the plan, never faster
            $minutes = max(Tenant::interval('form', $tid), (int) $r['test_interval']);
            $items[] = ['form', 'form.test', (int) $r['id'], $tid, [], 5, 0, 'ft:' . $r['id']];
            $byInterval[$minutes][] = (int) $r['id'];
        }
        Queue::pushMany($items);
        foreach ($byInterval as $minutes => $ids) foreach (array_chunk($ids, 500) as $chunk) DB::query("UPDATE forms SET next_test_at = DATE_ADD(NOW(), INTERVAL " . (int) $minutes . " MINUTE) WHERE id IN (" . implode(',', $chunk) . ")");
        return count($rows);
    }

    /**
     * SQL condition (alias t = tenants): monitoring runs only for active workspaces whose subscription is not
     * expired / cancelled – unless the platform explicitly keeps monitoring expired subscriptions. Reactivating a
     * subscription (renewal) makes the overdue targets due again immediately, nothing else to do.
     */
    public static function tenantActiveSql(): string
    {
        $keepExpired = (int) setting('monitor_expired_subscriptions', 0) === 1;
        return "(t.id IS NULL OR (t.status = 'active'" . ($keepExpired ? '' : " AND t.subscription_status NOT IN ('expired','cancelled')") . '))';
    }

    /**
     * Plan limits are enforced server-side at creation time (Tenant::canAdd). After a downgrade a workspace can still
     * own more websites than the new plan allows: only the oldest N websites keep being monitored until the customer
     * removes the extra ones or upgrades. Rows of due websites → rows within the limit.
     */
    private static function withinPlanLimits(array $rows): array
    {
        $byTenant = [];
        foreach ($rows as $r) if (!empty($r['tenant_id'])) $byTenant[(int) $r['tenant_id']][] = (int) $r['id'];
        $drop = [];
        foreach ($byTenant as $tid => $ids) {
            $max = Tenant::limit('websites', $tid);
            if ($max === null) continue;
            $allowed = Cache::remember('plan:allowed_sites:' . $tid, 120, function () use ($tid, $max) {
                $n = (int) DB::value("SELECT COUNT(*) FROM websites WHERE tenant_id = ? AND monitoring_enabled = 1", [$tid]);
                if ($n <= $max) return null; // within the limit – nothing to restrict
                return array_map('intval', array_column(DB::fetchAll("SELECT id FROM websites WHERE tenant_id = ? AND monitoring_enabled = 1 ORDER BY id ASC LIMIT " . (int) $max, [$tid]), 'id'));
            });
            if ($allowed === null) continue;
            foreach ($ids as $id) if (!in_array($id, $allowed, true)) $drop[$id] = true;
        }
        if (!$drop) return $rows;
        // push the excluded websites out by their plan interval so the dispatcher does not re-read them every minute
        foreach (array_chunk(array_keys($drop), 500) as $chunk) DB::query("UPDATE websites SET next_check_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE), next_ssl_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE), next_scan_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE) WHERE id IN (" . implode(',', $chunk) . ")");
        return array_values(array_filter($rows, fn($r) => !isset($drop[(int) $r['id']])));
    }

    /**
     * Re-align every schedule of a workspace with its CURRENT plan (called after a plan change, a subscription status
     * change or an interval edit on the plan). next = last run + new interval, never in the past: an upgrade to a
     * shorter interval makes overdue targets due at once; a downgrade stretches them out. $tenantId = null → all
     * workspaces on $planId.
     */
    public static function reschedule(?int $tenantId, ?int $planId = null): int
    {
        $tenants = $tenantId ? [$tenantId] : array_map('intval', array_column(DB::fetchAll("SELECT id FROM tenants WHERE plan_id = ?", [(int) $planId]), 'id'));
        $n = 0;
        foreach ($tenants as $tid) {
            Tenant::forget($tid);
            Cache::forget('plan:allowed_sites:' . $tid);
            $w = Tenant::interval('website', $tid); $s = Tenant::interval('ssl', $tid); $p = Tenant::interval('page', $tid); $f = Tenant::interval('form', $tid);
            $n += DB::query("UPDATE websites SET next_check_at = GREATEST(NOW(), DATE_ADD(IFNULL(last_checked_at, NOW()), INTERVAL ? MINUTE)), next_ssl_at = GREATEST(NOW(), DATE_ADD(IFNULL(ssl_checked_at, NOW()), INTERVAL ? MINUTE)), next_scan_at = GREATEST(NOW(), DATE_ADD(IFNULL(last_scan_at, NOW()), INTERVAL ? MINUTE)) WHERE tenant_id = ?", [$w, $s, $p, $tid])->rowCount();
            DB::query("UPDATE forms SET next_test_at = GREATEST(NOW(), DATE_ADD(IFNULL(last_tested_at, NOW()), INTERVAL GREATEST(?, IF(test_interval > 0, test_interval, 0)) MINUTE)) WHERE tenant_id = ?", [$f, $tid]);
        }
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state');
        return $n;
    }

    private static function dispatchAnalytics(): int
    {
        if (!setting('analytics_async', 1)) return 0;
        if (!DB::value("SELECT 1 FROM analytics_events WHERE processed = 0 LIMIT 1")) return 0;
        return Queue::push('analytics', 'analytics.rollup', null, null, [], 3, 0, 'analytics:rollup') ? 1 : 0;
    }

    /** System jobs whose interval elapsed → one queued job each (dedupe: never two copies). */
    private static function dispatchSystemJobs(bool $force): int
    {
        $n = 0;
        foreach (DB::fetchAll("SELECT * FROM scheduler_jobs WHERE enabled = 1 AND name <> 'dispatch'") as $r) {
            $def = self::JOBS[$r['name']] ?? null;
            if (!$def || empty($def['queue'])) continue;
            $last = $r['last_started_at'] ? strtotime($r['last_started_at']) : 0;
            if (!$force && $last > time() - max(1, (int) $r['interval_minutes']) * 60 + 15) continue;
            if ($r['locked_until'] && strtotime($r['locked_until']) > time()) continue;
            if (Queue::push($def['queue'], 'system.' . $r['name'], null, null, ['force' => $force], $def['priority'], 0, 'system:' . $r['name'], 2)) $n++;
        }
        return $n;
    }

    /* =====================================================================
     * Execution of queued jobs (called by workers and by the inline fallback)
     * ===================================================================== */

    /** Process one claimed batch (same queue). Website / SSL jobs are executed concurrently as a group. Returns [done, failed]. */
    public static function runBatch(array $jobs, string $workerId): array
    {
        if (!$jobs) return [0, 0];
        $queue = $jobs[0]['queue'];
        if ($queue === 'website') return self::runWebsiteBatch($jobs);
        $done = 0; $failed = 0;
        foreach ($jobs as $job) { self::runQueued($job, $workerId) ? $done++ : $failed++; }
        return [$done, $failed];
    }

    private static function runWebsiteBatch(array $jobs): array
    {
        $ids = array_map(fn($j) => (int) $j['target_id'], $jobs);
        $sites = []; foreach (DB::fetchAll("SELECT * FROM websites WHERE id IN (" . implode(',', $ids) . ") AND monitoring_enabled = 1") as $w) $sites[$w['id']] = $w;
        $byId = []; foreach ($jobs as $j) $byId[(int) $j['target_id']] = $j;
        foreach ($byId as $id => $j) if (!isset($sites[$id])) { Queue::complete($j, 'Skipped – website removed or monitoring disabled'); unset($byId[$id]); }
        if (!$sites) return [count($jobs), 0];
        try {
            $r = Monitor::checkWebsitesBatch(array_values($sites), true);
            foreach ($byId as $id => $j) { $res = $r['results'][$id] ?? null; Queue::complete($j, $res ? ($res['up'] ? 'up' : 'down: ' . ($res['reason'] ?? $res['status'] ?? '')) : 'checked'); }
            DB::query("UPDATE websites SET check_job_id = NULL, check_attempts = 0 WHERE id IN (" . implode(',', array_keys($sites)) . ")");
            self::flushNotifications();
            data_changed();
            return [count($byId), 0];
        } catch (Throwable $e) {
            app_log('error', 'Website batch failed: ' . $e->getMessage());
            foreach ($byId as $id => $j) { Queue::fail($j, $e->getMessage(), self::isTransient($e)); DB::query("UPDATE websites SET check_attempts = check_attempts + 1 WHERE id = ?", [$id]); }
            return [0, count($byId)];
        }
    }

    /** Execute a single queued job. Returns true when it completed. */
    public static function runQueued(array $job, string $workerId): bool
    {
        $type = (string) $job['type'];
        $tid = (int) $job['target_id'];
        try {
            switch ($type) {
                case 'website.check':
                    [$d] = self::runWebsiteBatch([$job]); return $d === 1;
                case 'website.ssl':
                    $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND monitoring_enabled = 1", [$tid]);
                    if (!$w) { Queue::complete($job, 'Skipped – website removed or paused'); return true; }
                    $r = Monitor::checkSsl($w, true);
                    Queue::complete($job, $r['status'] . ($r['days'] !== null ? ' · ' . $r['days'] . 'd' : ''));
                    self::flushNotifications();
                    return true;
                case 'website.pages':
                    $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND monitoring_enabled = 1 AND page_monitoring_enabled = 1", [$tid]);
                    if (!$w) { Queue::complete($job, 'Skipped – page monitoring off'); return true; }
                    $r = Monitor::scanPagesBatch([$w], true, true);
                    Queue::complete($job, "{$r['pages']} pages, {$r['failed']} failed");
                    self::flushNotifications();
                    data_changed();
                    return true;
                case 'form.test':
                    $f = DB::fetch("SELECT * FROM forms WHERE id = ? AND status NOT IN ('disabled','removed')", [$tid]);
                    if (!$f) { Queue::complete($job, 'Skipped – form removed or disabled'); return true; }
                    if (!self::browserSlot($job, $workerId)) return false; // released, not failed
                    try {
                        $r = Monitor::testForm($f, true);
                    } finally { self::releaseBrowserSlot($workerId); }
                    DB::query("UPDATE forms SET test_job_id = NULL WHERE id = ?", [$tid]);
                    Queue::complete($job, !empty($r['skipped']) ? 'skipped (already running)' : ($r['success'] ? 'working' : (!empty($r['blocked']) ? 'blocked by CAPTCHA' : 'failed: ' . ($r['reason'] ?? ''))));
                    self::flushNotifications();
                    return true;
                case 'website.discovery':
                    $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND monitoring_enabled = 1 AND form_discovery_enabled = 1", [$tid]);
                    if (!$w) { Queue::complete($job, 'Skipped – discovery off'); return true; }
                    if (!self::browserSlot($job, $workerId)) return false;
                    try {
                        $r = FormDiscovery::scanWebsite($w, ['budget' => 240, 'trigger' => 'worker', 'engine' => 'auto']);
                    } finally { self::releaseBrowserSlot($workerId); Browser::shutdownShared(); }
                    Queue::complete($job, $r['summary'] ?? 'scanned');
                    data_changed();
                    return true;
                case 'analytics.rollup':
                    $n = Analytics::processPending(2000);
                    Queue::complete($job, "$n events rolled up");
                    if ($n >= 2000) Queue::push('analytics', 'analytics.rollup', null, null, [], 3, 0, 'analytics:rollup'); // more waiting → next batch immediately
                    return true;
                default:
                    if (str_starts_with($type, 'system.')) return self::runSystemJob($job, substr($type, 7), $workerId);
                    Queue::fail($job, 'Unknown job type ' . $type, false);
                    return false;
            }
        } catch (Throwable $e) {
            app_log('error', "Job #{$job['id']} ($type) failed: " . $e->getMessage());
            Queue::fail($job, $e->getMessage(), self::isTransient($e));
            return false;
        }
    }

    private static function runSystemJob(array $job, string $name, string $workerId): bool
    {
        $def = self::JOBS[$name] ?? null;
        if (!$def || empty($def['script'])) { Queue::fail($job, 'Unknown system job', false); return false; }
        if (!self::lock($name, max(300, (int) ($def['interval'] ?? 5) * 60), $workerId)) { Queue::complete($job, 'Skipped – already running elsewhere'); return true; }
        $payload = $job['payload'] ? json_decode($job['payload'], true) : [];
        $r = self::runJob($name, $def['script'], !empty($payload['force']) ? ['--force'] : [], 900);
        self::finish($name, $r['ok'], $r['message']);
        if ($r['ok']) { Queue::complete($job, $r['message']); return true; }
        Queue::fail($job, $r['message'], true);
        return false;
    }

    /** Execute one cron script (separate process when possible, in-process fallback with output buffering). */
    public static function runJob(string $name, string $script, array $args = [], int $timeout = 600): array
    {
        $path = ROOT_PATH . '/cron/' . $script;
        $php = PHP_BINARY ?: '';
        $canExec = function_exists('exec') && $php !== '' && !preg_match('~php-fpm|httpd|apache~i', $php) && is_executable($php);
        if ($canExec) {
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);
            foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
            $out = []; $code = 0;
            @exec($cmd . ' 2>&1', $out, $code);
            $last = trim((string) end($out));
            return ['ok' => $code === 0, 'message' => $last !== '' ? preg_replace('~^\[[^\]]+\]\s*~', '', $last) : ($code === 0 ? 'Done' : 'Exit code ' . $code)];
        }
        if (!defined('SCHEDULER_INPROC')) define('SCHEDULER_INPROC', true);
        $argvBackup = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = array_merge([$path], $args);
        @set_time_limit($timeout + 30);
        ob_start();
        $ok = true; $msg = '';
        try {
            $run = static function (string $__file) { include $__file; };
            $run($path);
        } catch (Throwable $e) {
            $ok = false; $msg = $e->getMessage();
            app_log('error', "Scheduler job $name failed: " . $e->getMessage());
        }
        $output = trim((string) ob_get_clean());
        $GLOBALS['argv'] = $argvBackup;
        if ($msg === '') { $lines = preg_split('~\r?\n~', $output); $msg = preg_replace('~^\[[^\]]+\]\s*~', '', trim((string) end($lines))) ?: 'Done'; }
        return ['ok' => $ok, 'message' => $msg];
    }

    /** Deliver queued alert emails right after monitoring work (bounded; the notification job is the safety net). */
    private static function flushNotifications(): void
    {
        try { if (DB::value("SELECT 1 FROM email_queue WHERE status = 'pending' LIMIT 1")) Mailer::processQueue(50); } catch (Throwable $e) { app_log('warning', 'Email flush failed: ' . $e->getMessage()); }
    }

    public static function isTransient(Throwable $e): bool
    {
        return (bool) preg_match('~timed? ?out|timeout|reset by peer|temporar|deadlock|lock wait|gone away|too many connections|connection refused|could not connect|dns|resolve|429|50[234]|browser~i', $e->getMessage());
    }

    /* ---------- Browser slots: at most N headless browsers platform-wide (DB locks, so it holds across servers) ---------- */

    private static function browserSlot(array $job, string $workerId): bool
    {
        if (!Browser::available()) return true; // HTTP-only engine – nothing to limit
        $max = max(1, (int) setting('browser_max_concurrent', 2));
        for ($i = 0; $i < $max; $i++) {
            if (Tenant::lock('browser:slot:' . $i, 900)) { $GLOBALS['__browser_slot'][$workerId] = $i; return true; }
        }
        Queue::release($job, 20, 'Waiting for a free browser slot');
        return false;
    }

    private static function releaseBrowserSlot(string $workerId): void
    {
        if (isset($GLOBALS['__browser_slot'][$workerId])) { Tenant::unlock('browser:slot:' . $GLOBALS['__browser_slot'][$workerId]); unset($GLOBALS['__browser_slot'][$workerId]); }
    }

    /* =====================================================================
     * Ticks (cron / web heartbeat) and inline fallback processing
     * ===================================================================== */

    /**
     * One scheduler tick: dispatch + (when no worker is alive) process the queue inline within the time budget.
     * $mode = cron | web. Returns a summary.
     */
    public static function tick(int $budgetSeconds = 240, string $mode = 'cron', bool $force = false): array
    {
        $t0 = microtime(true);
        $owner = $mode . '@' . gethostname() . '#' . getmypid();
        $out = ['dispatched' => [], 'processed' => 0, 'failed' => 0, 'inline' => false, 'seconds' => 0];
        try { $out['dispatched'] = self::dispatch($owner, $force); } catch (Throwable $e) { $out['dispatched'] = ['error' => $e->getMessage()]; }
        if (self::workersAlive() === 0 && setting('inline_fallback_enabled', 1)) {
            $out['inline'] = true;
            $left = $budgetSeconds - (int) (microtime(true) - $t0);
            [$out['processed'], $out['failed']] = self::work($owner, max(5, $left), $mode === 'web' ? ['notification', 'website', 'ssl', 'analytics', 'form', 'page', 'maintenance', 'discovery', 'report'] : self::QUEUE_ORDER, $mode === 'web' ? 40 : 0);
        }
        self::recordRunner($mode);
        $out['seconds'] = round(microtime(true) - $t0, 1);
        return $out;
    }

    /** Claim + run jobs from the given queues until they are empty or the budget is used. Returns [done, failed]. */
    public static function work(string $workerId, int $budgetSeconds, array $queues = self::QUEUE_ORDER, int $maxJobs = 0): array
    {
        $t0 = microtime(true); $done = 0; $failed = 0; $n = 0;
        $batch = max(1, (int) setting('queue_claim_batch', 50));
        while (microtime(true) - $t0 < $budgetSeconds) {
            $claimed = null;
            foreach ($queues as $q) {
                $jobs = Queue::claim([$q], min($batch, Queue::BATCH[$q] ?? 1), $workerId);
                if ($jobs) { $claimed = $jobs; break; }
            }
            if (!$claimed) break;
            [$d, $f] = self::runBatch($claimed, $workerId);
            $done += $d; $failed += $f; $n += count($claimed);
            if ($maxJobs && $n >= $maxJobs) break;
        }
        return [$done, $failed];
    }

    /* =====================================================================
     * Workers registry & health
     * ===================================================================== */

    public static function registerWorker(string $id, array $queues): void
    {
        DB::query("INSERT INTO workers (id, host, pid, queues, status, version, started_at, heartbeat_at) VALUES (?,?,?,?,'idle',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE host = VALUES(host), pid = VALUES(pid), queues = VALUES(queues), status = 'idle', version = VALUES(version), started_at = NOW(), heartbeat_at = NOW(), last_error = NULL",
            [$id, gethostname(), getmypid(), implode(',', $queues), APP_VERSION]);
        self::recordRunner('worker');
    }

    public static function heartbeat(string $id, string $status = 'idle', ?int $jobId = null, int $doneDelta = 0, int $failedDelta = 0, ?string $error = null): void
    {
        DB::query("UPDATE workers SET status = ?, current_job_id = ?, heartbeat_at = NOW(), jobs_done = jobs_done + ?, jobs_failed = jobs_failed + ?, memory_mb = ?, last_error = IFNULL(?, last_error) WHERE id = ?",
            [$status, $jobId, $doneDelta, $failedDelta, (int) round(memory_get_usage(true) / 1048576), $error !== null ? mb_substr($error, 0, 300) : null, $id]);
    }

    public static function stopWorker(string $id): void
    {
        DB::query("UPDATE workers SET status = 'stopped', heartbeat_at = NOW(), current_job_id = NULL WHERE id = ?", [$id]);
        Cache::forget('scheduler:state');
    }

    /** Workers with a recent heartbeat. */
    /**
     * Workers with a recent heartbeat – or a job whose lease is still running: a worker cannot heartbeat while it is
     * inside one long browser job (discovery / popup form test can take minutes), and must not be declared dead
     * (and its job re-queued, and a duplicate worker spawned) while that lease is live.
     */
    public static function workersAlive(): int
    {
        $stale = max(30, (int) setting('worker_stale_seconds', 120));
        return (int) DB::value("SELECT COUNT(*) FROM workers w WHERE w.status IN ('idle','busy') AND (w.heartbeat_at > DATE_SUB(NOW(), INTERVAL ? SECOND) OR EXISTS (SELECT 1 FROM jobs j WHERE j.locked_by = w.id AND j.status = 'running' AND j.lease_until > NOW()))", [$stale]);
    }

    /** Declare silent workers dead and give their jobs back to the queue at once (no need to wait for the lease). */
    public static function reapWorkers(): int
    {
        $stale = max(30, (int) setting('worker_stale_seconds', 120));
        $dead = DB::fetchAll("SELECT w.id FROM workers w WHERE w.status IN ('idle','busy') AND w.heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND) AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.locked_by = w.id AND j.status = 'running' AND j.lease_until > NOW())", [$stale * 2]);
        foreach ($dead as $w) {
            DB::query("UPDATE workers SET status = 'dead' WHERE id = ?", [$w['id']]);
            DB::query("UPDATE jobs SET status = 'pending', available_at = NOW(), last_error = CONCAT('Worker ', ?, ' stopped responding'), locked_by = NULL, locked_at = NULL, lease_until = NULL, claim_token = NULL WHERE status = 'running' AND locked_by = ? AND attempts < max_attempts", [$w['id'], $w['id']]);
        }
        DB::query("DELETE FROM workers WHERE status IN ('stopped','dead') AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        return count($dead);
    }

    /** Remember which runner is alive so the UI can say "worker", "cron" or "web heartbeat". */
    public static function recordRunner(string $mode): void
    {
        try { set_setting('scheduler_last_' . $mode, date('Y-m-d H:i:s')); set_setting('scheduler_last_run', date('Y-m-d H:i:s')); } catch (Throwable $e) {}
        Cache::forget('cron:status'); Cache::forget('scheduler:state');
    }

    /** True when no runner (worker / cron / web) has ticked recently – the web heartbeat should step in. */
    public static function stale(int $graceMinutes = 3): bool
    {
        if (self::workersAlive() > 0) return false;
        $last = setting('scheduler_last_run', '');
        return !$last || strtotime($last) < time() - $graceMinutes * 60;
    }

    /**
     * Consolidated health (cached 20 s): status = running | delayed | stopped | failed | never, plus everything the
     * Scheduler Health page, the header dot and cron/health-check.php need.
     */
    public static function health(bool $fresh = false): array
    {
        if ($fresh) Cache::forget('scheduler:health');
        return Cache::remember('scheduler:health', 20, function () {
            self::ensureRows();
            $q = Queue::stats();
            $workers = DB::fetchAll("SELECT * FROM workers WHERE status IN ('idle','busy') OR heartbeat_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY FIELD(status,'busy','idle','dead','stopped'), heartbeat_at DESC LIMIT 50");
            $stale = max(30, (int) setting('worker_stale_seconds', 120));
            $alive = 0; $busy = 0;
            foreach ($workers as &$w) { $w['alive'] = in_array($w['status'], ['idle', 'busy'], true) && strtotime($w['heartbeat_at']) > time() - $stale; if ($w['alive']) { $alive++; if ($w['status'] === 'busy') $busy++; } }
            unset($w);
            $dispatch = DB::fetch("SELECT * FROM scheduler_jobs WHERE name = 'dispatch'") ?: [];
            $lastTick = setting('scheduler_last_run', '');
            $runners = [];
            foreach (['worker', 'engine', 'cron', 'web'] as $m) { $v = setting('scheduler_last_' . $m, ''); if ($v) $runners[$m] = $v; }
            $graceMin = max(3, (int) setting('cron_stale_minutes', 12));
            $lastDispatch = $dispatch['last_finished_at'] ?? null;
            $lastTs = max($lastTick ? strtotime($lastTick) : 0, $lastDispatch ? strtotime($lastDispatch) : 0);
            $lagMin = (int) floor($q['totals']['max_lag'] / 60);
            $chain = class_exists('Engine') && Engine::chainAlive();
            if ($alive === 0 && $lastTs === 0) { $status = 'never'; $label = 'Not connected'; $msg = 'The scheduler has never run. The execution engine starts with the next request to the application – or press "Start engine" on the Scheduler Health page.'; }
            elseif ($alive === 0 && !$chain && $lastTs < time() - $graceMin * 60) { $status = 'stopped'; $label = 'Stopped'; $msg = 'Scheduler is not responding (last tick ' . time_ago(date('Y-m-d H:i:s', $lastTs)) . '). The engine restarts on the next inbound request; if it keeps stopping check the environment facts on the Scheduler Health page.'; }
            elseif (($dispatch['status'] ?? '') === 'failed') { $status = 'failed'; $label = 'Failed'; $msg = 'The last dispatcher run failed: ' . ($dispatch['last_message'] ?? 'unknown error'); }
            elseif ($lagMin >= 10) { $status = 'delayed'; $label = 'Delayed'; $msg = 'Jobs are waiting up to ' . $lagMin . ' min for a worker (' . number_format($q['totals']['due']) . ' due). The engine processes them inline every minute; a worker process would clear them faster.'; }
            else { $status = 'running'; $label = $alive ? 'Running' : 'Connected'; $msg = ($alive ? $alive . ' worker' . ($alive > 1 ? 's' : '') . ' online' : ($chain ? 'Request chain hops every minute, queue processed inline' : 'Scheduler ticks are arriving, queue processed inline')) . ' · ' . number_format($q['hour']['processed']) . ' jobs in the last hour.'; }
            $mode = $alive ? 'worker' : ($chain ? 'engine' : (isset($runners['cron']) && strtotime($runners['cron']) > time() - 900 ? 'cron' : (isset($runners['web']) && strtotime($runners['web']) > time() - 900 ? 'web' : 'none')));
            $nextDispatch = $lastDispatch ? date('Y-m-d H:i:s', strtotime($lastDispatch) + 60) : null;
            $tenants = DB::fetchAll("SELECT t.id, t.name, t.status, p.name AS plan, p.website_interval, p.form_interval FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.status = 'active' ORDER BY t.last_active_at DESC, t.id LIMIT 25");
            foreach ($tenants as &$t) {
                $s = DB::fetch("SELECT COUNT(*) AS sites, MIN(next_check_at) AS next_run, MAX(last_checked_at) AS last_run FROM websites WHERE tenant_id = ? AND monitoring_enabled = 1", [$t['id']]) ?: [];
                $t['sites'] = (int) ($s['sites'] ?? 0); $t['next_run'] = $s['next_run'] ?? null; $t['last_run'] = $s['last_run'] ?? null;
                $t['interval'] = Tenant::interval('website', (int) $t['id']);
                $t['jobs_pending'] = (int) DB::value("SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND status IN ('pending','running')", [$t['id']]);
            }
            unset($t);
            $queueState = []; foreach (DB::fetchAll("SELECT * FROM queue_state") as $r) $queueState[$r['queue']] = $r;
            return ['status' => $status, 'label' => $label, 'healthy' => in_array($status, ['running', 'delayed'], true), 'message' => $msg, 'mode' => $mode, 'runners' => $runners,
                'last_tick' => $lastTick ?: null, 'last_dispatch' => $lastDispatch, 'next_dispatch' => $nextDispatch, 'dispatch' => $dispatch, 'dispatch_duration_ms' => $dispatch['last_duration_ms'] ?? null,
                'queue' => $q, 'queue_state' => $queueState, 'workers' => $workers, 'workers_alive' => $alive, 'workers_busy' => $busy, 'workers_idle' => $alive - $busy, 'inline' => $alive === 0 && (bool) setting('inline_fallback_enabled', 1),
                'tenants' => $tenants, 'web_enabled' => (bool) setting('web_heartbeat_enabled', 1)];
        });
    }

    /** Compact summary for the header dot / dashboard (cached 30 s). */
    public static function state(): array
    {
        return Cache::remember('scheduler:state', 30, function () {
            $h = self::health();
            $labels = ['worker' => 'Background worker', 'engine' => 'Execution engine (request chain)', 'cron' => 'External scheduler', 'web' => 'Inbound-request ticks', 'none' => 'No background runner'];
            return ['mode' => $h['mode'], 'status' => $h['status'], 'healthy' => $h['healthy'], 'message' => $h['healthy'] ? $labels[$h['mode']] . ' – ' . $h['message'] : $h['message'], 'runners' => $h['runners'], 'last_run' => $h['last_tick'], 'web_enabled' => $h['web_enabled']];
        });
    }

    /**
     * Web heartbeat: called from an ordinary AJAX request. Sends the response first, then (if nothing else is running the
     * scheduler) executes one short tick in the background of this PHP process. Never blocks the user.
     */
    public static function heartbeatAfterResponse(): void
    {
        if (!setting('web_heartbeat_enabled', 1)) return;
        if (!self::stale()) return;
        if (!Tenant::lock('scheduler:web', 60)) return; // one attempt per minute across all visitors (DB lock)
        ignore_user_abort(true);
        @set_time_limit(90);
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
        try { self::tick(50, 'web'); } catch (Throwable $e) { app_log('error', 'Web heartbeat tick failed: ' . $e->getMessage()); }
    }
}
