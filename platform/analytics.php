<?php
/** Super Admin → Analytics: platform growth (clients, users, websites), plan mix, logins, monitoring volume, email delivery, and website-analytics usage. Charts load lazily. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$days = in_array((int) get('days'), [7, 30, 90], true) ? (int) get('days') : 30;
$a = Stats::platform($days);
$an = Analytics::platformStats();
$u = $a['users']; $t = $a['tenants']; $vol = $a['volume'];
$pageTitle = 'Analytics';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Analytics']];
$pageSubtitle = 'Platform metrics for the last ' . $days . ' days · ' . number_format($a['checks_total']) . ' monitoring checks · computed ' . e(time_ago($a['computed_at']));
$pageActions = '<div class="btn-group btn-group-sm">' . implode('', array_map(fn($d) => '<a href="' . url('platform/analytics.php?days=' . $d) . '" class="btn ' . ($d === $days ? 'btn-dark' : 'btn-light') . '">' . $d . ' d</a>', [7, 30, 90])) . '</div>';
$useCharts = true;
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="kpi-grid compact mb-3">
  <a href="<?= url('platform/customers.php') ?>" class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-buildings"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($t['total']) ?></div><div class="stat-label">Clients · +<?= (int) $t['new_30d'] ?> in 30 d</div></div></a>
  <a href="<?= url('platform/subscriptions.php?sub=active') ?>" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-credit-card"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($a['paid']) ?></div><div class="stat-label">Paid · ₹<?= number_format($a['mrr']) ?> MRR</div></div></a>
  <a href="<?= url('platform/subscriptions.php?sub=trial') ?>" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-hourglass-split"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($t['trial']) ?></div><div class="stat-label">On trial</div></div></a>
  <a href="<?= url('platform/users.php') ?>" class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-people"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($u['total']) ?></div><div class="stat-label">Users · <?= number_format($u['active_7d']) ?> active in 7 d</div></div></a>
  <a href="<?= url('platform/websites.php') ?>" class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-globe2"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($vol['websites']) ?></div><div class="stat-label"><?= number_format($vol['pages']) ?> pages · <?= number_format($vol['forms']) ?> forms</div></div></a>
  <div class="stat-card"><div class="stat-icon <?= $a['open_incidents'] ? 'tint-danger' : 'tint-success' ?>"><i class="bi bi-exclamation-octagon"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($a['open_incidents']) ?></div><div class="stat-label">Open incidents</div></div></div>
  <a href="<?= url('platform/emails.php?tab=logs') ?>" class="stat-card"><div class="stat-icon <?= $a['emails_24h']['failed'] ? 'tint-danger' : 'tint-success' ?>"><i class="bi bi-envelope-check"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($a['emails_24h']['sent']) ?></div><div class="stat-label">Emails · 24 h<?= $a['emails_24h']['failed'] ? ' · <span class="text-danger">' . (int) $a['emails_24h']['failed'] . ' failed</span>' : '' ?></div></div></a>
  <a href="<?= url('platform/usage.php') ?>" class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-bar-chart-line"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($an['pv_month']) ?></div><div class="stat-label">Page views · month · <?= number_format($an['tracked']) ?> tracked sites</div></div></a>
</div>

<div id="platformCharts">
<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-graph-up-arrow me-2"></i>Growth · <?= $days ?> days</span><span class="small text-muted">clients, users &amp; websites added per day</span></div><div class="card-body"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chGrowth" class="d-none"></canvas></div></div></div></div>
  <div class="col-xl-4"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-tags me-2"></i>Clients by plan</span></div><div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chPlans" class="d-none"></canvas><div class="donut-center d-none" id="chPlansCenter"></div></div></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-xl-3 col-md-6"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-people me-2"></i>User base</span></div><div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chUsers" class="d-none"></canvas><div class="donut-center d-none" id="chUsersCenter"></div></div></div></div></div>
  <div class="col-xl-3 col-md-6"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-box-arrow-in-right me-2"></i>Logins / day</span></div><div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chLogins" class="d-none"></canvas></div></div></div></div>
  <div class="col-xl-3 col-md-6"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-activity me-2"></i>Checks / day</span></div><div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chChecks" class="d-none"></canvas></div></div></div></div>
  <div class="col-xl-3 col-md-6"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-envelope-paper me-2"></i>Email delivery</span><a href="<?= url('platform/emails.php?tab=logs') ?>" class="small">Logs</a></div><div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chEmails" class="d-none"></canvas></div></div></div></div>
</div>
</div>
<div class="card"><div class="card-header"><span><i class="bi bi-bar-chart-line me-2"></i>Website analytics · platform</span><span class="small text-muted"><?= number_format($an['events']) ?> raw events · <?= number_format($an['devices']) ?> devices · <?= number_format($an['active']) ?> sites active in 7 d</span></div>
  <div class="card-body"><div class="row g-3">
    <div class="col-lg-6"><div class="section-title">Highest traffic websites · 30 days</div><?php if (!$an['top']): ?><div class="small text-muted">No page views recorded yet.</div><?php else: ?><ul class="mini-list"><?php foreach ($an['top'] as $r): ?><li><span class="ml-ico tint-brand"><i class="bi bi-globe2"></i></span><span class="ml-main"><span class="ml-title"><?= e($r['name']) ?></span><span class="ml-sub"><?= e($r['tenant_name']) ?></span></span><span class="ml-side"><b><?= number_format((int) $r['pageviews']) ?></b> views<br><?= number_format((int) $r['visitors']) ?> visitors</span></li><?php endforeach; ?></ul><?php endif; ?></div>
    <div class="col-lg-6"><div class="section-title">Page views by client · this month</div><?php if (!$an['by_tenant']): ?><div class="small text-muted">No usage yet.</div><?php else: ?><div class="d-grid gap-2"><?php foreach ($an['by_tenant'] as $r): $lim = $r['max_pageviews_month'] !== null ? (int) $r['max_pageviews_month'] : null; $pct = $lim ? min(100, (int) round($r['pageviews'] / max(1, $lim) * 100)) : 0; ?><div class="usage-row"><span class="u-lbl text-truncate" style="flex-basis:140px"><a href="<?= url('platform/customer.php?id=' . $r['id']) ?>"><?= e($r['name']) ?></a><div class="small-xs text-muted"><?= e($r['plan_name'] ?? '—') ?></div></span><div class="progress"><div class="progress-bar <?= $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $lim ? max(2, $pct) : 4 ?>%"></div></div><span class="u-val"><?= number_format((int) $r['pageviews']) ?> / <?= $lim ? number_format($lim) : '∞' ?></span></div><?php endforeach; ?></div><?php endif; ?></div>
  </div></div></div>
<?php
$pageScripts = <<<JS
<script>
CRM.lazyCharts('#platformCharts', 'api/stats.php', { action: 'platform', days: {$days} }, function (d) {
  const P = CRM.palette, show = id => { const c = document.getElementById(id); c.classList.remove('d-none'); return c; };
  CRM.lineChart(show('chGrowth'), d.labels, [
    { label: 'Clients', data: d.registrations, color: P.brand }, { label: 'Users', data: d.user_signups, color: P.info, fill: false }, { label: 'Websites', data: d.websites_added, color: P.success, fill: false }
  ], { plugins: { legend: { display: true } }, scales: { y: { ticks: { precision: 0 } } } });
  const plans = d.by_plan.map(p => p.name), pn = d.by_plan.map(p => +p.n);
  CRM.donut(show('chPlans'), plans, pn, [P.muted, P.info, P.brand, P.success, P.ink]);
  $('#chPlansCenter').removeClass('d-none').html('<div class="v">' + pn.reduce((a, b) => a + b, 0) + '</div><div class="l">clients</div>');
  const u = d.users;
  CRM.donut(show('chUsers'), ['Active (verified)', 'Unverified', 'Inactive'], [u.active, u.unverified, u.inactive], [P.success, P.warning, P.muted]);
  $('#chUsersCenter').removeClass('d-none').html('<div class="v">' + u.total + '</div><div class="l">users</div>');
  CRM.barChart(show('chLogins'), d.labels, [{ label: 'Logins', data: d.logins, color: P.ink }], { scales: { y: { ticks: { precision: 0 } } } });
  CRM.lineChart(show('chChecks'), d.labels, [{ label: 'Checks', data: d.checks, color: P.brand }]);
  CRM.barChart(show('chEmails'), d.labels, [{ label: 'Sent', data: d.emails.sent, color: P.success }, { label: 'Failed', data: d.emails.failed, color: P.danger }], { stacked: true, plugins: { legend: { display: true } } });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
