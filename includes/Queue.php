<?php
/**
 * Database-backed job queue (v3.4).
 *
 *   Scheduler::dispatch()  →  Queue::push()  →  jobs table  →  Queue::claim() (workers)  →  Scheduler::runQueued()  →  Queue::complete() / fail()
 *
 * Design goals: one small row per monitoring check, atomic claims (UPDATE … LIMIT with a claim token, works on several
 * servers at once), leases that expire when a worker dies (Queue::reap() re-queues them), bounded retries with
 * exponential back-off, dead-lettering, de-duplication (one pending/running job per target) and cheap hourly stats.
 * Every query is index-bounded – nothing ever scans the whole table.
 */
class Queue
{
    public const QUEUES = ['notification', 'website', 'ssl', 'page', 'form', 'discovery', 'analytics', 'report', 'maintenance'];
    /** Lease per queue (seconds a worker may hold a job before it is considered crashed). */
    public const LEASE = ['website' => 180, 'ssl' => 180, 'page' => 900, 'form' => 600, 'discovery' => 900, 'analytics' => 300, 'notification' => 300, 'report' => 900, 'maintenance' => 1800];
    /** Jobs claimed per round per queue (websites / SSL checks run concurrently inside one round). */
    public const BATCH = ['website' => 50, 'ssl' => 20, 'page' => 2, 'form' => 1, 'discovery' => 1, 'analytics' => 1, 'notification' => 1, 'report' => 1, 'maintenance' => 1];

    /** Enqueue one job. Returns the job id, or 0 when an identical job (dedupe key) is already pending/running. */
    public static function push(string $queue, string $type, ?int $targetId = null, ?int $tenantId = null, array $payload = [], int $priority = 5, int $delaySeconds = 0, ?string $dedupeKey = null, ?int $maxAttempts = null): int
    {
        $rows = self::pushMany([[$queue, $type, $targetId, $tenantId, $payload, $priority, $delaySeconds, $dedupeKey, $maxAttempts]]);
        return $rows[0] ?? 0;
    }

    /** Bulk enqueue (one multi-row INSERT IGNORE). Each item: [queue, type, targetId, tenantId, payload, priority, delaySeconds, dedupeKey, maxAttempts]. */
    public static function pushMany(array $items): array
    {
        if (!$items) return [];
        $maxDefault = max(1, (int) setting('job_max_attempts', 3));
        $ids = [];
        foreach (array_chunk($items, 500) as $chunk) {
            $sql = "INSERT IGNORE INTO jobs (queue, type, target_id, tenant_id, payload, priority, status, max_attempts, available_at, dedupe_key, created_at) VALUES ";
            $params = []; $ph = [];
            foreach ($chunk as $it) {
                [$queue, $type, $targetId, $tenantId, $payload, $priority, $delay, $dedupe, $maxAttempts] = array_pad($it, 9, null);
                $ph[] = "(?,?,?,?,?,?,'pending',?,DATE_ADD(NOW(), INTERVAL ? SECOND),?,NOW())";
                array_push($params, $queue, $type, $targetId, $tenantId, $payload ? json_encode($payload) : null, (int) ($priority ?? 5), (int) ($maxAttempts ?? $maxDefault), (int) ($delay ?? 0), $dedupe);
            }
            $stmt = DB::query($sql . implode(',', $ph), $params);
            $n = $stmt->rowCount();
            $first = (int) DB::pdo()->lastInsertId();
            for ($i = 0; $i < count($chunk); $i++) $ids[] = $i < $n ? $first + $i : 0; // approximate for IGNOREd rows (ids are only informative)
        }
        return $ids;
    }

