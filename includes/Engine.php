<?php
/**
 * EXECUTION ENGINE (v3.9) – keeps monitoring running WITHOUT a cPanel cron job, a supervisor or an open browser.
 *
 * The scheduler itself (Scheduler + Queue) is plan-based and server-side: every website / form / SSL target has its
 * own next_*_at computed from the customer's subscription plan; the dispatcher queues only what is due and workers
 * process the queue. The ENGINE answers the remaining question – "who keeps calling the scheduler when nobody set up
 * a cron job?" – with three server-side mechanisms, used in this order and all managed by the application:
 *
 *   1. Auto-spawned worker process   When the PHP CLI can be executed from the web server (exec / proc_open / popen
 *                                    allowed, php binary found), the engine starts `cron/worker.php` as a detached
 *                                    background process and restarts it whenever it is gone (it recycles every 6 h).
 *   2. Self-perpetuating request     cron/engine.php is a "hop": it runs one scheduler tick, waits until the minute
 *      chain (loopback HTTP)         is over and then fires an HTTP request to ITSELF before exiting. The chain runs
 *                                    once a minute for as long as the web server is up – nobody has to be logged in
 *                                    and no browser tab has to be open. A DB lock guarantees a single chain, and a
 *                                    hop only fires its successor while it still owns that lock (no doubling).
 *   3. Inbound-request watchdog      Every request the server receives (visitors of the public pages, the analytics
 *                                    beacons that client websites send, the API, uptime pingers, logged-in users)
 *                                    runs a ~1 ms check at the end of the response: if the chain has not hopped for
 *                                    a few minutes it is restarted (and the worker respawned). Beacon requests, which
 *                                    nobody waits for, also process due work inline when nothing else can.
 *
 * What it honestly cannot do: PHP cannot run when the web server never receives a request AND the chain was killed
 * (server reboot, PHP process limits). The next inbound request – of any kind – restarts it within one round trip.
 * The Scheduler Health page shows which mechanism is active, the environment facts behind it, and a one-click test.
 */
class Engine
{
    const HOP_SCRIPT = 'cron/engine.php';
    /** The chain is considered alive while the last hop is younger than this (seconds). */
    const CHAIN_STALE = 150;
    private static ?array $state = null;

    /* =====================================================================
     * State (engine_state row) & configuration
     * ===================================================================== */

    public static function enabled(): bool
    {
        return (bool) setting('engine_enabled', 1);
    }

    public static function state(bool $fresh = false): array
    {
        if (self::$state === null || $fresh) {
            try { self::$state = DB::fetch("SELECT * FROM engine_state WHERE id = 1") ?: []; } catch (Throwable $e) { self::$state = []; }
        }
        return self::$state;
    }

    private static function save(array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        try { DB::update('engine_state', $fields, 'id = 1'); } catch (Throwable $e) {}
        self::$state = null;
    }

    /** Secret of the hop URL – generated once, never configured by hand. */
    public static function token(): string
    {
        $t = (string) (self::state()['token'] ?? '');
        if (strlen($t) < 32) { $t = bin2hex(random_bytes(24)); self::save(['token' => $t]); }
        return $t;
    }

    /** Absolute base URL usable from any context (remembered from web requests so CLI / worker can fire hops). */
    public static function baseUrl(): string
    {
        $stored = (string) (self::state()['base_url'] ?? '');
        if (!IS_CLI && defined('BASE_URL') && preg_match('~^https?://~', BASE_URL) && !str_contains(BASE_URL, 'localhost:0')) {
            if ($stored !== BASE_URL) self::save(['base_url' => BASE_URL]);
            return BASE_URL;
        }
        return $stored !== '' ? $stored : (defined('BASE_URL') ? BASE_URL : '');
    }

    public static function hopUrl(): string
    {
        return rtrim(self::baseUrl(), '/') . '/' . self::HOP_SCRIPT . '?t=' . self::token();
    }

    /* =====================================================================
     * Environment: can we start processes?
     * ===================================================================== */

