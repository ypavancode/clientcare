<?php
/** Page-level monitoring across all websites: every monitored page with its current status (server-side DataTable). */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$status = in_array(get('status'), ['failed', 'working', 'unknown'], true) ? get('status') : '';
$clientId = (int) get('client_id', 0);
$websiteId = (int) get('website_id', 0);
$interval = max(1, (int) setting('website_check_interval', 5));

$counts = Cache::remember(Cache::vkey('data', 'pages:counts:' . Tenant::id()), 30, fn() => DB::fetch("SELECT COUNT(*) AS total,
    COALESCE(SUM(p.status IN ('online','redirecting')),0) AS working,
    COALESCE(SUM(p.status IN (" . down_statuses_sql() . ")),0) AS failed,
    COALESCE(SUM(p.status IN ('unknown','paused')),0) AS unknown,
    COUNT(DISTINCT p.website_id) AS sites
    FROM website_pages p JOIN websites w ON w.id = p.website_id JOIN clients c ON c.id = w.client_id WHERE p.is_active = 1 AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . ""));
$lastScan = Cache::remember('pages:lastscan', 20, fn() => DB::value("SELECT last_done_at FROM queue_state WHERE queue = 'page'"));
$cronJobs = array_column(Monitor::cronHealth(), null, 'name');
$pagesJob = $cronJobs['check-pages'] ?? null;

$pageTitle = 'Page Monitoring';
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => 'Page Monitoring']];
$pageSubtitle = 'Every page of every website is checked every <strong>' . $interval . ' min</strong> · Last full scan: <strong>' . e(time_ago($lastScan)) . '</strong>' . ($pagesJob && $pagesJob['next'] && $pagesJob['status'] === 'active' ? ' · Next: <strong>' . format_datetime($pagesJob['next']) . '</strong>' : '') . ' · Page monitoring: ' . ($pagesJob && $pagesJob['status'] === 'active' ? '<strong class="text-success">ACTIVE</strong>' : '<strong class="text-danger">' . ($pagesJob && $pagesJob['status'] === 'never' ? 'NEVER RUN' : 'NOT RUNNING') . '</strong>');
$pageActions = '<button class="btn btn-brand btn-sm run-check" data-check="check_pages"><i class="bi bi-files me-1"></i>Scan All Pages Now</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><a href="<?= url('websites/pages.php') ?>" class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-files"></i></div><div><div class="stat-value"><?= number_format((int) $counts['total']) ?></div><div class="stat-label">Monitored pages · <?= number_format((int) $counts['sites']) ?> websites</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=working" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check-circle-fill"></i></div><div><div class="stat-value text-success"><?= number_format((int) $counts['working']) ?></div><div class="stat-label">🟢 Working</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=failed" class="stat-card <?= $counts['failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-file-earmark-x-fill"></i></div><div><div class="stat-value text-danger"><?= number_format((int) $counts['failed']) ?></div><div class="stat-label">🔴 Failed pages</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=unknown" class="stat-card"><div class="stat-icon tint-secondary"><i class="bi bi-question-circle"></i></div><div><div class="stat-value"><?= number_format((int) $counts['unknown']) ?></div><div class="stat-label">⚪ Not checked yet</div></div></a></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm">
    <option value="">All pages</option>
    <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed only</option>
    <option value="working" <?= $status === 'working' ? 'selected' : '' ?>>Working only</option>
    <option value="unknown" <?= $status === 'unknown' ? 'selected' : '' ?>>Not checked yet</option>
  </select>
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Filter by client…', ['small' => 1]) ?>
  <?= remote_select('website_id', 'websites', $websiteId ?: null, 'Filter by website…', ['small' => 1, 'depends' => 'client_id']) ?>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100" data-source="pages" data-filters="#filters" data-page-length="50">
    <thead><tr><th>Page</th><th>Website</th><th>Client</th><th>URL</th><th>Status</th><th>HTTP</th><th>Time</th><th>Reason</th><th>Last Checked</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php
include ROOT_PATH . '/includes/partials/run-check-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
