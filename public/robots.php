<?php
/** robots.txt (served at /robots.txt): public pages are crawlable, the application and APIs are not. */
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
$base = rtrim(parse_url(url(''), PHP_URL_PATH) ?: '/', '/');
$lines = ['User-agent: *', "Allow: $base/$", "Allow: $base/pricing", "Allow: $base/contact-sales", "Allow: $base/terms", "Allow: $base/privacy", "Allow: $base/login", "Allow: $base/register", "Allow: $base/status/", "Allow: $base/assets/"];
foreach (['dashboard', 'websites', 'clients', 'forms', 'pages', 'ssl', 'domains', 'hosting', 'incidents', 'alerts', 'reports', 'status-pages', 'activity', 'analytics', 'emails', 'team', 'profile', 'settings', 'billing', 'projects', 'platform', 'api/', 'cron/', 'uploads/', 'collect', 't.js'] as $p) $lines[] = "Disallow: $base/$p";
$lines[] = '';
$lines[] = 'Sitemap: ' . url('public/sitemap.php');
echo implode("\n", $lines) . "\n";
