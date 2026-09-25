<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$filter = get('filter', '');
$clientId = (int) get('client_id', 0);
$warn = (int) explode(',', (string) setting('domain_alert_days', '30,10'))[0] ?: 30;
$prefWebsite = (int) get('website_id', 0);
$prefClient = $prefWebsite ? (int) DB::value("SELECT client_id FROM websites WHERE id = ? AND tenant_id = ?", [$prefWebsite, Tenant::id()]) : 0;

$pageTitle = 'Domains';
$breadcrumbs = [['label' => 'Domains & Hosting'], ['label' => 'Domains']];
$pageActions = '<button class="btn btn-outline-primary btn-sm run-check" data-check="check_expiry"><i class="bi bi-bell me-1"></i>Run Expiry Alerts</button><button class="btn btn-dark btn-sm" data-open-modal="#domainModal"><i class="bi bi-plus-lg me-1"></i>Add Domain</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="filter" class="form-select form-select-sm">
    <option value="">All domains</option>
    <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>Expiring within <?= $warn ?> days</option>
    <option value="expired" <?= $filter === 'expired' ? 'selected' : '' ?>>Expired</option>
  </select>
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Filter by client…', ['small' => 1]) ?>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="domains" data-filters="#filters">
    <thead><tr><th>Domain</th><th>Client</th><th>Website</th><th>Registrar</th><th>Registered</th><th>Expiry</th><th>Days Left</th><th>Auto-renew</th><th class="no-sort">Alerts Sent</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<div class="modal fade" id="domainModal" tabindex="-1" data-auto-open><div class="modal-dialog modal-lg"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/domains.php') ?>" data-reload="table" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="Add Domain">Add Domain</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Client *</label><?= remote_select('client_id', 'clients', $prefClient ?: null, 'Type the client name…', ['required' => 1] + ($prefClient ? ['keep' => 1] : [])) ?></div>
      <div class="col-md-6"><label class="form-label">Website</label><?= remote_select('website_id', 'websites', $prefWebsite ?: null, 'Type the website name…', ['depends' => 'client_id'] + ($prefWebsite ? ['keep' => 1] : [])) ?></div>
      <div class="col-md-6"><label class="form-label">Domain name *</label><input type="text" name="domain_name" class="form-control" required placeholder="example.com"></div>
      <div class="col-md-6"><label class="form-label">Registrar</label><input type="text" name="registrar" class="form-control" placeholder="GoDaddy, Namecheap, BigRock…"></div>
      <div class="col-md-4"><label class="form-label">Registration date</label><input type="date" name="registration_date" class="form-control"></div>
      <div class="col-md-4"><label class="form-label">Expiry date</label><input type="date" name="expiry_date" class="form-control"></div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="auto_renew" id="d_ar"><label class="form-check-label small" for="d_ar">Auto-renew enabled</label></div></div>
      <div class="col-md-6"><label class="form-label">Login reference</label><input type="text" name="login_ref" class="form-control" placeholder="Registrar account / password manager entry"></div>
      <div class="col-md-6"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Domain</button></div>
  </form>
</div></div></div>
<?php
include ROOT_PATH . '/includes/partials/run-check-script.php';
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-domain', function () {
  CRM.post('api/domains.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) CRM.openEdit('#domainModal', res.domain, 'Edit Domain'); });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
