<?php
/** XML sitemap of the public pages (served at /sitemap.xml). Cached for an hour; the app itself is noindex. */
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$pages = [['', '1.0', 'weekly'], ['public/pricing.php', '0.9', 'weekly'], ['public/contact-sales.php', '0.6', 'monthly'], ['auth/register.php', '0.7', 'monthly'], ['auth/login.php', '0.3', 'monthly'], ['public/terms.php', '0.2', 'yearly'], ['public/privacy.php', '0.2', 'yearly']];
$mod = date('Y-m-d', max(array_map(fn($p) => @filemtime(ROOT_PATH . '/' . ($p[0] ?: 'public/landing.php')) ?: time(), $pages)));
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$p, $prio, $freq]) echo '  <url><loc>' . e(url($p)) . '</loc><lastmod>' . $mod . '</lastmod><changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority></url>' . "\n";
echo "</urlset>\n";
