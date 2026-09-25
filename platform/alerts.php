<?php
/** Super Admin – Alert History across every workspace (website / page / form / SSL / domain / hosting / SMTP / system). */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$counts = DB::fetch("SELECT SUM(status = 'open') AS open_n, SUM(status = 'recovered' AND recovered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS recovered_7d, SUM(detected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS new_24h, SUM(notification_count) AS notifications, SUM(kind = 'smtp' AND status = 'open') AS smtp_open, SUM(kind = 'system' AND status = 'open') AS system_open FROM alerts") ?: [];
$tenants = DB::fetchAll("SELECT id, name FROM tenants ORDER BY name LIMIT 500");
$pageTitle = 'Alert History';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Alert History']];
$pageSubtitle = 'Alert state machine across all clients: one row per problem – notified once, silent while failing, one recovery notification. SMTP and scheduler problems appear here too.';
$pageActions = '<a href="' . url('platform/system.php') . '" class="btn btn-light btn-sm"><i class="bi bi-activity me-1"></i>System Health</a><a href="' . url('platform/emails.php?tab=logs') . '" class="btn btn-light btn-sm"><i class="bi bi-journal-text me-1"></i>Email logs</a>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3 stagger">
  <div class="col-6 col-md-3"><a href="?status=open" class="stat-card <?= (int) ($counts['open_n'] ?? 0) ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-exclamation-octagon"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['open_n'] ?? 0)) ?></div><div class="stat-label">🔴 Failing now</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=recovered" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['recovered_7d'] ?? 0)) ?></div><div class="stat-label">🟢 Recovered (7 days)</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?kind=smtp" class="stat-card <?= (int) ($counts['smtp_open'] ?? 0) ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-warning"><i class="bi bi-envelope-x"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['smtp_open'] ?? 0)) ?> / <?= number_format((int) ($counts['system_open'] ?? 0)) ?></div><div class="stat-label">SMTP / scheduler alerts open</div></div></a></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-envelope-check"></i></div><div><div class="stat-value"><?= number_format((int) ($counts['notifications'] ?? 0)) ?></div><div class="stat-label">Notifications sent (total)</div></div></div></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm"><option value="">Any state</option><option value="open" <?= get('status') === 'open' ? 'selected' : '' ?>>Failing now</option><option value="recovered" <?= get('status') === 'recovered' ? 'selected' : '' ?>>Recovered</option><option value="info" <?= get('status') === 'info' ? 'selected' : '' ?>>Notices</option></select>
  <select name="kind" class="form-select form-select-sm"><option value="">All types</option><?php foreach (['website' => 'Website', 'page' => 'Page', 'form' => 'Form', 'ssl' => 'SSL', 'domain' => 'Domain', 'hosting' => 'Hosting', 'smtp' => 'SMTP', 'system' => 'System'] as $k => $l): ?><option value="<?= $k ?>" <?= get('kind') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="tenant_id" class="form-select form-select-sm"><option value="">All clients</option><?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) get('tenant_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
  <select name="days" class="form-select form-select-sm"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= (int) get('days', 30) === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select>
  <a href="<?= url('platform/alerts.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_alerts" data-filters="#filters" data-page-length="50" data-order='[[0,"desc"]]' data-empty="No alerts" data-empty-text="Nothing has failed in this period." data-empty-icon="bi-shield-check">
    <thead><tr><th>Detected</th><th>Type</th><th>Client</th><th>Problem</th><th>Error</th><th>State · downtime</th><th>Notifications</th><th>Recovered</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';
