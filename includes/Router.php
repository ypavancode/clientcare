<?php
/**
 * Clean-URL routing helpers (used by index.php, url() and CRM.url in JavaScript – keep the three in sync).
 */

/** File path → clean route, e.g. websites/view.php?id=5&tab=pages → websites/5?tab=pages */
function clean_route(string $path): string
{
    $path = ltrim($path, '/');
    [$p, $q] = array_pad(explode('?', $path, 2), 2, null);
    $map = [
        'index.php' => '', 'auth/login.php' => 'login', 'auth/logout.php' => 'logout', 'auth/register.php' => 'register', 'auth/verify.php' => 'verify',
        'auth/verify-pending.php' => 'verify-pending', 'auth/forgot-password.php' => 'forgot-password', 'auth/reset-password.php' => 'reset-password', 'auth/accept-invite.php' => 'accept-invite',
        'public/pricing.php' => 'pricing', 'public/contact-sales.php' => 'contact-sales', 'public/landing.php' => '', 'public/sitemap.php' => 'sitemap.xml', 'public/robots.php' => 'robots.txt', 'api/tracker.php' => 't.js', 'api/collect.php' => 'collect', 'analytics/index.php' => 'analytics',
        'dashboard/index.php' => 'dashboard', 'users/index.php' => 'team', 'users/profile.php' => 'profile', 'notifications/index.php' => 'alerts', 'billing/index.php' => 'billing',
        'incidents/index.php' => 'incidents', 'websites/ssl.php' => 'ssl', 'websites/pages.php' => 'pages', 'status/index.php' => 'status-pages', 'platform/index.php' => 'platform',
    ];
    $id = null;
    if ($q !== null && preg_match('~(?:^|&)id=(\d+)(?:&|$)~', $q, $m)) { $id = $m[1]; }
    $stripId = function () use (&$q) { $q = trim((string) preg_replace('~(?:^|&)id=\d+~', '', (string) $q), '&'); if ($q === '') $q = null; };
    if (isset($map[$p])) {
        $p = $map[$p];
    } elseif (preg_match('~^([a-z0-9_-]+)/index\.php$~', $p, $m)) {
        $p = $m[1];
    } elseif (preg_match('~^([a-z0-9_-]+)/view\.php$~', $p, $m) && $id !== null) {
        $p = $m[1] . '/' . $id; $stripId();
    } elseif (preg_match('~^(websites)/(forms|pages|analytics)\.php$~', $p, $m) && $id !== null) {
        $p = $m[1] . '/' . $id . '/' . $m[2]; $stripId();
    } elseif (preg_match('~^platform/customer\.php$~', $p) && $id !== null) {
        $p = 'platform/customers/' . $id; $stripId();
    } elseif (preg_match('~^status/edit\.php$~', $p) && $id !== null) {
        $p = 'status-pages/' . $id; $stripId();
    } elseif (preg_match('~^([a-z0-9_-]+)/([a-z0-9_-]+)\.php$~', $p, $m)) {
        $p = $m[1] . '/' . $m[2];
    }
    return $p . ($q !== null && $q !== '' ? '?' . $q : '');
}

