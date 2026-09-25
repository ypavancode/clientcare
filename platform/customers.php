<?php
/** Super Admin → Clients: every customer workspace (server-side table) with search, filters, edit, activate / deactivate, view-as and delete. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$plans = DB::fetchAll("SELECT id, name FROM plans ORDER BY sort_order, id");
$c = Cache::remember('platform:clients:counts', 60, fn() => DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'pending') AS pending, SUM(status IN ('suspended','cancelled')) AS inactive, SUM(subscription_status = 'trial') AS trial FROM tenants") ?: []);
$pageTitle = 'Clients';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Clients']];
$pageSubtitle = number_format((int) $c['total']) . ' client workspaces · ' . number_format((int) $c['active']) . ' active · ' . number_format((int) $c['pending']) . ' pending · ' . number_format((int) $c['inactive']) . ' inactive · ' . number_format((int) $c['trial']) . ' on trial';
$tenantModalReloadTable = true;
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm"><option value="">Any account status</option><?php foreach (['active', 'pending', 'suspended', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= get('status') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
  <select name="sub" class="form-select form-select-sm"><option value="">Any subscription</option><?php foreach (['free', 'trial', 'active', 'past_due', 'expired', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= get('sub') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select>
  <select name="plan_id" class="form-select form-select-sm"><option value="">Any plan</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= (int) get('plan_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_clients" data-filters="#filters" data-order='[]' data-page-length="25" data-empty="No clients match" data-empty-text="Adjust the filters or the search (name, slug, owner email)." data-empty-icon="bi-buildings">
    <thead><tr><th>Client</th><th>Owner</th><th>Plan</th><th>Subscription</th><th>Status</th><th class="no-sort">Usage</th><th>Last active</th><th>Registered</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/partials/tenant-modal.php'; ?>
<?php include ROOT_PATH . '/includes/layout/footer.php';
