<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('projects.view');

$id = (int) get('id', 0);
$p = DB::fetch("SELECT p.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone, d.name AS department_name, t.name AS type_name, u.name AS assigned_name, cb.name AS creator_name,
    w.id AS wid, w.monitoring_enabled, w.page_monitoring_enabled, w.status AS site_status, w.ssl_status, w.ssl_days_left, w.pages_total, w.pages_failed, w.last_checked_at, w.failure_reason,
    (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id) AS form_count, (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status = 'failed') AS forms_failed
    FROM website_projects p JOIN clients c ON c.id = p.client_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id
    LEFT JOIN users u ON u.id = p.assigned_user_id LEFT JOIN users cb ON cb.id = p.created_by LEFT JOIN websites w ON w.id = p.website_id WHERE p.id = ? AND p.tenant_id = ?", [$id, Tenant::id()]);
if (!$p) http_error(404, 'Project not found.');
$history = DB::fetchAll("SELECT h.*, u.name AS user_name FROM project_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.project_id = ? ORDER BY h.changed_at DESC, h.id DESC", [$id]);
$activity = DB::fetchAll("SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE (a.website_id = ? AND ? > 0) OR (a.client_id = ? AND a.action LIKE 'project_%') ORDER BY a.id DESC LIMIT 30", [$p['wid'] ?: 0, $p['wid'] ?: 0, $p['client_id']]);
$statuses = project_statuses();
$flow = ['design', 'development', 'testing', 'client_review', 'changes_required', 'ready_for_launch', 'live'];
$reached = [];
foreach ($history as $h) $reached[$h['to_status']] = true;
$reached[$p['status']] = true;
$curIdx = array_search($p['status'], $flow, true);
$canEdit = Auth::can('projects');
$mon = $p['wid'] && $p['monitoring_enabled'];

$pageTitle = $p['project_name'];
$breadcrumbs = [['label' => 'Website Projects', 'url' => 'projects/index.php'], ['label' => $p['project_name']]];
$pageSubtitle = project_kind_badge($p['project_kind']) . ' ' . project_status_badge($p['status']) . ' · Client: <a href="' . url('clients/view.php?id=' . $p['client_id']) . '">' . e($p['client_name']) . '</a>' . ($p['website_url'] ? ' · <a href="' . e($p['website_url']) . '" target="_blank" rel="noopener">' . e($p['website_url']) . ' <i class="bi bi-box-arrow-up-right small"></i></a>' : '');
$statusMenu = '';
foreach ($statuses as $k => [$lbl, $cls]) $statusMenu .= '<li><a class="dropdown-item small btn-status' . ($k === $p['status'] ? ' active' : '') . '" href="#" data-id="' . $id . '" data-status="' . $k . '"><span class="badge badge-status ' . $cls . ' me-1"><span class="dot"></span></span>' . e($lbl) . '</a></li>';
$pageActions = ($canEdit ? '<div class="dropdown d-inline-block"><button class="btn btn-brand btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-arrow-repeat me-1"></i>Change Status</button><ul class="dropdown-menu dropdown-menu-end">' . $statusMenu . '</ul></div>
<button class="btn btn-dark btn-sm btn-edit-project" data-id="' . $id . '"><i class="bi bi-pencil me-1"></i>Edit</button>' : '')
 . ($p['website_url'] ? '<a href="' . e($p['website_url']) . '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Open Website</a>' : '')
 . '<a href="' . url('clients/view.php?id=' . $p['client_id']) . '" class="btn btn-light btn-sm"><i class="bi bi-person me-1"></i>View Client</a>'
 . (Auth::can('websites') && $p['website_url'] ? ($mon
     ? '<button class="btn btn-light btn-sm btn-action" data-url="api/projects.php" data-params=\'{"action":"disable_monitoring","id":' . $id . '}\' data-confirm="Disable monitoring for this website? Alerts will stop until it is enabled again." data-icon="question" data-reload="1"><i class="bi bi-toggle-on text-success me-1"></i>Disable Monitoring</button>'
     : '<button class="btn btn-success btn-sm btn-action" data-url="api/projects.php" data-params=\'{"action":"enable_monitoring","id":' . $id . '}\' data-loading="1" data-reload="1"><i class="bi bi-toggle-off me-1"></i>Enable Monitoring</button>') : '')
 . (in_array(Auth::role(), ['admin', 'manager'], true) ? '<div class="dropdown d-inline-block"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item text-danger btn-action" data-url="api/projects.php" data-params=\'{"action":"delete","id":' . $id . '}\' data-confirm="Delete this project? The linked website and its monitoring history are kept." data-confirm-btn="Delete project"><i class="bi bi-trash me-2"></i>Delete Project</button></li></ul></div>' : '');
$projectDefaults = ['client_id' => $p['client_id']];
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card mb-3">
  <div class="card-header"><span><i class="bi bi-signpost-split me-1"></i>Project Timeline</span><span class="small fw-normal">Current status: <?= project_status_badge($p['status']) ?><?= $p['status'] === 'on_hold' || $p['status'] === 'cancelled' ? ' <span class="text-muted">(outside the standard flow)</span>' : '' ?></span></div>
  <div class="card-body">
    <div class="project-timeline">
      <span class="step done"><i class="bi bi-plus-circle"></i>Project Created</span><i class="bi bi-arrow-right arrow"></i>
      <?php foreach ($flow as $i => $k): [$lbl, $cls] = $statuses[$k]; $isCur = $k === $p['status']; $done = !$isCur && (isset($reached[$k]) || ($curIdx !== false && $i < $curIdx)); ?>
        <span class="step <?= $isCur ? 'current' : ($done ? 'done' : '') ?>"><i class="bi <?= $isCur ? 'bi-record-circle-fill' : ($done ? 'bi-check-circle-fill' : 'bi-circle') ?>"></i><?= e($lbl) ?></span><?php if ($i < count($flow) - 1): ?><i class="bi bi-arrow-right arrow"></i><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($p['status'] === 'live'): ?><i class="bi bi-arrow-right arrow"></i><span class="step <?= $mon ? 'done' : '' ?>"><i class="bi <?= $mon ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>Monitoring <?= $mon ? 'Active' : 'Not Enabled' ?></span><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="card mb-3">
      <div class="card-header">Project Information</div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>Project</dt><dd><?= e($p['project_name']) ?></dd>
          <dt>Client</dt><dd><a href="<?= url('clients/view.php?id=' . $p['client_id']) ?>"><?= e($p['client_name']) ?></a><?= $p['client_company'] ? '<div class="text-muted small-xs">' . e($p['client_company']) . '</div>' : '' ?></dd>
          <dt>Website</dt><dd><?= $p['website_url'] ? '<a href="' . e($p['website_url']) . '" target="_blank" rel="noopener">' . e($p['website_url']) . '</a>' : '<span class="text-muted">Not set yet</span>' ?><?= $p['website_name'] ? '<div class="text-muted small-xs">' . e($p['website_name']) . '</div>' : '' ?></dd>
          <dt>Kind</dt><dd><?= project_kind_badge($p['project_kind']) ?></dd>
          <dt>Department</dt><dd><?= e($p['department_name'] ?: '—') ?></dd>
          <dt>Website type</dt><dd><?= e($p['type_name'] ?: '—') ?></dd>
          <dt>Technology</dt><dd><?= e($p['technology'] ?: '—') ?></dd>
          <dt>Status</dt><dd><?= project_status_badge($p['status']) ?></dd>
          <dt>Assigned</dt><dd><?= e($p['assigned_name'] ?: 'Unassigned') ?></dd>
        </dl>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Dates</div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>Start</dt><dd><?= format_date($p['start_date']) ?></dd>
          <dt>Expected launch</dt><dd><?= format_date($p['expected_launch_date']) ?><?php $dl = days_until($p['expected_launch_date']); if ($dl !== null && !$p['actual_launch_date'] && !in_array($p['status'], ['live', 'cancelled'], true)): ?> <?= $dl < 0 ? status_pill('danger', abs($dl) . ' days overdue', false) : status_pill($dl <= 7 ? 'warning' : 'info', $dl . ' days left', false) ?><?php endif; ?></dd>
          <dt>Actual launch</dt><dd><?= $p['actual_launch_date'] ? '<span class="text-success fw-500">' . format_date($p['actual_launch_date']) . '</span>' : '—' ?></dd>
          <dt>Created</dt><dd><?= format_datetime($p['created_at']) ?><?= $p['creator_name'] ? ' <span class="text-muted small-xs">by ' . e($p['creator_name']) . '</span>' : '' ?></dd>
          <dt>Updated</dt><dd><?= format_datetime($p['updated_at'] ?: $p['created_at']) ?></dd>
        </dl>
      </div>
    </div>
    <?php if ($p['notes']): ?><div class="card mb-3"><div class="card-header">Project Notes</div><div class="card-body small"><?= nl2br(e($p['notes'])) ?></div></div><?php endif; ?>
  </div>

  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-activity me-1"></i>Website Monitoring</span><?php if ($p['wid']): ?><a href="<?= url('websites/view.php?id=' . $p['wid']) ?>" class="small fw-normal">Open monitoring page</a><?php endif; ?></div>
      <div class="card-body">
        <?php if (!$p['website_url']): ?>
          <div class="empty-state py-3"><i class="bi bi-link-45deg"></i>No website URL yet. Add the URL to the project (Edit) to link it with the monitoring module.</div>
        <?php elseif (!$p['wid']): ?>
          <div class="empty-state py-3"><i class="bi bi-toggle-off"></i>Not linked to monitoring yet. <?= Auth::can('websites') ? 'Click <strong>Enable Monitoring</strong> to start automatic checks.' : '' ?></div>
        <?php else: ?>
          <div class="row g-2">
            <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Monitoring</div><div class="val"><?= $mon ? '<span class="text-success">ACTIVE</span>' : '<span class="text-secondary">DISABLED</span>' ?></div></div></div>
            <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Website</div><div class="val"><?= website_status_badge($p['site_status']) ?></div><?php if ($p['failure_reason']): ?><div class="small-xs text-danger"><?= e($p['failure_reason']) ?></div><?php endif; ?></div></div>
            <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">SSL</div><div class="val"><?= ssl_status_badge($p['ssl_status'], $p['ssl_days_left']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Pages / Forms</div><div class="val small"><?= (int) $p['pages_total'] ?> pages<?= $p['pages_failed'] ? ' <span class="text-danger">(' . (int) $p['pages_failed'] . ' failed)</span>' : '' ?><br><?= (int) $p['form_count'] ?> forms<?= $p['forms_failed'] ? ' <span class="text-danger">(' . (int) $p['forms_failed'] . ' failed)</span>' : '' ?></div></div></div>
          </div>
          <div class="small text-muted mt-2">Last check: <?= e(time_ago($p['last_checked_at'])) ?> · <?= $mon ? 'Uptime, pages, SSL and forms are checked automatically by the cron.' : 'Monitoring is paused – enable it to resume automatic checks and alerts.' ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Status History</span><span class="small fw-normal text-muted"><?= count($history) ?> change(s)</span></div>
      <div class="card-body p-0">
        <?php if (!$history): ?><div class="empty-state py-3"><i class="bi bi-clock"></i>No status changes recorded yet</div>
        <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0">
          <thead><tr><th>Date / Time</th><th>From</th><th>To</th><th>Changed by</th><th>Note</th></tr></thead>
          <tbody><?php foreach ($history as $h): ?>
            <tr><td class="text-nowrap"><?= format_datetime($h['changed_at']) ?></td><td><?= $h['from_status'] ? project_status_badge($h['from_status']) : '<span class="text-muted">—</span>' ?></td><td><?= project_status_badge($h['to_status']) ?></td><td><?= e($h['user_name'] ?? 'System') ?></td><td class="small text-muted"><?= e($h['note'] ?: '') ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span><i class="bi bi-list-ul me-1"></i>Activity</span></div>
      <div class="card-body">
        <?php if (!$activity): ?><div class="empty-state py-3"><i class="bi bi-activity"></i>No activity yet</div>
        <?php else: ?><ul class="timeline"><?php foreach ($activity as $a): ?>
          <li><span class="tl-icon"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><div class="tl-title"><?= e($a['description']) ?></div><div class="tl-meta"><?= e($a['user_name'] ?? 'System') ?> · <?= format_datetime($a['created_at']) ?></div></li>
        <?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
if ($canEdit) include ROOT_PATH . '/includes/partials/project-modal.php';
include ROOT_PATH . '/includes/partials/project-status-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
