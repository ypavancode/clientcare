<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$statusFilter = in_array(get('status'), ['active', 'inactive', 'archived'], true) ? get('status') : '';
$counts = Cache::remember(Cache::vkey('data', 'clients:counts:' . Tenant::id()), 30, function () {
    $c = ['all' => 0, 'active' => 0, 'inactive' => 0, 'archived' => 0];
    foreach (DB::fetchAll("SELECT status, COUNT(*) AS n FROM clients WHERE tenant_id = " . Tenant::id() . " GROUP BY status") as $r) { $c[$r['status']] = (int) $r['n']; $c['all'] += (int) $r['n']; }
    return $c;
});

$pageTitle = 'Clients';
$breadcrumbs = [['label' => 'Clients']];
$pageActions = '<button class="btn btn-dark btn-sm" data-open-modal="#clientModal"><i class="bi bi-person-plus me-1"></i>Add Client</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card dt-card">
  <div class="card-header">
    <ul class="nav nav-pills gap-1">
      <li class="nav-item"><a class="nav-link <?= !$statusFilter && get('status') !== 'all' ? 'active' : '' ?>" href="<?= url('clients/index.php') ?>">Current <span class="opacity-75">(<?= number_format($counts['active'] + $counts['inactive']) ?>)</span></a></li>
      <li class="nav-item"><a class="nav-link <?= $statusFilter === 'active' ? 'active' : '' ?>" href="?status=active">Active (<?= number_format($counts['active']) ?>)</a></li>
      <li class="nav-item"><a class="nav-link <?= $statusFilter === 'inactive' ? 'active' : '' ?>" href="?status=inactive">Inactive (<?= number_format($counts['inactive']) ?>)</a></li>
      <li class="nav-item"><a class="nav-link <?= $statusFilter === 'archived' ? 'active' : '' ?>" href="?status=archived">Archived (<?= number_format($counts['archived']) ?>)</a></li>
      <li class="nav-item"><a class="nav-link <?= get('status') === 'all' ? 'active' : '' ?>" href="?status=all">All (<?= number_format($counts['all']) ?>)</a></li>
    </ul>
  </div>
  <table class="table table-hover datatable w-100" data-source="clients" data-params='<?= json_encode(['status' => get('status', ''), 'sort' => get('sort', '')]) ?>' data-order='[[0,"asc"]]'>
    <thead><tr><th>Client</th><th>Contact</th><th>Websites</th><th class="no-sort">Health</th><th class="no-sort">Pages OK</th><th>Assigned</th><th>Status</th><th>Added</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<?php include ROOT_PATH . '/includes/partials/client-modal.php'; ?>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-client', function () {
  CRM.post('api/clients.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) CRM.openEdit('#clientModal', res.client, 'Edit Client'); });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
