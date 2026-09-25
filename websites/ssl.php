<?php
/** SSL monitoring overview of every website in the workspace. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('websites.view');
$tid = Tenant::id();
$filter = in_array(get('status'), ['valid', 'expiring_soon', 'expired', 'error', 'unknown'], true) ? get('status') : '';
$sites = DB::fetchAll("SELECT w.id, w.name, w.url, w.ssl_status, w.ssl_expires_at, w.ssl_days_left, w.ssl_issuer, w.ssl_error, w.ssl_checked_at, w.monitoring_enabled, c.name AS client_name FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.tenant_id = ? " . ($filter ? "AND w.ssl_status = ? " : '') . "ORDER BY FIELD(w.ssl_status,'expired','error','expiring_soon','unknown','valid'), w.ssl_days_left ASC, w.name LIMIT 1000", $filter ? [$tid, $filter] : [$tid]);
$counts = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0, 'error' => 0, 'unknown' => 0];
foreach (DB::fetchAll("SELECT ssl_status, COUNT(*) n FROM websites WHERE tenant_id = ? GROUP BY ssl_status", [$tid]) as $r) $counts[$r['ssl_status']] = (int) $r['n'];
$warn = (int) Tenant::setting('ssl_warning_days', 30);
$alertDays = (string) Tenant::setting('ssl_alert_days', '30,15,7');

$pageTitle = 'SSL Monitoring';
$breadcrumbs = [['label' => 'SSL']];
$pageSubtitle = 'Certificate validity, chain, hostname and expiry are checked every <strong>' . Tenant::interval('ssl') . ' min</strong> · "expiring soon" within <strong>' . $warn . ' days</strong> · alert emails at <strong>' . e($alertDays) . '</strong> days';
$pageActions = Auth::can('monitor') ? '<button class="btn btn-brand btn-sm run-check" data-check="check_ssl"><i class="bi bi-shield-check me-1"></i>Check All SSL Now</button>' : '';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <?php foreach ([['valid', 'Valid', 'tint-success', 'bi-shield-check'], ['expiring_soon', 'Expiring Soon', 'tint-warning', 'bi-hourglass-split'], ['expired', 'Expired', 'tint-danger', 'bi-shield-x'], ['error', 'Errors', 'tint-danger', 'bi-shield-exclamation'], ['unknown', 'Not Checked', 'tint-secondary', 'bi-question-circle']] as [$k, $l, $t, $i]): ?>
    <div class="col-6 col-md"><a href="?status=<?= $k ?>" class="stat-card <?= $filter === $k ? 'border-dark' : '' ?> <?= in_array($k, ['expired', 'error'], true) && $counts[$k] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon <?= $t ?>"><i class="bi <?= $i ?>"></i></div><div><div class="stat-value"><?= $counts[$k] ?></div><div class="stat-label"><?= $l ?></div></div></a></div>
  <?php endforeach; ?>
</div>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[]'>
    <thead><tr><th>Website</th><th>Client</th><th>SSL Status</th><th>Expires</th><th>Days left</th><th>Issuer</th><th>Last checked</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($sites as $w): ?>
      <tr data-row-id="<?= $w['id'] ?>">
        <td><a href="<?= url('websites/view.php?id=' . $w['id'] . '&tab=ssl') ?>" class="fw-500"><?= e($w['name']) ?></a><div class="small-xs text-muted"><?= e(host_from_url($w['url'])) ?><?= stripos($w['url'], 'https://') !== 0 ? ' · <span class="text-warning">http:// – no SSL</span>' : '' ?></div></td>
        <td><?= e($w['client_name']) ?></td>
        <td><?= ssl_status_badge($w['ssl_status'], $w['ssl_days_left']) ?><?= $w['ssl_error'] ? '<div class="small-xs text-danger">' . e(truncate($w['ssl_error'], 70)) . '</div>' : '' ?></td>
        <td class="small"><?= $w['ssl_expires_at'] ? format_date($w['ssl_expires_at']) : '—' ?></td>
        <td class="<?= $w['ssl_days_left'] !== null && $w['ssl_days_left'] <= $warn ? 'text-danger fw-600' : '' ?>"><?= $w['ssl_days_left'] !== null ? (int) $w['ssl_days_left'] : '—' ?></td>
        <td class="small"><?= e($w['ssl_issuer'] ?: '—') ?></td>
        <td class="small text-muted"><?= e(time_ago($w['ssl_checked_at'])) ?></td>
        <td class="row-actions text-end"><?php if (Auth::can('monitor')): ?><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"check_ssl","id":<?= $w['id'] ?>}' data-loading="1" data-reload="1" title="Re-check SSL"><i class="bi bi-arrow-repeat"></i></button> <?php endif; ?><a href="<?= url('websites/view.php?id=' . $w['id'] . '&tab=ssl') ?>" class="btn btn-light btn-sm" title="Details"><i class="bi bi-eye"></i></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php if (!$sites): ?><div class="empty-state"><i class="bi bi-shield-check"></i>No websites yet. <a href="<?= url('websites/add.php') ?>">Add a website</a> – its SSL certificate is checked automatically.</div><?php endif; ?>
</div>
<?php include ROOT_PATH . '/includes/partials/run-check-script.php'; include ROOT_PATH . '/includes/layout/footer.php';