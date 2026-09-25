<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$id = (int) get('id', 0);
$w = DB::fetch("SELECT w.*, c.name AS client_name, c.status AS client_status FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.id = ? AND w.tenant_id = ?", [$id, Tenant::id()]);
if (!$w) http_error(404, 'Website not found.');
$isAdmin = Auth::isAdmin();
$interval = Tenant::interval('page', !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null); // page-scan cadence comes from the plan (v3.9)
$tab = in_array(get('tab'), ['pages', 'forms', 'incidents', 'history', 'scans', 'ssl'], true) ? get('tab') : 'pages';
$domain = DB::fetch("SELECT * FROM domains WHERE website_id = ?", [$id]);
$hosting = DB::fetch("SELECT * FROM hosting WHERE website_id = ?", [$id]);
$forms = DB::fetchAll("SELECT * FROM forms WHERE website_id = ? ORDER BY name", [$id]);
$pages = DB::fetchAll("SELECT * FROM website_pages WHERE website_id = ? AND is_active = 1 ORDER BY (status IN (" . down_statuses_sql() . ")) DESC, priority, path LIMIT 600", [$id]);
$removedPages = (int) DB::value("SELECT COUNT(*) FROM website_pages WHERE website_id = ? AND is_active = 0", [$id]);
$incidents = DB::fetchAll("SELECT * FROM website_incidents WHERE website_id = ? ORDER BY started_at DESC LIMIT 30", [$id]);
$pageIncidents = DB::fetchAll("SELECT i.*, p.title, p.path, p.url FROM page_incidents i JOIN website_pages p ON p.id = i.page_id WHERE i.website_id = ? ORDER BY i.id DESC LIMIT 40", [$id]);
$scans = DB::fetchAll("SELECT * FROM website_scans WHERE website_id = ? ORDER BY id DESC LIMIT 40", [$id]);
$history = DB::fetchAll("SELECT * FROM website_monitoring WHERE website_id = ? ORDER BY checked_at DESC LIMIT 60", [$id]);
$chart = array_reverse(DB::fetchAll("SELECT checked_at, status, response_time FROM website_monitoring WHERE website_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY checked_at DESC LIMIT 500", [$id]));
$sslHistory = DB::fetchAll("SELECT * FROM ssl_monitoring WHERE website_id = ? ORDER BY checked_at DESC LIMIT 10", [$id]);
$activity = DB::fetchAll("SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.website_id = ? ORDER BY a.id DESC LIMIT 40", [$id]);

