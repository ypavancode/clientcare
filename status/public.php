<?php
/** Public status page: /status/{slug} (no login). Custom domains are matched by host name. */
require_once __DIR__ . '/../includes/init.php';
$slug = strtolower(trim((string) get('slug', '')));
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$page = $slug !== '' ? DB::fetch("SELECT * FROM status_pages WHERE slug = ?", [$slug]) : DB::fetch("SELECT * FROM status_pages WHERE custom_domain = ?", [$host]);
if (!$page || (!$page['is_public'] && !(Auth::check() && Auth::user() && (Auth::tenantId() === (int) $page['tenant_id'] || Auth::isPlatformAdmin())))) http_error(404, 'This status page does not exist or is not public.');
$tid = (int) $page['tenant_id'];
$tenant = DB::fetch("SELECT * FROM tenants WHERE id = ?", [$tid]);
$ids = json_decode((string) $page['website_ids'], true) ?: [];
$where = "w.tenant_id = ?"; $params = [$tid];
if ($ids) { $where .= " AND w.id IN (" . implode(',', array_map('intval', $ids)) . ")"; }
$sites = DB::fetchAll("SELECT w.* FROM websites w WHERE $where ORDER BY w.name LIMIT 200", $params);
$siteIds = array_map('intval', array_column($sites, 'id'));
$since = date('Y-m-d', time() - 30 * 86400);
$uptime = [];
if ($siteIds) {
    foreach (DB::fetchAll("SELECT website_id, COALESCE(SUM(checks),0) AS c, COALESCE(SUM(up_checks),0) AS u FROM website_uptime_daily WHERE website_id IN (" . implode(',', $siteIds) . ") AND day >= ? GROUP BY website_id", [$since]) as $r) $uptime[$r['website_id']] = ['c' => (int) $r['c'], 'u' => (int) $r['u']];
    foreach (DB::fetchAll("SELECT website_id, COUNT(*) AS c, COALESCE(SUM(status IN ('online','redirecting')),0) AS u FROM website_monitoring WHERE website_id IN (" . implode(',', $siteIds) . ") AND checked_at >= CURDATE() GROUP BY website_id") as $r) { $uptime[$r['website_id']]['c'] = ($uptime[$r['website_id']]['c'] ?? 0) + (int) $r['c']; $uptime[$r['website_id']]['u'] = ($uptime[$r['website_id']]['u'] ?? 0) + (int) $r['u']; }
}
$incidents = $siteIds && $page['show_incidents'] ? DB::fetchAll("SELECT 'Website' AS kind, i.website_id, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason AS reason FROM website_incidents i WHERE i.website_id IN (" . implode(',', $siteIds) . ") AND i.started_at >= ?
    UNION ALL SELECT 'Form', i.website_id, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason FROM form_incidents i WHERE i.website_id IN (" . implode(',', $siteIds) . ") AND i.started_at >= ?
    UNION ALL SELECT 'SSL', i.website_id, i.started_at, i.resolved_at, i.duration_seconds, i.status FROM ssl_incidents i WHERE i.website_id IN (" . implode(',', $siteIds) . ") AND i.started_at >= ? ORDER BY started_at DESC LIMIT 40", [$since, $since, $since]) : [];
$siteName = array_column($sites, 'name', 'id');
$down = count(array_filter($sites, fn($w) => in_array($w['status'], explode(',', str_replace("'", '', down_statuses_sql())), true)));
$formsFailed = array_sum(array_column($sites, 'forms_failed'));
$sslIssues = count(array_filter($sites, fn($w) => in_array($w['ssl_status'], ['expired', 'error', 'expiring_soon'], true)));
$overall = $down ? 'major' : (($formsFailed || $sslIssues) ? 'degraded' : 'ok');
$color = preg_match('~^#[0-9a-fA-F]{6}$~', (string) $page['theme_color']) ? $page['theme_color'] : '#FCAF17';
$brand = $page['hide_branding'] && Tenant::feature('white_label', $tid) ? null : setting('platform_name', 'Outline Monitor');
$statusLabel = fn(string $s) => in_array($s, ['online', 'redirecting'], true) ? ['Operational', 'ok'] : (in_array($s, ['unknown', 'paused'], true) ? ['Not monitored', 'na'] : ['Down – ' . ucwords(str_replace('_', ' ', $s)), 'down']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="120">
<title><?= e($page['name']) ?> – Status</title>
<meta name="description" content="<?= e($page['description'] ?: 'Live status of ' . $page['name']) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f5f6f8; font-family: 'DM Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2328; }
  .top { background: #111; color: #fff; padding: 28px 0; border-bottom: 5px solid <?= $color ?>; }
  .overall { border-radius: 14px; padding: 18px 22px; font-weight: 600; font-size: 1.1rem; margin: -26px 0 22px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
  .overall.ok { background: #d9f2e6; color: #157347; } .overall.degraded { background: #fff4cc; color: #9a7400; } .overall.major { background: #ffe1dc; color: #c8321f; }
  .card { border: 0; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
  .comp { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eef0f3; gap: 12px; flex-wrap: wrap; }
  .comp:last-child { border-bottom: 0; }
  .st { font-weight: 600; font-size: .85rem; } .st.ok { color: #157347; } .st.down { color: #c8321f; } .st.na { color: #8a8f98; }
  .bar { display: flex; gap: 2px; margin-top: 6px; } .bar span { flex: 1; height: 8px; border-radius: 2px; background: #d9f2e6; } .bar span.d { background: #f3b1a8; } .bar span.n { background: #e6e8ec; }
  .foot { color: #8a8f98; font-size: .8rem; padding: 26px 0; text-align: center; }
</style>
</head>
<body>
<div class="top"><div class="container"><div class="d-flex align-items-center gap-3 flex-wrap"><?php if ($page['logo_file']): ?><img src="<?= url('uploads/' . $page['logo_file']) ?>" alt="" style="height:36px"><?php endif; ?><div><h1 class="h3 mb-0"><?= e($page['name']) ?></h1><div class="text-white-50 small"><?= e($page['description'] ?: 'Live status of our websites and services') ?></div></div></div></div></div>
<div class="container py-3">
  <div class="overall <?= $overall ?>"><i class="bi <?= $overall === 'ok' ? 'bi-check-circle-fill' : ($overall === 'degraded' ? 'bi-exclamation-triangle-fill' : 'bi-x-octagon-fill') ?> me-2"></i><?= $overall === 'ok' ? 'All systems operational' : ($overall === 'degraded' ? 'Some components need attention' : $down . ' website(s) down') ?><span class="float-end small fw-normal text-muted">Updated <?= date('d M Y H:i') ?></span></div>
  <div class="card mb-3"><div class="card-body">
    <?php foreach ($sites as $w): [$lbl, $cls] = $statusLabel($w['status']); $u = $uptime[$w['id']] ?? null; $pct = $u && $u['c'] ? round($u['u'] / $u['c'] * 100, 2) : null;
      $days = $page['show_uptime'] ? DB::fetchAll("SELECT day, checks, up_checks FROM website_uptime_daily WHERE website_id = ? AND day >= ? ORDER BY day", [$w['id'], $since]) : []; $byDay = array_column($days, null, 'day'); ?>
      <div class="comp"><div style="min-width:220px"><div class="fw-600"><?= e($w['name']) ?></div><div class="small text-muted"><?= e(host_from_url($w['url'])) ?><?= $page['show_ssl'] ? ' · SSL ' . e(str_replace('_', ' ', $w['ssl_status'])) . ($w['ssl_days_left'] !== null ? ' (' . (int) $w['ssl_days_left'] . ' d)' : '') : '' ?><?= $page['show_forms'] && $w['forms_total'] ? ' · forms ' . (int) $w['forms_working'] . '/' . (int) $w['forms_total'] . ' working' . ($w['forms_failed'] ? ' <span class="text-danger">(' . (int) $w['forms_failed'] . ' failed)</span>' : '') : '' ?></div>
        <?php if ($page['show_uptime']): ?><div class="bar" title="Last 30 days"><?php for ($i = 29; $i >= 0; $i--): $d = date('Y-m-d', time() - $i * 86400); $r = $byDay[$d] ?? null; ?><span class="<?= !$r ? 'n' : ((int) $r['checks'] && (int) $r['up_checks'] < (int) $r['checks'] * 0.99 ? 'd' : '') ?>"></span><?php endfor; ?></div><?php endif; ?></div>
        <div class="text-end"><div class="st <?= $cls ?>"><?= e($lbl) ?></div><?= $pct !== null && $page['show_uptime'] ? '<div class="small text-muted">' . $pct . '% uptime (30 d)</div>' : '' ?></div></div>
    <?php endforeach; ?>
    <?php if (!$sites): ?><div class="text-muted">No components published yet.</div><?php endif; ?>
  </div></div>
  <?php if ($page['show_incidents']): ?>
  <div class="card"><div class="card-body"><h2 class="h6 fw-bold mb-3">Incident history (30 days)</h2>
    <?php if (!$incidents): ?><div class="text-muted small">No incidents in the last 30 days.</div><?php else: ?>
    <?php foreach ($incidents as $i): ?><div class="comp"><div><div class="fw-600 small"><?= e($i['kind']) ?> · <?= e($siteName[$i['website_id']] ?? '') ?><?= $i['reason'] ? ' – ' . e(ucwords(str_replace('_', ' ', $i['reason']))) : '' ?></div><div class="small text-muted"><?= format_datetime($i['started_at']) ?><?= $i['resolved_at'] ? ' → ' . format_datetime($i['resolved_at']) . ' · ' . duration_human((int) $i['duration_seconds']) : '' ?></div></div><div class="st <?= $i['resolved_at'] ? 'ok' : 'down' ?>"><?= $i['resolved_at'] ? 'Resolved' : 'Investigating' ?></div></div><?php endforeach; ?>
    <?php endif; ?></div></div>
  <?php endif; ?>
</div>
<div class="foot"><?= $page['footer_text'] ? e($page['footer_text']) . ' · ' : '' ?><?= $brand ? 'Powered by <a href="' . e(BASE_URL) . '/" style="color:#555">' . e($brand) . '</a>' : e($tenant['name']) ?></div>
</body>
</html>
