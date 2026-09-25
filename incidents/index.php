<?php
/** Incidents: one list across websites, pages, forms and SSL certificates with the detected root cause. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('incidents.view');
$tid = Tenant::id();
$state = in_array(get('state'), ['open', 'resolved'], true) ? get('state') : '';
$kind = in_array(get('kind'), ['website', 'page', 'form', 'ssl'], true) ? get('kind') : '';
$websiteId = (int) get('website_id', 0);
$days = max(1, min(365, (int) get('days', 30)));
$since = date('Y-m-d H:i:s', time() - $days * 86400);

$sql = "SELECT * FROM (
    SELECT 'website' AS kind, i.id, i.website_id, NULL AS component_id, w.name AS website_name, w.url AS component, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason AS reason, i.error_message AS detail, i.status_code AS http_code, i.failed_checks, i.alert_sent FROM website_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ?
    UNION ALL SELECT 'page', i.id, i.website_id, i.page_id, w.name, p.url, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason, i.error_message, i.status_code, i.failed_checks, i.alert_sent FROM page_incidents i JOIN websites w ON w.id = i.website_id JOIN website_pages p ON p.id = i.page_id WHERE i.tenant_id = ?
    UNION ALL SELECT 'form', i.id, i.website_id, i.form_id, w.name, f.name, i.started_at, i.resolved_at, i.duration_seconds, i.failure_reason, i.error_message, i.http_code, i.failed_tests, i.alert_sent FROM form_incidents i JOIN websites w ON w.id = i.website_id JOIN forms f ON f.id = i.form_id WHERE i.tenant_id = ?
    UNION ALL SELECT 'ssl', i.id, i.website_id, NULL, w.name, w.url, i.started_at, i.resolved_at, i.duration_seconds, i.status, i.error_message, NULL, i.failed_checks, i.alert_sent FROM ssl_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ?
  ) x WHERE (x.resolved_at IS NULL OR x.started_at >= ?)";
$params = [$tid, $tid, $tid, $tid, $since];
if ($state === 'open') $sql .= " AND x.resolved_at IS NULL"; elseif ($state === 'resolved') $sql .= " AND x.resolved_at IS NOT NULL";
if ($kind) { $sql .= " AND x.kind = ?"; $params[] = $kind; }
if ($websiteId) { $sql .= " AND x.website_id = ?"; $params[] = $websiteId; }
$sql .= " ORDER BY (x.resolved_at IS NULL) DESC, x.started_at DESC LIMIT 300";
$rows = DB::fetchAll($sql, $params);
$open = count(array_filter($rows, fn($r) => $r['resolved_at'] === null));
$prefix = ['website' => 'W', 'page' => 'P', 'form' => 'F', 'ssl' => 'S'];
$kindLabel = ['website' => 'Website', 'page' => 'Page', 'form' => 'Form', 'ssl' => 'SSL certificate'];
$rootCause = function (array $r): string {
    $reason = trim((string) $r['reason']);
    if ($r['kind'] === 'ssl') return $reason ? 'SSL: ' . ucwords(str_replace('_', ' ', $reason)) : 'SSL error';
    if ($reason === '' && $r['http_code']) return 'HTTP ' . $r['http_code'];
    return $reason !== '' ? $reason : 'Unknown / unable to determine';
};
$websites = DB::fetchAll("SELECT id, name FROM websites WHERE tenant_id = ? ORDER BY name LIMIT 500", [$tid]);

$pageTitle = 'Incidents';
$breadcrumbs = [['label' => 'Incidents']];
$pageSubtitle = '<strong>' . $open . '</strong> open · showing incidents of the last ' . $days . ' days across websites, pages, forms and SSL';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" method="get">
  <select name="state" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Open + resolved</option><option value="open" <?= $state === 'open' ? 'selected' : '' ?>>Open</option><option value="resolved" <?= $state === 'resolved' ? 'selected' : '' ?>>Resolved</option></select>
  <select name="kind" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All components</option><?php foreach ($kindLabel as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="website_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All websites</option><?php foreach ($websites as $w): ?><option value="<?= $w['id'] ?>" <?= $websiteId === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
  <select name="days" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select>
  <a href="<?= url('incidents/index.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[]'>
    <thead><tr><th>Incident</th><th>Component</th><th>Website</th><th>Status</th><th>Root cause</th><th>Detected</th><th>Recovered</th><th>Downtime</th><th>Alert</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $openRow = $r['resolved_at'] === null; ?>
      <tr class="<?= $openRow ? 'table-danger-subtle' : '' ?>">
        <td class="mono fw-500">#<?= $prefix[$r['kind']] . (int) $r['id'] ?><div class="small-xs text-muted"><?= $kindLabel[$r['kind']] ?></div></td>
        <td><?php if ($r['kind'] === 'form'): ?><a href="<?= url('websites/forms.php?id=' . $r['website_id']) ?>"><?= e($r['component']) ?></a><?php else: ?><a href="<?= e($r['component']) ?>" target="_blank" rel="noopener"><?= e(truncate($r['component'], 48)) ?></a><?php endif; ?></td>
        <td><a href="<?= url('websites/view.php?id=' . $r['website_id']) ?>"><?= e($r['website_name']) ?></a></td>
        <td><?= $openRow ? status_pill('danger', 'OPEN') : status_pill('success', 'RESOLVED') ?></td>
        <td class="small"><span class="fw-500 <?= $openRow ? 'text-danger' : '' ?>"><?= e($rootCause($r)) ?></span><?= $r['detail'] ? '<div class="small-xs text-muted" title="' . e($r['detail']) . '">' . e(truncate($r['detail'], 90)) . '</div>' : '' ?><?= $r['failed_checks'] > 1 ? '<div class="small-xs text-muted">' . (int) $r['failed_checks'] . ' failed checks</div>' : '' ?></td>
        <td class="text-nowrap small"><?= format_datetime($r['started_at']) ?></td>
        <td class="text-nowrap small"><?= $r['resolved_at'] ? format_datetime($r['resolved_at']) : '<span class="text-muted">—</span>' ?></td>
        <td class="small"><?= duration_human((int) ($r['duration_seconds'] ?? (time() - strtotime($r['started_at'])))) ?></td>
        <td class="small"><?= $r['alert_sent'] ? '<span class="text-success"><i class="bi bi-envelope-check"></i> sent</span>' : '<span class="text-muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-emoji-smile"></i>No incidents in this period – everything is healthy.</div><?php endif; ?>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';