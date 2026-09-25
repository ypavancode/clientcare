<?php
/**
 * Shared bootstrap for cron scripts.
 * Runs from the command line (cPanel cron / Task Scheduler) or over HTTP with ?key=CRON_KEY.
 * Every run is recorded in the cron_runs table so the dashboard can show whether automatic monitoring is alive.
 */
require_once __DIR__ . '/../includes/init.php';

if (!IS_CLI && !defined('SCHEDULER_INPROC')) {
    $key = $_GET['key'] ?? '';
    if ($key === '' || CRON_KEY === 'change-this-cron-secret-key' || !hash_equals(CRON_KEY, $key)) {
        http_response_code(403);
        exit('Forbidden. Set CRON_KEY in config/config.php and call this script with ?key=YOUR_KEY');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
set_time_limit(0);
ignore_user_abort(true);

$GLOBALS['__cron_name'] = null;
$GLOBALS['__cron_done'] = false;

/** Prevent overlapping runs of the same script and record the start of the run. */
function cron_lock(string $name): bool
{
    $file = LOG_PATH . '/' . $name . '.lock';
    $fp = fopen($file, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        cron_out("Another instance of $name is still running. Exiting.");
        return false;
    }
    $GLOBALS['__cron_lock'] = $fp;
    $GLOBALS['__cron_name'] = $name;
    try {
        Monitor::cronStart($name);
    } catch (Throwable $e) {
        app_log('error', 'cronStart failed: ' . $e->getMessage());
    }
    register_shutdown_function(function () use ($fp, $file, $name) {
        if (!$GLOBALS['__cron_done']) {
            // Script ended without cron_done(): fatal error or exit – record as failed
            $err = error_get_last();
            try {
                Monitor::cronFinish($name, false, 'Aborted: ' . ($err['message'] ?? 'unknown error'));
            } catch (Throwable $e) {
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($file);
    });
    return true;
}

/** Record a successful (or failed) end of the run. */
function cron_done(string $message, bool $ok = true): void
{
    $GLOBALS['__cron_done'] = true;
    cron_out($message);
    if ($GLOBALS['__cron_name']) {
        try {
            Monitor::cronFinish($GLOBALS['__cron_name'], $ok, $message);
        } catch (Throwable $e) {
            app_log('error', 'cronFinish failed: ' . $e->getMessage());
        }
    }
}

function cron_out(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    @file_put_contents(LOG_PATH . '/cron.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    // keep the log from growing forever (~2 MB)
    if (random_int(1, 200) === 1 && @filesize(LOG_PATH . '/cron.log') > 2 * 1024 * 1024) {
        $lines = @file(LOG_PATH . '/cron.log');
        if ($lines) @file_put_contents(LOG_PATH . '/cron.log', implode('', array_slice($lines, -2000)));
    }
}

/** Is --force / ?force=1 given? */
function cron_force(): bool
{
    global $argv;
    return IS_CLI ? in_array('--force', $argv ?? [], true) : isset($_GET['force']);
}

/** Read --name=value (CLI) or ?name=value (HTTP). */
function cron_opt(string $name, $default = null)
{
    global $argv;
    if (IS_CLI) {
        foreach ($argv ?? [] as $a) {
            if (str_starts_with($a, '--' . $name . '=')) return substr($a, strlen($name) + 3);
        }
        return $default;
    }
    return $_GET[$name] ?? $default;
}

/** --shard=N/M → [N-1, M] (zero-based shard index and shard count). */
function cron_shard(): array
{
    $v = (string) cron_opt('shard', '');
    if (preg_match('~^(\d+)/(\d+)$~', $v, $m) && (int) $m[2] > 1 && (int) $m[1] >= 1 && (int) $m[1] <= (int) $m[2]) {
        return [(int) $m[1] - 1, (int) $m[2]];
    }
    return [0, 1];
}
