<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$days = (int) get('days', 7);
if (!in_array($days, [1, 7, 30, 90], true)) $days = 7;
$since = date('Y-m-d', strtotime("-$days days"));

// Overall uptime from daily rollups (+ today's raw checks). Cached 60 s.
$overall = Cache::remember(Cache::vkey('data', 'uptime:overall:' . Tenant::id() . ':' . $days), 60, function () use ($since) {
    $r = DB::fetch("SELECT COALESCE(SUM(checks),0) AS checks, COALESCE(SUM(up_checks),0) AS up FROM website_uptime_daily u JOIN websites w ON w.id = u.website_id WHERE w.tenant_id = " . Tenant::id() . " AND u.day >= ?", [$since]);
    $t = DB::fetch("SELECT COUNT(*) AS checks, COALESCE(SUM(m.status IN ('online','redirecting')),0) AS up FROM website_monitoring m JOIN websites w ON w.id = m.website_id WHERE w.tenant_id = " . Tenant::id() . " AND m.checked_at >= CURDATE()");
    return ['checks' => (int) $r['checks'] + (int) $t['checks'], 'up' => (int) $r['up'] + (int) $t['up']];
});
$recentIncidents = DB::fetchAll("SELECT i.*, w.name AS website_name, w.id AS website_id, c.name AS client_name FROM website_incidents i JOIN websites w ON w.id = i.website_id JOIN clients c ON c.id = w.client_id WHERE w.tenant_id = " . Tenant::id() . " ORDER BY i.started_at DESC LIMIT 25");

$pageTitle = 'Uptime Monitoring';
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => 'Uptime Monitoring']];
$pageSubtitle = 'Check interval: every <strong>' . Tenant::interval('website') . ' min</strong> (plan interval) · Overall uptime (' . $days . 'd): <strong>' . ($overall['checks'] ? number_format($overall['up'] / $overall['checks'] * 100, 2) . '%' : '—') . '</strong> · Raw checks are kept ' . (int) setting('retention_monitoring_days', 7) . ' days, daily totals for a year.';
$pageActions = '<div class="btn-group btn-group-sm">' . implode('', array_map(fn($d) => '<a href="?days=' . $d . '" class="btn ' . ($days === $d ? 'btn-dark' : 'btn-outline-primary') . '">' . ($d === 1 ? '24h' : $d . 'd') . '</a>', [1, 7, 30, 90])) . '</div><button class="btn btn-brand btn-sm run-check" data-check="check_websites"><i class="bi bi-arrow-repeat me-1"></i>Check Now</button>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card dt-card mb-3">
  <table class="table table-hover datatable w-100" data-source="uptime" data-params='<?= json_encode(['days' => $days]) ?>' data-page-length="50">
    <thead><tr><th>Website</th><th>Client</th><th>Status</th><th class="no-sort">Uptime (<?= $days ?>d)</th><th class="no-sort">Recent checks</th><th class="no-sort">Avg response</th><th class="no-sort">Incidents</th><th class="no-sort">Downtime</th><th>Last check</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<div class="card">
  <div class="card-header">Recent Incidents</div>
  <div class="card-body p-0">
    <?php if (!$recentIncidents): ?><div class="empty-state"><i class="bi bi-emoji-smile"></i>No downtime incidents recorded</div>
    <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
      <thead><tr><th>Website</th><th>Client</th><th>Started</th><th>Resolved</th><th>Duration</th><th>Reason</th></tr></thead>
      <tbody><?php foreach ($recentIncidents as $i): ?>
        <tr><td><a href="<?= url('websites/view.php?id=' . $i['website_id']) ?>" class="fw-500"><?= e($i['website_name']) ?></a></td><td><?= e($i['client_name']) ?></td><td><?= format_datetime($i['started_at']) ?></td>
          <td><?= $i['resolved_at'] ? format_datetime($i['resolved_at']) : '<span class="badge bg-danger-subtle text-danger">Ongoing</span>' ?></td>
          <td><?= duration_human($i['resolved_at'] ? (int) $i['duration_seconds'] : time() - strtotime($i['started_at'])) ?></td><td class="small"><span class="text-danger fw-500"><?= e($i['failure_reason'] ?: '') ?></span> <span class="text-muted"><?= e(truncate($i['error_message'] ?? '', 60)) ?></span></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
</div>
<?php
include ROOT_PATH . '/includes/partials/run-check-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
