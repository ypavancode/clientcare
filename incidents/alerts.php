<?php
/**
 * Alert History (v3.9) – the alert state machine made visible: every problem once (detected → notified → silent while
 * it keeps failing → recovered → recovery notified), with notification counts, downtime and the error that caused it.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('incidents.view');
$tid = Tenant::id();
$counts = DB::fetch("SELECT SUM(status = 'open') AS open_n, SUM(status = 'recovered' AND recovered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS recovered_7d, SUM(detected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS new_24h, SUM(notification_count) AS notifications FROM alerts WHERE tenant_id = ?", [$tid]) ?: [];
$websites = DB::fetchAll("SELECT id, name FROM websites WHERE tenant_id = ? ORDER BY name LIMIT 500", [$tid]);
$pageTitle = 'Alert History';
$breadcrumbs = [['label' => 'Incidents', 'url' => 'incidents/index.php'], ['label' => 'Alert History']];
$pageSubtitle = 'One row per problem. A failure is notified once, repeated failing checks stay silent, and one recovery notification closes it.';
$pageActions = '<a href="' . url('incidents/index.php') . '" class="btn btn-light btn-sm"><i class="bi bi-exclamation-octagon me-1"></i>Incidents</a><a href="' . url('notifications/index.php') . '" class="btn btn-light btn-sm"><i class="bi bi-bell me-1"></i>Alerts inbox</a>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3 stagger">
  <div class="col-6 col-md-3"><a href="?status=open" class="stat-card <?= (int) ($counts['open_n'] ?? 0) ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-exclamation-octagon"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['open_n'] ?? 0)) ?></div><div class="stat-label">🔴 Currently failing</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=recovered" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['recovered_7d'] ?? 0)) ?></div><div class="stat-label">🟢 Recovered (7 days)</div></div></a></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['new_24h'] ?? 0)) ?></div><div class="stat-label">New in 24 h</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-envelope-check"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['notifications'] ?? 0)) ?></div><div class="stat-label">Notifications sent (total)</div></div></div></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm"><option value="">Any state</option><option value="open" <?= get('status') === 'open' ? 'selected' : '' ?>>Failing now</option><option value="recovered" <?= get('status') === 'recovered' ? 'selected' : '' ?>>Recovered</option><option value="info" <?= get('status') === 'info' ? 'selected' : '' ?>>Notices (expiry)</option></select>
  <select name="kind" class="form-select form-select-sm"><option value="">All types</option><?php foreach (['website' => 'Website', 'page' => 'Page', 'form' => 'Form', 'ssl' => 'SSL', 'domain' => 'Domain', 'hosting' => 'Hosting'] as $k => $l): ?><option value="<?= $k ?>" <?= get('kind') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="website_id" class="form-select form-select-sm"><option value="">All websites</option><?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>" <?= (int) get('website_id') === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
  <select name="days" class="form-select form-select-sm"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= (int) get('days', 30) === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select>
  <a href="<?= url('incidents/alerts.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="alerts" data-filters="#filters" data-page-length="50" data-order='[[0,"desc"]]' data-empty="No alerts" data-empty-text="Nothing has failed in this period – successful checks never create alerts." data-empty-icon="bi-shield-check">
    <thead><tr><th>Detected</th><th>Type</th><th>Problem</th><th>Error</th><th>State · downtime</th><th>Notifications</th><th>Recovered</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';
