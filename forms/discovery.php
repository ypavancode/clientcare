<?php
/** Form Discovery overview: automatic form inventory status of every website. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('forms.view');

$sites = DB::fetchAll("SELECT w.id, w.name, w.url, w.client_id, c.name AS client_name, w.pages_total, w.forms_total, w.forms_working, w.forms_failed, w.forms_blocked, w.forms_removed, w.forms_normal, w.forms_popup, w.forms_ajax,
    w.last_form_scan_at, w.form_scan_requested, w.form_discovery_enabled, w.monitoring_enabled,
    (SELECT s.summary FROM form_scans s WHERE s.website_id = w.id ORDER BY s.id DESC LIMIT 1) AS last_summary
    FROM websites w JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' AND w.tenant_id = " . Tenant::id() . " ORDER BY w.forms_failed DESC, w.last_form_scan_at IS NULL DESC, w.name LIMIT 2000");
$tot = ['sites' => count($sites), 'forms' => 0, 'working' => 0, 'failed' => 0, 'blocked' => 0, 'never' => 0, 'due' => 0];
foreach ($sites as $s) { $tot['forms'] += $s['forms_total']; $tot['working'] += $s['forms_working']; $tot['failed'] += $s['forms_failed']; $tot['blocked'] += $s['forms_blocked']; if (!$s['last_form_scan_at']) $tot['never']++; if ($s['form_scan_requested']) $tot['due']++; }
$lastRun = ($qsDisc = DB::fetch("SELECT * FROM queue_state WHERE queue = 'discovery'")) && $qsDisc['last_done_at'] ? ['last_started_at' => $qsDisc['last_done_at'], 'last_finished_at' => $qsDisc['last_done_at'], 'last_status' => $qsDisc['last_failed_at'] && strtotime($qsDisc['last_failed_at']) > strtotime($qsDisc['last_done_at']) ? 'failed' : 'success', 'last_message' => $qsDisc['last_error'], 'last_duration' => null, 'run_count' => 0] : null;
$recent = DB::fetchAll("SELECT s.*, w.name AS website_name FROM form_scans s JOIN websites w ON w.id = s.website_id WHERE w.tenant_id = " . Tenant::id() . " ORDER BY s.id DESC LIMIT 12");

$pageTitle = 'Form Discovery';
$breadcrumbs = [['label' => 'Forms', 'url' => 'forms/index.php'], ['label' => 'Form Discovery']];
$pageSubtitle = 'Every website is crawled automatically – all pages are inspected for forms (normal, popup, AJAX, WordPress, iframe) and every form found is monitored every <strong>' . (int) setting('form_check_interval', 10) . ' min</strong>. Re-scan every <strong>' . (int) setting('form_scan_interval_hours', 24) . ' h</strong> · Last discovery cron run: <strong>' . e(time_ago($lastRun['last_finished_at'] ?? null)) . '</strong>' . ($lastRun && !empty($lastRun['last_message']) ? ' · ' . e(truncate($lastRun['last_message'], 120)) : '');
$pageActions = (Auth::isPlatformAdmin() ? '<a href="' . url('platform/settings.php?tab=monitoring') . '" class="btn btn-light btn-sm"><i class="bi bi-sliders me-1"></i>Discovery Settings</a>' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-globe2"></i></div><div><div class="stat-value"><?= number_format($tot['sites']) ?></div><div class="stat-label">Websites</div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-ui-checks"></i></div><div><div class="stat-value"><?= number_format($tot['forms']) ?></div><div class="stat-label">Forms in Inventory</div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><a href="<?= url('forms/monitoring.php?status=working') ?>" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= number_format($tot['working']) ?></div><div class="stat-label">Working</div></div></a></div>
  <div class="col-6 col-md-4 col-xl-2"><a href="<?= url('forms/monitoring.php?status=failed') ?>" class="stat-card <?= $tot['failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-x-octagon"></i></div><div><div class="stat-value"><?= number_format($tot['failed']) ?></div><div class="stat-label">Failed</div></div></a></div>
  <div class="col-6 col-md-4 col-xl-2"><a href="<?= url('forms/monitoring.php?status=captcha_blocked') ?>" class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-shield-lock"></i></div><div><div class="stat-value"><?= number_format($tot['blocked']) ?></div><div class="stat-label">CAPTCHA Blocked</div></div></a></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-radar"></i></div><div><div class="stat-value"><?= number_format($tot['due']) ?></div><div class="stat-label">Scans Pending<?= $tot['never'] ? ' · ' . $tot['never'] . ' never scanned' : '' ?></div></div></div></div>
</div>

<div class="card dt-card mb-3">
  <div class="card-header"><span><i class="bi bi-globe2 me-1"></i>Websites</span></div>
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[]'>
    <thead><tr><th>Website</th><th>Client</th><th>Pages</th><th>Forms</th><th>Normal / Popup / AJAX</th><th>Working</th><th>Failed</th><th>CAPTCHA</th><th>Last Scan</th><th>Next Scan</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($sites as $s): $next = FormDiscovery::nextScanAt($s); ?>
      <tr data-row-id="<?= $s['id'] ?>">
        <td><a href="<?= url('websites/forms.php?id=' . $s['id']) ?>" class="fw-500"><?= e($s['name']) ?></a><div class="small-xs text-muted"><?= e(host_from_url($s['url'])) ?><?= !$s['form_discovery_enabled'] ? ' · <span class="text-secondary">discovery off</span>' : '' ?><?= !$s['monitoring_enabled'] ? ' · <span class="text-warning">monitoring paused</span>' : '' ?></div></td>
        <td><a href="<?= url('clients/view.php?id=' . $s['client_id']) ?>"><?= e($s['client_name']) ?></a></td>
        <td><?= (int) $s['pages_total'] ?></td>
        <td><a href="<?= url('websites/forms.php?id=' . $s['id']) ?>" class="fw-500"><?= (int) $s['forms_total'] ?></a><?= $s['forms_removed'] ? '<div class="small-xs text-muted">' . (int) $s['forms_removed'] . ' removed</div>' : '' ?></td>
        <td class="small"><?= (int) $s['forms_normal'] ?> / <?= (int) $s['forms_popup'] ?> / <?= (int) $s['forms_ajax'] ?></td>
        <td class="text-success fw-500"><?= (int) $s['forms_working'] ?></td>
        <td class="<?= $s['forms_failed'] ? 'text-danger fw-600' : 'text-muted' ?>"><?= (int) $s['forms_failed'] ?></td>
        <td class="<?= $s['forms_blocked'] ? 'text-info' : 'text-muted' ?>"><?= (int) $s['forms_blocked'] ?></td>
        <td class="small" title="<?= e($s['last_summary'] ?? '') ?>"><?= $s['last_form_scan_at'] ? format_datetime($s['last_form_scan_at']) . '<div class="small-xs text-muted">' . e(time_ago($s['last_form_scan_at'])) . '</div>' : '<span class="text-warning">Never</span>' ?></td>
        <td class="small"><?= $next === 'at the next cron run' ? '<span class="text-info">Next cron run</span>' : ($next ? format_datetime($next) : '—') ?></td>
        <td class="row-actions text-end text-nowrap"><?php if (Auth::can('monitor')): ?><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"discover_forms","id":<?= $s['id'] ?>}' data-loading="1" data-reload="1" title="Scan website for forms now"><i class="bi bi-search"></i></button> <?php endif; ?><a href="<?= url('websites/forms.php?id=' . $s['id']) ?>" class="btn btn-light btn-sm" title="Form inventory"><i class="bi bi-list-check"></i></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
</div>

<div class="card">
  <div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Recent Discovery Scans</span></div>
  <div class="card-body p-0">
    <?php if (!$recent): ?><div class="empty-state py-3"><i class="bi bi-radar"></i>No discovery scans yet. Websites are scanned automatically by the cron; use the search button to scan one now.</div>
    <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0 small">
      <thead><tr><th>When</th><th>Website</th><th>Pages</th><th>Forms</th><th>New</th><th>Changed</th><th>Removed</th><th>Engine</th><th>Status</th><th>Summary</th></tr></thead>
      <tbody><?php foreach ($recent as $s): ?>
        <tr><td class="text-nowrap"><?= format_datetime($s['started_at']) ?></td><td><a href="<?= url('websites/forms.php?id=' . $s['website_id']) ?>"><?= e($s['website_name']) ?></a></td><td><?= (int) $s['pages_scanned'] ?></td><td><?= (int) $s['forms_found'] ?></td><td><?= (int) $s['forms_new'] ?></td><td><?= (int) $s['forms_changed'] ?></td><td><?= (int) $s['forms_removed'] ?></td><td><?= e($s['engine']) ?></td><td><?= status_pill(['done' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'running' => 'info'][$s['status']] ?? 'secondary', ucfirst($s['status']), false) ?></td><td class="text-muted"><?= e(truncate($s['summary'] ?? '', 110)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';