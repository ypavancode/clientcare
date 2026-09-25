<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('activity');

$actions = Cache::remember('activity:actions:' . Tenant::id(), 300, fn() => DB::fetchAll("SELECT DISTINCT action FROM activity_logs WHERE tenant_id = " . Tenant::id() . " ORDER BY action"));
$total = cached_count('activity:total:' . Tenant::id(), "SELECT COUNT(*) FROM activity_logs WHERE tenant_id = " . Tenant::id() . "", [], 60);

$pageTitle = 'Activity Logs';
$breadcrumbs = [['label' => 'Activity Logs']];
$pageSubtitle = number_format($total) . ' entries · retained ' . (int) setting('retention_activity_days', 365) . ' days';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <?= remote_select('user_id', 'users', (int) get('user_id', 0) ?: null, 'User…', ['small' => 1]) ?>
  <select name="action" class="form-select form-select-sm"><option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= get('action') === $a['action'] ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $a['action']))) ?></option><?php endforeach; ?></select>
  <?= remote_select('client_id', 'clients', (int) get('client_id', 0) ?: null, 'Client…', ['small' => 1]) ?>
  <input type="date" name="from" class="form-control form-control-sm" value="<?= e(get('from', '')) ?>">
  <input type="date" name="to" class="form-control form-control-sm" value="<?= e(get('to', '')) ?>">
  <a href="<?= url('activity/index.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="activity" data-filters="#filters" data-page-length="50" data-order='[[0,"desc"]]'>
    <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>Description</th><th>Client</th><th>Website</th><th>IP</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php'; ?>