/** Clean route → [file, params] or null. $clean=false when a legacy ".php" path is being served in place. */
function route_target(string $rel, bool $clean = true): ?array
{
    $rel = trim($rel, '/');
    if (!$clean) {
        $file = $rel;
        if (!preg_match('~^[a-z0-9_/-]+\.php$~i', $file) || preg_match('~^(includes|config|vendor|database|logs|cache|cron)/~', $file) || !is_file(ROOT_PATH . '/' . $file)) return null;
        return [$file, []];
    }
    $static = [
        '' => 'public/landing.php', 'login' => 'auth/login.php', 'logout' => 'auth/logout.php', 'register' => 'auth/register.php', 'verify' => 'auth/verify.php',
        'verify-pending' => 'auth/verify-pending.php', 'forgot-password' => 'auth/forgot-password.php', 'reset-password' => 'auth/reset-password.php', 'accept-invite' => 'auth/accept-invite.php',
        'pricing' => 'public/pricing.php', 'contact-sales' => 'public/contact-sales.php', 'sitemap.xml' => 'public/sitemap.php', 'robots.txt' => 'public/robots.php', 'terms' => 'public/terms.php', 'privacy' => 'public/privacy.php', 't.js' => 'api/tracker.php', 'collect' => 'api/collect.php', 'analytics' => 'analytics/index.php',
        'dashboard' => 'dashboard/index.php', 'team' => 'users/index.php', 'profile' => 'users/profile.php', 'alerts' => 'notifications/index.php', 'billing' => 'billing/index.php',
        'incidents' => 'incidents/index.php', 'ssl' => 'websites/ssl.php', 'pages' => 'websites/pages.php', 'status-pages' => 'status/index.php', 'platform' => 'platform/index.php',
    ];
    if (isset($static[$rel])) return [$static[$rel], []];
    $parts = explode('/', $rel);
    $n = count($parts);
    foreach ($parts as $seg) if (!preg_match('~^[A-Za-z0-9._-]+$~', $seg)) return null;
    $sec = $parts[0];
    if ($sec === 'api') {
        if ($n >= 2 && $parts[1] === 'v1') return ['api/v1/index.php', ['route' => implode('/', array_slice($parts, 2))]];
        if ($n === 2 && is_file(ROOT_PATH . '/api/' . $parts[1] . '.php')) return ['api/' . $parts[1] . '.php', []];
        return null;
    }
    if ($sec === 'analytics' && $n === 2 && preg_match('~^([A-Za-z0-9-]{8,24})\.js$~', $parts[1], $km)) return ['api/tracker.php', ['site' => $km[1]]];
    if ($sec === 'status' && $n === 2) return ['status/public.php', ['slug' => $parts[1]]];
    if ($sec === 'status-pages' && $n === 2 && ctype_digit($parts[1])) return ['status/edit.php', ['id' => (int) $parts[1]]];
    if ($sec === 'platform') {
        if ($n === 3 && $parts[1] === 'customers' && ctype_digit($parts[2])) return ['platform/customer.php', ['id' => (int) $parts[2]]];
        if ($n === 2 && is_file(ROOT_PATH . '/platform/' . $parts[1] . '.php')) return ['platform/' . $parts[1] . '.php', []];
        return null;
    }
    $sections = ['dashboard', 'clients', 'websites', 'forms', 'domains', 'hosting', 'reports', 'notifications', 'activity', 'emails', 'users', 'settings', 'projects', 'incidents', 'billing', 'status', 'public', 'auth', 'analytics'];
    if (!in_array($sec, $sections, true)) return null;
    if ($n === 1) return is_file(ROOT_PATH . "/$sec/index.php") ? ["$sec/index.php", []] : null;
    if ($n === 2 && ctype_digit($parts[1])) return is_file(ROOT_PATH . "/$sec/view.php") ? ["$sec/view.php", ['id' => (int) $parts[1]]] : null;
    if ($n === 3 && ctype_digit($parts[1]) && is_file(ROOT_PATH . "/$sec/{$parts[2]}.php")) return ["$sec/{$parts[2]}.php", ['id' => (int) $parts[1]]];
    if ($n === 2 && is_file(ROOT_PATH . "/$sec/{$parts[1]}.php")) return ["$sec/{$parts[1]}.php", []];
    return null;
}

/** Branded HTTP error page (404 / 403 / 429 / 500 / 503). */
function http_error(int $code, string $message = ''): void
{
    http_response_code($code);
    if (is_ajax()) json_response(['success' => false, 'message' => $message ?: ['404' => 'Not found', '403' => 'Access denied', '429' => 'Too many requests', '500' => 'Something went wrong', '503' => 'Maintenance'][$code] ?? 'Error'], $code);
    $httpErrorCode = $code;
    $httpErrorMessage = $message;
    include ROOT_PATH . '/includes/layout/http-error.php';
    exit;
}
