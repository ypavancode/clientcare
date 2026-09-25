<?php
/** Super Admin → Websites: every website of every client workspace – search, filters, edit, enable / disable, tracking ID, analytics, delete. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$tenants = DB::fetchAll("SELECT id, name FROM tenants ORDER BY name LIMIT 500");
$c = Cache::remember('platform:websites:counts', 60, fn() => DB::fetch("SELECT COUNT(*) AS total, SUM(monitoring_enabled = 1 AND status <> 'paused') AS active, SUM(status IN (" . down_statuses_sql() . ")) AS down, SUM(analytics_enabled = 1) AS tracked, SUM(analytics_enabled = 1 AND analytics_last_event_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS receiving FROM websites") ?: []);
$pageTitle = 'Websites';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Websites']];
$pageSubtitle = number_format((int) $c['total']) . ' websites · ' . number_format((int) $c['active']) . ' monitored · ' . ($c['down'] ? '<span class="text-danger">' . (int) $c['down'] . ' down</span>' : 'none down') . ' · ' . number_format((int) $c['tracked']) . ' with tracking (' . number_format((int) $c['receiving']) . ' receiving data)';
$websiteModalReloadTable = true;
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="tenant_id" class="form-select form-select-sm"><option value="">All clients</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>" <?= (int) get('tenant_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
  <select name="status" class="form-select form-select-sm"><option value="">Any status</option><?php foreach (['online' => 'Online', 'down' => 'Down / error', 'paused' => 'Paused', 'unknown' => 'Not checked yet'] as $k => $l): ?><option value="<?= $k ?>" <?= get('status') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="monitoring" class="form-select form-select-sm"><option value="">Monitoring: any</option><option value="1" <?= get('monitoring') === '1' ? 'selected' : '' ?>>Enabled</option><option value="0" <?= get('monitoring') === '0' ? 'selected' : '' ?>>Disabled</option></select>
  <select name="tracking" class="form-select form-select-sm"><option value="">Tracking: any</option><option value="on" <?= get('tracking') === 'on' ? 'selected' : '' ?>>Enabled</option><option value="active" <?= get('tracking') === 'active' ? 'selected' : '' ?>>Receiving data (7 d)</option><option value="silent" <?= get('tracking') === 'silent' ? 'selected' : '' ?>>Enabled but silent</option><option value="off" <?= get('tracking') === 'off' ? 'selected' : '' ?>>Off</option></select>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_websites" data-filters="#filters" data-order='[]' data-page-length="25" data-empty="No websites match" data-empty-text="Adjust the filters or search by website, host or client name." data-empty-icon="bi-globe2">
    <thead><tr><th>Website</th><th>Client</th><th>Status</th><th>Monitoring · plan interval</th><th>Tracking</th><th class="no-sort">Views · month</th><th>Last check</th><th>Next check</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/partials/platform-website-modal.php'; ?>
<?php
$pageScripts = <<<'JS'
<script>
// Analytics lives inside the client's workspace – switch the Super Admin into "view as client" and open it.
$(document).on('click', '.btn-site-analytics', function (e) {
  e.preventDefault(); const href = this.href;
  CRM.post('api/platform.php', { action: 'act_as', tenant_id: $(this).data('tenant') }).done(res => { if (res.success) window.location.href = href; else CRM.toast(res.message, 'error'); });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
