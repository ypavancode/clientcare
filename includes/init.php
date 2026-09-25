<?php
/**
 * Application bootstrap. Include this at the top of every page, API endpoint and cron script.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
if (!is_file(__DIR__ . '/../config/database.php')) {
    exit('Missing config/database.php. Copy config/database.sample.php to config/database.php and fill in your database details.');
}
require_once __DIR__ . '/../config/database.php';

define('IS_CLI', PHP_SAPI === 'cli');

/* ---------- Error handling: never show raw errors to users ---------- */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!is_dir(LOG_PATH)) @mkdir(LOG_PATH, 0755, true);
ini_set('error_log', LOG_PATH . '/php-errors.log');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/SessionStore.php';
require_once __DIR__ . '/DataTable.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/Registration.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Browser.php';
require_once __DIR__ . '/FormTester.php';
require_once __DIR__ . '/Monitor.php';
require_once __DIR__ . '/FormDiscovery.php';
require_once __DIR__ . '/Webhooks.php';
require_once __DIR__ . '/Queue.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/Engine.php';
require_once __DIR__ . '/Analytics.php';

/* ---------- Execution engine watchdog (v3.9): every web request keeps the server-side scheduler alive – no cron, no open browser needed ---------- */
if (PHP_SAPI !== 'cli' && !defined('ENGINE_HOP')) {
    register_shutdown_function(static function (): void {
        try { Engine::watchdog(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'request'))); } catch (Throwable $e) {}
    });
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    app_log('error', get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    try {
        Notifier::systemError($e);
    } catch (Throwable $ignored) {
    }
    $detail = APP_ENV === 'development' ? $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() : null;
    if (IS_CLI) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (is_ajax()) {
        json_response(['success' => false, 'message' => 'Something went wrong. Please try again or contact the administrator.', 'detail' => $detail], 500);
    }
    http_response_code(500);
    $errorPage = ROOT_PATH . '/includes/layout/http-error.php';
    $httpErrorCode = 500;
    if (is_file($errorPage)) {
        include $errorPage;
    } else {
        echo '<h1>Something went wrong</h1><p>Please try again or contact the administrator.</p>';
    }
    exit;
});

/* ---------- Performance instrumentation (development only) ---------- */
if (APP_ENV === 'development' && !IS_CLI) {
    define('REQUEST_START', microtime(true));
    if (isset($_GET['__trace'])) DB::$traceOn = true; // ?__trace=1 → every query of this request is written to logs/trace.log
    register_shutdown_function(function () {
        if (!headers_sent()) {
            header('X-DB-Queries: ' . DB::$queries);
            header('X-DB-Time: ' . round(DB::$time * 1000) . 'ms');
            header('X-Request-Time: ' . round((microtime(true) - REQUEST_START) * 1000) . 'ms');
            if (DB::$slow) header('X-DB-Slow: ' . count(DB::$slow));
        }
        if (DB::$slow) app_log('perf', 'Slow queries on ' . ($_SERVER['REQUEST_URI'] ?? ''), DB::$slow);
        if (DB::$traceOn) @file_put_contents(LOG_PATH . '/trace.log', "== " . ($_SERVER['REQUEST_URI'] ?? '') . "
" . implode("
", array_map(fn($t) => sprintf('%6.1fms  %s', $t[0], $t[1]), DB::$trace)) . "
");
        // per-request profile (development): time, query count, DB time – used by the performance audit
        @file_put_contents(LOG_PATH . '/perf.log', sprintf("%s %s %dms q=%d db=%dms%s
", date('H:i:s'), $_SERVER['REQUEST_URI'] ?? '', (int) round((microtime(true) - REQUEST_START) * 1000), DB::$queries, (int) round(DB::$time * 1000), DB::$slow ? ' SLOW=' . count(DB::$slow) : ''), FILE_APPEND);
    });
}

/* ---------- Base URL ---------- */
if (APP_URL !== '') {
    define('BASE_URL', rtrim(APP_URL, '/'));
} elseif (!IS_CLI) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''), '/');
    $root = str_replace('\\', '/', realpath(ROOT_PATH));
    $sub = ($docRoot !== '' && str_starts_with($root, $docRoot)) ? substr($root, strlen($docRoot)) : '';
    define('BASE_URL', ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($sub, '/'));
} else {
    define('BASE_URL', 'http://localhost');
}

/* ---------- Timezone ---------- */
date_default_timezone_set(APP_TIMEZONE);
try {
    $tz = setting('timezone');
    if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
} catch (Throwable $e) {
    // settings table may not exist yet during installation
}

/* ---------- Session ---------- */
if (!IS_CLI && !defined('SKIP_SESSION') && session_status() === PHP_SESSION_NONE) {
    // Secure flag also behind a TLS-terminating proxy / CDN (same rule as the BASE_URL detection above)
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') == 443 || str_starts_with(BASE_URL, 'https://');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    if (defined('SESSION_DRIVER') && SESSION_DRIVER === 'database') SessionStore::enable(); // shared login state across app servers
    session_start();

    // Idle timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        Auth::logout(false);
        session_start();
        flash('warning', 'Your session expired. Please log in again.');
    }
    $_SESSION['last_activity'] = time();

    // Restore "remember me" login
    if (empty($_SESSION['user_id'])) {
        Auth::loginFromRememberCookie();
    }
}