    /**
     * Atomically claim up to $n due jobs from the given queues for $workerId. Returns the claimed rows.
     * A claim token makes the two statements (UPDATE … LIMIT, SELECT) safe with any number of concurrent workers.
     */
    public static function claim(array $queues, int $n, string $workerId, ?int $lease = null): array
    {
        $queues = array_values(array_intersect($queues, self::QUEUES));
        if (!$queues || $n < 1) return [];
        // one queue at a time (argument order = priority order): the UPDATE then walks idx_jobs_claim in
        // (priority, available_at) order and stops at LIMIT – no filesort however many jobs are pending
        foreach ($queues as $q) {
            // 1) pick candidates by walking the index (no filesort, stops at LIMIT); 2) claim them with a status guard so two
            // workers that picked the same ids never both win – the loser simply gets fewer rows and tries again.
            $ids = array_column(DB::fetchAll("SELECT id FROM jobs FORCE INDEX (idx_jobs_claim) WHERE status = 'pending' AND queue = ? AND available_at <= NOW() ORDER BY priority ASC, available_at ASC LIMIT " . (int) $n, [$q]), 'id');
            if (!$ids) continue;
            $token = bin2hex(random_bytes(16));
            $in = implode(',', array_map('intval', $ids));
            $updated = DB::query("UPDATE jobs SET status = 'running', locked_by = ?, locked_at = NOW(), lease_until = DATE_ADD(NOW(), INTERVAL ? SECOND), claim_token = ?, started_at = NOW(), attempts = attempts + 1
                                  WHERE id IN ($in) AND status = 'pending'", [$workerId, $lease ?? (self::LEASE[$q] ?? 300), $token])->rowCount();
            if ($updated > 0) return DB::fetchAll("SELECT * FROM jobs WHERE claim_token = ? AND status = 'running' ORDER BY priority, id", [$token]);
        }
        return [];
    }

    /** Extend the lease of a long job (page scans / discovery call this between phases). */
    public static function touch(int $id, int $seconds): void
    {
        DB::query("UPDATE jobs SET lease_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ? AND status = 'running'", [$seconds, $id]);
    }

    public static function complete(array $job, ?string $note = null): void
    {
        $ms = $job['started_at'] ? max(0, (int) round((microtime(true) - strtotime($job['started_at'])) * 1000)) : null;
        DB::query("UPDATE jobs SET status = 'done', finished_at = NOW(), duration_ms = ?, last_error = ?, dedupe_key = NULL, claim_token = NULL, lease_until = NULL WHERE id = ?", [$ms, $note !== null ? mb_substr($note, 0, 500) : null, $job['id']]);
        self::stat($job['queue'], true, $ms ?? 0);
    }

    /**
     * Mark a failed attempt. Transient failures are retried with exponential back-off (30 s, 60 s, 120 s … max 15 min);
     * after max_attempts the job is dead-lettered (kept for the health page, never blocks the target's next schedule).
     */
    public static function fail(array $job, string $error, bool $retryable = true): string
    {
        $error = mb_substr($error, 0, 500);
        $attempts = (int) $job['attempts'];
        if ($retryable && $attempts < (int) $job['max_attempts']) {
            $delay = min(900, 30 * (2 ** max(0, $attempts - 1)));
            DB::query("UPDATE jobs SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), last_error = ?, locked_by = NULL, locked_at = NULL, lease_until = NULL, claim_token = NULL WHERE id = ?", [$delay, $error, $job['id']]);
            self::stat($job['queue'], false, 0, $error);
            return 'retry';
        }
        DB::query("UPDATE jobs SET status = 'dead', finished_at = NOW(), last_error = ?, dedupe_key = NULL, claim_token = NULL, lease_until = NULL WHERE id = ?", [$error, $job['id']]);
        self::stat($job['queue'], false, 0, $error);
        return 'dead';
    }

    /** Put a running job back to pending without counting an attempt (e.g. no browser slot free right now). */
    public static function release(array $job, int $delaySeconds = 30, ?string $note = null): void
    {
        DB::query("UPDATE jobs SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), attempts = GREATEST(0, attempts - 1), last_error = ?, locked_by = NULL, locked_at = NULL, lease_until = NULL, claim_token = NULL WHERE id = ?", [$delaySeconds, $note, $job['id']]);
    }

    /** Self-healing: jobs whose lease expired (worker crashed / killed) go back to the queue; exhausted ones are dead-lettered. */
    public static function reap(): array
    {
        $out = ['requeued' => 0, 'dead' => 0];
        $out['requeued'] = DB::query("UPDATE jobs SET status = 'pending', available_at = NOW(), last_error = CONCAT('Lease expired (worker ', IFNULL(locked_by, '?'), ')'), locked_by = NULL, locked_at = NULL, lease_until = NULL, claim_token = NULL
                                      WHERE status = 'running' AND lease_until < NOW() AND attempts < max_attempts LIMIT 1000")->rowCount();
        $out['dead'] = DB::query("UPDATE jobs SET status = 'dead', finished_at = NOW(), last_error = CONCAT('Lease expired (worker ', IFNULL(locked_by, '?'), ') – attempts exhausted'), dedupe_key = NULL, claim_token = NULL, lease_until = NULL
                                  WHERE status = 'running' AND lease_until < NOW() AND attempts >= max_attempts LIMIT 1000")->rowCount();
        return $out;
    }

    /** Keep the table small: finished jobs are kept 1 h (throughput view), dead ones 7 days. */
    public static function prune(): int
    {
        $n = DB::query("DELETE FROM jobs WHERE status = 'done' AND finished_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 5000")->rowCount();
        $n += DB::query("DELETE FROM jobs WHERE status = 'dead' AND finished_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 5000")->rowCount();
        DB::query("DELETE FROM queue_stats WHERE hour < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        return $n;
    }

    private static function stat(string $queue, bool $ok, int $ms, ?string $error = null): void
    {
        try {
            DB::query("INSERT INTO queue_stats (hour, queue, processed, failed, total_ms) VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, ?, ?, ?) ON DUPLICATE KEY UPDATE processed = processed + VALUES(processed), failed = failed + VALUES(failed), total_ms = total_ms + VALUES(total_ms)", [$queue, $ok ? 1 : 0, $ok ? 0 : 1, $ms]);
            if ($ok) DB::query("INSERT INTO queue_state (queue, last_done_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_done_at = NOW()", [$queue]);
            else DB::query("INSERT INTO queue_state (queue, last_failed_at, last_error) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE last_failed_at = NOW(), last_error = VALUES(last_error)", [$queue, $error !== null ? mb_substr($error, 0, 300) : null]);
        } catch (Throwable $e) {}
    }

    /** Counts per queue and status + throughput (all index-only queries on small result sets). */
    public static function stats(): array
    {
        $by = [];
        foreach (self::QUEUES as $q) $by[$q] = ['pending' => 0, 'due' => 0, 'running' => 0, 'done' => 0, 'dead' => 0, 'oldest_due_seconds' => 0];
        foreach (DB::fetchAll("SELECT queue, status, COUNT(*) AS n FROM jobs GROUP BY queue, status") as $r) if (isset($by[$r['queue']])) $by[$r['queue']][$r['status']] = (int) $r['n'];
        foreach (DB::fetchAll("SELECT queue, COUNT(*) AS n, TIMESTAMPDIFF(SECOND, MIN(available_at), NOW()) AS lag FROM jobs WHERE status = 'pending' AND available_at <= NOW() GROUP BY queue") as $r) { if (isset($by[$r['queue']])) { $by[$r['queue']]['due'] = (int) $r['n']; $by[$r['queue']]['oldest_due_seconds'] = max(0, (int) $r['lag']); } }
        $hour = DB::fetch("SELECT IFNULL(SUM(processed),0) AS processed, IFNULL(SUM(failed),0) AS failed, IFNULL(SUM(total_ms),0) AS ms FROM queue_stats WHERE hour >= DATE_SUB(NOW(), INTERVAL 1 HOUR)") ?: [];
        $day = DB::fetch("SELECT IFNULL(SUM(processed),0) AS processed, IFNULL(SUM(failed),0) AS failed, IFNULL(SUM(total_ms),0) AS ms FROM queue_stats WHERE hour >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?: [];
        $totals = ['pending' => 0, 'due' => 0, 'running' => 0, 'done' => 0, 'dead' => 0, 'max_lag' => 0];
        foreach ($by as $q) { foreach (['pending', 'due', 'running', 'done', 'dead'] as $k) $totals[$k] += $q[$k]; $totals['max_lag'] = max($totals['max_lag'], $q['oldest_due_seconds']); }
        return ['queues' => $by, 'totals' => $totals, 'hour' => ['processed' => (int) ($hour['processed'] ?? 0), 'failed' => (int) ($hour['failed'] ?? 0), 'avg_ms' => !empty($hour['processed']) ? (int) round($hour['ms'] / $hour['processed']) : 0], 'day' => ['processed' => (int) ($day['processed'] ?? 0), 'failed' => (int) ($day['failed'] ?? 0)]];
    }

    /** Recently dead / failed jobs for the health page. */
    public static function dead(int $limit = 30): array
    {
        return DB::fetchAll("SELECT id, queue, type, tenant_id, target_id, attempts, max_attempts, last_error, finished_at FROM jobs WHERE status = 'dead' ORDER BY finished_at DESC LIMIT " . (int) $limit);
    }

    public static function retry(int $id): bool
    {
        return DB::query("UPDATE jobs SET status = 'pending', attempts = 0, available_at = NOW(), last_error = NULL, finished_at = NULL WHERE id = ? AND status IN ('dead','failed')", [$id])->rowCount() === 1;
    }

    public static function retryAllDead(): int
    {
        return DB::query("UPDATE jobs SET status = 'pending', attempts = 0, available_at = NOW(), last_error = NULL, finished_at = NULL WHERE status = 'dead' LIMIT 5000")->rowCount();
    }

    public static function discard(int $id): bool
    {
        return DB::query("DELETE FROM jobs WHERE id = ? AND status IN ('dead','failed','pending')", [$id])->rowCount() === 1;
    }
}