// Uptime % for 24h / 7d / 30d: raw checks for the recent window + daily rollups for older days (raw rows are pruned after the retention period)
function uptime_pct(int $wid, int $days): ?string
{
    $raw = DB::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('online','redirecting')),0) AS up FROM website_monitoring WHERE website_id = ? AND checked_at >= ?", [$wid, date('Y-m-d H:i:s', time() - $days * 86400)]);
    $roll = $days > 1 ? DB::fetch("SELECT COALESCE(SUM(checks),0) AS total, COALESCE(SUM(up_checks),0) AS up FROM website_uptime_daily WHERE website_id = ? AND day >= ? AND day < ?", [$wid, date('Y-m-d', time() - $days * 86400), date('Y-m-d', time() - max(0, (int) setting('retention_monitoring_days', 7) - 1) * 86400)]) : ['total' => 0, 'up' => 0];
    $total = (int) $raw['total'] + (int) $roll['total'];
    if (!$total) return null;
    return number_format(((int) $raw['up'] + (int) $roll['up']) / $total * 100, 2);
}
$up24 = uptime_pct($id, 1);
$up7 = uptime_pct($id, 7);
$up30 = uptime_pct($id, 30);
$downtime30 = (int) DB::value("SELECT COALESCE(SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))),0) FROM website_incidents WHERE website_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$id]);
$bar = array_reverse(DB::fetchAll("SELECT status FROM website_monitoring WHERE website_id = ? ORDER BY checked_at DESC LIMIT 60", [$id]));
$pagesFailedList = array_values(array_filter($pages, fn($p) => in_array($p['status'], explode(',', str_replace("'", '', down_statuses_sql())), true)));
$nextScan = $w['next_scan_at'] ?: ($w['last_scan_at'] ? date('Y-m-d H:i:s', strtotime($w['last_scan_at']) + $interval * 60) : null); // scheduler value (dispatcher sets it = last scan + plan interval)
$isWp = $w['technology'] === 'WordPress';

$pageTitle = $w['name'];
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => $w['name']]];
$pageSubtitle = '<a href="' . e($w['url']) . '" target="_blank" rel="noopener">' . e($w['url']) . ' <i class="bi bi-box-arrow-up-right small"></i></a> · Client: <a href="' . url('clients/view.php?id=' . $w['client_id']) . '">' . e($w['client_name']) . '</a> · ' . e($w['technology']);
$pageActions = '<a href="' . url('websites/analytics.php?id=' . $id) . '" class="btn btn-light btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Analytics</a><button class="btn btn-brand btn-sm btn-action" data-url="api/websites.php" data-params=\'{"action":"check","id":' . $id . '}\' data-loading="1" data-reload="1"><i class="bi bi-arrow-repeat me-1"></i>Check Now</button>
<button class="btn btn-brand btn-sm btn-action" data-url="api/websites.php" data-params=\'{"action":"scan_pages","id":' . $id . '}\' data-loading="1" data-reload="1" title="Check every monitored page now"><i class="bi bi-files me-1"></i>Scan All Pages</button>
<button class="btn btn-outline-primary btn-sm" data-open-modal="#formModal"><i class="bi bi-ui-checks me-1"></i>Add Form</button>
<button class="btn btn-dark btn-sm" id="editWebsiteBtn"><i class="bi bi-pencil me-1"></i>Edit</button>
<div class="dropdown d-inline-block"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">'
 . ($w['admin_url'] ? '<li><a class="dropdown-item" href="' . e($w['admin_url']) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-2"></i>Open Admin Panel</a></li>' : '')
 . implode('', array_map(fn($l) => '<li><a class="dropdown-item" href="' . e($l[2]) . '" target="_blank" rel="noopener"><i class="bi ' . $l[1] . ' me-2"></i>Open ' . e($l[0]) . '</a></li>', array_values(website_design_links($w))))
 . '<li><button class="dropdown-item btn-action" data-url="api/websites.php" data-params=\'{"action":"discover_pages","id":' . $id . '}\' data-loading="1" data-reload="1"><i class="bi bi-diagram-3 me-2"></i>Re-discover Pages (sitemap &amp; links)</button></li>'
 . '<li><button class="dropdown-item btn-action" data-url="api/websites.php" data-params=\'{"action":"check_ssl","id":' . $id . '}\' data-reload="1"><i class="bi bi-shield-check me-2"></i>Re-check SSL</button></li>'
 . '<li><button class="dropdown-item btn-action" data-url="api/forms.php" data-params=\'{"action":"test_website","website_id":' . $id . '}\' data-reload="1" data-confirm="Test all forms on this website now?" data-icon="question"><i class="bi bi-clipboard-check me-2"></i>Test All Forms</button></li>'
 . '<li><hr class="dropdown-divider"></li>'
 . '<li><button class="dropdown-item btn-action" data-url="api/websites.php" data-params=\'{"action":"toggle_monitoring","id":' . $id . '}\' data-reload="1"><i class="bi ' . ($w['monitoring_enabled'] ? 'bi-pause-circle' : 'bi-play-circle') . ' me-2"></i>' . ($w['monitoring_enabled'] ? 'Pause Monitoring' : 'Resume Monitoring') . '</button></li>'
 . '<li><button class="dropdown-item btn-action" data-url="api/websites.php" data-params=\'{"action":"toggle_page_monitoring","id":' . $id . '}\' data-reload="1"><i class="bi ' . ($w['page_monitoring_enabled'] ? 'bi-pause-circle' : 'bi-play-circle') . ' me-2"></i>' . ($w['page_monitoring_enabled'] ? 'Pause Page Monitoring' : 'Resume Page Monitoring') . '</button></li>'
 . ($isAdmin ? '<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger btn-action" data-url="api/websites.php" data-params=\'{"action":"delete","id":' . $id . '}\' data-confirm="Delete this website with all pages, forms and history?" data-confirm-btn="Delete"><i class="bi bi-trash me-2"></i>Delete Website</button></li>' : '')
 . '</ul></div>';
$useCharts = true;
$selectedWebsiteId = $id;
$selectedClientId = $w['client_id'];
include ROOT_PATH . '/includes/layout/header.php';
$monActive = $cronStatus['status'] === 'running';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon <?= in_array($w['status'], ['online', 'redirecting']) ? 'tint-success' : (in_array($w['status'], ['unknown', 'paused']) ? 'tint-secondary' : 'tint-danger') ?>"><i class="bi bi-activity"></i></div><div><div class="stat-value"><?= website_status_badge($w['status']) ?></div><div class="stat-label">HTTP <?= e($w['http_code'] ?: '—') ?> · <?= $w['response_time'] !== null ? (int) $w['response_time'] . ' ms' : '—' ?></div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><a href="?id=<?= $id ?>&tab=pages<?= $w['pages_failed'] ? '&filter=failed' : '' ?>" class="stat-card <?= $w['pages_failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon <?= $w['page_health'] === 'good' ? 'tint-success' : ($w['page_health'] === 'unknown' ? 'tint-secondary' : 'tint-danger') ?>"><i class="bi bi-file-earmark-text"></i></div><div><div class="stat-value"><?= (int) $w['pages_ok'] ?>/<?= (int) $w['pages_total'] ?></div><div class="stat-label">Pages working<?= $w['pages_failed'] ? ' · <span class="text-danger">' . (int) $w['pages_failed'] . ' failed</span>' : '' ?></div></div></a></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon <?= $w['ssl_status'] === 'valid' ? 'tint-success' : ($w['ssl_status'] === 'unknown' ? 'tint-secondary' : ($w['ssl_status'] === 'expiring_soon' ? 'tint-warning' : 'tint-danger')) ?>"><i class="bi bi-shield-lock"></i></div><div><div class="stat-value"><?= ssl_status_badge($w['ssl_status'], $w['ssl_days_left']) ?></div><div class="stat-label">Expires <?= format_date($w['ssl_expires_at']) ?></div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-graph-up"></i></div><div><div class="stat-value"><?= $up24 !== null ? $up24 . '%' : '—' ?></div><div class="stat-label">Uptime 24h</div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-graph-up"></i></div><div><div class="stat-value"><?= $up30 !== null ? $up30 . '%' : '—' ?></div><div class="stat-label">Uptime 30 days<?= $up7 !== null ? ' · 7d ' . $up7 . '%' : '' ?></div></div></div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="stat-card"><div class="stat-icon <?= $downtime30 ? 'tint-danger' : 'tint-success' ?>"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= duration_human($downtime30) ?></div><div class="stat-label">Downtime 30 days</div></div></div></div>
</div>

<?php if (!in_array($w['status'], ['online', 'redirecting', 'unknown', 'paused'], true)): ?>
<div class="alert alert-danger d-flex align-items-center gap-2"><i class="bi bi-exclamation-octagon-fill fs-5"></i><div><strong>Website is DOWN.</strong> Reason: <strong><?= e($w['failure_reason'] ?: strtoupper(str_replace('_', ' ', $w['status']))) ?></strong>. <?= e($w['error_message']) ?> · Last failed <?= e(time_ago($w['last_failed_at'])) ?>.</div></div>
<?php elseif ($w['status'] === 'redirecting'): ?>
<div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i><?= e($w['error_message']) ?></div>
<?php endif; ?>
<?php if ($pagesFailedList && in_array($w['status'], ['online', 'redirecting'], true)): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 py-2"><i class="bi bi-file-earmark-x-fill fs-5"></i><div><strong><?= count($pagesFailedList) ?> page<?= count($pagesFailedList) > 1 ? 's are' : ' is' ?> not working:</strong> <?= e(implode(', ', array_map(fn($p) => ($p['title'] ?: Monitor::pathLabel($p['path'])) . ' (HTTP ' . ($p['http_code'] ?: 'none') . ')', array_slice($pagesFailedList, 0, 6)))) ?><?= count($pagesFailedList) > 6 ? ' …' : '' ?> · <a href="?id=<?= $id ?>&tab=pages&filter=failed" class="alert-link">show failed pages</a></div></div>
<?php endif; ?>
<?php if (!$w['monitoring_enabled']): ?><div class="alert alert-warning py-2"><i class="bi bi-pause-circle me-1"></i>Monitoring is paused for this website.</div><?php elseif (!$w['page_monitoring_enabled']): ?><div class="alert alert-warning py-2"><i class="bi bi-pause-circle me-1"></i>Page-level monitoring is paused for this website (only the homepage is checked).</div><?php endif; ?>
<?php if ($w['ssl_error']): ?><div class="alert alert-warning py-2"><i class="bi bi-shield-exclamation me-1"></i>SSL: <?= e($w['ssl_error']) ?></div><?php endif; ?>
<?php if ($isWp && (!$w['wp_login_url'] || !$w['wp_username'] || !$w['wp_password'])): ?><div class="alert alert-warning py-2"><i class="bi bi-wordpress me-1"></i>WordPress login details are incomplete. <a href="#" id="editWebsiteLink" class="alert-link">Edit the website</a> to add the login URL, username and password (required for WordPress websites).</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-files me-1"></i>Website Health – all pages</span><span class="small fw-normal"><?= page_health_badge($w['page_health']) ?></span></div>
      <div class="card-body">
        <div class="health-summary">
          <div class="hs"><div class="lbl">Total Pages</div><div class="val"><?= (int) $w['pages_total'] ?></div></div>
          <a href="?id=<?= $id ?>&tab=pages&filter=working" class="hs hs-success"><div class="lbl">Working</div><div class="val text-success"><?= (int) $w['pages_ok'] ?></div></a>
          <a href="?id=<?= $id ?>&tab=pages&filter=failed" class="hs <?= $w['pages_failed'] ? 'hs-danger' : '' ?>" title="Show the failed pages"><div class="lbl">Failed</div><div class="val <?= $w['pages_failed'] ? 'text-danger' : '' ?>"><?= (int) $w['pages_failed'] ?></div></a>
          <div class="hs"><div class="lbl">Last Full Scan</div><div class="val" style="font-size:.9rem"><?= $w['last_scan_at'] ? format_datetime($w['last_scan_at']) : 'never' ?></div><div class="small-xs text-muted"><?= $w['last_scan_duration'] !== null ? 'took ' . round($w['last_scan_duration'] / 1000, 1) . ' s' : '' ?></div></div>
          <div class="hs"><div class="lbl">Next Scan</div><div class="val" style="font-size:.9rem"><?= $nextScan && $monActive && $w['monitoring_enabled'] && $w['page_monitoring_enabled'] ? (strtotime($nextScan) <= time() ? 'due now' : format_datetime($nextScan)) : '—' ?></div><div class="small-xs text-muted">every <?= $interval ?> min · engine <?= $monActive ? 'running' : 'NOT running' ?></div></div>
        </div>
        <div class="mt-2 small"><?= $w['pages_total'] ? ($w['pages_failed'] ? '<strong class="text-danger">' . ((int) $w['pages_total'] - (int) $w['pages_failed']) . ' / ' . (int) $w['pages_total'] . ' Pages Working – ' . (int) $w['pages_failed'] . ' Page' . ($w['pages_failed'] > 1 ? 's' : '') . ' Failed</strong>' : '<strong class="text-success">' . (int) $w['pages_total'] . ' / ' . (int) $w['pages_total'] . ' Pages Working</strong>') : '<span class="text-muted">No pages scanned yet – click <strong>Scan All Pages</strong> or wait for the next automatic run.</span>' ?>
          <span class="text-muted">· pages discovered <?= e(time_ago($w['pages_discovered_at'])) ?> (sitemap, WordPress sitemap and internal links; re-discovered every <?= (int) setting('page_discovery_hours', 24) ?> h) · limit <?= Monitor::maxPages($w) ?> pages</span></div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header p-0 border-0">
        <ul class="nav nav-tabs px-2">
          <li class="nav-item"><button class="nav-link <?= $tab === 'pages' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabPages" type="button">Pages (<?= count($pages) ?>)</button></li>
          <li class="nav-item"><button class="nav-link <?= $tab === 'forms' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabForms" type="button">Forms (<?= count($forms) ?>)</button></li>
          <li class="nav-item"><button class="nav-link <?= $tab === 'incidents' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabIncidents" type="button">Incidents (<?= count($incidents) + count($pageIncidents) ?>)</button></li>
          <li class="nav-item"><button class="nav-link <?= $tab === 'scans' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabScans" type="button">Scan History</button></li>
          <li class="nav-item"><button class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabHistory" type="button">Check History</button></li>
          <li class="nav-item"><button class="nav-link <?= $tab === 'ssl' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabSsl" type="button">SSL History</button></li>
        </ul>
      </div>
      <div class="card-body p-0 tab-content">
        <div class="tab-pane fade <?= $tab === 'pages' ? 'show active' : '' ?>" id="tabPages">
          <div class="d-flex flex-wrap align-items-center gap-2 p-2 border-bottom">
            <div class="btn-group btn-group-sm" role="group" id="pageFilter">
              <button type="button" class="btn btn-outline-secondary active" data-filter="all">All (<?= count($pages) ?>)</button>
              <button type="button" class="btn btn-outline-danger" data-filter="failed">Failed (<?= count($pagesFailedList) ?>)</button>
              <button type="button" class="btn btn-outline-success" data-filter="working">Working (<?= (int) $w['pages_ok'] ?>)</button>
            </div>
            <input type="search" class="form-control form-control-sm" style="max-width:220px" placeholder="Filter pages…" id="pageSearch">
            <span class="ms-auto"></span>
            <button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"discover_pages","id":<?= $id ?>}' data-loading="1" data-reload="1" title="Read sitemap.xml / internal links again"><i class="bi bi-diagram-3 me-1"></i>Discover Pages</button>
            <button class="btn btn-light btn-sm" data-bs-toggle="collapse" data-bs-target="#addPagesBox"><i class="bi bi-plus-lg me-1"></i>Add Pages</button>
            <button class="btn btn-brand btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"scan_pages","id":<?= $id ?>,"discover":0}' data-loading="1" data-reload="1"><i class="bi bi-arrow-repeat me-1"></i>Check All Pages Now</button>
          </div>
          <div class="collapse border-bottom" id="addPagesBox"><div class="p-3">
            <form class="ajax-form" action="<?= url('api/websites.php') ?>" data-reload="1" novalidate>
              <?= csrf_field() ?><input type="hidden" name="action" value="page_add"><input type="hidden" name="website_id" value="<?= $id ?>">
              <label class="form-label small">Page URLs or paths to monitor <span class="text-muted">(one per line, e.g. <code>/pricing/</code> or <code><?= e(rtrim($w['url'], '/')) ?>/blog/</code>)</span></label>
              <textarea name="urls" class="form-control form-control-sm mono" rows="3" required></textarea>
              <div class="mt-2"><button class="btn btn-dark btn-sm"><i class="bi bi-check2 me-1"></i>Add to monitoring</button> <span class="small text-muted ms-2">Manually added pages are always kept, even when they disappear from the sitemap.</span></div>
            </form>
          </div></div>
          <?php if (!$pages): ?><div class="empty-state"><i class="bi bi-files"></i>No pages discovered yet. Click <strong>Discover Pages</strong> (reads sitemap.xml / internal links) or add pages manually.</div>
          <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0" id="pagesTable">
            <thead><tr><th>Page</th><th>URL</th><th>Status</th><th>Response</th><th>Time</th><th>Last Checked</th><th>Last OK</th><th></th></tr></thead>
            <tbody><?php foreach ($pages as $p): $failed = in_array($p['status'], explode(',', str_replace("'", '', down_statuses_sql())), true); $ok = in_array($p['status'], ['online', 'redirecting'], true); ?>
              <tr class="page-row <?= $failed ? 'failed' : ($ok ? 'working' : 'unknown') ?>" data-row-id="<?= $p['id'] ?>" data-state="<?= $failed ? 'failed' : ($ok ? 'working' : 'unknown') ?>" data-text="<?= e(strtolower(($p['title'] ?: '') . ' ' . $p['path'])) ?>">
                <td><span class="fw-500"><?= e($p['title'] ?: Monitor::pathLabel($p['path'])) ?></span><div class="small-xs text-muted"><?= ['home' => 'Homepage', 'sitemap' => 'from sitemap', 'links' => 'from internal links', 'manual' => 'added manually'][$p['source']] ?? '' ?></div></td>
                <td><a href="<?= e(page_display_url($p['clean_url'] ?? null, $p['url'])) ?>" target="_blank" rel="noopener" class="small mono" title="<?= e($p['url']) ?>"><?= e(truncate((string) (parse_url(page_display_url($p['clean_url'] ?? null, $p['url']), PHP_URL_PATH) ?: '/') . (parse_url($p['url'], PHP_URL_QUERY) ? '?' . parse_url($p['url'], PHP_URL_QUERY) : ''), 42)) ?> <i class="bi bi-box-arrow-up-right"></i></a></td>
                <td><?= page_status_badge($p['status']) ?><?= $failed ? '<div class="small-xs text-danger">' . e($p['failure_reason'] ?: '') . '</div>' : '' ?></td>
                <td><span class="<?= $failed ? 'text-danger fw-500' : '' ?>"><?= e($p['http_code'] ?: '—') ?></span><?= $failed && $p['error_message'] ? '<div class="small-xs text-muted" title="' . e($p['error_message']) . '">' . e(truncate($p['error_message'], 40)) . '</div>' : '' ?></td>
                <td><?= $p['response_time'] !== null ? (int) $p['response_time'] . ' ms' : '—' ?></td>
                <td class="text-muted small"><?= e(time_ago($p['last_checked_at'])) ?></td>
                <td class="text-muted small"><?= e(time_ago($p['last_success_at'])) ?></td>
                <td class="row-actions text-nowrap"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"page_check","id":<?= $p['id'] ?>}' data-loading="1" data-reload="1" title="Check this page now"><i class="bi bi-arrow-repeat"></i></button><?php if ($p['source'] !== 'home'): ?> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/websites.php" data-params='{"action":"page_remove","id":<?= $p['id'] ?>}' data-confirm="Stop monitoring this page? It will not be re-added by automatic discovery." data-confirm-btn="Remove" data-reload="1" title="Remove from monitoring"><i class="bi bi-x-lg"></i></button><?php endif; ?></td>
              </tr>
            <?php endforeach; ?></tbody></table></div>
          <?php if ($removedPages): ?><div class="p-2 small text-muted border-top"><?= $removedPages ?> page(s) not monitored (removed or over the page limit). <a href="<?= url('websites/pages.php?website_id=' . $id . '&status=all') ?>">Manage on the Page Monitoring list</a>.</div><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="tab-pane fade <?= $tab === 'forms' ? 'show active' : '' ?>" id="tabForms">
          <div class="d-flex flex-wrap align-items-center gap-2 p-2 border-bottom small">
            <span class="text-muted"><i class="bi bi-radar me-1"></i>Forms are discovered automatically from every page of the website<?= $w['last_form_scan_at'] ? ' · last scan ' . e(time_ago($w['last_form_scan_at'])) : ' · not scanned yet' ?>.</span>
            <span class="ms-auto"></span>
            <a href="<?= url('websites/forms.php?id=' . $id) ?>" class="btn btn-light btn-sm"><i class="bi bi-list-check me-1"></i>Full Form Inventory</a>
            <?php if (Auth::can('monitor')): ?><button class="btn btn-brand btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"discover_forms","id":<?= $id ?>}' data-loading="1" data-reload="1"><i class="bi bi-search me-1"></i>Scan for Forms Now</button><?php endif; ?>
          </div>
          <?php if (!$forms): ?><div class="empty-state"><i class="bi bi-ui-checks"></i>No forms found yet. Run <strong>Scan for Forms Now</strong> (or wait for the cron) – or <a href="#" data-open-modal="#formModal">add a form manually</a>.</div>
          <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
            <thead><tr><th>Form</th><th>Type</th><th>Status</th><th>Last test</th><th>Result</th><th></th></tr></thead>
            <tbody><?php foreach ($forms as $f): ?>
              <tr data-row-id="<?= $f['id'] ?>"><td><span class="fw-500"><?= e($f['name']) ?></span><div class="small-xs"><a href="<?= e($f['page_url']) ?>" target="_blank" rel="noopener" class="text-muted"><?= e(truncate($f['page_url'], 50)) ?></a></div></td>
                <td><?= e($f['form_type']) ?></td><td><?= form_status_badge($f['status']) ?></td><td class="text-muted"><?= e(time_ago($f['last_tested_at'])) ?></td>
                <td class="small <?= $f['last_result'] === 'failed' ? 'text-danger' : ($f['last_result'] === 'blocked' ? 'text-info' : '') ?>"><?= e(truncate($f['last_error'] ?: ($f['last_result'] === 'success' ? 'Success' : '—'), 60)) ?></td>
                <td class="row-actions"><button class="btn btn-light btn-sm btn-action" data-url="api/forms.php" data-params='{"action":"test","id":<?= $f['id'] ?>}' data-loading="1" data-reload="1" title="Test now"><i class="bi bi-play-circle"></i></button> <a href="<?= url('forms/index.php?website_id=' . $id . '&highlight=' . $f['id']) ?>" class="btn btn-light btn-sm" title="Manage"><i class="bi bi-gear"></i></a></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="tab-pane fade <?= $tab === 'incidents' ? 'show active' : '' ?>" id="tabIncidents">
          <?php if (!$incidents && !$pageIncidents): ?><div class="empty-state"><i class="bi bi-emoji-smile"></i>No downtime incidents recorded</div>
          <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
            <thead><tr><th>Started</th><th>Resolved</th><th>Duration</th><th>What</th><th>Status</th><th>Reason</th><th>Checks</th><th>Emails</th></tr></thead>
            <tbody>
            <?php foreach ($incidents as $i): ?>
              <tr><td><?= format_datetime($i['started_at']) ?></td><td><?= $i['resolved_at'] ? format_datetime($i['resolved_at']) : '<span class="badge bg-danger-subtle text-danger">Ongoing</span>' ?></td>
                <td><?= duration_human($i['resolved_at'] ? (int) $i['duration_seconds'] : time() - strtotime($i['started_at'])) ?></td><td><span class="badge bg-danger-subtle text-danger">Website</span></td><td><?= website_status_badge($i['status']) ?></td><td class="small"><span class="fw-500 text-danger"><?= e($i['failure_reason'] ?: '—') ?></span><div class="text-muted small-xs"><?= e($i['error_message']) ?></div></td><td><?= (int) $i['failed_checks'] ?></td>
                <td class="small text-muted"><?= $i['alert_sent'] ? '<i class="bi bi-envelope-check text-success" title="Down alert sent"></i>' : '' ?> <?= $i['recovery_sent'] ? '<i class="bi bi-envelope-check text-success" title="Recovery sent"></i>' : '' ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($pageIncidents as $i): ?>
              <tr><td><?= format_datetime($i['started_at']) ?></td><td><?= $i['resolved_at'] ? format_datetime($i['resolved_at']) : '<span class="badge bg-danger-subtle text-danger">Ongoing</span>' ?></td>
                <td><?= duration_human($i['resolved_at'] ? (int) $i['duration_seconds'] : time() - strtotime($i['started_at'])) ?></td><td><span class="badge bg-warning-subtle text-warning">Page</span> <span class="small"><?= e($i['title'] ?: Monitor::pathLabel($i['path'])) ?></span><div class="small-xs text-muted"><?= e($i['path']) ?></div></td><td>HTTP <?= e($i['status_code'] ?: 'none') ?></td><td class="small"><span class="fw-500 text-danger"><?= e($i['failure_reason'] ?: '—') ?></span><div class="text-muted small-xs"><?= e($i['error_message']) ?></div></td><td><?= (int) $i['failed_checks'] ?></td>
                <td class="small text-muted"><?= $i['alert_sent'] ? '<i class="bi bi-envelope-check text-success" title="Page alert sent"></i>' : '<span title="No email: the whole website was down (covered by the website alert)">—</span>' ?> <?= $i['recovery_sent'] ? '<i class="bi bi-envelope-check text-success" title="Recovery sent"></i>' : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div>
        <div class="tab-pane fade <?= $tab === 'scans' ? 'show active' : '' ?>" id="tabScans">
          <?php if (!$scans): ?><div class="empty-state"><i class="bi bi-files"></i>No page scans recorded yet</div>
          <?php else: ?><div class="table-responsive" style="max-height:420px"><table class="table table-hover table-compact mb-0">
            <thead><tr><th>Scanned</th><th>Health</th><th>Pages</th><th>Working</th><th>Failed</th><th>Duration</th><th>Failed pages</th></tr></thead>
            <tbody><?php foreach ($scans as $s): $fl = $s['failed_pages'] ? json_decode($s['failed_pages'], true) : []; ?>
              <tr><td><?= format_datetime($s['scanned_at']) ?></td><td><?= page_health_badge($s['health']) ?></td><td><?= (int) $s['pages_total'] ?></td><td class="text-success"><?= (int) $s['pages_ok'] ?></td><td class="<?= $s['pages_failed'] ? 'text-danger fw-500' : '' ?>"><?= (int) $s['pages_failed'] ?></td><td class="small text-muted"><?= $s['duration_ms'] !== null ? round($s['duration_ms'] / 1000, 1) . ' s' : '—' ?></td>
                <td class="small-xs text-danger"><?= $fl ? e(implode(', ', array_map(fn($f) => $f['title'] . ' (' . ($f['http_code'] ?: 'no response') . ($f['reason'] ? ' – ' . $f['reason'] : '') . ')', $fl))) : '<span class="text-muted">—</span>' ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="tab-pane fade <?= $tab === 'history' ? 'show active' : '' ?>" id="tabHistory">
          <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between small text-muted mb-1"><span>Response time &amp; status (last 7 days)</span><span>Last <?= count($bar) ?> checks:</span></div>
            <div class="uptime-bar mb-3" title="Most recent checks (right = latest)">
              <?php if (!$bar): ?><span></span><?php endif; ?>
              <?php foreach ($bar as $b): ?><span class="<?= in_array($b['status'], ['online'], true) ? 'up' : ($b['status'] === 'redirecting' ? 'warn' : 'down') ?>" title="<?= e($b['status']) ?>"></span><?php endforeach; ?>
            </div>
            <?php if (count($chart) < 2): ?><div class="empty-state py-2"><i class="bi bi-graph-up"></i>Not enough data yet. The chart fills in as monitoring runs.</div>
            <?php else: ?><div style="height:200px"><canvas id="rtChart"></canvas></div><?php endif; ?>
          </div>
          <?php if (!$history): ?><div class="empty-state"><i class="bi bi-clock-history"></i>No checks recorded yet</div>
          <?php else: ?><div class="table-responsive" style="max-height:420px"><table class="table table-hover table-compact mb-0">
            <thead><tr><th>Checked</th><th>Status</th><th>HTTP</th><th>Response</th><th>Message</th></tr></thead>
            <tbody><?php foreach ($history as $h): ?>
              <tr><td><?= format_datetime($h['checked_at']) ?></td><td><?= website_status_badge($h['status']) ?></td><td><?= e($h['http_code'] ?: '—') ?></td><td><?= $h['response_time'] !== null ? (int) $h['response_time'] . ' ms' : '—' ?></td><td class="small text-muted"><?= e($h['error_message']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="tab-pane fade <?= $tab === 'ssl' ? 'show active' : '' ?>" id="tabSsl">
          <?php if (!$sslHistory): ?><div class="empty-state"><i class="bi bi-shield"></i>No SSL checks yet</div>
          <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
            <thead><tr><th>Checked</th><th>Status</th><th>Expiry</th><th>Days</th><th>Issuer</th><th>Error</th></tr></thead>
            <tbody><?php foreach ($sslHistory as $s): ?>
              <tr><td><?= format_datetime($s['checked_at']) ?></td><td><?= ssl_status_badge($s['status']) ?></td><td><?= format_date($s['expiry_date']) ?></td><td><?= $s['days_remaining'] ?? '—' ?></td><td class="small"><?= e($s['issuer']) ?></td><td class="small text-danger"><?= e($s['error_message']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <?php $nextFormScan = FormDiscovery::nextScanAt($w); ?>
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-ui-checks me-1"></i>Form Monitoring</span><a href="<?= url('websites/forms.php?id=' . $id) ?>" class="small fw-normal">Form inventory</a></div>
      <div class="card-body">
        <div class="row g-2 mb-2">
          <div class="col-6"><a href="<?= url('websites/forms.php?id=' . $id) ?>" class="info-box d-block text-reset"><div class="lbl">Total Forms</div><div class="val"><?= (int) $w['forms_total'] ?></div></a></div>
          <div class="col-6"><div class="info-box"><div class="lbl">Working</div><div class="val text-success"><?= (int) $w['forms_working'] ?></div></div></div>
          <div class="col-6"><div class="info-box"><div class="lbl">Failed</div><div class="val <?= $w['forms_failed'] ? 'text-danger' : '' ?>"><?= (int) $w['forms_failed'] ?></div></div></div>
          <div class="col-6"><div class="info-box"><div class="lbl">CAPTCHA Blocked</div><div class="val <?= $w['forms_blocked'] ? 'text-info' : '' ?>"><?= (int) $w['forms_blocked'] ?></div></div></div>
        </div>
        <dl class="dl-grid mb-0">
          <dt>Normal / Popup / AJAX</dt><dd><?= (int) $w['forms_normal'] ?> / <?= (int) $w['forms_popup'] ?> / <?= (int) $w['forms_ajax'] ?></dd>
          <dt>Last form scan</dt><dd><?= $w['last_form_scan_at'] ? format_datetime($w['last_form_scan_at']) : '<span class="text-warning">Not scanned yet</span>' ?></dd>
          <dt>Next form scan</dt><dd><?= $nextFormScan === 'at the next cron run' ? 'Next cron run' : ($nextFormScan ? format_datetime($nextFormScan) : '—') ?></dd>
          <dt>Discovery</dt><dd><?= $w['form_discovery_enabled'] ? '<span class="text-success">Automatic</span>' : '<span class="text-secondary">Disabled</span>' ?> · tests every <?= Tenant::interval('form', !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null) ?> min (plan)</dd>
        </dl>
        <?php if (Auth::can('monitor')): ?><div class="mt-2"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"discover_forms","id":<?= $id ?>}' data-loading="1" data-reload="1"><i class="bi bi-search me-1"></i>Scan for Forms Now</button></div><?php endif; ?>
      </div>
    </div>
    <?php
    // ---- Monitoring schedule (v3.9): plan-driven cadence, last / next check, current problem, notification state
    $tenantIdOfSite = !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null;
    $planRow = Tenant::plan($tenantIdOfSite);
    $ivWeb = Tenant::interval('website', $tenantIdOfSite); $ivForm = Tenant::interval('form', $tenantIdOfSite); $ivSsl = Tenant::interval('ssl', $tenantIdOfSite); $ivPage = Tenant::interval('page', $tenantIdOfSite);
    $openAlert = DB::fetch("SELECT * FROM alerts WHERE website_id = ? AND status = 'open' ORDER BY detected_at DESC LIMIT 1", [$id]);
    $lastRecovered = DB::fetch("SELECT * FROM alerts WHERE website_id = ? AND status = 'recovered' ORDER BY recovered_at DESC LIMIT 1", [$id]);
    $schedState = Scheduler::state();
    $engineLive = !empty($schedState['healthy']);
    $nextCheck = $w['next_check_at'] ?? null;
    $nextIn = $nextCheck ? strtotime($nextCheck) - time() : null;
    $siteUp = in_array($w['status'], ['online', 'redirecting'], true);
    ?>
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-alarm me-1"></i>Monitoring schedule</span><span class="small fw-normal text-muted">plan <strong><?= e($planRow['name'] ?? 'Free') ?></strong><?= !empty($planRow['expired_from']) ? ' (trial of ' . e($planRow['expired_from']) . ' ended)' : '' ?></span></div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>Status</dt><dd><?= !$w['monitoring_enabled'] ? '<span class="text-warning">⏸ Monitoring paused</span>' : ($w['status'] === 'unknown' ? '<span class="text-muted">⚪ Not checked yet</span>' : ($siteUp ? '<span class="text-success">🟢 Working</span>' : '<span class="text-danger">🔴 ' . e(ucwords(str_replace('_', ' ', $w['status']))) . '</span>')) ?></dd>
          <dt>Interval</dt><dd>every <strong><?= $ivWeb ?> min</strong> <span class="text-muted small">· forms <?= $ivForm ?> min · SSL <?= $ivSsl ?> min · pages <?= $ivPage ?> min (from the plan)</span></dd>
          <dt>Last check</dt><dd><?= $w['last_checked_at'] ? format_datetime($w['last_checked_at']) . ' <span class="text-muted small">(' . e(time_ago($w['last_checked_at'])) . ')</span>' : '—' ?></dd>
          <dt>Next check</dt><dd><?= !$w['monitoring_enabled'] ? '<span class="text-muted">paused</span>' : ($nextCheck ? ($nextIn <= 0 ? '<span class="text-warning">due now' . ($engineLive ? '' : ' – waiting for the engine') . '</span>' : format_datetime($nextCheck) . ' <span class="text-muted small">(in ' . e(human_seconds($nextIn)) . ')</span>') : '—') ?></dd>
          <dt>Last success</dt><dd><?= $w['last_success_at'] ? format_datetime($w['last_success_at']) : '—' ?></dd>
          <dt>Last failure</dt><dd><?= $w['last_failed_at'] ? format_datetime($w['last_failed_at']) : '<span class="text-muted">never</span>' ?></dd>
          <dt>Current error</dt><dd><?= $openAlert ? '<span class="text-danger fw-500">' . e($openAlert['error_type'] ?: $openAlert['title']) . '</span>' . ($openAlert['error_message'] ? '<div class="small text-muted">' . e(truncate($openAlert['error_message'], 120)) . '</div>' : '') . '<div class="small-xs text-muted">since ' . e(time_ago($openAlert['detected_at'])) . ' · ' . (int) $openAlert['checks_while_failing'] . ' failing check' . ((int) $openAlert['checks_while_failing'] === 1 ? '' : 's') . '</div>' : '<span class="text-muted">none</span>' ?></dd>
          <dt>Notification</dt><dd><?= $openAlert ? ($openAlert['notified_at'] ? '<span class="text-success">alert sent ' . e(time_ago($openAlert['notified_at'])) . '</span> · <span class="text-muted">no repeat emails while it keeps failing</span>' : '<span class="text-muted">alert not sent</span>') : ($lastRecovered && $lastRecovered['recovery_notified_at'] ? '<span class="text-success">recovery sent ' . e(time_ago($lastRecovered['recovery_notified_at'])) . '</span>' : '<span class="text-muted">nothing to notify – successful checks are silent</span>') ?></dd>
          <dt>Last recovery</dt><dd><?= $lastRecovered ? format_datetime($lastRecovered['recovered_at']) . ' <span class="text-muted small">after ' . e(duration_human((int) $lastRecovered['downtime_seconds'])) . '</span>' : '<span class="text-muted">—</span>' ?></dd>
          <dt>Engine</dt><dd><?= $engineLive ? '<span class="text-success">🟢 running server-side</span>' : '<span class="text-danger">🔴 ' . e($schedState['message'] ?? 'not running') . '</span>' ?> <a href="<?= url('incidents/alerts.php?website_id=' . $id) ?>" class="small">alert history</a></dd>
        </dl>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Website Details</div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>URL</dt><dd><a href="<?= e($w['url']) ?>" target="_blank" rel="noopener"><?= e($w['url']) ?></a></dd>
          <dt>Admin URL</dt><dd><?= $w['admin_url'] ? '<a href="' . e($w['admin_url']) . '" target="_blank" rel="noopener">' . e(truncate($w['admin_url'], 40)) . '</a>' : '—' ?></dd>
          <dt>Technology</dt><dd><?= e($w['technology']) ?></dd>
          <dt>Client</dt><dd><a href="<?= url('clients/view.php?id=' . $w['client_id']) ?>"><?= e($w['client_name']) ?></a></dd>
          <dt>Monitoring</dt><dd><?= $w['monitoring_enabled'] ? '<span class="text-success">Enabled</span>' : '<span class="text-warning">Paused</span>' ?> · pages <?= $w['page_monitoring_enabled'] ? '<span class="text-success">on</span>' : '<span class="text-warning">off</span>' ?></dd>
          <dt>Last checked</dt><dd><?= format_datetime($w['last_checked_at']) ?></dd>
          <dt>Last success</dt><dd><?= format_datetime($w['last_success_at']) ?></dd>
          <dt>Last failure</dt><dd><?= format_datetime($w['last_failed_at']) ?></dd>
          <dt>Last page scan</dt><dd><?= format_datetime($w['last_scan_at']) ?></dd>
          <dt>Hosting login</dt><dd><?= e($w['hosting_login_ref'] ?: '—') ?></dd>
          <dt>Added</dt><dd><?= format_date($w['created_at']) ?></dd>
          <?php if ($w['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($w['notes'])) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php $designLinks = website_design_links($w); ?>
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-vector-pen me-1"></i>Design &amp; Reference Links</span><a href="#" class="small fw-normal" id="editWebsiteLinkDesign">Edit</a></div>
      <div class="card-body">
        <?php if (!$designLinks): ?><div class="text-muted small">No design / reference URLs added yet. Add Figma, Adobe XD, HTML demo or other reference links via <strong>Edit → Design &amp; References</strong>.</div>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach ($designLinks as $k => [$label, $icon, $href]): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi <?= $icon ?> me-1"></i>Open <?= e($label) ?> <i class="bi bi-box-arrow-up-right small ms-1"></i></a><?php endforeach; ?>
        </div>
        <dl class="dl-grid mb-0">
          <?php foreach ([['figma_url', 'Figma'], ['xd_url', 'Adobe XD'], ['demo_url', 'HTML Demo'], ['reference_url', 'Reference']] as [$k, $label]): ?>
            <dt><?= $label ?></dt><dd><?= $w[$k] ? '<a href="' . e($w[$k]) . '" target="_blank" rel="noopener" title="' . e($w[$k]) . '">' . e(truncate($w[$k], 48)) . '</a>' : '<span class="text-muted">—</span>' ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($isWp || $w['wp_login_url'] || $w['wp_username']): ?>
    <div class="card mb-3">
      <div class="card-header"><span><i class="bi bi-wordpress me-1"></i>WordPress Login</span><a href="#" class="small fw-normal" id="editWebsiteLink2">Edit</a></div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>WordPress URL</dt><dd><?= $w['wp_login_url'] ? '<a href="' . e($w['wp_login_url']) . '" target="_blank" rel="noopener">' . e($w['wp_login_url']) . ' <i class="bi bi-box-arrow-up-right small"></i></a>' : '<span class="text-danger">missing</span>' ?></dd>
          <dt>Username</dt><dd><?= $w['wp_username'] ? '<span class="mono">' . e($w['wp_username']) . '</span> <i class="bi bi-clipboard copy-btn text-muted cursor-pointer" data-copy="' . e($w['wp_username']) . '" title="Copy"></i>' : '<span class="text-danger">missing</span>' ?></dd>
          <dt>Password</dt><dd><?php if (!$w['wp_password']): ?><span class="text-danger">missing</span>
            <?php elseif ($isAdmin): ?><span class="secret" data-secret="wp" data-id="<?= $id ?>"><?= password_mask() ?></span> <button class="btn btn-light btn-sm btn-reveal ms-1" data-secret="wp" data-id="<?= $id ?>" title="Show / hide"><i class="bi bi-eye"></i></button> <button class="btn btn-light btn-sm btn-copy-secret" data-secret="wp" data-id="<?= $id ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
            <?php else: ?><span class="text-muted"><i class="bi bi-lock me-1"></i>Admin only</span><?php endif; ?></dd>
        </dl>
      </div>
    </div>
    <?php endif; ?>
    <div class="card mb-3">
      <div class="card-header">SSL Certificate</div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>Status</dt><dd><?= ssl_status_badge($w['ssl_status']) ?></dd>
          <dt>Expiry</dt><dd><?= format_datetime($w['ssl_expires_at']) ?></dd>
          <dt>Days left</dt><dd><?= $w['ssl_days_left'] !== null ? (int) $w['ssl_days_left'] : '—' ?></dd>
          <dt>Issuer</dt><dd><?= e($w['ssl_issuer'] ?: '—') ?></dd>
          <dt>Checked</dt><dd><?= e(time_ago($w['ssl_checked_at'])) ?></dd>
          <?php if ($w['ssl_error']): ?><dt>Error</dt><dd class="text-danger"><?= e($w['ssl_error']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><span>Domain</span><a href="<?= url('domains/index.php' . ($domain ? '?highlight=' . $domain['id'] : '?add=1&website_id=' . $id)) ?>" class="small fw-normal"><?= $domain ? 'Manage' : 'Add' ?></a></div>
      <div class="card-body">
        <?php if (!$domain): ?><div class="text-muted small">No domain record. Edit the website to add domain details.</div>
        <?php else: ?><dl class="dl-grid mb-0">
          <dt>Domain</dt><dd><?= e($domain['domain_name']) ?></dd>
          <dt>Provider</dt><dd><?= e($domain['registrar'] ?: '—') ?></dd>
          <dt>Registered</dt><dd><?= format_date($domain['registration_date']) ?></dd>
          <dt>Expiry</dt><dd><?= format_date($domain['expiry_date']) ?> <?= expiry_badge($domain['expiry_date']) ?></dd>
          <dt>Auto-renew</dt><dd><?= $domain['auto_renew'] ? 'Yes' : 'No' ?></dd>
          <?php if ($domain['login_ref']): ?><dt>Login ref</dt><dd><?= e($domain['login_ref']) ?></dd><?php endif; ?>
        </dl><?php endif; ?>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><span>Hosting</span><a href="<?= url('hosting/index.php' . ($hosting ? '?highlight=' . $hosting['id'] : '?add=1&website_id=' . $id)) ?>" class="small fw-normal"><?= $hosting ? 'Manage' : 'Add' ?></a></div>
      <div class="card-body">
        <?php if (!$hosting): ?><div class="text-muted small">No hosting record. Edit the website to add hosting details.</div>
        <?php else: ?><dl class="dl-grid mb-0">
          <dt>Provider</dt><dd><?= e($hosting['provider']) ?></dd>
          <dt>Server / IP</dt><dd><?= e($hosting['server_ip'] ?: '—') ?></dd>
          <dt>Plan</dt><dd><?= e($hosting['plan'] ?: '—') ?></dd>
          <dt>Login / ref</dt><dd><?= e($hosting['login_ref'] ?: '—') ?></dd>
          <dt>Start</dt><dd><?= format_date($hosting['start_date']) ?></dd>
          <dt>Expiry</dt><dd><?= format_date($hosting['expiry_date']) ?> <?= expiry_badge($hosting['expiry_date']) ?></dd>
          <dt>Renewal</dt><dd><?= e(ucfirst($hosting['renewal_status'])) ?></dd>
        </dl><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Activity</div>
      <div class="card-body" style="max-height:420px;overflow-y:auto">
        <?php if (!$activity): ?><div class="empty-state py-3"><i class="bi bi-activity"></i>No activity yet</div>
        <?php else: ?><ul class="timeline"><?php foreach ($activity as $a): ?>
          <li><span class="tl-icon"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><div class="tl-title"><?= e($a['description']) ?></div><div class="tl-meta"><?= e($a['user_name'] ?? 'System') ?> · <?= e(time_ago($a['created_at'])) ?></div></li>
        <?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
include ROOT_PATH . '/includes/partials/website-modal.php';
include ROOT_PATH . '/includes/partials/form-modal.php';
$chartJson = json_encode(array_map(fn($c) => ['t' => date('d M H:i', strtotime($c['checked_at'])), 'v' => (int) $c['response_time'], 'up' => in_array($c['status'], ['online', 'redirecting'], true)], $chart));
$initialFilter = json_encode(in_array(get('filter'), ['failed', 'working'], true) ? get('filter') : 'all');
$pageScripts = <<<JS
<script>
$('#editWebsiteBtn, #editWebsiteLink, #editWebsiteLink2, #editWebsiteLinkDesign').on('click', function (e) { e.preventDefault(); CRM.post('api/websites.php', { action: 'get', id: $id }).done(res => { if (res.success) fillWebsiteModal(res.website); }); });
// Page list filter (All / Failed / Working + text)
(function () {
  let state = $initialFilter;
  function apply() {
    const q = ($('#pageSearch').val() || '').toLowerCase();
    $('#pagesTable tbody tr').each(function () {
      const okState = state === 'all' || $(this).data('state') === state;
      const okText = !q || String($(this).data('text')).indexOf(q) > -1;
      $(this).toggle(okState && okText);
    });
    $('#pageFilter button').removeClass('active').filter('[data-filter="' + state + '"]').addClass('active');
  }
  $('#pageFilter').on('click', 'button', function () { state = $(this).data('filter'); apply(); });
  $('#pageSearch').on('input', apply);
  apply();
})();
// Reveal / copy WordPress password (admin) – fetched on demand, never embedded in the page
const secretCache = {};
function fetchSecret(kind, id) {
  const key = kind + ':' + id;
  if (secretCache[key]) return $.Deferred().resolve(secretCache[key]).promise();
  return CRM.post('api/websites.php', { action: 'wp_reveal', id: id }).then(res => { if (!res.success) { CRM.toast(res.message || 'Could not reveal', 'error'); return $.Deferred().reject(); } secretCache[key] = res.password; return res.password; });
}
$(document).on('click', '.btn-reveal', function () {
  const \$b = $(this), id = \$b.data('id'), \$s = $('.secret[data-id="' + id + '"]');
  if (\$s.hasClass('revealed')) { \$s.removeClass('revealed').text('••••••••••'); \$b.find('i').attr('class', 'bi bi-eye'); return; }
  fetchSecret('wp', id).done(pw => { \$s.addClass('revealed').text(pw); \$b.find('i').attr('class', 'bi bi-eye-slash'); setTimeout(() => { if (\$s.hasClass('revealed')) { \$s.removeClass('revealed').text('••••••••••'); \$b.find('i').attr('class', 'bi bi-eye'); } }, 60000); });
});
$(document).on('click', '.btn-copy-secret', function () { fetchSecret('wp', $(this).data('id')).done(pw => { navigator.clipboard && navigator.clipboard.writeText(pw).then(() => CRM.toast('Password copied', 'info')); }); });
(function () {
  const data = $chartJson;
  const el = document.getElementById('rtChart');
  if (!el || !data.length) return;
  new Chart(el, {
    type: 'line',
    data: { labels: data.map(d => d.t), datasets: [{ label: 'Response time (ms)', data: data.map(d => d.up ? d.v : null), borderColor: '#FCAF17', backgroundColor: 'rgba(252,175,23,.18)', fill: true, tension: .3, pointRadius: 2, spanGaps: false },
      { label: 'Down', data: data.map(d => d.up ? null : 0), borderColor: '#ef4444', backgroundColor: '#ef4444', pointRadius: 5, pointStyle: 'rectRot', showLine: false }] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: true, labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { x: { ticks: { maxTicksLimit: 8, font: { size: 10 } }, grid: { display: false } }, y: { beginAtZero: true, ticks: { font: { size: 10 } } } } }
  });
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
