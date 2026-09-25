<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$websiteId = (int) get('website_id', 0);
$clientId = (int) get('client_id', 0);
$websiteRow = $websiteId ? DB::fetch("SELECT id, name FROM websites WHERE id = ? AND tenant_id = ?", [$websiteId, Tenant::id()]) : null;

$pageTitle = 'All Forms' . ($websiteRow ? ' · ' . $websiteRow['name'] : '');
$breadcrumbs = [['label' => 'Forms']];
$pageActions = '<button class="btn btn-brand btn-sm run-check" data-check="check_forms"><i class="bi bi-clipboard-check me-1"></i>Test All Forms</button><button class="btn btn-dark btn-sm" data-open-modal="#formModal"><i class="bi bi-plus-lg me-1"></i>Add Form</button>';
$selectedWebsiteId = $websiteId ?: null;
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Filter by client…', ['small' => 1]) ?>
  <?= remote_select('website_id', 'websites', $websiteId ?: null, 'Filter by website…', ['small' => 1, 'depends' => 'client_id']) ?>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="forms" data-filters="#filters">
    <thead><tr><th>Form</th><th>Website</th><th>Client</th><th>Type</th><th>Recipient</th><th>Status</th><th>Last Test</th><th>Result</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<div class="modal fade" id="historyModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Test History</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-0" id="historyBody"><div class="p-4 text-center text-muted">Loading…</div></div>
</div></div></div>

<?php
include ROOT_PATH . '/includes/partials/form-modal.php';
include ROOT_PATH . '/includes/partials/run-check-script.php';
include ROOT_PATH . '/includes/partials/form-test-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
