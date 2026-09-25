<?php
/** Super Admin → Usage: every client's consumption against its plan limits (websites, pages, forms, users, page views, stored events, retention). */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$plans = DB::fetchAll("SELECT id, name FROM plans ORDER BY sort_order, id");
$tot = Cache::remember('platform:usage:totals', 120, function () {
    return [
        'websites' => (int) DB::value("SELECT COUNT(*) FROM websites"), 'pages' => (int) DB::value("SELECT COUNT(*) FROM website_pages WHERE is_active = 1"), 'forms' => (int) DB::value("SELECT COUNT(*) FROM forms WHERE status <> 'removed'"),
        'users' => (int) DB::value("SELECT COUNT(*) FROM users WHERE status = 'active' AND role <> 'notify'"), 'pv' => (int) DB::value("SELECT COALESCE(SUM(pageviews),0) FROM analytics_daily WHERE day >= ?", [date('Y-m-01')]),
        'events' => (int) DB::value("SELECT COUNT(*) FROM analytics_events"), 'checks' => (int) DB::value("SELECT COALESCE(SUM(checks),0) FROM website_uptime_daily WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"),
        'over' => (int) DB::value("SELECT COUNT(*) FROM tenants t JOIN plans p ON p.id = t.plan_id LEFT JOIN (SELECT tenant_id, COUNT(*) n FROM websites GROUP BY tenant_id) w ON w.tenant_id = t.id WHERE p.max_websites IS NOT NULL AND COALESCE(w.n,0) >= p.max_websites"),
    ];
});
$pageTitle = 'Usage';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Usage']];
$pageSubtitle = 'Platform totals: ' . number_format($tot['websites']) . ' websites · ' . number_format($tot['pages']) . ' pages · ' . number_format($tot['forms']) . ' forms · ' . number_format($tot['users']) . ' users · ' . number_format($tot['pv']) . ' page views this month · ' . number_format($tot['events']) . ' raw events stored (' . (int) setting('analytics_raw_retention_days', 90) . ' d retention) · ' . number_format($tot['checks']) . ' checks in 30 days';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="plan_id" class="form-select form-select-sm"><option value="">Any plan</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <select name="over" class="form-select form-select-sm"><option value="">All clients</option><option value="1">At / near a limit<?= $tot['over'] ? ' (' . $tot['over'] . ' at website limit)' : '' ?></option></select>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_usage" data-filters="#filters" data-order='[]' data-page-length="25" data-empty="No clients" data-empty-icon="bi-bar-chart-steps">
    <thead><tr><th>Client</th><th>Plan</th><th>Websites</th><th>Pages</th><th>Forms</th><th>Users</th><th>Page views · month</th><th>Events stored</th><th>Retention</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<div class="small text-muted mt-2">Limits come from the plan (Plans &amp; Pricing). Page views count against <em>max views / month</em>; clients receive 80 / 90 / 100 % notices automatically. Raw events are pruned after the platform retention; daily summaries follow each plan's analytics retention.</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';
