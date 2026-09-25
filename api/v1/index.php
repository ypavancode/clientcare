<?php
/**
 * REST API v1 (API keys, tenant-isolated, rate limited).
 *   Authorization: Bearer om_xxxxxxxx_<secret>
 *   GET  /api/v1/status                 workspace summary
 *   GET  /api/v1/websites               list          GET /api/v1/websites/{id}   details (pages, forms, ssl, incidents)
 *   POST /api/v1/websites  {url,name}   create (write scope, plan limits)
 *   POST /api/v1/websites/{id}/check    run an uptime + SSL check now (write scope, rate limited)
 *   POST /api/v1/websites/{id}/pause | /resume
 *   GET  /api/v1/forms  /incidents  /ssl  /domains  /hosting
 */
require_once __DIR__ . '/../../includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('X-API-Version: 1');

function api_out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
function api_err(string $msg, int $code = 400, array $extra = []): void { api_out(['error' => $msg] + $extra, $code); }

// ---- authentication
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('apache_request_headers')) { $h = apache_request_headers(); $auth = $h['Authorization'] ?? $h['authorization'] ?? ''; }
if (!preg_match('~^Bearer\s+(om_[A-Za-z0-9]{7,8}_[A-Za-z0-9]{40})$~', trim($auth), $m)) api_err('Missing or malformed API key. Send "Authorization: Bearer <key>".', 401);
$key = DB::fetch("SELECT k.*, t.status AS tenant_status FROM api_keys k JOIN tenants t ON t.id = k.tenant_id WHERE k.key_hash = ? AND k.status = 'active'", [hash('sha256', $m[1])]);
if (!$key) api_err('Invalid or revoked API key.', 401);
if ($key['tenant_status'] !== 'active') api_err('Workspace is not active.', 403);
$tid = (int) $key['tenant_id'];
Tenant::act($tid);
$apiLevel = (string) Tenant::feature('api', $tid);
if ($apiLevel === '' || $apiLevel === 'none' || $apiLevel === '0') api_err('API access is not included in the workspace plan.', 403, ['upgrade' => true]);
// ---- rate limit per key per minute
$limit = max(1, min(600, (int) ($key['rate_limit_per_min'] ?: setting('api_rate_limit_per_min', 60))));
$bucket = 'api:' . $key['id'] . ':' . date('YmdHi');
$n = (int) Cache::get($bucket, 0) + 1;
Cache::set($bucket, $n, 70);
header('X-RateLimit-Limit: ' . $limit);
header('X-RateLimit-Remaining: ' . max(0, $limit - $n));
if ($n > $limit) api_err('Rate limit exceeded. Try again in a minute.', 429);
DB::query("UPDATE api_keys SET last_used_at = NOW(), request_count = request_count + 1 WHERE id = ?", [$key['id']]);
$canWrite = str_contains($key['scopes'], 'write');
$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['route'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);
$body = [];
if ($method === 'POST') { $raw = file_get_contents('php://input'); $body = json_decode((string) $raw, true) ?: $_POST; }
$requireWrite = function () use ($canWrite) { if (!$canWrite) api_err('This API key is read-only.', 403); };
$site = function (int $id) use ($tid): array { $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND tenant_id = ?", [$id, $tid]); if (!$w) api_err('Website not found.', 404); return $w; };
$siteOut = fn(array $w) => ['id' => (int) $w['id'], 'name' => $w['name'], 'url' => $w['url'], 'status' => $w['status'], 'http_code' => $w['http_code'], 'response_time_ms' => $w['response_time'], 'monitoring_enabled' => (bool) $w['monitoring_enabled'],
    'failure_reason' => $w['failure_reason'], 'last_checked_at' => $w['last_checked_at'], 'ssl' => ['status' => $w['ssl_status'], 'expires_at' => $w['ssl_expires_at'], 'days_left' => $w['ssl_days_left'], 'issuer' => $w['ssl_issuer']],
    'pages' => ['total' => (int) $w['pages_total'], 'ok' => (int) $w['pages_ok'], 'failed' => (int) $w['pages_failed'], 'health' => $w['page_health']],
    'forms' => ['total' => (int) $w['forms_total'], 'working' => (int) $w['forms_working'], 'failed' => (int) $w['forms_failed'], 'captcha_blocked' => (int) $w['forms_blocked']], 'created_at' => $w['created_at']];

$res = $parts[0] ?? '';
if ($res === '' || $res === 'status') {
    Tenant::act($tid);
    $s = Stats::dashboard();
    api_out(['workspace' => Tenant::name($tid), 'plan' => Tenant::plan($tid)['name'], 'usage' => Tenant::usage($tid), 'websites' => ['total' => $s['sites_total'], 'online' => $s['sites_online'], 'down' => $s['sites_down']], 'pages' => ['total' => $s['pages_total'], 'failed' => $s['pages_failed']],
        'forms' => ['total' => $s['forms_total'], 'working' => $s['forms_working'], 'failed' => $s['forms_failed'], 'captcha_blocked' => $s['forms_blocked'] ?? 0], 'ssl_issues' => $s['ssl_issues'], 'domains_expiring' => $s['domain_expiring'], 'hosting_expiring' => $s['hosting_expiring'], 'generated_at' => date('c')]);
}
if ($res === 'websites') {
    if ($method === 'GET' && count($parts) === 1) {
        $rows = DB::fetchAll("SELECT * FROM websites WHERE tenant_id = ? ORDER BY name LIMIT 1000", [$tid]);
        api_out(['data' => array_map($siteOut, $rows), 'count' => count($rows)]);
    }
    if ($method === 'POST' && count($parts) === 1) {
        $requireWrite();
        $url = normalize_url((string) ($body['url'] ?? ''));
        if (!valid_url($url)) api_err('A valid url is required.', 422);
        $can = Tenant::canAdd('websites', 1, $tid);
        if (!$can['ok']) api_err($can['message'], 403, ['upgrade' => true, 'usage' => $can]);
        if (DB::value("SELECT id FROM websites WHERE tenant_id = ? AND url = ?", [$tid, $url])) api_err('This website already exists.', 409);
        $clientId = (int) DB::value("SELECT id FROM clients WHERE tenant_id = ? AND name = 'My Websites' LIMIT 1", [$tid]) ?: DB::insert('clients', ['tenant_id' => $tid, 'name' => 'My Websites', 'status' => 'active', 'monitoring_enabled' => 1, 'notify_client' => 0]);
        $id = DB::insert('websites', ['tenant_id' => $tid, 'client_id' => $clientId, 'name' => mb_substr((string) ($body['name'] ?? '') ?: host_from_url($url), 0, 150), 'url' => $url, 'technology' => 'Other', 'monitoring_enabled' => 1, 'page_monitoring_enabled' => 1, 'form_discovery_enabled' => (int) (bool) Tenant::feature('form_discovery', $tid), 'status' => 'unknown']);
        ActivityLog::add('website_added', 'Website added via API: ' . $url, ['website_id' => $id, 'tenant_id' => $tid]);
        api_out(['data' => $siteOut($site($id))], 201);
    }
    if (count($parts) >= 2 && ctype_digit($parts[1])) {
        $w = $site((int) $parts[1]);
        $sub = $parts[2] ?? '';
        if ($method === 'GET' && $sub === '') {
            $pages = DB::fetchAll("SELECT id, url, title, status, http_code, response_time, last_checked_at FROM website_pages WHERE website_id = ? AND is_active = 1 ORDER BY priority LIMIT 500", [$w['id']]);
            $forms = DB::fetchAll("SELECT id, name, page_url, form_type, form_kind, technology, status, last_outcome, captcha_detected, last_tested_at FROM forms WHERE website_id = ? AND status <> 'removed' ORDER BY name", [$w['id']]);
            $inc = DB::fetchAll("SELECT id, started_at, resolved_at, duration_seconds, status, failure_reason FROM website_incidents WHERE website_id = ? ORDER BY id DESC LIMIT 20", [$w['id']]);
            api_out(['data' => $siteOut($w) + ['pages_list' => $pages, 'forms_list' => $forms, 'incidents' => $inc]]);
        }
        if ($method === 'POST' && $sub === 'check') {
            $requireWrite();
            $cool = 'api:check:' . $w['id'];
            if (Cache::get($cool)) api_err('A check was run for this website less than a minute ago.', 429);
            Cache::set($cool, 1, 60);
            $r = Monitor::checkWebsite($w, true);
            $ssl = stripos($w['url'], 'https://') === 0 ? Monitor::checkSsl($w, true) : null;
            Mailer::processQueue(10);
            api_out(['data' => ['status' => $r['status'], 'up' => (bool) $r['up'], 'http_code' => $r['http_code'], 'response_time_ms' => $r['response_ms'], 'reason' => $r['reason'], 'ssl' => $ssl ? ['status' => $ssl['status'], 'days' => $ssl['days']] : null]]);
        }
        if ($method === 'POST' && in_array($sub, ['pause', 'resume'], true)) {
            $requireWrite();
            $on = $sub === 'resume' ? 1 : 0;
            DB::update('websites', ['monitoring_enabled' => $on, 'status' => $on ? 'unknown' : 'paused'], 'id = ?', [$w['id']]);
            ActivityLog::add('website_updated', 'Monitoring ' . ($on ? 'resumed' : 'paused') . ' via API for ' . $w['name'], ['website_id' => $w['id'], 'tenant_id' => $tid]);
            api_out(['data' => ['id' => (int) $w['id'], 'monitoring_enabled' => (bool) $on]]);
        }
    }
    api_err('Unknown websites route.', 404);
}
if ($method !== 'GET') api_err('Method not allowed.', 405);
switch ($res) {
    case 'forms':
        api_out(['data' => DB::fetchAll("SELECT f.id, f.website_id, w.name AS website, f.name, f.page_url, f.form_type, f.form_kind, f.technology, f.status, f.last_outcome, f.captcha_detected, f.last_tested_at, f.last_success_at, f.last_failure_reason FROM forms f JOIN websites w ON w.id = f.website_id WHERE f.tenant_id = ? AND f.status <> 'removed' ORDER BY w.name, f.name LIMIT 2000", [$tid])]);
    case 'incidents':
        $open = ($_GET['state'] ?? '') === 'open';
        $rows = DB::fetchAll("SELECT 'website' AS kind, i.id, i.website_id, w.name AS website, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason AS reason, i.status_code AS http_code FROM website_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ? " . ($open ? 'AND i.resolved_at IS NULL ' : '') . "
            UNION ALL SELECT 'form', i.id, i.website_id, w.name, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason, i.http_code FROM form_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ? " . ($open ? 'AND i.resolved_at IS NULL ' : '') . "
            UNION ALL SELECT 'page', i.id, i.website_id, w.name, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason, i.status_code FROM page_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ? " . ($open ? 'AND i.resolved_at IS NULL ' : '') . "
            UNION ALL SELECT 'ssl', i.id, i.website_id, w.name, i.started_at, i.resolved_at, i.duration_seconds, i.status, NULL FROM ssl_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ? " . ($open ? 'AND i.resolved_at IS NULL ' : '') . " ORDER BY started_at DESC LIMIT 500", [$tid, $tid, $tid, $tid]);
        api_out(['data' => $rows]);
    case 'ssl':
        api_out(['data' => DB::fetchAll("SELECT id, name, url, ssl_status, ssl_expires_at, ssl_days_left, ssl_issuer, ssl_error, ssl_checked_at FROM websites WHERE tenant_id = ? ORDER BY ssl_days_left ASC LIMIT 1000", [$tid])]);
    case 'domains':
        api_out(['data' => DB::fetchAll("SELECT d.id, d.domain_name, d.registrar, d.registration_date, d.expiry_date, d.auto_renew, d.website_id, c.name AS client FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.tenant_id = ? ORDER BY d.expiry_date LIMIT 1000", [$tid])]);
    case 'hosting':
        api_out(['data' => DB::fetchAll("SELECT h.id, h.provider, h.plan, h.server_ip, h.start_date, h.expiry_date, h.renewal_status, h.website_id, c.name AS client FROM hosting h JOIN clients c ON c.id = h.client_id WHERE h.tenant_id = ? ORDER BY h.expiry_date LIMIT 1000", [$tid])]);
    default:
        api_err('Unknown route.', 404);
}
