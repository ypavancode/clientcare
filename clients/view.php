<?php
/**
 * CLIENT CONTROL CENTRE – everything about one client on a single page:
 * client information, websites (with page-level health), WordPress logins, other login credentials (admin only),
 * domains, hosting, SSL, monitoring (website / page / form health + history), forms, alerts and activity.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$id = (int) get('id', 0);
$client = DB::fetch("SELECT c.*, u.name AS assigned_name FROM clients c LEFT JOIN users u ON u.id = c.assigned_user_id WHERE c.id = ? AND c.tenant_id = ?", [$id, Tenant::id()]);
if (!$client) http_error(404, 'Client not found.');
$isAdmin = Auth::isAdmin();
$interval = Tenant::interval('website');      // plan-driven cadence (v3.9)
$pageInterval = Tenant::interval('page');

$websites = DB::fetchAll("SELECT w.*,
    (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id) AS form_count,
    (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status = 'failed') AS failed_forms
    FROM websites w WHERE w.client_id = ? ORDER BY w.status, w.name LIMIT 200", [$id]);
$websiteTotal = (int) DB::value("SELECT COUNT(*) FROM websites WHERE client_id = ?", [$id]);
$websiteIds = array_map(fn($w) => (int) $w['id'], $websites);
$domains = DB::fetchAll("SELECT d.*, w.name AS website_name FROM domains d LEFT JOIN websites w ON w.id = d.website_id WHERE d.client_id = ? ORDER BY d.expiry_date IS NULL, d.expiry_date", [$id]);
$hosting = DB::fetchAll("SELECT h.*, w.name AS website_name FROM hosting h LEFT JOIN websites w ON w.id = h.website_id WHERE h.client_id = ? ORDER BY h.expiry_date IS NULL, h.expiry_date", [$id]);
$credentials = $isAdmin ? DB::fetchAll("SELECT cr.*, w.name AS website_name FROM client_credentials cr LEFT JOIN websites w ON w.id = cr.website_id WHERE cr.client_id = ? ORDER BY FIELD(cr.type,'wordpress','domain','hosting','other','cpanel','ftp','email','database'), cr.label", [$id]) : [];
$forms = DB::fetchAll("SELECT f.*, w.name AS website_name FROM forms f JOIN websites w ON w.id = f.website_id WHERE w.client_id = ? ORDER BY f.status, w.name, f.name LIMIT 200", [$id]);
$formStats = DB::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(f.status='working'),0) AS working, COALESCE(SUM(f.status='failed'),0) AS failed, COALESCE(SUM(f.status='not_tested'),0) AS not_tested FROM forms f JOIN websites w ON w.id = f.website_id WHERE w.client_id = ?", [$id]);
$siteStats = DB::fetch("SELECT COALESCE(SUM(status IN (" . down_statuses_sql() . ")),0) AS down, COALESCE(SUM(status IN ('online','redirecting')),0) AS online,
    COALESCE(SUM(pages_total),0) AS pages_total, COALESCE(SUM(pages_ok),0) AS pages_ok, COALESCE(SUM(pages_failed),0) AS pages_failed, MAX(last_scan_at) AS last_scan, MAX(last_checked_at) AS last_check, MIN(IF(monitoring_enabled = 1 AND page_monitoring_enabled = 1, next_scan_at, NULL)) AS next_scan,
    COALESCE(SUM(ssl_status IN ('expiring_soon','expired','error')),0) AS ssl_issues FROM websites WHERE client_id = ?", [$id]);
$down = (int) $siteStats['down']; $online = (int) $siteStats['online'];
$failedPages = $websiteIds ? DB::fetchAll("SELECT p.*, w.name AS website_name FROM website_pages p JOIN websites w ON w.id = p.website_id WHERE p.website_id IN (" . implode(',', $websiteIds) . ") AND p.is_active = 1 AND p.status IN (" . down_statuses_sql() . ") ORDER BY p.last_failed_at DESC LIMIT 100") : [];
$scans = $websiteIds ? DB::fetchAll("SELECT s.*, w.name AS website_name FROM website_scans s JOIN websites w ON w.id = s.website_id WHERE s.website_id IN (" . implode(',', $websiteIds) . ") ORDER BY s.id DESC LIMIT 40") : [];
$pageIncidents = $websiteIds ? DB::fetchAll("SELECT i.*, p.title, p.path, p.url, w.name AS website_name FROM page_incidents i JOIN website_pages p ON p.id = i.page_id JOIN websites w ON w.id = i.website_id WHERE i.website_id IN (" . implode(',', $websiteIds) . ") ORDER BY i.id DESC LIMIT 30") : [];
$siteIncidents = $websiteIds ? DB::fetchAll("SELECT i.*, w.name AS website_name FROM website_incidents i JOIN websites w ON w.id = i.website_id WHERE i.website_id IN (" . implode(',', $websiteIds) . ") ORDER BY i.id DESC LIMIT 20") : [];
$activity = DB::fetchAll("SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.client_id = ? ORDER BY a.id DESC LIMIT 60", [$id]);
$notifications = DB::fetchAll("SELECT * FROM notifications WHERE client_id = ? ORDER BY id DESC LIMIT 30", [$id]);
$projects = DB::fetchAll("SELECT p.*, d.name AS department_name, t.name AS type_name, u.name AS assigned_name, w.monitoring_enabled, w.id AS wid FROM website_projects p LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id LEFT JOIN users u ON u.id = p.assigned_user_id LEFT JOIN websites w ON w.id = p.website_id WHERE p.client_id = ? ORDER BY p.status, p.updated_at DESC", [$id]);
$wpSites = array_values(array_filter($websites, fn($w) => $w['technology'] === 'WordPress' || $w['wp_login_url'] || $w['wp_username']));

$daysCell = function (?string $date): string {
    $days = days_until($date);
    if ($days === null) return '<span class="text-muted">—</span>';
    $cls = $days < 0 ? 'text-danger' : ($days <= 10 ? 'text-danger' : ($days <= 30 ? 'text-warning' : 'text-success'));
    return '<span class="fw-500 ' . $cls . '">' . ($days < 0 ? 'Expired ' . abs($days) . ' days ago' : $days . ' days') . '</span>';
};
$nextScan = $siteStats['next_scan'] ?: ($siteStats['last_scan'] ? date('Y-m-d H:i:s', strtotime($siteStats['last_scan']) + $pageInterval * 60) : null); // scheduler value (dispatcher sets it = last scan + plan interval)

$pageTitle = $client['name'];
$breadcrumbs = [['label' => 'Clients', 'url' => 'clients/index.php'], ['label' => $client['name']]];
$pageSubtitle = ($client['company'] ? e($client['company']) . ' · ' : '') . client_status_badge($client['status']) . (!$client['monitoring_enabled'] ? ' <span class="badge bg-secondary-subtle text-secondary">Monitoring disabled</span>' : '') . ' <span class="text-muted">· Client control centre</span>';
$pageActions = '<button class="btn btn-outline-primary btn-sm" data-open-modal="#websiteModal"><i class="bi bi-globe2 me-1"></i>Add Website</button>'
 . ($isAdmin ? '<button class="btn btn-outline-primary btn-sm" id="addCredBtn"><i class="bi bi-key me-1"></i>Add Login</button>' : '')
 . '<button class="btn btn-dark btn-sm" id="editClientBtn"><i class="bi bi-pencil me-1"></i>Edit Client</button>
<div class="dropdown d-inline-block"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
<ul class="dropdown-menu dropdown-menu-end">'
 . ($client['status'] !== 'active' ? '<li><button class="dropdown-item btn-action" data-url="api/clients.php" data-params=\'{"action":"status","id":' . $id . ',"status":"active"}\' data-reload="1"><i class="bi bi-check-circle me-2 text-success"></i>Mark Active</button></li>' : '')
 . ($client['status'] !== 'inactive' ? '<li><button class="dropdown-item btn-action" data-url="api/clients.php" data-params=\'{"action":"status","id":' . $id . ',"status":"inactive","disable_monitoring":1}\' data-confirm="Mark this client inactive and pause monitoring? History is preserved." data-icon="question" data-reload="1"><i class="bi bi-pause-circle me-2 text-warning"></i>Mark Inactive</button></li>' : '')
 . ($client['status'] !== 'archived' ? '<li><button class="dropdown-item btn-action" data-url="api/clients.php" data-params=\'{"action":"status","id":' . $id . ',"status":"archived","disable_monitoring":1}\' data-confirm="Archive this client? Monitoring stops, but all websites, forms, logins and history are kept." data-icon="question" data-reload="1"><i class="bi bi-archive me-2"></i>Archive Client</button></li>' : '')
 . ($isAdmin ? '<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger btn-action" data-url="api/clients.php" data-params=\'{"action":"delete","id":' . $id . '}\' data-confirm="This permanently deletes the client AND all websites, pages, forms, login details, notifications and monitoring history. This cannot be undone." data-confirm-btn="Delete permanently"><i class="bi bi-trash me-2"></i>Delete Permanently</button></li>' : '')
 . '</ul></div>';
$selectedClientId = $id;
include ROOT_PATH . '/includes/layout/header.php';
$monActive = $cronStatus['status'] === 'running';
$nextScanText = $nextScan && $monActive ? (strtotime($nextScan) <= time() ? 'due now' : format_datetime($nextScan)) : '—';
?>
<nav class="cc-nav">
  <a href="#sec-info"><i class="bi bi-person me-1"></i>Client</a>
  <a href="#sec-websites"><i class="bi bi-globe2 me-1"></i>Websites <span class="badge bg-secondary-subtle text-secondary"><?= $websiteTotal ?></span></a>
  <a href="#sec-logins"><i class="bi bi-key me-1"></i>Login Credentials <span class="badge bg-secondary-subtle text-secondary"><?= count($wpSites) + count($credentials) ?></span></a>
  <a href="#sec-domain"><i class="bi bi-hdd-network me-1"></i>Domain</a>
  <a href="#sec-hosting"><i class="bi bi-server me-1"></i>Hosting</a>
  <a href="#sec-ssl"><i class="bi bi-shield-lock me-1"></i>SSL</a>
  <a href="#sec-monitoring"><i class="bi bi-activity me-1"></i>Monitoring <?= $siteStats['pages_failed'] || $down ? '<span class="badge bg-danger-subtle text-danger">' . ($down + (int) $siteStats['pages_failed']) . '</span>' : '' ?></a>
  <a href="#sec-forms"><i class="bi bi-ui-checks me-1"></i>Forms <?= $formStats['failed'] ? '<span class="badge bg-danger-subtle text-danger">' . (int) $formStats['failed'] . ' failed</span>' : '' ?></a>
  <a href="#sec-alerts"><i class="bi bi-bell me-1"></i>Alerts</a>
  <a href="#sec-activity"><i class="bi bi-clock-history me-1"></i>Activity</a>
</nav>

<div class="row g-3 mb-3" id="sec-info">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-person me-1"></i>Client Information</span><a href="#" class="small fw-normal" id="editClientLink">Edit</a></div>
      <div class="card-body">
        <dl class="dl-grid mb-0">
          <dt>Client Name</dt><dd class="fw-500"><?= e($client['name']) ?></dd>
          <dt>Company</dt><dd><?= e($client['company'] ?: '—') ?></dd>
          <dt>Email</dt><dd><?= $client['email'] ? '<a href="mailto:' . e($client['email']) . '">' . e($client['email']) . '</a>' : '—' ?></dd>
          <dt>Phone</dt><dd><?= $client['phone'] ? '<a href="tel:' . e($client['phone']) . '">' . e($client['phone']) . '</a>' : '—' ?></dd>
          <dt>WhatsApp</dt><dd><?= $client['whatsapp'] ? '<a href="https://wa.me/' . e(preg_replace('~\D~', '', $client['whatsapp'])) . '" target="_blank" rel="noopener"><i class="bi bi-whatsapp text-success me-1"></i>' . e($client['whatsapp']) . '</a>' : '—' ?></dd>
          <dt>Website(s)</dt><dd><?php if (!$websites): ?>—<?php else: foreach ($websites as $w): ?><div><a href="<?= e($w['url']) ?>" target="_blank" rel="noopener"><?= e(host_from_url($w['url'])) ?></a> <?= website_status_badge($w['status']) ?></div><?php endforeach; endif; ?></dd>
          <dt>Client Status</dt><dd><?= client_status_badge($client['status']) ?><?= !$client['monitoring_enabled'] ? ' <span class="badge bg-secondary-subtle text-secondary">Monitoring off</span>' : '' ?></dd>
          <dt>Address</dt><dd><?= nl2br(e($client['address'] ?: '—')) ?></dd>
          <dt>Assigned</dt><dd><?= e($client['assigned_name'] ?: 'Unassigned') ?></dd>
          <dt>Client since</dt><dd><?= format_date($client['created_at']) ?></dd>
          <dt>Alerts to client</dt><dd><?= $client['notify_client'] ? '<span class="text-success">Yes</span> <span class="text-muted small">(' . e($client['email'] ?: 'no email!') . ')</span>' : 'No' ?></dd>
          <dt>Notes</dt><dd><?= $client['notes'] ? nl2br(e($client['notes'])) : '<span class="text-muted">—</span>' ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="row g-2">
      <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-globe2"></i></div><div><div class="stat-value"><?= number_format($websiteTotal) ?></div><div class="stat-label">Websites</div></div></div></div>
      <div class="col-6 col-md-3"><div class="stat-card <?= $down ? 'alert-card border-danger' : '' ?>"><div class="stat-icon <?= $down ? 'tint-danger' : 'tint-success' ?>"><i class="bi <?= $down ? 'bi-wifi-off' : 'bi-wifi' ?>"></i></div><div><div class="stat-value"><?= $down ? $down . ' <small class="text-danger">down</small>' : $online . ' <small class="text-success">up</small>' ?></div><div class="stat-label">Website status</div></div></div></div>
      <div class="col-6 col-md-3"><a href="#sec-monitoring" class="stat-card <?= $siteStats['pages_failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon <?= $siteStats['pages_failed'] ? 'tint-danger' : 'tint-success' ?>"><i class="bi bi-file-earmark-text"></i></div><div><div class="stat-value"><?= (int) $siteStats['pages_ok'] ?>/<?= (int) $siteStats['pages_total'] ?></div><div class="stat-label">Pages working<?= $siteStats['pages_failed'] ? ' · <span class="text-danger">' . (int) $siteStats['pages_failed'] . ' failed</span>' : '' ?></div></div></a></div>
      <div class="col-6 col-md-3"><a href="#sec-forms" class="stat-card <?= $formStats['failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon <?= $formStats['failed'] ? 'tint-danger' : 'tint-success' ?>"><i class="bi bi-ui-checks"></i></div><div><div class="stat-value"><?= (int) $formStats['working'] ?>/<?= (int) $formStats['total'] ?></div><div class="stat-label">Forms working<?= $formStats['failed'] ? ' · <span class="text-danger">' . (int) $formStats['failed'] . ' failed</span>' : '' ?></div></div></a></div>
      <div class="col-12">
        <div class="card">
          <div class="card-header"><span><i class="bi bi-robot me-1"></i>Automatic Monitoring</span><?= $monActive ? status_pill('success', 'ACTIVE') : status_pill('danger', $cronStatus['status'] === 'never' ? 'NOT SET UP' : 'NOT RUNNING') ?></div>
          <div class="card-body py-2">
            <div class="monitor-status-line">
              <span><span class="k">Website Monitoring:</span> <?= $client['monitoring_enabled'] && $monActive ? '<strong class="text-success">ACTIVE</strong>' : '<strong class="text-danger">' . ($client['monitoring_enabled'] ? 'STOPPED' : 'DISABLED') . '</strong>' ?></span>
              <span><span class="k">Page Monitoring:</span> <?= $client['monitoring_enabled'] && $monActive && setting('page_monitoring_enabled', 1) ? '<strong class="text-success">ACTIVE</strong>' : '<strong class="text-danger">' . ($client['monitoring_enabled'] ? 'STOPPED' : 'DISABLED') . '</strong>' ?></span>
              <span><span class="k">Form Monitoring:</span> <?= $client['monitoring_enabled'] && $monActive ? '<strong class="text-success">ACTIVE</strong>' : '<strong class="text-danger">' . ($client['monitoring_enabled'] ? 'STOPPED' : 'DISABLED') . '</strong>' ?></span>
              <span><span class="k">Interval:</span> every <strong><?= $interval ?> min</strong> <span class="text-muted">(pages <?= $pageInterval ?> min, from the plan)</span></span>
              <span><span class="k">Last Website Check:</span> <strong><?= $siteStats['last_check'] ? format_datetime($siteStats['last_check']) : 'never' ?></strong></span>
              <span><span class="k">Last Full Page Scan:</span> <strong><?= $siteStats['last_scan'] ? format_datetime($siteStats['last_scan']) : 'never' ?></strong></span>
              <span><span class="k">Next Scan:</span> <strong><?= $nextScanText ?></strong></span>
              <span><span class="k">Engine:</span> <?= $monActive ? '<strong class="text-success">RUNNING</strong>' : '<strong class="text-danger">NOT RUNNING</strong> <a href="' . url('settings/index.php?tab=cron') . '">setup</a>' ?></span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12" id="sec-websites">
        <div class="card">
          <div class="card-header"><span><i class="bi bi-globe2 me-1"></i>Websites</span><span class="small fw-normal"><?php if ($websiteTotal > count($websites)): ?><a href="<?= url('websites/index.php?client_id=' . $id) ?>">Showing <?= count($websites) ?> of <?= number_format($websiteTotal) ?> · view all</a> · <?php endif; ?><a href="#" data-open-modal="#websiteModal">Add website</a></span></div>
          <div class="card-body p-0">
            <?php if (!$websites): ?><div class="empty-state"><i class="bi bi-globe2"></i>No websites yet. <a href="#" data-open-modal="#websiteModal">Add the first website</a></div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-hover table-compact mb-0">
              <thead><tr><th>Website</th><th>Technology</th><th>Status</th><th>Pages</th><th>Failed</th><th>SSL</th><th>Forms</th><th>Last Scan</th><th></th></tr></thead>
              <tbody><?php foreach ($websites as $w): $wf = (int) $w['pages_failed']; ?>
                <tr>
                  <td><a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="fw-500"><?= e($w['name']) ?></a><div class="small-xs"><a href="<?= e($w['url']) ?>" target="_blank" rel="noopener" class="text-muted"><?= e($w['url']) ?> <i class="bi bi-box-arrow-up-right"></i></a></div></td>
                  <td><span class="badge bg-secondary-subtle text-secondary"><?= e($w['technology']) ?></span></td>
                  <td><?= website_status_badge($w['status']) ?><?= $w['http_code'] ? '<div class="small-xs text-muted">HTTP ' . (int) $w['http_code'] . ($w['response_time'] !== null ? ' · ' . (int) $w['response_time'] . ' ms' : '') . '</div>' : '' ?></td>
                  <td><?= $w['pages_total'] ? '<span class="fw-500">' . (int) $w['pages_ok'] . ' / ' . (int) $w['pages_total'] . '</span><div class="small-xs">' . page_health_badge($w['page_health']) . '</div>' : '<span class="text-muted">' . ($w['page_monitoring_enabled'] ? 'not scanned' : 'off') . '</span>' ?></td>
                  <td><?= $wf ? '<a href="' . url('websites/view.php?id=' . $w['id'] . '&tab=pages&filter=failed') . '" class="text-danger fw-500">' . $wf . ' failed</a>' : '<span class="text-success">0</span>' ?></td>
                  <td><?= ssl_status_badge($w['ssl_status'], $w['ssl_days_left']) ?><?= $w['ssl_expires_at'] ? '<div class="small-xs text-muted">' . format_date($w['ssl_expires_at']) . '</div>' : '' ?></td>
                  <td><?= $w['failed_forms'] ? '<span class="text-danger fw-500">' . $w['failed_forms'] . ' failed</span> / ' : '' ?><?= (int) $w['form_count'] ?></td>
                  <td class="text-muted small"><?= e(time_ago($w['last_scan_at'])) ?><div class="small-xs">check <?= e(time_ago($w['last_checked_at'])) ?></div></td>
                  <td class="row-actions text-nowrap"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"check","id":<?= $w['id'] ?>}' data-loading="1" data-reload="1" title="Check website now"><i class="bi bi-arrow-repeat"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"scan_pages","id":<?= $w['id'] ?>}' data-loading="1" data-reload="1" title="Scan all pages now"><i class="bi bi-files"></i></button> <a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="btn btn-light btn-sm" title="Open"><i class="bi bi-eye"></i></a></td>
                </tr>
              <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// ---------------------------------------------------------------- Login Credentials (v1.8)
// One place for every login of this client: WordPress logins (from each website's WordPress tab plus extra stored
// WordPress accounts), Domain logins, Hosting logins and Other logins (cPanel, FTP, email, database, services).
// Stored logins are admin only; passwords are fetched on demand and never embedded in the page.
$credGroups = ['wordpress' => [], 'domain' => [], 'hosting' => [], 'other' => []];
foreach ($credentials as $c) $credGroups[in_array($c['type'], ['wordpress', 'domain', 'hosting'], true) ? $c['type'] : 'other'][] = $c;
$credCounts = ['wordpress' => count($wpSites) + count($credGroups['wordpress']), 'domain' => count($credGroups['domain']), 'hosting' => count($credGroups['hosting']), 'other' => count($credGroups['other'])];
$credTotal = count($wpSites) + count($credentials);
$credRow = function (array $c): void {
    $meta = credential_type_meta($c['type']);
    ?>
    <tr class="cred-row" data-row-id="c<?= $c['id'] ?>">
      <td><i class="bi <?= $meta['icon'] ?> text-muted me-1"></i><span class="fw-500"><?= e($c['label']) ?></span><div class="small-xs text-muted"><?= e(credential_types()[$c['type']] ?? $c['type']) ?><?= $c['website_name'] ? ' · ' . e($c['website_name']) : '' ?></div><?= $c['notes'] ? '<div class="small-xs text-muted" title="' . e($c['notes']) . '"><i class="bi bi-sticky"></i> ' . e(truncate($c['notes'], 40)) . '</div>' : '' ?></td>
      <td><?= $c['provider'] ? e($c['provider']) : '<span class="text-muted">—</span>' ?></td>
      <td><?= $c['login_url'] ? '<a href="' . e($c['login_url']) . '" target="_blank" rel="noopener">' . e(truncate(preg_replace('~^https?://~', '', $c['login_url']), 32)) . ' <i class="bi bi-box-arrow-up-right"></i></a>' : '<span class="text-muted">—</span>' ?></td>
      <td><?= $c['username'] ? '<span class="mono">' . e($c['username']) . '</span> <i class="bi bi-clipboard copy-btn text-muted cursor-pointer" data-copy="' . e($c['username']) . '" title="Copy"></i>' : '<span class="text-muted">—</span>' ?></td>
      <td><?= $c['password'] ? '<span class="secret" data-secret="cred" data-id="' . $c['id'] . '">' . password_mask() . '</span>' : '<span class="text-muted">—</span>' ?></td>
      <td class="row-actions text-nowrap text-end"><?php if ($c['password']): ?><button class="btn btn-light btn-sm btn-reveal" data-secret="cred" data-id="<?= $c['id'] ?>" title="Show / hide password"><i class="bi bi-eye"></i></button> <button class="btn btn-light btn-sm btn-copy-secret" data-secret="cred" data-id="<?= $c['id'] ?>" title="Copy password"><i class="bi bi-clipboard"></i></button><?php endif; ?> <button class="btn btn-light btn-sm btn-edit-cred" data-id="<?= $c['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/credentials.php" data-params='{"action":"delete","id":<?= $c['id'] ?>}' data-confirm="Delete this login record?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button></td>
    </tr>
    <?php
};
$credTable = function (array $rows, string $emptyText, string $addType) use ($credRow): void {
    if (!$rows) { echo '<div class="empty-state"><i class="bi bi-key"></i>' . $emptyText . ' <a href="#" class="add-cred" data-cred-type="' . $addType . '">Add one</a></div>'; return; }
    echo '<div class="table-responsive"><table class="table table-compact mb-0"><thead><tr><th>Login</th><th>Provider</th><th>Login URL</th><th>Username / Email</th><th>Password</th><th class="text-end">Actions</th></tr></thead><tbody>';
    foreach ($rows as $c) $credRow($c);
    echo '</tbody></table></div>';
};
?>
<div class="row g-3 mb-3" id="sec-logins">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-key me-1"></i>Login Credentials <span class="badge bg-secondary-subtle text-secondary"><?= $credTotal ?></span></span>
        <span class="small fw-normal d-flex flex-wrap align-items-center gap-1">
          <?php if ($isAdmin): ?>
          <span class="text-muted me-2"><i class="bi bi-shield-lock"></i> encrypted · admin only · every view is logged</span>
          <button type="button" class="btn btn-dark btn-sm add-cred" data-cred-type=""><i class="bi bi-plus-lg me-1"></i>Add Login</button>
          <button type="button" class="btn btn-outline-primary btn-sm add-cred" data-cred-type="domain"><i class="bi bi-plus-lg me-1"></i>Add Domain Login</button>
          <button type="button" class="btn btn-outline-primary btn-sm add-cred" data-cred-type="hosting"><i class="bi bi-plus-lg me-1"></i>Add Hosting Login</button>
          <?php else: ?><span class="text-muted"><i class="bi bi-lock me-1"></i>Passwords and stored logins are visible to administrators only</span><?php endif; ?>
        </span>
      </div>
      <div class="card-body py-2 border-bottom">
        <div class="cred-summary">
          <a href="#cred-wordpress" class="cs"><i class="bi bi-wordpress"></i> WordPress <strong><?= $credCounts['wordpress'] ?></strong> <span class="text-muted">login<?= $credCounts['wordpress'] === 1 ? '' : 's' ?></span></a>
          <?php if ($isAdmin): ?>
          <a href="#cred-domain" class="cs"><i class="bi bi-hdd-network"></i> Domain <strong><?= $credCounts['domain'] ?></strong> <span class="text-muted">login<?= $credCounts['domain'] === 1 ? '' : 's' ?></span></a>
          <a href="#cred-hosting" class="cs"><i class="bi bi-server"></i> Hosting <strong><?= $credCounts['hosting'] ?></strong> <span class="text-muted">login<?= $credCounts['hosting'] === 1 ? '' : 's' ?></span></a>
          <a href="#cred-other" class="cs"><i class="bi bi-key"></i> Other <strong><?= $credCounts['other'] ?></strong> <span class="text-muted">login<?= $credCounts['other'] === 1 ? '' : 's' ?></span></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="cred-group" id="cred-wordpress"><span id="sec-wp"></span>
          <div class="cred-group-title"><span><i class="bi bi-wordpress me-1"></i>WordPress Login <span class="badge bg-secondary-subtle text-secondary"><?= $credCounts['wordpress'] ?></span></span><span class="text-muted">Website logins are managed on each website's WordPress tab<?= $isAdmin ? ' · <a href="#" class="add-cred" data-cred-type="wordpress"><i class="bi bi-plus-lg"></i> Add extra WordPress login</a>' : '' ?></span></div>
          <?php if (!$wpSites && !$credGroups['wordpress']): ?><div class="empty-state"><i class="bi bi-wordpress"></i>No WordPress logins. Set Technology = WordPress on a website (login URL, username and password are then required)<?= $isAdmin ? ', or <a href="#" class="add-cred" data-cred-type="wordpress">add a WordPress login</a>' : '' ?>.</div>
          <?php else: ?>
          <div class="table-responsive"><table class="table table-compact mb-0">
            <thead><tr><th>Login</th><th>Provider</th><th>Login URL</th><th>Username / Email</th><th>Password</th><th class="text-end">Actions</th></tr></thead>
            <tbody><?php foreach ($wpSites as $w): ?>
              <tr class="cred-row">
                <td><i class="bi bi-wordpress text-muted me-1"></i><a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="fw-500"><?= e($w['name']) ?></a><div class="small-xs text-muted">WordPress Login · website</div></td>
                <td><?= e(host_from_url($w['url'])) ?></td>
                <td><?= $w['wp_login_url'] ? '<a href="' . e($w['wp_login_url']) . '" target="_blank" rel="noopener">' . e(truncate(preg_replace('~^https?://~', '', $w['wp_login_url']), 32)) . ' <i class="bi bi-box-arrow-up-right"></i></a>' : '<span class="text-danger small">missing</span>' ?></td>
                <td><?= $w['wp_username'] ? '<span class="mono">' . e($w['wp_username']) . '</span> <i class="bi bi-clipboard copy-btn text-muted cursor-pointer" data-copy="' . e($w['wp_username']) . '" title="Copy"></i>' : '<span class="text-danger small">missing</span>' ?></td>
                <td><?php if (!$w['wp_password']): ?><span class="text-danger small">missing</span>
                    <?php elseif ($isAdmin): ?><span class="secret" data-secret="wp" data-id="<?= $w['id'] ?>"><?= password_mask() ?></span>
                    <?php else: ?><span class="text-muted small"><i class="bi bi-lock me-1"></i>Admin only</span><?php endif; ?></td>
                <td class="row-actions text-nowrap text-end"><?php if ($isAdmin && $w['wp_password']): ?><button class="btn btn-light btn-sm btn-reveal" data-secret="wp" data-id="<?= $w['id'] ?>" title="Show / hide password"><i class="bi bi-eye"></i></button> <button class="btn btn-light btn-sm btn-copy-secret" data-secret="wp" data-id="<?= $w['id'] ?>" title="Copy password"><i class="bi bi-clipboard"></i></button><?php endif; ?> <button class="btn btn-light btn-sm btn-edit-website" data-id="<?= $w['id'] ?>" title="Edit website login"><i class="bi bi-pencil"></i></button></td>
              </tr>
            <?php endforeach; foreach ($credGroups['wordpress'] as $c) $credRow($c); ?></tbody></table></div>
          <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
        <div class="cred-group" id="cred-domain">
          <div class="cred-group-title"><span><i class="bi bi-hdd-network me-1"></i>Domain Login <span class="badge bg-secondary-subtle text-secondary"><?= $credCounts['domain'] ?></span></span><a href="#" class="add-cred" data-cred-type="domain"><i class="bi bi-plus-lg"></i> Add Domain Login</a></div>
          <?php $credTable($credGroups['domain'], 'No domain / registrar logins stored.', 'domain'); ?>
        </div>
        <div class="cred-group" id="cred-hosting">
          <div class="cred-group-title"><span><i class="bi bi-server me-1"></i>Hosting Login <span class="badge bg-secondary-subtle text-secondary"><?= $credCounts['hosting'] ?></span></span><a href="#" class="add-cred" data-cred-type="hosting"><i class="bi bi-plus-lg"></i> Add Hosting Login</a></div>
          <?php $credTable($credGroups['hosting'], 'No hosting logins stored.', 'hosting'); ?>
        </div>
        <div class="cred-group" id="cred-other">
          <div class="cred-group-title"><span><i class="bi bi-key me-1"></i>Other Logins <span class="badge bg-secondary-subtle text-secondary"><?= $credCounts['other'] ?></span> <span class="text-muted fw-normal">cPanel, FTP, email, database, other services</span></span><a href="#" class="add-cred" data-cred-type="other"><i class="bi bi-plus-lg"></i> Add Other Login</a></div>
          <?php $credTable($credGroups['other'], 'No other logins stored.', 'other'); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-4" id="sec-domain">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-hdd-network me-1"></i>Domain Information</span><a href="<?= url('domains/index.php?client_id=' . $id) ?>" class="small fw-normal">Manage</a></div>
      <div class="card-body p-0">
        <?php if (!$domains): ?><div class="empty-state py-4"><i class="bi bi-hdd-network"></i>No domain records. Edit a website (Domain tab) to add one.</div>
        <?php else: foreach ($domains as $d): ?>
          <div class="p-3 border-bottom">
            <dl class="dl-grid mb-0">
              <dt>Domain Name</dt><dd class="fw-500"><?= e($d['domain_name']) ?><?= $d['website_name'] ? '<div class="small-xs text-muted">' . e($d['website_name']) . '</div>' : '' ?></dd>
              <dt>Provider</dt><dd><?= e($d['registrar'] ?: '—') ?></dd>
              <dt>Expiry Date</dt><dd><?= format_date($d['expiry_date']) ?></dd>
              <dt>Auto Renewal</dt><dd><?= $d['auto_renew'] ? '<span class="text-success"><i class="bi bi-arrow-repeat"></i> Enabled</span>' : '<span class="text-warning">Not enabled</span>' ?></dd>
              <dt>Days Remaining</dt><dd><?= $daysCell($d['expiry_date']) ?></dd>
              <?php if ($d['login_ref']): ?><dt>Login ref</dt><dd><?= e($d['login_ref']) ?></dd><?php endif; ?>
            </dl>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4" id="sec-hosting">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-server me-1"></i>Hosting Information</span><a href="<?= url('hosting/index.php?client_id=' . $id) ?>" class="small fw-normal">Manage</a></div>
      <div class="card-body p-0">
        <?php if (!$hosting): ?><div class="empty-state py-4"><i class="bi bi-server"></i>No hosting records. Edit a website (Hosting tab) to add one.</div>
        <?php else: foreach ($hosting as $h): ?>
          <div class="p-3 border-bottom">
            <dl class="dl-grid mb-0">
              <dt>Provider</dt><dd class="fw-500"><?= e($h['provider']) ?><?= $h['website_name'] ? '<div class="small-xs text-muted">' . e($h['website_name']) . '</div>' : '' ?></dd>
              <dt>Plan</dt><dd><?= e($h['plan'] ?: '—') ?></dd>
              <dt>Server / IP</dt><dd><?= e($h['server_ip'] ?: '—') ?></dd>
              <dt>Login / Ref</dt><dd><?= e($h['login_ref'] ?: '—') ?></dd>
              <dt>Expiry Date</dt><dd><?= format_date($h['expiry_date']) ?> <span class="text-muted small">(<?= e(ucfirst($h['renewal_status'])) ?>)</span></dd>
              <dt>Days Remaining</dt><dd><?= $daysCell($h['expiry_date']) ?></dd>
            </dl>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4" id="sec-ssl">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-shield-lock me-1"></i>SSL Certificates</span><span class="small fw-normal text-muted"><?= $siteStats['ssl_issues'] ? '<span class="text-danger">' . (int) $siteStats['ssl_issues'] . ' issue(s)</span>' : 'checked every 12 h' ?></span></div>
      <div class="card-body p-0">
        <?php if (!$websites): ?><div class="empty-state py-4"><i class="bi bi-shield"></i>No websites</div>
        <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0">
          <thead><tr><th>Website</th><th>Status</th><th>Expiry</th><th>Days</th></tr></thead>
          <tbody><?php foreach ($websites as $w): ?>
            <tr><td><a href="<?= url('websites/view.php?id=' . $w['id']) ?>"><?= e(host_from_url($w['url'])) ?></a><?= $w['ssl_error'] ? '<div class="small-xs text-danger" title="' . e($w['ssl_error']) . '">' . e(truncate($w['ssl_error'], 34)) . '</div>' : '' ?></td>
              <td><?= ssl_status_badge($w['ssl_status']) ?></td><td><?= format_date($w['ssl_expires_at']) ?></td><td><?= $w['ssl_days_left'] !== null ? '<span class="fw-500 ' . ($w['ssl_days_left'] < 0 ? 'text-danger' : ($w['ssl_days_left'] <= 30 ? 'text-warning' : 'text-success')) . '">' . (int) $w['ssl_days_left'] . '</span>' : '—' ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3" id="sec-monitoring">
  <div class="card-header p-0 border-0">
    <ul class="nav nav-tabs px-2" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSiteHealth" type="button"><i class="bi bi-activity me-1"></i>Website Health</button></li>
      <li class="nav-item"><button class="nav-link <?= $failedPages ? 'text-danger' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabPageHealth" type="button"><i class="bi bi-file-earmark-text me-1"></i>Page Health<?= $failedPages ? ' (' . count($failedPages) . ' failed)' : '' ?></button></li>
      <li class="nav-item"><button class="nav-link <?= $formStats['failed'] ? 'text-danger' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabFormHealth" type="button"><i class="bi bi-ui-checks me-1"></i>Form Health</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHistory" type="button"><i class="bi bi-clock-history me-1"></i>Monitoring History</button></li>
    </ul>
  </div>
  <div class="card-body p-0 tab-content">
    <div class="tab-pane fade show active" id="tabSiteHealth">
      <?php if (!$websites): ?><div class="empty-state"><i class="bi bi-globe2"></i>No websites</div>
      <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
        <thead><tr><th>Website</th><th>Website Status</th><th>Reason</th><th>Response</th><th>Page Health</th><th>Last Website Check</th><th>Last Successful Check</th></tr></thead>
        <tbody><?php foreach ($websites as $w): $isDown = in_array($w['status'], explode(',', str_replace("'", '', down_statuses_sql())), true); ?>
          <tr><td><a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="fw-500"><?= e($w['name']) ?></a></td><td><?= website_status_badge($w['status']) ?></td>
            <td class="small"><?= $isDown ? '<span class="text-danger fw-500">' . e($w['failure_reason'] ?: 'Unknown') . '</span><div class="text-muted small-xs">' . e(truncate($w['error_message'] ?? '', 60)) . '</div>' : '<span class="text-muted">—</span>' ?></td>
            <td><?= $w['http_code'] ? 'HTTP ' . (int) $w['http_code'] : '—' ?><?= $w['response_time'] !== null ? '<div class="small-xs text-muted">' . (int) $w['response_time'] . ' ms</div>' : '' ?></td>
            <td><?= page_health_badge($w['page_health'], $w['pages_total'], $w['pages_failed']) ?></td>
            <td><?= format_datetime($w['last_checked_at']) ?></td><td><?= format_datetime($w['last_success_at']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabPageHealth">
      <div class="p-3 border-bottom">
        <div class="health-summary">
          <div class="hs"><div class="lbl">Total Pages</div><div class="val"><?= (int) $siteStats['pages_total'] ?></div></div>
          <div class="hs hs-success"><div class="lbl">Working</div><div class="val text-success"><?= (int) $siteStats['pages_ok'] ?></div></div>
          <div class="hs <?= $siteStats['pages_failed'] ? 'hs-danger' : '' ?>"><div class="lbl">Failed</div><div class="val <?= $siteStats['pages_failed'] ? 'text-danger' : '' ?>"><?= (int) $siteStats['pages_failed'] ?></div></div>
          <div class="hs"><div class="lbl">Last Full Scan</div><div class="val" style="font-size:.9rem"><?= $siteStats['last_scan'] ? format_datetime($siteStats['last_scan']) : 'never' ?></div></div>
          <div class="hs"><div class="lbl">Next Scan</div><div class="val" style="font-size:.9rem"><?= $nextScanText ?></div></div>
        </div>
        <div class="small mt-2"><?= $siteStats['pages_total'] ? ($siteStats['pages_failed'] ? '<strong class="text-danger">' . ((int) $siteStats['pages_total'] - (int) $siteStats['pages_failed']) . ' / ' . (int) $siteStats['pages_total'] . ' Pages Working – ' . (int) $siteStats['pages_failed'] . ' Page' . ($siteStats['pages_failed'] > 1 ? 's' : '') . ' Failed</strong>' : '<strong class="text-success">' . (int) $siteStats['pages_total'] . ' / ' . (int) $siteStats['pages_total'] . ' Pages Working</strong>') : '<span class="text-muted">Pages are discovered and checked automatically by the 5-minute cron. Use "Scan all pages now" on a website to start immediately.</span>' ?></div>
      </div>
      <?php if (!$failedPages): ?><div class="empty-state py-4"><i class="bi bi-emoji-smile"></i>No failed pages – every monitored page is working</div>
      <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
        <thead><tr><th>Page</th><th>Website</th><th>URL</th><th>Status</th><th>HTTP</th><th>Reason</th><th>Failing since</th><th>Last OK</th><th></th></tr></thead>
        <tbody><?php foreach ($failedPages as $p): ?>
          <tr class="page-row failed"><td class="fw-500"><?= e($p['title'] ?: Monitor::pathLabel($p['path'])) ?></td><td><a href="<?= url('websites/view.php?id=' . $p['website_id'] . '&tab=pages&filter=failed') ?>"><?= e($p['website_name']) ?></a></td>
            <td><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener" class="small"><?= e(truncate($p['url'], 45)) ?></a></td><td><?= page_status_badge($p['status']) ?></td><td class="text-danger fw-500"><?= e($p['http_code'] ?: '—') ?></td>
            <td class="small"><span class="text-danger fw-500"><?= e($p['failure_reason'] ?: 'Failed') ?></span><div class="text-muted small-xs"><?= e(truncate($p['error_message'] ?? '', 60)) ?></div></td>
            <td class="small"><?= format_datetime($p['last_failed_at']) ?><?= $p['failed_checks'] > 1 ? '<div class="small-xs text-muted">' . (int) $p['failed_checks'] . ' checks</div>' : '' ?></td><td class="small"><?= format_datetime($p['last_success_at']) ?></td>
            <td class="row-actions"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params='{"action":"page_check","id":<?= $p['id'] ?>}' data-loading="1" data-reload="1" title="Check now"><i class="bi bi-arrow-repeat"></i></button></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabFormHealth">
      <div class="p-3 border-bottom"><div class="health-summary">
        <div class="hs"><div class="lbl">Total Forms</div><div class="val"><?= (int) $formStats['total'] ?></div></div>
        <div class="hs hs-success"><div class="lbl">Working</div><div class="val text-success"><?= (int) $formStats['working'] ?></div></div>
        <div class="hs <?= $formStats['failed'] ? 'hs-danger' : '' ?>"><div class="lbl">Failed</div><div class="val <?= $formStats['failed'] ? 'text-danger' : '' ?>"><?= (int) $formStats['failed'] ?></div></div>
        <div class="hs"><div class="lbl">Not Tested</div><div class="val"><?= (int) $formStats['not_tested'] ?></div></div>
      </div></div>
      <?php if (!$forms): ?><div class="empty-state py-4"><i class="bi bi-ui-checks"></i>No forms configured</div>
      <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
        <thead><tr><th>Form</th><th>Website</th><th>Status</th><th>Last Test</th><th>Last Success</th><th>Result</th></tr></thead>
        <tbody><?php foreach ($forms as $f): ?>
          <tr><td><a href="<?= url('forms/index.php?website_id=' . $f['website_id'] . '&highlight=' . $f['id']) ?>" class="fw-500"><?= e($f['name']) ?></a></td><td><?= e($f['website_name']) ?></td><td><?= form_status_badge($f['status']) ?></td><td><?= format_datetime($f['last_tested_at']) ?></td><td><?= format_datetime($f['last_success_at']) ?></td><td class="small <?= $f['last_result'] === 'failed' ? 'text-danger' : ($f['last_result'] === 'blocked' ? 'text-info' : '') ?>"><?= e(truncate($f['last_error'] ?: ($f['last_result'] === 'success' ? 'Success' : '—'), 70)) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabHistory">
      <div class="row g-0">
        <div class="col-lg-6 border-end">
          <div class="px-3 pt-3 section-title">Page scans (latest)</div>
          <?php if (!$scans): ?><div class="empty-state py-3"><i class="bi bi-files"></i>No page scans yet</div>
          <?php else: ?><div class="table-responsive" style="max-height:380px"><table class="table table-compact mb-0">
            <thead><tr><th>Scanned</th><th>Website</th><th>Health</th><th>Pages</th><th>Failed pages</th></tr></thead>
            <tbody><?php foreach ($scans as $s): $fl = $s['failed_pages'] ? json_decode($s['failed_pages'], true) : []; ?>
              <tr><td class="text-nowrap small"><?= format_datetime($s['scanned_at']) ?></td><td class="small"><?= e($s['website_name']) ?></td><td><?= page_health_badge($s['health']) ?></td><td class="small"><?= (int) $s['pages_ok'] ?> / <?= (int) $s['pages_total'] ?></td>
                <td class="small-xs text-danger"><?= $fl ? e(implode(', ', array_map(fn($f) => $f['title'] . ' (' . ($f['http_code'] ?: 'no response') . ')', array_slice($fl, 0, 4)))) . (count($fl) > 4 ? ' +' . (count($fl) - 4) : '') : '<span class="text-muted">—</span>' ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="col-lg-6">
          <div class="px-3 pt-3 section-title">Incidents (website &amp; page)</div>
          <?php if (!$pageIncidents && !$siteIncidents): ?><div class="empty-state py-3"><i class="bi bi-emoji-smile"></i>No incidents recorded</div>
          <?php else: ?><div class="table-responsive" style="max-height:380px"><table class="table table-compact mb-0">
            <thead><tr><th>Started</th><th>What</th><th>Duration</th><th>Reason</th><th>Email</th></tr></thead>
            <tbody>
            <?php foreach ($siteIncidents as $i): ?>
              <tr><td class="text-nowrap small"><?= format_datetime($i['started_at']) ?></td><td class="small"><span class="badge bg-danger-subtle text-danger">Website</span> <?= e($i['website_name']) ?></td><td class="small"><?= $i['resolved_at'] ? duration_human((int) $i['duration_seconds']) : '<span class="text-danger">ongoing</span>' ?></td><td class="small-xs"><?= e($i['failure_reason'] ?: '—') ?> <span class="text-muted"><?= e(truncate($i['error_message'] ?? '', 40)) ?></span></td><td class="small"><?= $i['alert_sent'] ? '<i class="bi bi-envelope-check text-success" title="Alert sent"></i>' : '' ?> <?= $i['recovery_sent'] ? '<i class="bi bi-envelope-check text-success" title="Recovery sent"></i>' : '' ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($pageIncidents as $i): ?>
              <tr><td class="text-nowrap small"><?= format_datetime($i['started_at']) ?></td><td class="small"><span class="badge bg-warning-subtle text-warning">Page</span> <?= e($i['title'] ?: Monitor::pathLabel($i['path'])) ?> <span class="text-muted small-xs">· <?= e($i['website_name']) ?></span></td><td class="small"><?= $i['resolved_at'] ? duration_human((int) $i['duration_seconds']) : '<span class="text-danger">ongoing</span>' ?></td><td class="small-xs">HTTP <?= e($i['status_code'] ?: 'none') ?> · <?= e($i['failure_reason'] ?: '—') ?></td><td class="small"><?= $i['alert_sent'] ? '<i class="bi bi-envelope-check text-success" title="Alert sent"></i>' : '<span class="text-muted" title="No email (website was down)">—</span>' ?> <?= $i['recovery_sent'] ? '<i class="bi bi-envelope-check text-success" title="Recovery sent"></i>' : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card" id="sec-forms">
  <div class="card-header p-0 border-0">
    <ul class="nav nav-tabs px-2" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabForms" type="button"><i class="bi bi-ui-checks me-1"></i>Forms (<?= count($forms) ?>)</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProjects" type="button" id="sec-projects"><i class="bi bi-kanban me-1"></i>Projects (<?= count($projects) ?>)</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabNotif" type="button" id="sec-alerts"><i class="bi bi-bell me-1"></i>Alerts (<?= count($notifications) ?>)</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabActivity" type="button" id="sec-activity"><i class="bi bi-clock-history me-1"></i>Activity</button></li>
    </ul>
  </div>
  <div class="card-body tab-content">
    <div class="tab-pane fade show active" id="tabForms">
      <?php if (!$forms): ?><div class="empty-state"><i class="bi bi-ui-checks"></i>No forms added for this client's websites. <a href="<?= url('forms/index.php?client_id=' . $id . '&add=1') ?>">Add a form</a></div>
      <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
        <thead><tr><th>Form</th><th>Website</th><th>Type</th><th>Recipient</th><th>Status</th><th>Last test</th><th>Result</th><th></th></tr></thead>
        <tbody><?php foreach ($forms as $f): ?>
          <tr><td><a href="<?= url('forms/index.php?website_id=' . $f['website_id'] . '&highlight=' . $f['id']) ?>" class="fw-500"><?= e($f['name']) ?></a><div class="small-xs text-muted"><?= e(truncate($f['page_url'], 45)) ?></div></td><td><?= e($f['website_name']) ?></td><td><?= e($f['form_type']) ?></td><td class="small"><?= e($f['recipient_email'] ?: '—') ?></td><td><?= form_status_badge($f['status']) ?></td><td class="text-muted"><?= e(time_ago($f['last_tested_at'])) ?></td><td class="small <?= $f['last_result'] === 'failed' ? 'text-danger' : ($f['last_result'] === 'blocked' ? 'text-info' : '') ?>"><?= e(truncate($f['last_error'] ?: ($f['last_result'] === 'success' ? 'Success' : '—'), 60)) ?></td>
            <td class="row-actions"><button class="btn btn-light btn-sm btn-action" data-url="api/forms.php" data-params='{"action":"test","id":<?= $f['id'] ?>}' data-loading="1" data-reload="1" title="Test now"><i class="bi bi-play-circle"></i></button></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabProjects">
      <?php if (Auth::can('projects')): ?><div class="text-end mb-2"><a href="<?= url('projects/index.php?client_id=' . $id . '&add=1') ?>" class="btn btn-dark btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Website Project</a></div><?php endif; ?>
      <?php if (!$projects): ?><div class="empty-state"><i class="bi bi-kanban"></i>No website projects for this client yet</div>
      <?php else: ?><div class="table-responsive"><table class="table table-hover table-compact mb-0">
        <thead><tr><th>Project</th><th>Website</th><th>Department / Type</th><th>Technology</th><th>Status</th><th>Start</th><th>Launch</th><th>Monitoring</th><th></th></tr></thead>
        <tbody><?php foreach ($projects as $p): ?>
          <tr><td><a href="<?= url('projects/view.php?id=' . $p['id']) ?>" class="fw-500"><?= e($p['project_name']) ?></a><div class="small-xs"><?= project_kind_badge($p['project_kind']) ?></div></td>
            <td><?= $p['website_url'] ? '<a href="' . e($p['website_url']) . '" target="_blank" rel="noopener">' . e(host_from_url($p['website_url'])) . '</a>' : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= e($p['department_name'] ?: '—') ?><?= $p['type_name'] ? '<div class="text-muted small-xs">' . e($p['type_name']) . '</div>' : '' ?></td>
            <td><?= e($p['technology'] ?: '—') ?></td><td><?= project_status_badge($p['status']) ?></td><td class="text-nowrap"><?= format_date($p['start_date']) ?></td>
            <td class="text-nowrap"><?= $p['actual_launch_date'] ? '<span class="text-success">' . format_date($p['actual_launch_date']) . '</span>' : format_date($p['expected_launch_date']) ?></td>
            <td><?= $p['wid'] ? ($p['monitoring_enabled'] ? status_pill('success', 'Active') : status_pill('secondary', 'Disabled')) : '<span class="text-muted small">Not linked</span>' ?></td>
            <td class="row-actions text-end"><a href="<?= url('projects/view.php?id=' . $p['id']) ?>" class="btn btn-light btn-sm" title="View"><i class="bi bi-eye"></i></a></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabNotif">
      <?php if (!$notifications): ?><div class="empty-state"><i class="bi bi-bell-slash"></i>No alerts for this client</div>
      <?php else: ?><?php foreach ($notifications as $n): ?>
        <a href="<?= url('notifications/index.php?open=' . $n['id']) ?>" class="notif-item type-<?= $n['type'] ?> rounded"><span class="n-icon"><i class="bi <?= ['critical' => 'bi-exclamation-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill', 'recovery' => 'bi-check-circle-fill', 'info' => 'bi-info-circle-fill'][$n['type']] ?>"></i></span><span><div class="n-title"><?= e($n['title']) ?></div><div class="n-msg"><?= e($n['message']) ?></div><div class="n-time"><?= format_datetime($n['created_at']) ?></div></span></a>
      <?php endforeach; ?><?php endif; ?>
    </div>
    <div class="tab-pane fade" id="tabActivity">
      <?php if (!$activity): ?><div class="empty-state"><i class="bi bi-activity"></i>No activity recorded yet</div>
      <?php else: ?><ul class="timeline">
        <?php foreach ($activity as $a): ?>
          <li><span class="tl-icon"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><div class="tl-title"><?= e($a['description']) ?></div><div class="tl-meta"><?= e($a['user_name'] ?? 'System') ?> · <?= format_datetime($a['created_at']) ?> (<?= e(time_ago($a['created_at'])) ?>)</div></li>
        <?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="credModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/credentials.php') ?>" data-reload="1" novalidate autocomplete="off" id="credForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value=""><input type="hidden" name="client_id" value="<?= $id ?>">
    <div class="modal-header"><h5 class="modal-title" data-add="Add Login">Add Login</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-4"><label class="form-label">Login type *</label>
        <select name="type" class="form-select" id="credType">
          <?php foreach (['wordpress', 'domain', 'hosting', 'other'] as $k): ?><option value="<?= $k ?>"><?= e(credential_types()[$k]) ?></option><?php endforeach; ?>
          <optgroup label="More types"><?php foreach (['cpanel', 'ftp', 'email', 'database'] as $k): ?><option value="<?= $k ?>"><?= e(credential_types()[$k]) ?></option><?php endforeach; ?></optgroup>
        </select></div>
      <div class="col-md-4"><label class="form-label" id="credProviderLabel">Provider</label><input type="text" name="provider" class="form-control" maxlength="120" id="credProvider" placeholder="GoDaddy, Hostinger…"></div>
      <div class="col-md-4"><label class="form-label">Website <span class="text-muted fw-normal">(optional)</span></label><select name="website_id" class="form-select"><option value="">— Client level —</option><?php foreach ($websites as $w): ?><option value="<?= $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Login name *</label><input type="text" name="label" class="form-control" required maxlength="120" id="credLabel" placeholder="e.g. Primary domain account"></div>
      <div class="col-md-6"><label class="form-label" id="credUrlLabel">Login URL</label><input type="url" name="login_url" class="form-control" id="credUrl" placeholder="https://"></div>
      <div class="col-md-6"><label class="form-label" id="credUserLabel">Username / Email</label><input type="text" name="username" class="form-control" maxlength="190" autocomplete="off" placeholder="admin@example.com"></div>
      <div class="col-md-6"><label class="form-label">Password <span class="text-muted fw-normal" id="credPassHint">*</span></label><div class="input-group"><input type="password" name="password" class="form-control" id="cred_password" autocomplete="new-password"><button class="btn btn-outline-secondary toggle-password" type="button" data-target="#cred_password" title="Show / hide"><i class="bi bi-eye"></i></button></div></div>
      <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="e.g. Primary domain account · 2FA on owner's phone · security questions…"></textarea></div>
      <div class="col-12 small text-muted"><i class="bi bi-shield-lock me-1"></i>Stored AES-256 encrypted. Only administrators can view passwords, every reveal is written to the activity log, and passwords are never included in emails or logs.</div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Login</button></div>
  </form>
</div></div></div>
<?php endif; ?>

<?php
include ROOT_PATH . '/includes/partials/client-modal.php';
include ROOT_PATH . '/includes/partials/website-modal.php';
$clientJson = json_encode($client);
$credMetaJson = json_encode(array_combine(array_keys(credential_types()), array_map('credential_type_meta', array_keys(credential_types()))));
$pageScripts = <<<JS
<script>
$('#editClientBtn, #editClientLink').on('click', function (e) { e.preventDefault(); CRM.openEdit('#clientModal', $clientJson, 'Edit Client'); });
$(document).on('click', '.btn-edit-website', function () {
  CRM.post('api/websites.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) fillWebsiteModal(res.website); });
});
// Login credentials (admin) – one modal, fields and labels follow the selected login type
const credMeta = $credMetaJson;
const credLabelHint = { wordpress: 'e.g. Editor account – example.com', domain: 'e.g. Primary domain account', hosting: 'e.g. Main hosting account', cpanel: 'e.g. cPanel – server 12', ftp: 'e.g. FTP – example.com', email: 'e.g. info@example.com mailbox', database: 'e.g. Production database', other: 'e.g. Cloudflare account' };
function credApplyType(type) {
  const m = credMeta[type] || credMeta.other;
  $('#credProviderLabel').text(m.provider); $('#credProvider').attr('placeholder', m.provider_ph);
  $('#credUrlLabel').text(m.url); $('#credUrl').attr('placeholder', m.url_ph);
  $('#credUserLabel').text(m.user); $('#credLabel').attr('placeholder', credLabelHint[type] || credLabelHint.other);
}
$(document).on('change', '#credType', function () { credApplyType(this.value); });
function openCredModal(type) {
  const \$m = $('#credModal'); if (!\$m.length) return;
  const t = type && credMeta[type] ? type : '';
  \$m.find('form')[0].reset(); \$m.find('input[name=id]').val(''); $('#credPassHint').text('*'); \$m.find('[name=password]').prop('required', true);
  \$m.find('[name=type]').val(t || 'domain'); credApplyType(t || 'domain');
  \$m.find('.modal-title').text(t ? 'Add ' + credMeta[t].group : 'Add Login');
  bootstrap.Modal.getOrCreateInstance(\$m[0]).show();
}
$('#addCredBtn').on('click', function (e) { e.preventDefault(); openCredModal(''); });
$(document).on('click', '.add-cred', function (e) { e.preventDefault(); openCredModal($(this).data('credType') || ''); });
$(document).on('click', '.btn-edit-cred', function () {
  CRM.post('api/credentials.php', { action: 'get', id: $(this).data('id') }).done(res => {
    if (!res.success) return;
    const \$m = $('#credModal'); \$m.find('form')[0].reset(); CRM.fillForm(\$m.find('form'), res.credential);
    \$m.find('[name=password]').val('').prop('required', !res.credential.has_password);
    $('#credPassHint').text(res.credential.has_password ? '(saved – leave blank to keep)' : '*');
    credApplyType(res.credential.type);
    \$m.find('.modal-title').text('Edit ' + (credMeta[res.credential.type] || credMeta.other).group); bootstrap.Modal.getOrCreateInstance(\$m[0]).show();
  });
});
// Reveal / hide / copy passwords – values are fetched on demand and never embedded in the page
const secretCache = {};
function fetchSecret(kind, id) {
  const key = kind + ':' + id;
  if (secretCache[key]) return $.Deferred().resolve(secretCache[key]).promise();
  const req = kind === 'wp' ? CRM.post('api/websites.php', { action: 'wp_reveal', id: id }) : CRM.post('api/credentials.php', { action: 'reveal', id: id });
  return req.then(res => { if (!res.success) { CRM.toast(res.message || 'Could not reveal', 'error'); return $.Deferred().reject(); } secretCache[key] = res.password; return res.password; });
}
$(document).on('click', '.btn-reveal', function () {
  const \$b = $(this), kind = \$b.data('secret'), id = \$b.data('id');
  const \$s = $('.secret[data-secret="' + kind + '"][data-id="' + id + '"]');
  if (\$s.hasClass('revealed')) { \$s.removeClass('revealed').text('••••••••••'); \$b.find('i').attr('class', 'bi bi-eye'); return; }
  fetchSecret(kind, id).done(pw => { \$s.addClass('revealed').text(pw); \$b.find('i').attr('class', 'bi bi-eye-slash'); setTimeout(() => { if (\$s.hasClass('revealed')) { \$s.removeClass('revealed').text('••••••••••'); \$b.find('i').attr('class', 'bi bi-eye'); } }, 60000); });
});
$(document).on('click', '.btn-copy-secret', function () {
  fetchSecret($(this).data('secret'), $(this).data('id')).done(pw => { navigator.clipboard && navigator.clipboard.writeText(pw).then(() => CRM.toast('Password copied', 'info')); });
});
// Smooth anchor navigation with sticky-nav offset
$('.cc-nav a').on('click', function (e) {
  const t = $($(this).attr('href')); if (!t.length) return; e.preventDefault();
  if (t.is('button')) { t.tab('show'); const card = t.closest('.card'); $('html, body').animate({ scrollTop: card.offset().top - 130 }, 250); return; }
  $('html, body').animate({ scrollTop: t.offset().top - 130 }, 250);
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
