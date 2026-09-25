<?php
/**
 * Analytics beacon endpoint – POST /collect (text/plain JSON body, no cookies, no session, CORS *).
 * Always answers 204 quickly; validation failures are silent (the visitor's browser must never see errors).
 */
if (!defined('SKIP_SESSION')) define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/init.php';
// Under clean URLs the front controller (index.php) has already started the CRM session before this file runs, so the
// SKIP_SESSION flag above only helps for direct calls. Drop that anonymous session again: visitors must never receive a
// cookie from the beacon and no session (with their IP, when SESSION_DRIVER = database) may be stored per page view.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id'])) { $_SESSION = []; session_destroy(); header_remove('Set-Cookie'); }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 8192);
$p = json_decode((string) $raw, true);
http_response_code(204);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { header('Content-Length: 0'); header('Connection: close'); flush(); }
ignore_user_abort(true);
if (!is_array($p)) exit;
try {
    [$ok, $why] = Analytics::collect($p, Analytics::clientIp(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $_SERVER['HTTP_ORIGIN'] ?? null);
    if (defined('APP_ENV') && APP_ENV === 'development' && !$ok && !in_array($why, ['bot', 'unknown site'], true)) app_log('info', 'analytics collect rejected: ' . $why);
} catch (Throwable $e) {
    app_log('error', 'analytics collect failed: ' . $e->getMessage());
}
// Beacons are requests nobody waits for: when neither a worker nor the request chain is alive, process due monitoring here.
Engine::inlineTickIfNeeded(25);
