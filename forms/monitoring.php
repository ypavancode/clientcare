<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$status = get('status', '');
$clientId = (int) get('client_id', 0);
$websiteId = (int) get('website_id', 0);
$stale = get('stale') === '1';
$staleHours = (int) setting('form_stale_hours', 48);

$counts = Cache::remember(Cache::vkey('data', 'forms:counts:' . Tenant::id()), 30, function () {
    $c = ['working' => 0, 'failed' => 0, 'captcha_blocked' => 0, 'not_tested' => 0, 'disabled' => 0, 'removed' => 0];
    foreach (DB::fetchAll("SELECT f.status, COUNT(*) n FROM forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " GROUP BY f.status") as $r) $c[$r['status']] = (int) $r['n'];
    return $c;
});
$lastScan = Cache::remember('forms:lastscan', 20, fn() => DB::value("SELECT last_done_at FROM queue_state WHERE queue = 'form'"));

$pageTitle = $status === 'failed' ? 'Failed Forms' : ($status === 'captcha_blocked' ? 'Forms Blocked by CAPTCHA' : 'Form Monitoring');
$breadcrumbs = [['label' => 'Forms', 'url' => 'forms/index.php'], ['label' => $pageTitle]];
$pageSubtitle = 'Automatic test every <strong>' . Tenant::interval('form') . ' min</strong> (plan interval; a per-form override can only make it slower) · Last scan: <strong>' . e(time_ago($lastScan)) . '</strong> · Test email: <strong>' . e(setting('form_test_email', 'not set')) . '</strong>';
$pageActions = '<button class="btn btn-brand btn-sm run-check" data-check="check_forms"><i class="bi bi-clipboard-check me-1"></i>Test All Forms</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><a href="?status=working" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= number_format($counts['working']) ?></div><div class="stat-label">🟢 Working</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=failed" class="stat-card <?= $counts['failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-x-octagon"></i></div><div><div class="stat-value"><?= number_format($counts['failed']) ?></div><div class="stat-label">🔴 Failed</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=captcha_blocked" class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-shield-lock"></i></div><div><div class="stat-value"><?= number_format($counts['captcha_blocked']) ?></div><div class="stat-label">🔵 CAPTCHA Blocked</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=not_tested" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-question-circle"></i></div><div><div class="stat-value"><?= number_format($counts['not_tested']) ?></div><div class="stat-label">🟡 Not Tested</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=disabled" class="stat-card"><div class="stat-icon tint-secondary"><i class="bi bi-slash-circle"></i></div><div><div class="stat-value"><?= number_format($counts['disabled']) ?></div><div class="stat-label">⚪ Disabled</div></div></a></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Client…', ['small' => 1]) ?>
  <?= remote_select('website_id', 'websites', $websiteId ?: null, 'Website…', ['small' => 1, 'depends' => 'client_id']) ?>
  <select name="status" class="form-select form-select-sm"><option value="">All statuses</option><?php foreach (['working' => 'Working', 'failed' => 'Failed', 'captcha_blocked' => 'CAPTCHA blocked', 'not_tested' => 'Not tested', 'disabled' => 'Disabled', 'removed' => 'Removed / not found'] as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <input type="date" name="from" class="form-control form-control-sm" value="<?= e(get('from', '')) ?>" title="Tested from">
  <input type="date" name="to" class="form-control form-control-sm" value="<?= e(get('to', '')) ?>" title="Tested to">
  <div class="form-check form-check-inline ms-1"><input class="form-check-input" type="checkbox" name="stale" value="1" id="stale" <?= $stale ? 'checked' : '' ?>><label class="form-check-label small" for="stale">Not tested in <?= $staleHours ?>h</label></div>
  <a href="?status=failed" class="btn btn-outline-danger btn-sm">Failed only</a>
  <a href="<?= url('forms/monitoring.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="forms_monitoring" data-filters="#filters" data-page-length="50">
    <thead><tr><th>Client</th><th>Website</th><th>Form</th><th>Status</th><th>Last Test</th><th>Result</th><th>Reason</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php
include ROOT_PATH . '/includes/partials/run-check-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
