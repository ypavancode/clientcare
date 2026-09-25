<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

// Open a notification: mark read and jump to its link
if ($open = (int) get('open', 0)) {
    $n = DB::fetch("SELECT * FROM notifications WHERE id = ? AND tenant_id = ?", [$open, Tenant::id()]);
    if ($n) {
        DB::update('notifications', ['is_read' => 1], 'id = ?', [$open]);
        data_changed();
        if ($n['link']) redirect($n['link']);
    }
    redirect('notifications/index.php');
}

$type = in_array(get('type'), ['critical', 'warning', 'recovery', 'info'], true) ? get('type') : '';
$clientId = (int) get('client_id', 0);
$websiteId = (int) get('website_id', 0);
$unreadOnly = get('unread') === '1';
$page = max(1, (int) get('page', 1));
$perPage = 50;
$conds = ['n.tenant_id = ' . Tenant::id()];
$params = [];
if ($type) { $conds[] = "n.type = ?"; $params[] = $type; }
if ($clientId) { $conds[] = "n.client_id = ?"; $params[] = $clientId; }
if ($websiteId) { $conds[] = "n.website_id = ?"; $params[] = $websiteId; }
if ($unreadOnly) $conds[] = "n.is_read = 0";
$whereSql = implode(' AND ', $conds);
$total = (int) DB::value("SELECT COUNT(*) FROM notifications n WHERE $whereSql", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$rows = DB::fetchAll("SELECT n.*, c.name AS client_name, w.name AS website_name FROM notifications n LEFT JOIN clients c ON c.id = n.client_id LEFT JOIN websites w ON w.id = n.website_id WHERE $whereSql ORDER BY n.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$counts = Cache::remember(Cache::vkey('data', 'notif:counts:' . Tenant::id()), 30, function () {
    $c = ['critical' => 0, 'warning' => 0, 'recovery' => 0, 'info' => 0, 'unread' => (int) DB::value("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND tenant_id = " . Tenant::id() . "")];
    foreach (DB::fetchAll("SELECT type, COUNT(*) n FROM notifications WHERE tenant_id = " . Tenant::id() . " GROUP BY type") as $r) $c[$r['type']] = (int) $r['n'];
    return $c;
});
$icons = ['critical' => 'bi-exclamation-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill', 'recovery' => 'bi-check-circle-fill', 'info' => 'bi-info-circle-fill'];
$pageLink = fn(int $p) => '?' . http_build_query(array_filter(['type' => $type, 'client_id' => $clientId ?: null, 'website_id' => $websiteId ?: null, 'unread' => $unreadOnly ? 1 : null, 'page' => $p]));

$pageTitle = 'Notifications';
$breadcrumbs = [['label' => 'Notifications']];
$pageActions = '<button class="btn btn-outline-primary btn-sm btn-action" data-url="api/notifications.php" data-params=\'{"action":"mark_all_read"}\' data-reload="1"><i class="bi bi-check2-all me-1"></i>Mark All Read</button>'
    . (in_array(Auth::role(), ['admin', 'manager']) ? '<button class="btn btn-light btn-sm btn-action" data-url="api/notifications.php" data-params=\'{"action":"clear_read"}\' data-confirm="Delete all read notifications?" data-reload="1"><i class="bi bi-trash me-1"></i>Clear Read</button>' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><a href="?type=critical" class="stat-card"><div class="stat-icon tint-danger"><i class="bi bi-exclamation-octagon-fill"></i></div><div><div class="stat-value"><?= number_format($counts['critical']) ?></div><div class="stat-label">🔴 Critical</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?type=warning" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-exclamation-triangle-fill"></i></div><div><div class="stat-value"><?= number_format($counts['warning']) ?></div><div class="stat-label">🟠 Warning</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?type=recovery" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-check-circle-fill"></i></div><div><div class="stat-value"><?= number_format($counts['recovery']) ?></div><div class="stat-label">🟢 Recovery</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?type=info" class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-info-circle-fill"></i></div><div><div class="stat-value"><?= number_format($counts['info']) ?></div><div class="stat-label">🔵 Information</div></div></a></div>
</div>
<form class="filter-bar" method="get" id="notifFilters">
  <select name="type" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All types</option><?php foreach (['critical' => 'Critical', 'warning' => 'Warning', 'recovery' => 'Recovery', 'info' => 'Information'] as $k => $v): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <?= remote_select('client_id', 'clients', $clientId ?: null, 'Client…', ['small' => 1]) ?>
  <?= remote_select('website_id', 'websites', $websiteId ?: null, 'Website…', ['small' => 1, 'depends' => 'client_id']) ?>
  <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="unread" value="1" id="unread" <?= $unreadOnly ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label small" for="unread">Unread only (<?= number_format($counts['unread']) ?>)</label></div>
  <button class="btn btn-dark btn-sm">Filter</button>
  <?php if ($type || $clientId || $websiteId || $unreadOnly): ?><a href="<?= url('notifications/index.php') ?>" class="btn btn-light btn-sm">Clear</a><?php endif; ?>
  <span class="ms-auto text-muted small"><?= number_format($total) ?> notification(s)</span>
</form>
<div class="card">
  <div class="card-body p-0">
    <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-bell-slash"></i>No notifications</div>
    <?php else: ?>
    <?php foreach ($rows as $n): ?>
      <div class="notif-item type-<?= $n['type'] ?> <?= $n['is_read'] ? '' : 'unread' ?>" data-row-id="<?= $n['id'] ?>">
        <span class="n-icon"><i class="bi <?= $icons[$n['type']] ?>"></i></span>
        <div class="flex-grow-1 min-w-0">
          <div class="n-title"><a href="?open=<?= $n['id'] ?>" class="text-reset"><?= e($n['title']) ?></a></div>
          <div class="n-msg"><?= e($n['message']) ?></div>
          <div class="n-time"><?= format_datetime($n['created_at']) ?> (<?= e(time_ago($n['created_at'])) ?>)<?php if ($n['client_name']): ?> · <a href="<?= url('clients/view.php?id=' . $n['client_id']) ?>"><?= e($n['client_name']) ?></a><?php endif; ?><?php if ($n['website_name']): ?> · <a href="<?= url('websites/view.php?id=' . $n['website_id']) ?>"><?= e($n['website_name']) ?></a><?php endif; ?></div>
        </div>
        <div class="row-actions d-flex align-items-start gap-1">
          <?php if ($n['link']): ?><a href="?open=<?= $n['id'] ?>" class="btn btn-light btn-sm" title="Open"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
          <button class="btn btn-light btn-sm btn-action" data-url="api/notifications.php" data-params='{"action":"<?= $n['is_read'] ? 'mark_unread' : 'mark_read' ?>","id":<?= $n['id'] ?>}' data-reload="1" title="<?= $n['is_read'] ? 'Mark unread' : 'Mark read' ?>"><i class="bi <?= $n['is_read'] ? 'bi-envelope' : 'bi-envelope-open' ?>"></i></button>
          <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/notifications.php" data-params='{"action":"delete","id":<?= $n['id'] ?>}' data-reload="1" title="Delete"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center small">
    <span class="text-muted">Page <?= $page ?> of <?= number_format($pages) ?></span>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-primary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $pageLink(max(1, $page - 1)) ?>">‹ Newer</a>
      <a class="btn btn-outline-primary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $pageLink(min($pages, $page + 1)) ?>">Older ›</a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$pageScripts = <<<'JS'
<script>$('#notifFilters').on('rs:change', function () { this.submit(); });</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php'; ?>
