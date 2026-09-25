<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('projects.view');
require_once ROOT_PATH . '/includes/ProjectFilters.php';

$status = get('status', '');
$stats = Stats::projects();
$statuses = project_statuses();
$projectDefaults = ['client_id' => (int) get('client_id', 0) ?: null];
$filtersActive = array_filter(['status' => $status, 'kind' => get('kind'), 'client_id' => get('client_id'), 'department_id' => get('department_id'), 'website_type_id' => get('website_type_id'), 'technology' => get('technology'), 'assigned' => get('assigned'), 'start_from' => get('start_from'), 'start_to' => get('start_to'), 'launch_from' => get('launch_from'), 'launch_to' => get('launch_to'), 'monitoring' => get('monitoring')]);

$pageTitle = 'Website Projects';
$breadcrumbs = [['label' => 'Website Projects']];
$pageSubtitle = number_format($stats['total']) . ' project(s) · ' . number_format($stats['in_progress']) . ' in progress · ' . number_format($stats['by_status']['live'] ?? 0) . ' live';
$pageActions = (Auth::can('projects') ? '<button class="btn btn-dark btn-sm" data-open-modal="#projectModal"><i class="bi bi-plus-lg me-1"></i>Add Website Project</button>' : '')
    . (Auth::isAdmin() ? '<a href="' . url('projects/departments.php') . '" class="btn btn-light btn-sm"><i class="bi bi-tags me-1"></i>Departments &amp; Types</a>' : '');
$useDtButtons = true;
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3 project-stat-row">
  <div class="col-6 col-md-4 col-xl"><a href="<?= url('projects/index.php') ?>" class="stat-card <?= $status === '' ? 'border-dark' : '' ?>"><div class="stat-icon tint-dark"><i class="bi bi-kanban"></i></div><div><div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-label">Total Projects</div></div></a></div>
  <?php foreach (['design', 'development', 'testing', 'client_review', 'changes_required', 'ready_for_launch', 'live'] as $k): [$lbl, $cls] = $statuses[$k]; ?>
    <div class="col-6 col-md-4 col-xl"><a href="?status=<?= $k ?>" class="stat-card <?= $status === $k ? 'border-dark' : '' ?>"><div class="stat-icon <?= $cls ?>"><i class="bi <?= ['design' => 'bi-palette', 'development' => 'bi-code-slash', 'testing' => 'bi-bug', 'client_review' => 'bi-chat-square-text', 'changes_required' => 'bi-arrow-counterclockwise', 'ready_for_launch' => 'bi-rocket', 'live' => 'bi-broadcast'][$k] ?>"></i></div><div><div class="stat-value"><?= number_format($stats['by_status'][$k] ?? 0) ?></div><div class="stat-label"><?= e($lbl) ?></div></div></a></div>
  <?php endforeach; ?>
</div>

<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm">
    <option value="">All statuses</option>
    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In progress (all active stages)</option>
    <?php foreach ($statuses as $k => [$lbl, $cls]): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
  </select>
  <select name="kind" class="form-select form-select-sm"><option value="">New + Existing</option><option value="new" <?= get('kind') === 'new' ? 'selected' : '' ?>>New websites</option><option value="existing" <?= get('kind') === 'existing' ? 'selected' : '' ?>>Existing websites</option></select>
  <?= remote_select('client_id', 'clients', (int) get('client_id', 0) ?: null, 'Client…', ['small' => 1]) ?>
  <select name="department_id" class="form-select form-select-sm"><option value="">All departments</option><?php foreach (departments_options(true) as $d): ?><option value="<?= $d['id'] ?>" <?= (int) get('department_id', 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
  <select name="website_type_id" class="form-select form-select-sm"><option value="">All website types</option><?php foreach (website_types_options(true) as $t): ?><option value="<?= $t['id'] ?>" <?= (int) get('website_type_id', 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['department_name'] ? ' (' . e($t['department_name']) . ')' : '' ?></option><?php endforeach; ?></select>
  <select name="technology" class="form-select form-select-sm"><option value="">All technologies</option><?php foreach (technologies() as $t): ?><option value="<?= $t ?>" <?= get('technology') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
  <select name="assigned" class="form-select form-select-sm"><option value="">Anyone</option><?php foreach (all_users_options() as $u): ?><option value="<?= $u['id'] ?>" <?= (int) get('assigned', 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
  <select name="monitoring" class="form-select form-select-sm"><option value="">Monitoring: any</option><option value="active" <?= get('monitoring') === 'active' ? 'selected' : '' ?>>Monitoring active</option><option value="inactive" <?= get('monitoring') === 'inactive' ? 'selected' : '' ?>>Monitoring disabled</option></select>
  <div class="input-group input-group-sm" style="width:auto"><span class="input-group-text">Start</span><input type="date" name="start_from" class="form-control form-control-sm" value="<?= e(get('start_from', '')) ?>"><input type="date" name="start_to" class="form-control form-control-sm" value="<?= e(get('start_to', '')) ?>"></div>
  <div class="input-group input-group-sm" style="width:auto"><span class="input-group-text">Launch</span><input type="date" name="launch_from" class="form-control form-control-sm" value="<?= e(get('launch_from', '')) ?>"><input type="date" name="launch_to" class="form-control form-control-sm" value="<?= e(get('launch_to', '')) ?>"></div>
  <a href="<?= url('projects/index.php') ?>" class="btn btn-light btn-sm"><i class="bi bi-x-circle me-1"></i>Clear Filters</a>
</form>

<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="projects" data-filters="#filters" data-buttons="1" data-export-url="<?= url('api/projects.php?action=export') ?>" data-page-length="25">
    <thead><tr><th>Project</th><th>Client</th><th>Website</th><th>Department</th><th>Type</th><th>Technology</th><th>Status</th><th>Start Date</th><th>Launch Date</th><th class="no-sort">Monitoring</th><th class="no-sort no-export text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<div class="row g-3 mt-1">
  <div class="col-md-6">
    <div class="card h-100"><div class="card-header"><span><i class="bi bi-diagram-3 me-1"></i>Projects by Department</span></div>
      <div class="card-body dept-list py-2">
        <?php if (!$stats['by_department']): ?><div class="text-muted small py-2">No projects yet</div><?php endif; ?>
        <?php foreach ($stats['by_department'] as $d): ?><a href="?department_id=<?= (int) $d['id'] ?>"><span><?= e($d['name'] ?: 'Not set') ?></span><span class="count"><?= number_format($d['n']) ?></span></a><?php endforeach; ?>
      </div></div>
  </div>
  <div class="col-md-6">
    <div class="card h-100"><div class="card-header"><span><i class="bi bi-grid me-1"></i>Projects by Website Type</span></div>
      <div class="card-body dept-list py-2">
        <?php if (!$stats['by_type']): ?><div class="text-muted small py-2">No projects yet</div><?php endif; ?>
        <?php foreach ($stats['by_type'] as $t): ?><a href="?website_type_id=<?= (int) $t['id'] ?>"><span><?= e($t['name'] ?: 'Not set') ?><?= $t['department_name'] ? ' <span class="text-muted small-xs">· ' . e($t['department_name']) . '</span>' : '' ?></span><span class="count"><?= number_format($t['n']) ?></span></a><?php endforeach; ?>
      </div></div>
  </div>
</div>
<?php
if (Auth::can('projects')) include ROOT_PATH . '/includes/partials/project-modal.php';
include ROOT_PATH . '/includes/partials/project-status-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
