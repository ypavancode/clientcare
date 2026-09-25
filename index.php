<?php
/**
 * FRONT CONTROLLER – clean URLs for the whole product (no ".php" in any user-facing URL).
 *
 *   /                      landing page (or the dashboard when logged in)
 *   /login /register /verify /verify-pending /forgot-password /reset-password /logout /pricing /contact-sales
 *   /dashboard /websites /websites/12 /websites/12/forms /forms /forms/monitoring /alerts /team /billing /incidents ...
 *   /api/forms            → api/forms.php          /api/v1/websites   → api/v1/index.php (REST, API keys)
 *   /status/acme          → status/public.php      /platform/...      → platform admin area
 *
 * .htaccess sends every request here (assets, uploads and cron scripts are served directly). Requests that still
 * contain ".php" are redirected permanently to their clean equivalent.
 */
// Visitor-facing analytics endpoints (beacon + tracker script) must never start a CRM session or set a cookie on the
// visitor's browser – decide that BEFORE the bootstrap starts the session.
if (preg_match('~/(collect|t\.js|analytics/[A-Za-z0-9-]{8,24}\.js)/?$~', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) define('SKIP_SESSION', true);
require_once __DIR__ . '/includes/init.php';

$base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rel = trim(substr($uri, strlen($base)), '/');
$rel = rawurldecode($rel);

// ---- legacy ".php" URLs → permanent redirect to the clean URL (POST requests are served in place so nothing breaks)
if ($rel !== '' && preg_match('~\.php$~i', $rel)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !is_ajax()) {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $clean = clean_route($rel . ($qs !== '' ? '?' . $qs : ''));
        header('Location: ' . BASE_URL . '/' . $clean, true, 301);
        exit;
    }
    $target = route_target($rel, false);
} else {
    $target = route_target($rel, true);
}

if ($target === null) {
    http_error(404);
}
[$file, $params] = $target;
foreach ($params as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }
$_SERVER['SCRIPT_NAME'] = $base . '/' . $file;
$_SERVER['PHP_SELF'] = $base . '/' . $file;
require ROOT_PATH . '/' . $file;
