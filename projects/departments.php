<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireRole('admin');

$departments = DB::fetchAll("SELECT d.*, (SELECT COUNT(*) FROM website_projects p WHERE p.department_id = d.id) AS used, (SELECT COUNT(*) FROM website_types t WHERE t.department_id = d.id) AS types FROM departments d WHERE d.tenant_id IS NULL OR d.tenant_id = " . Tenant::id() . " ORDER BY d.sort_order, d.name");
$types = DB::fetchAll("SELECT t.*, d.name AS department_name, (SELECT COUNT(*) FROM website_projects p WHERE p.website_type_id = t.id) AS used FROM website_types t LEFT JOIN departments d ON d.id = t.department_id WHERE t.tenant_id IS NULL OR t.tenant_id = " . Tenant::id() . " ORDER BY d.sort_order, d.name, t.sort_order, t.name");

$pageTitle = 'Departments & Website Types';
$breadcrumbs = [['label' => 'Website Projects', 'url' => 'projects/index.php'], ['label' => 'Departments & Types']];
$pageSubtitle = 'Departments identify the industry (Education, Real Estate…); website types describe the site inside that industry (School, Villa Project…). Both lists feed the project form.';
$pageActions = '<button class="btn btn-dark btn-sm" data-open-modal="#deptModal"><i class="bi bi-plus-lg me-1"></i>Add Department</button><button class="btn btn-outline-primary btn-sm" data-open-modal="#typeModal"><i class="bi bi-plus-lg me-1"></i>Add Website Type</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card dt-card">
      <div class="card-header"><span><i class="bi bi-diagram-3 me-1"></i>Departments / Industries</span><span class="small fw-normal text-muted"><?= count($departments) ?></span></div>
      <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[[2,"asc"]]'>
        <thead><tr><th>Department</th><th>Status</th><th>Order</th><th>Types</th><th>Projects</th><th class="no-sort text-end">Actions</th></tr></thead>
        <tbody><?php foreach ($departments as $d): ?>
          <tr data-row-id="d<?= $d['id'] ?>"><td class="fw-500"><?= e($d['name']) ?></td><td><?= status_pill($d['status'] === 'active' ? 'success' : 'secondary', ucfirst($d['status']), false) ?></td><td><?= (int) $d['sort_order'] ?></td><td><?= (int) $d['types'] ?></td><td><?= $d['used'] ? '<a href="' . url('projects/index.php?department_id=' . $d['id']) . '">' . (int) $d['used'] . '</a>' : '0' ?></td>
            <td class="row-actions text-end"><button class="btn btn-light btn-sm btn-edit-dept" data-id="<?= $d['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/departments.php" data-params='{"action":"toggle","kind":"department","id":<?= $d['id'] ?>}' data-reload="1" title="<?= $d['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi <?= $d['status'] === 'active' ? 'bi-pause-circle text-warning' : 'bi-play-circle text-success' ?>"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/departments.php" data-params='{"action":"delete","kind":"department","id":<?= $d['id'] ?>}' data-confirm="Delete department <?= e($d['name']) ?>?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card dt-card">
      <div class="card-header"><span><i class="bi bi-grid me-1"></i>Website Types</span><span class="small fw-normal text-muted"><?= count($types) ?></span></div>
      <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[[1,"asc"]]'>
        <thead><tr><th>Website type</th><th>Department</th><th>Status</th><th>Order</th><th>Projects</th><th class="no-sort text-end">Actions</th></tr></thead>
        <tbody><?php foreach ($types as $t): ?>
          <tr data-row-id="t<?= $t['id'] ?>"><td class="fw-500"><?= e($t['name']) ?></td><td><?= e($t['department_name'] ?: 'Any department') ?></td><td><?= status_pill($t['status'] === 'active' ? 'success' : 'secondary', ucfirst($t['status']), false) ?></td><td><?= (int) $t['sort_order'] ?></td><td><?= $t['used'] ? '<a href="' . url('projects/index.php?website_type_id=' . $t['id']) . '">' . (int) $t['used'] . '</a>' : '0' ?></td>
            <td class="row-actions text-end"><button class="btn btn-light btn-sm btn-edit-type" data-id="<?= $t['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/departments.php" data-params='{"action":"toggle","kind":"type","id":<?= $t['id'] ?>}' data-reload="1" title="<?= $t['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi <?= $t['status'] === 'active' ? 'bi-pause-circle text-warning' : 'bi-play-circle text-success' ?>"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/departments.php" data-params='{"action":"delete","kind":"type","id":<?= $t['id'] ?>}' data-confirm="Delete website type <?= e($t['name']) ?>?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1" data-auto-open><div class="modal-dialog modal-sm"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/departments.php') ?>" data-reload="1" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="department"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="Add Department">Add Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Department / industry name *</label><input type="text" name="name" class="form-control" required maxlength="100" placeholder="e.g. Real Estate"></div>
      <div class="row g-2"><div class="col-6"><label class="form-label">Sort order</label><input type="number" name="sort_order" class="form-control" value="100" min="0" max="9999"></div>
        <div class="col-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-dark w-100"><i class="bi bi-check2 me-1"></i>Save</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/departments.php') ?>" data-reload="1" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="type"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="Add Website Type">Add Website Type</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Website type name *</label><input type="text" name="name" class="form-control" required maxlength="100" placeholder="e.g. Villa Project"></div>
      <div class="mb-3"><label class="form-label">Department</label><select name="department_id" class="form-select"><option value="">Any department</option><?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select><div class="form-text">Types tied to a department are offered when that department is chosen.</div></div>
      <div class="row g-2"><div class="col-6"><label class="form-label">Sort order</label><input type="number" name="sort_order" class="form-control" value="100" min="0" max="9999"></div>
        <div class="col-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-dark w-100"><i class="bi bi-check2 me-1"></i>Save</button></div>
  </form>
</div></div></div>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-dept', function () { CRM.post('api/departments.php', { action: 'get', kind: 'department', id: $(this).data('id') }).done(res => { if (res.success) CRM.openEdit('#deptModal', res.item, 'Edit Department'); }); });
$(document).on('click', '.btn-edit-type', function () { CRM.post('api/departments.php', { action: 'get', kind: 'type', id: $(this).data('id') }).done(res => { if (res.success) CRM.openEdit('#typeModal', res.item, 'Edit Website Type'); }); });
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
