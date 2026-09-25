<?php
/** Super Admin – every user of every workspace (server-side table, filters, last login). */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$c = Cache::remember('platform:users:counts', 60, fn() => DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status <> 'active') AS inactive, SUM(email_verified_at IS NULL) AS unverified, SUM(last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS active_7d, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_7d FROM users"));
$tenants = DB::fetchAll("SELECT id, name FROM tenants ORDER BY name LIMIT 500");
$pageTitle = 'Users';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Users']];
$pageSubtitle = number_format((int) $c['total']) . ' registered · ' . number_format((int) $c['active_7d']) . ' active in the last 7 days · ' . number_format((int) $c['new_7d']) . ' new this week · activate / deactivate accounts, send password reset links' . (Auth::isPlatformOwner() ? ', grant platform roles' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="kpi-grid stagger mb-4">
  <a href="<?= url('platform/users.php') ?>" class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-people"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $c['total'] ?>">0</div><div class="stat-label">Registered users</div></div></a>
  <a href="<?= url('platform/users.php?status=active') ?>" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-person-check"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $c['active'] ?>">0</div><div class="stat-label">Active accounts</div></div></a>
  <div class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-lightning"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $c['active_7d'] ?>">0</div><div class="stat-label">Logged in · 7 days</div></div></div>
  <a href="<?= url('platform/users.php?verified=0') ?>" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-envelope-exclamation"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $c['unverified'] ?>">0</div><div class="stat-label">Unverified e-mail</div></div></a>
  <a href="<?= url('platform/users.php?status=inactive') ?>" class="stat-card"><div class="stat-icon tint-secondary"><i class="bi bi-person-dash"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $c['inactive'] ?>">0</div><div class="stat-label">Inactive</div></div></a>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm"><option value="">Any status</option><option value="active" <?= get('status') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= get('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
  <select name="role" class="form-select form-select-sm"><option value="">Any role</option><?php foreach (['owner', 'admin', 'manager', 'viewer', 'staff', 'notify'] as $r): ?><option value="<?= $r ?>" <?= get('role') === $r ? 'selected' : '' ?>><?= e(Registration::roleLabel($r)) ?></option><?php endforeach; ?></select>
  <select name="verified" class="form-select form-select-sm"><option value="">Verified or not</option><option value="1" <?= get('verified') === '1' ? 'selected' : '' ?>>Verified</option><option value="0" <?= get('verified') === '0' ? 'selected' : '' ?>>Unverified</option></select>
  <select name="tenant_id" class="form-select form-select-sm"><option value="">Any workspace</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>" <?= (int) get('tenant_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="platform_users" data-filters="#filters" data-order='[]' data-empty="No users match" data-empty-text="Adjust the filters or the search to find users." data-empty-icon="bi-people">
    <thead><tr><th>User</th><th>Client</th><th>Role</th><th>Status</th><th>Last login</th><th>Registered</th><th class="no-sort text-end">Access</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-reset-link', async function () {
  const $b = $(this);
  const ok = await CRM.confirm({ title: 'Send a password reset link?', text: 'A one-hour reset link is emailed to ' + $b.data('email') + '. The password itself is never shown to anyone.', icon: 'question', confirmButtonText: 'Send link' });
  if (!ok) return;
  $b.prop('disabled', true);
  CRM.post('api/platform.php', { action: 'user_reset_link', id: $b.data('id') }).done(res => {
    if (!res.success) { CRM.toast(res.message, 'error'); return; }
    if (res.emailed) CRM.toast(res.message); else Swal.fire({ title: 'Email could not be sent', html: CRM.esc(res.message) + '<br><code style="user-select:all">' + CRM.esc(res.link) + '</code>', icon: 'warning' });
  }).always(() => $b.prop('disabled', false));
});
$(document).on('click', '.btn-platform-role', async function () {
  const $b = $(this);
  const r = await Swal.fire({ title: 'Platform role for ' + $b.data('email'), input: 'select', inputValue: $b.data('role'), inputOptions: { none: 'No platform access (workspace user)', admin: 'Super Admin – clients, websites, subscriptions, system', owner: 'Owner – everything incl. plans, pricing, product settings' }, showCancelButton: true, confirmButtonText: 'Save role', reverseButtons: true });
  if (!r.isConfirmed) return;
  CRM.post('api/platform.php', { action: 'user_platform_role', id: $b.data('id'), role: r.value }).done(res => { CRM.toast(res.message, res.success ? 'success' : 'error'); if (res.success) CRM.reloadTable(); });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';