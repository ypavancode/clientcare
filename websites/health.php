<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$status = get('status', '');
$ssl = get('ssl', '');
$clientId = (int) get('client_id', 0);
$expWarn = max((int) explode(',', (string) setting('domain_alert_days', '30,10'))[0], (int) explode(',', (string) setting('hosting_alert_days', '30,10'))[0]) ?: 30;

$counts = Cache::remember(Cache::vkey('data', 'health:counts:' . Tenant::id()), 30, fn() => DB::fetch("SELECT
    SUM(w.status IN ('online','redirecting')) AS online,
    SUM(w.status IN (" . down_statuses_sql() . ")) AS down,
    SUM(w.status IN ('unknown','paused')) AS unknown,
    SUM(w.ssl_status IN ('expiring_soon','expired','error')) AS ssl_issues
    FROM websites w JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . ""));
$expiring = Cache::remember(Cache::vkey('data', 'health:expiring:' . Tenant::id()), 60, fn() => (int) DB::value("SELECT
    (SELECT COUNT(*) FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . ")
  + (SELECT COUNT(*) FROM hosting h JOIN clients c ON c.id = h.client_id WHERE h.expiry_date IS NOT NULL AND h.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . ")", [$expWarn, $expWarn]));
$lastScan = Cache::remember('health:lastscan', 20, fn() => DB::value("SELECT last_done_at FROM queue_state WHERE queue = 'website'"));

$pageTitle = 'Website Monitoring';
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => 'Website Health']];
$pageSubtitle = 'Automatic check every <strong>' . Tenant::interval('website') . ' min</strong> (plan interval) · Last scan: <strong>' . e(time_ago($lastScan)) . '</strong>';
$pageActions = '<button class="btn btn-brand btn-sm run-check" data-check="check_websites"><i class="bi bi-arrow-repeat me-1"></i>Check All Websites</button><button class="btn btn-outline-primary btn-sm run-check" data-check="check_ssl"><i class="bi bi-shield-check me-1"></i>Check All SSL</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><a href="?status=online" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check-circle-fill"></i></div><div><div class="stat-value text-success"><?= number_format((int) $counts['online']) ?></div><div class="stat-label">🟢 Online</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=down" class="stat-card <?= $counts['down'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-x-octagon-fill"></i></div><div><div class="stat-value text-danger"><?= number_format((int) $counts['down']) ?></div><div class="stat-label">🔴 Down</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?ssl=issues" class="stat-card <?= $counts['ssl_issues'] ? 'alert-card border-warning' : '' ?>"><div class="stat-icon tint-warning"><i class="bi bi-shield-exclamation"></i></div><div><div class="stat-value text-warning"><?= number_format((int) $counts['ssl_issues']) ?></div><div class="stat-label">🟠 SSL Issues</div></div></a></div>
  <div class="col-6 col-md-3"><a href="<?= url('domains/index.php?filter=expiring') ?>" class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-calendar-x"></i></div><div><div class="stat-value"><?= number_format($expiring) ?></div><div class="stat-label">🟡 Domain/Hosting Expiring</div></div></a></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm">
    <option value="">All statuses</option>
    <option value="down" <?= $status === 'down' ? 'selected' : '' ?>>Down / errors</option>
    <option value="online" <?= $status === 'online' ? 'selected' : '' ?>>Online</option>
    <option value="unknown" <?= $status === 'unknown' ? 'selected' : '' ?>>Not checked / paused</option>
  </select>
  <select name="ssl" class="form-select form-select-sm">
    <option value="">All SSL</option>
    <option value="issues" <?= $ssl === 'issues' ? 'selected' : '' ?>>SSL issues only</option>
  </select>
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Filter by client…', ['small' => 1]) ?>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="health" data-filters="#filters" data-page-length="50">
    <thead><tr><th>Website</th><th>Client</th><th>Status</th><th>Reason</th><th>Pages</th><th>Last Check</th><th>Response</th><th>SSL</th><th class="no-sort">Forms</th><th>Domain</th><th>Hosting</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php
include ROOT_PATH . '/includes/partials/run-check-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