    /** Located CLI php: php_cli_path setting → PHP_BINARY (CLI) → PHP_BINDIR → common cPanel / XAMPP locations. */
    public static function phpBinary(): ?string
    {
        static $bin = false;
        if ($bin !== false) return $bin;
        $cands = [];
        $cfg = trim((string) setting('php_cli_path', ''));
        if ($cfg !== '') $cands[] = $cfg;
        if (IS_CLI && PHP_BINARY && !preg_match('~php-fpm|httpd|apache|litespeed|lsphp~i', PHP_BINARY)) $cands[] = PHP_BINARY;
        $win = PHP_OS_FAMILY === 'Windows';
        $cands[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . ($win ? '.exe' : '');
        if ($win) {
            $ext = (string) ini_get('extension_dir');
            if ($ext) $cands[] = dirname($ext) . '\\php.exe';
            foreach (['E:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php.exe', 'D:\\xampp\\php\\php.exe', 'C:\\php\\php.exe'] as $c) $cands[] = $c;
        } else {
            $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
            foreach (["/opt/cpanel/ea-php$v/root/usr/bin/php", "/opt/alt/php$v/usr/bin/php", '/usr/local/bin/php', '/usr/bin/php', '/usr/local/php/bin/php', '/usr/bin/php-cli'] as $c) $cands[] = $c;
            foreach ((glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: []) as $g) $cands[] = $g;
            foreach ((glob('/opt/alt/php*/usr/bin/php') ?: []) as $g) $cands[] = $g;
        }
        foreach (array_unique($cands) as $c) { if ($c !== '' && @is_file($c) && @is_executable($c)) return $bin = $c; }
        return $bin = null;
    }

    /** Which process-spawning function is usable (null = none). */
    public static function execFunction(): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (['proc_open', 'exec', 'popen', 'shell_exec'] as $fn) if (function_exists($fn) && !in_array($fn, $disabled, true)) return $fn;
        return null;
    }

    public static function canExec(): bool
    {
        return self::execFunction() !== null && self::phpBinary() !== null;
    }

    /** True when set_time_limit() is honoured (a hop can then work for the whole minute). */
    public static function timeLimitAdjustable(): bool
    {
        $before = (int) ini_get('max_execution_time');
        if ($before === 0) return true;
        $ok = @set_time_limit($before + 1);
        $after = (int) ini_get('max_execution_time');
        @set_time_limit($before);
        return $ok && $after === $before + 1;
    }

    /* =====================================================================
     * Mechanism 1 – detached worker process
     * ===================================================================== */

    public static function spawnWorker(?string &$error = null, bool $force = false): bool
    {
        if (!self::canExec()) { $error = self::phpBinary() ? 'exec / proc_open / popen are disabled on this server' : 'No PHP CLI binary found (set php_cli_path)'; return false; }
        if (!$force && Scheduler::workersAlive() > 0) { $error = 'a worker is already running'; return false; }
        if (!Tenant::lock('engine:spawn', 90)) { $error = 'a worker was started less than 90 s ago'; return false; }
        $php = self::phpBinary();
        $script = ROOT_PATH . '/cron/worker.php';
        // one log file per launch: a running worker keeps its file open (Windows would refuse a second redirect to it)
        $log = LOG_PATH . '/worker-' . date('Ymd-His') . '.log';
        foreach (array_slice(array_reverse(glob(LOG_PATH . '/worker-*.log') ?: []), 5) as $old) @unlink($old); // keep the 5 newest
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = 'start /B "" ' . self::q($php) . ' ' . self::q($script) . ' --spawned=engine > ' . self::q($log) . ' 2>&1';
                $fn = self::execFunction();
                if ($fn === 'proc_open') {
                    $p = @proc_open('cmd /c ' . $cmd, [['pipe', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']], $pipes, ROOT_PATH, null, ['bypass_shell' => false]);
                    if (!is_resource($p)) throw new RuntimeException('proc_open failed');
                    foreach ($pipes as $pp) @fclose($pp);
                    @proc_close($p);
                } elseif ($fn === 'popen') { $p = @popen('cmd /c ' . $cmd, 'r'); if (!$p) throw new RuntimeException('popen failed'); @pclose($p); }
                else { @exec('cmd /c ' . $cmd); }
            } else {
                $cmd = 'nohup ' . self::q($php) . ' ' . self::q($script) . ' --spawned=engine >> ' . self::q($log) . ' 2>&1 < /dev/null &';
                $fn = self::execFunction();
                if ($fn === 'exec') { @exec($cmd); }
                elseif ($fn === 'shell_exec') { @shell_exec($cmd); }
                elseif ($fn === 'proc_open') { $p = @proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes); if (!is_resource($p)) throw new RuntimeException('proc_open failed'); @proc_close($p); }
                else { $p = @popen($cmd, 'r'); if (!$p) throw new RuntimeException('popen failed'); @pclose($p); }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            self::save(['last_spawn_at' => date('Y-m-d H:i:s'), 'last_spawn_error' => mb_substr($error, 0, 500)]);
            app_log('warning', 'Engine could not start a worker: ' . $error);
            return false;
        }
        self::save(['last_spawn_at' => date('Y-m-d H:i:s'), 'last_spawn_error' => null]);
        app_log('info', 'Engine started a background worker (' . $php . ')');
        return true;
    }

    private static function q(string $s): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '"' . str_replace('"', '', $s) . '"' : escapeshellarg($s);
    }

