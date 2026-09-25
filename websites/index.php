<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$clientId = (int) get('client_id', 0);
$pageTitle = 'All Websites';
$breadcrumbs = [['label' => 'Websites']];
$pageActions = '<button class="btn btn-brand btn-sm run-check" data-check="check_websites"><i class="bi bi-arrow-repeat me-1"></i>Check All</button><button class="btn btn-dark btn-sm" data-open-modal="#websiteModal"><i class="bi bi-globe2 me-1"></i>Add Website</button>';
$selectedClientId = $clientId ?: null;
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Filter by client…', ['small' => 1]) ?>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="websites" data-filters="#filters" data-order='[[0,"asc"]]'>
    <thead><tr><th>Website</th><th>Client</th><th>Technology</th><th>Status</th><th>Pages</th><th>SSL</th><th class="no-sort">Forms</th><th>Domain Exp.</th><th>Hosting Exp.</th><th>Last Check</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php
include ROOT_PATH . '/includes/partials/website-modal.php';
include ROOT_PATH . '/includes/partials/run-check-script.php';
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-website', function () {
  CRM.post('api/websites.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) fillWebsiteModal(res.website); });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