    /* =====================================================================
     * Mechanism 2 – self-perpetuating request chain
     * ===================================================================== */

    /** Fire-and-forget HTTP request to our own hop endpoint. Returns as soon as the request has been written. */
    public static function fireHop(?string &$error = null): bool
    {
        $url = self::hopUrl();
        $p = parse_url($url);
        if (empty($p['host'])) { $error = 'base URL unknown – open the application once in a browser'; self::save(['last_fire_at' => date('Y-m-d H:i:s'), 'last_fire_error' => $error]); return false; }
        $ssl = strtolower($p['scheme'] ?? 'http') === 'https';
        $port = (int) ($p['port'] ?? ($ssl ? 443 : 80));
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => true, 'peer_name' => $p['host']]]);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $p['host'] . ':' . $port, $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $error = trim($errstr) ?: ('connect failed (errno ' . $errno . ')');
            self::save(['last_fire_at' => date('Y-m-d H:i:s'), 'last_fire_error' => mb_substr($error, 0, 500)]);
            return false;
        }
        stream_set_timeout($fp, 3);
        $hostHdr = $p['host'] . (in_array($port, [80, 443], true) ? '' : ':' . $port);
        fwrite($fp, "GET $path HTTP/1.1\r\nHost: $hostHdr\r\nUser-Agent: OutlineMonitor-Engine/1.0\r\nX-Engine-Hop: 1\r\nAccept: text/plain\r\nConnection: close\r\n\r\n");
        // wait briefly for the first bytes so the server has certainly started the script, then leave it running
        $status = (string) @fgets($fp, 200);
        fclose($fp);
        if ($status !== '' && !preg_match('~^HTTP/\d\.\d 200~', $status)) {
            $error = 'hop endpoint answered ' . trim($status);
            self::save(['last_fire_at' => date('Y-m-d H:i:s'), 'last_fire_error' => mb_substr($error, 0, 500)]);
            return false;
        }
        self::save(['last_fire_at' => date('Y-m-d H:i:s'), 'last_fire_error' => null]);
        return true;
    }

    /** Owner string used for the hop lock. */
    private static function me(): string
    {
        return (IS_CLI ? 'cli' : 'web') . ':' . getmypid();
    }

    private static function holdsHopLock(): bool
    {
        try { $row = DB::fetch("SELECT owner, locked_until FROM monitor_locks WHERE lock_key = 'engine:hop'"); } catch (Throwable $e) { return true; }
        return $row && $row['owner'] === self::me() && strtotime($row['locked_until']) > time();
    }

    /**
     * One hop of the chain (body of cron/engine.php): tick → keep the worker alive → pace to one hop per minute →
     * fire the next hop → exit. Bounded by the effective PHP time limit; never runs twice in parallel.
     */
    public static function hop(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        $t = (string) ($_GET['t'] ?? '');
        if ($t === '' || !hash_equals(self::token(), $t)) { http_response_code(403); echo "forbidden\n"; return; }
        if (!self::enabled()) { echo "engine disabled\n"; return; }
        ignore_user_abort(true);
        @set_time_limit(0);
        $limit = (int) ini_get('max_execution_time');
        $hopSeconds = max(20, min(600, (int) setting('engine_hop_seconds', 55)));
        $budget = $limit > 0 ? min($hopSeconds, max(10, $limit - 8)) : $hopSeconds;
        // answer immediately (the caller does not wait) and keep going in the background of this process
        echo "engine hop " . date('H:i:s') . " budget {$budget}s\n";
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        if (!Tenant::lock('engine:hop', $budget + 600)) { return; } // another hop is running – never double the chain
        $t0 = microtime(true);
        $st = self::state(true);
        self::save(['last_hop_at' => date('Y-m-d H:i:s'), 'hops_total' => (int) ($st['hops_total'] ?? 0) + 1, 'last_hop_error' => null]);
        Scheduler::recordRunner('engine');
        $summary = '';
        try {
            $spawned = '';
            if (setting('worker_autostart', 1) && self::canExec() && Scheduler::workersAlive() === 0) { $err = null; $spawned = self::spawnWorker($err) ? ' · worker started' : ($err ? ' · worker not started: ' . $err : ''); }
            $r = Scheduler::tick(max(5, $budget - 8), 'engine');
            $d = $r['dispatched'] ?? [];
            $queued = array_sum(array_intersect_key($d, array_flip(['website', 'ssl', 'page', 'form', 'discovery', 'analytics', 'system'])));
            $summary = 'dispatched ' . $queued . ($r['inline'] ? ', processed inline ' . $r['processed'] . ' ok / ' . $r['failed'] . ' failed' : ', worker processes the queue') . $spawned;
            if (class_exists('Notifier')) Notifier::schedulerRecovered(); // a successful hop closes any "scheduler not running" alert
        } catch (Throwable $e) {
            $summary = 'ERROR ' . $e->getMessage();
            app_log('error', 'Engine hop failed: ' . $e->getMessage());
            self::save(['last_hop_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        self::save(['last_hop_ms' => (int) round((microtime(true) - $t0) * 1000), 'last_hop_summary' => mb_substr($summary, 0, 500)]);
        // pace the chain: one hop per minute (unless the work used the whole budget – then continue at once)
        $elapsed = (int) (microtime(true) - $t0);
        $sleep = max(0, min(60 - $elapsed, $budget - $elapsed - 2));
        for ($i = 0; $i < $sleep; $i++) {
            sleep(1);
            if ($i % 15 === 14) { try { if (!(int) DB::value("SELECT setting_value FROM settings WHERE setting_key = 'engine_enabled'")) { Tenant::unlock('engine:hop'); return; } } catch (Throwable $e) {} }
        }
        $mine = self::holdsHopLock();
        Tenant::unlock('engine:hop');
        if ($mine) { $err = null; if (!self::fireHop($err)) app_log('warning', 'Engine chain could not continue: ' . $err); }
    }

    /* =====================================================================
     * Mechanism 3 – inbound-request watchdog (+ inline ticks where nobody waits)
     * ===================================================================== */

    /** Age of the last hop in seconds (null = never). */
    public static function chainAge(): ?int
    {
        $v = self::state()['last_hop_at'] ?? null;
        return $v ? max(0, time() - strtotime($v)) : null;
    }

    public static function chainAlive(): bool
    {
        $a = self::chainAge();
        return $a !== null && $a < self::CHAIN_STALE;
    }

    /**
     * Called at the end of EVERY web request (init.php shutdown). ~1 ms when the engine runs: a cached flag skips
     * the DB for 30 s. When the chain is stale it fires a new hop and (when possible) respawns the worker – at most
     * once a minute platform-wide. Never throws, never delays the visitor noticeably.
     */
    public static function watchdog(string $source = 'request'): void
    {
        if (IS_CLI || defined('ENGINE_HOP') || !self::enabled()) return;
        try {
            if (Cache::get('engine:checked')) return;
            Cache::set('engine:checked', 1, 30);
            if (self::chainAlive()) return;
            if (!Tenant::lock('engine:kick', 60)) return;
            $err = null;
            $fired = self::fireHop($err);
            $spawned = false;
            if (setting('worker_autostart', 1) && self::canExec() && Scheduler::workersAlive() === 0) { $e2 = null; $spawned = self::spawnWorker($e2); }
            self::save(['last_kick_at' => date('Y-m-d H:i:s'), 'last_kick_source' => mb_substr($source . ($fired ? ' → hop fired' : ' → hop failed: ' . $err) . ($spawned ? ' + worker' : ''), 0, 120)]);
        } catch (Throwable $e) {
            // a watchdog must never break a page
        }
    }

    /**
     * Last resort for hosts where neither the loopback request nor process spawning works: requests nobody waits for
     * (analytics beacons, API pings) run one short scheduler tick after their response. Bounded, locked, opt-out.
     */
    public static function inlineTickIfNeeded(int $budget = 25): void
    {
        if (IS_CLI || defined('ENGINE_HOP') || !setting('inline_fallback_enabled', 1)) return;
        try {
            if (self::chainAlive() || Scheduler::workersAlive() > 0) return;
            if (!Scheduler::stale(2)) return;
            if (!Tenant::lock('scheduler:web', 60)) return;
            ignore_user_abort(true);
            @set_time_limit($budget + 30);
            Scheduler::tick($budget, 'web');
        } catch (Throwable $e) {
            app_log('warning', 'Inline tick failed: ' . $e->getMessage());
        }
    }

    /* =====================================================================
     * Status for the Scheduler Health page
     * ===================================================================== */

    /** Actively verify the loopback: fire a hop and wait (max $wait s) for the hop timestamp to move. */
    public static function selfTest(int $wait = 8): array
    {
        $before = self::state(true)['last_hop_at'] ?? null;
        $err = null;
        $fired = self::fireHop($err);
        if (!$fired) return ['ok' => false, 'fired' => false, 'error' => $err, 'hop_at' => $before];
        $t0 = microtime(true);
        while (microtime(true) - $t0 < $wait) {
            usleep(400000);
            $now = self::state(true)['last_hop_at'] ?? null;
            if ($now && $now !== $before) return ['ok' => true, 'fired' => true, 'error' => null, 'hop_at' => $now, 'seconds' => round(microtime(true) - $t0, 1)];
        }
        return ['ok' => false, 'fired' => true, 'error' => 'The request was sent but no hop was recorded within ' . $wait . ' s. A hop may still be running (lock held) – check again in a minute; otherwise the web server does not reach ' . self::hopUrl(), 'hop_at' => $before];
    }

    public static function status(): array
    {
        $st = self::state(true);
        $workers = Scheduler::workersAlive();
        $chainAge = self::chainAge();
        $chain = self::chainAlive();
        $canExec = self::canExec();
        if (!self::enabled()) { $mode = 'disabled'; $label = 'Engine disabled'; $tone = 'danger'; $text = 'The execution engine is switched off – monitoring runs only when a worker or an external trigger calls the scheduler.'; }
        elseif ($workers > 0 && $chain) { $mode = 'worker+chain'; $label = 'Worker process + request chain'; $tone = 'success'; $text = $workers . ' background worker' . ($workers > 1 ? 's' : '') . ' processing the queue; the request chain supervises and restarts it automatically.'; }
        elseif ($workers > 0) { $mode = 'worker'; $label = 'Worker process'; $tone = 'success'; $text = $workers . ' background worker' . ($workers > 1 ? 's' : '') . ' online (the request chain is not running – it restarts on the next inbound request).'; }
        elseif ($chain) { $mode = 'chain'; $label = 'Self-perpetuating request chain'; $tone = 'success'; $text = 'A hop runs every minute inside the web server and processes due work inline' . ($canExec ? ' (it will start a worker as soon as one can be spawned)' : ' – process spawning is not available on this server') . '.'; }
        elseif ($chainAge !== null && $chainAge < 900) { $mode = 'restarting'; $label = 'Restarting'; $tone = 'warning'; $text = 'The chain paused ' . human_seconds($chainAge) . ' ago; the next inbound request restarts it.'; }
        else { $mode = 'stopped'; $label = 'Not running'; $tone = 'danger'; $text = ($chainAge === null ? 'The engine has never run.' : 'No hop for ' . human_seconds($chainAge) . '.') . ' It starts with the next request to the application – or press Start engine.'; }
        return [
            'mode' => $mode, 'label' => $label, 'tone' => $tone, 'text' => $text, 'enabled' => self::enabled(),
            'workers_alive' => $workers, 'chain_alive' => $chain, 'chain_age' => $chainAge,
            'last_hop_at' => $st['last_hop_at'] ?? null, 'last_hop_ms' => $st['last_hop_ms'] ?? null, 'last_hop_summary' => $st['last_hop_summary'] ?? null, 'last_hop_error' => $st['last_hop_error'] ?? null, 'hops_total' => (int) ($st['hops_total'] ?? 0),
            'last_fire_at' => $st['last_fire_at'] ?? null, 'last_fire_error' => $st['last_fire_error'] ?? null, 'last_spawn_at' => $st['last_spawn_at'] ?? null, 'last_spawn_error' => $st['last_spawn_error'] ?? null,
            'last_kick_at' => $st['last_kick_at'] ?? null, 'last_kick_source' => $st['last_kick_source'] ?? null,
            'hop_url' => self::hopUrl(), 'base_url' => self::baseUrl(),
            'env' => ['sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'exec' => self::execFunction(), 'php_cli' => self::phpBinary(), 'can_exec' => $canExec, 'max_execution_time' => (int) ini_get('max_execution_time'), 'time_limit_adjustable' => self::timeLimitAdjustable(), 'loopback' => function_exists('stream_socket_client'), 'hop_seconds' => (int) setting('engine_hop_seconds', 55), 'worker_autostart' => (bool) setting('worker_autostart', 1), 'inline_fallback' => (bool) setting('inline_fallback_enabled', 1)],
        ];
    }
}
