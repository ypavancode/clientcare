<?php
/**
 * Page header: <head>, sidebar and topbar (premium SaaS shell, v3.1).
 * Variables a page can set before including:
 *   $pageTitle       string
 *   $breadcrumbs     array of ['label' => ..., 'url' => ...] (last item without url)
 *   $pageActions     string (HTML for buttons on the right of the page header)
 *   $pageSubtitle    string (HTML)
 *   $useCharts       bool  (loads Chart.js)
 *   $useDtButtons    bool  (loads DataTables Buttons)
 *   $platformArea    bool  (Super Admin navigation)
 *   $hidePageHeader  bool
 */
if (!isset($pageTitle)) $pageTitle = APP_NAME;
Auth::requireLogin();
$currentUser = Auth::user();
$currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$section = basename(dirname($currentPath));
$script = basename($currentPath);
$qs = $_GET;

$isActive = function (string $sec, ?string $file = null, ?array $query = null) use ($section, $script, $qs): bool {
    if ($section !== $sec) return false;
    if ($file !== null && $script !== $file) return false;
    if ($query !== null) {
        foreach ($query as $k => $v) {
            if (($qs[$k] ?? null) !== $v) return false;
        }
    } elseif ($file !== null && in_array($sec, ['forms', 'websites', 'projects'], true) && !empty($qs['status'])) {
        return false;
    }
    return true;
};

// Header counters are cached for 20 s (shared by every page view) – one cheap read instead of 4 COUNT queries per request.
$hdr = Cache::remember(Cache::vkey('data', 'header:counters:' . Tenant::id()), 20, function () {
    return [
        'unread' => (int) DB::value("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND tenant_id = " . Tenant::id() . ""),
        'down'   => (int) DB::value("SELECT COUNT(*) FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.status IN (" . down_statuses_sql() . ") AND c.status = 'active' AND c.tenant_id = " . Tenant::id() . ""),
        'failed' => (int) DB::value("SELECT COUNT(*) FROM forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id WHERE f.status = 'failed' AND c.status = 'active' AND c.tenant_id = " . Tenant::id() . ""),
        'pages'  => (int) DB::value("SELECT COALESCE(SUM(w.pages_failed),0) FROM websites w JOIN clients c ON c.id = w.client_id WHERE c.status = 'active' AND c.tenant_id = " . Tenant::id() . ""),
    ];
});
$unreadCount = $hdr['unread'];
$badges = $hdr;
$cronStatus = Cache::remember('cron:status', 30, fn() => Monitor::cronStatus());
$schedulerState = class_exists('Scheduler') ? Scheduler::state() : ['mode' => 'cron', 'healthy' => $cronStatus['status'] === 'running', 'message' => $cronStatus['message']];
if ($cronStatus['status'] === 'stale' && empty($schedulerState['healthy'])) {
    try {
        Notifier::cronStale($cronStatus); // once per hour; creates the in-app alert and queues the email (delivered by the next tick / web heartbeat – never inline in a page request)
    } catch (Throwable $e) {
    }
}

$platformArea = !empty($platformArea);
$tenantRow = Tenant::current();
$plan = Tenant::id() ? Tenant::plan() : null;
$trialDays = Tenant::trialDaysLeft();
$usageSummary = Tenant::id() ? Tenant::usageSummary() : [];
$isSuper = Auth::isPlatformAdmin();
$sections = [];
if ($platformArea) {
    // ---- Super Admin (platform) navigation – platform management only, completely separate from customer workspaces
    $pa = fn(string $file) => $section === 'platform' && $script === $file;
    $sections = [
        'Platform' => [
            ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'url' => 'platform/index.php', 'active' => $pa('index.php')],
            ['label' => 'Clients', 'icon' => 'bi-buildings', 'url' => 'platform/customers.php', 'active' => $pa('customers.php') || $pa('customer.php')],
            ['label' => 'Websites', 'icon' => 'bi-globe2', 'url' => 'platform/websites.php', 'active' => $pa('websites.php')],
            ['label' => 'Users', 'icon' => 'bi-people', 'url' => 'platform/users.php', 'active' => $pa('users.php')],
        ],
        'Billing' => [
            ['label' => 'Plans & Pricing', 'icon' => 'bi-tags', 'url' => 'platform/plans.php', 'active' => $pa('plans.php')],
            ['label' => 'Subscriptions', 'icon' => 'bi-credit-card', 'url' => 'platform/subscriptions.php', 'active' => $pa('subscriptions.php')],
            ['label' => 'Usage', 'icon' => 'bi-bar-chart-steps', 'url' => 'platform/usage.php', 'active' => $pa('usage.php')],
        ],
        'Insights' => [
            ['label' => 'Analytics', 'icon' => 'bi-graph-up-arrow', 'url' => 'platform/analytics.php', 'active' => $pa('analytics.php')],
            ['label' => 'Audit Logs', 'icon' => 'bi-clipboard-data', 'url' => 'platform/audit.php', 'active' => $pa('audit.php')],
        ],
        'System' => [
            ['label' => 'SMTP / Email', 'icon' => 'bi-envelope-paper', 'url' => 'platform/emails.php', 'active' => $pa('emails.php')],
            ['label' => 'Email Templates', 'icon' => 'bi-file-earmark-text', 'url' => 'platform/templates.php', 'active' => $pa('templates.php')],
            ['label' => 'System Health', 'icon' => 'bi-activity', 'url' => 'platform/system.php', 'active' => $pa('system.php')],
            ['label' => 'Alert History', 'icon' => 'bi-journal-check', 'url' => 'platform/alerts.php', 'active' => $pa('alerts.php')],
            ['label' => 'System Settings', 'icon' => 'bi-gear', 'url' => 'platform/settings.php', 'active' => $pa('settings.php')],
        ],
        'Workspace' => [
            ['label' => 'Back to my workspace', 'icon' => 'bi-arrow-left-circle', 'url' => 'dashboard/index.php', 'active' => false],
        ],
    ];
} else {
    // ---- Customer workspace navigation
    $sections['Monitoring'] = [
        ['label' => 'Overview', 'icon' => 'bi-speedometer2', 'url' => 'dashboard/index.php', 'active' => $isActive('dashboard')],
        ['label' => 'Websites', 'icon' => 'bi-globe2', 'active' => $section === 'websites' && !in_array($script, ['pages.php', 'ssl.php'], true), 'children' => [
            ['label' => 'All Websites', 'url' => 'websites/index.php', 'active' => $isActive('websites', 'index.php') || $isActive('websites', 'view.php') || $isActive('websites', 'forms.php')],
            ['label' => 'Add Website', 'url' => 'websites/add.php', 'active' => $isActive('websites', 'add.php')],
            ['label' => 'Website Health', 'url' => 'websites/health.php', 'active' => $isActive('websites', 'health.php')],
            ['label' => 'Uptime', 'url' => 'websites/uptime.php', 'active' => $isActive('websites', 'uptime.php')],
        ]],
        ['label' => 'Pages', 'icon' => 'bi-files', 'url' => 'websites/pages.php', 'active' => $isActive('websites', 'pages.php'), 'badge' => $badges['pages'] ?: null, 'badgeClass' => 'danger'],
        ['label' => 'Forms', 'icon' => 'bi-ui-checks', 'active' => $section === 'forms', 'children' => [
            ['label' => 'Form Monitoring', 'url' => 'forms/monitoring.php', 'active' => $isActive('forms', 'monitoring.php') && empty($qs['status'])],
            ['label' => 'All Forms', 'url' => 'forms/index.php', 'active' => $isActive('forms', 'index.php')],
            ['label' => 'Form Discovery', 'url' => 'forms/discovery.php', 'active' => $isActive('forms', 'discovery.php')],
            ['label' => 'Failed Forms', 'url' => 'forms/monitoring.php?status=failed', 'active' => $isActive('forms', 'monitoring.php', ['status' => 'failed']), 'badge' => $badges['failed'] ?: null, 'badgeClass' => 'danger'],
        ]],
        ['label' => 'SSL', 'icon' => 'bi-shield-check', 'url' => 'websites/ssl.php', 'active' => $isActive('websites', 'ssl.php')],
        ['label' => 'Domains', 'icon' => 'bi-hdd-network', 'url' => 'domains/index.php', 'active' => $isActive('domains')],
        ['label' => 'Hosting', 'icon' => 'bi-server', 'url' => 'hosting/index.php', 'active' => $isActive('hosting')],
        ['label' => 'Analytics', 'icon' => 'bi-bar-chart-line', 'url' => 'analytics/index.php', 'active' => $section === 'analytics' || $isActive('websites', 'analytics.php'), 'badge' => Analytics::level() === 'none' ? 'pro' : null, 'badgeClass' => 'brand'],
    ];
    $sections['Operations'] = [
        ['label' => 'Incidents', 'icon' => 'bi-exclamation-octagon', 'url' => 'incidents/index.php', 'active' => $isActive('incidents', 'index.php')],
        ['label' => 'Alert History', 'icon' => 'bi-journal-check', 'url' => 'incidents/alerts.php', 'active' => $isActive('incidents', 'alerts.php')],
        ['label' => 'Alerts', 'icon' => 'bi-bell', 'url' => 'notifications/index.php', 'active' => $section === 'notifications', 'badge' => $unreadCount ?: null, 'badgeClass' => 'brand'],
        ['label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'url' => 'reports/index.php', 'active' => $section === 'reports'],
        ['label' => 'Status Pages', 'icon' => 'bi-broadcast-pin', 'url' => 'status/index.php', 'active' => $section === 'status'],
        ['label' => 'Activity', 'icon' => 'bi-clock-history', 'url' => 'activity/index.php', 'active' => $section === 'activity'],
    ];
    $sections['Workspace'] = [
        ['label' => 'Clients', 'icon' => 'bi-people', 'active' => $section === 'clients', 'children' => [
            ['label' => 'All Clients', 'url' => 'clients/index.php', 'active' => $isActive('clients', 'index.php') || $isActive('clients', 'view.php')],
            ['label' => 'Add Client', 'url' => 'clients/index.php?add=1', 'active' => false, 'attr' => 'data-open-modal="#clientModal"'],
        ]],
        ['label' => 'Website Projects', 'icon' => 'bi-kanban', 'active' => $section === 'projects', 'children' => array_values(array_filter([
            ['label' => 'All Projects', 'url' => 'projects/index.php', 'active' => $isActive('projects', 'index.php') && empty($qs['status']) || $isActive('projects', 'view.php')],
            ['label' => 'In Progress', 'url' => 'projects/index.php?status=in_progress', 'active' => $isActive('projects', 'index.php', ['status' => 'in_progress'])],
            ['label' => 'Live Websites', 'url' => 'projects/index.php?status=live', 'active' => $isActive('projects', 'index.php', ['status' => 'live'])],
            Auth::isAdmin() ? ['label' => 'Departments & Types', 'url' => 'projects/departments.php', 'active' => $isActive('projects', 'departments.php')] : null,
        ]))],
    ];
    $sections['Workspace'][] = ['label' => 'Team', 'icon' => 'bi-person-badge', 'url' => 'users/index.php', 'active' => $section === 'users' && $script === 'index.php'];
    if (in_array(Auth::legacyRole(), ['admin', 'manager'], true)) $sections['Workspace'][] = ['label' => 'Email Logs', 'icon' => 'bi-envelope-paper', 'url' => 'emails/index.php', 'active' => $section === 'emails'];
    if (Auth::isOwner() || Auth::isAdmin()) $sections['Workspace'][] = ['label' => 'Billing & Plan', 'icon' => 'bi-credit-card', 'url' => 'billing/index.php', 'active' => $section === 'billing', 'badge' => $trialDays !== null && $trialDays <= 3 ? 'trial' : null, 'badgeClass' => 'warning'];
    if (Auth::isAdmin()) $sections['Workspace'][] = ['label' => 'Settings', 'icon' => 'bi-gear', 'url' => 'settings/index.php', 'active' => $section === 'settings'];
    $sections['Workspace'][] = ['label' => 'Help & Support', 'icon' => 'bi-life-preserver', 'url' => 'public/contact-sales.php', 'active' => false, 'attr' => 'target="_blank" rel="noopener"'];
    if ($isSuper) $sections['Super Admin'] = [['label' => 'Platform Console', 'icon' => 'bi-shield-shaded', 'url' => 'platform/index.php', 'active' => false]];
}
$appName = setting('platform_name', 'Outline Monitor');
$roleLabel = $isSuper ? (Auth::isPlatformOwner() ? 'Owner' : 'Super Admin') : Registration::roleLabel($currentUser['role']);
$usagePct = !empty($usageSummary['websites']) && $usageSummary['websites']['limit'] ? min(100, (int) round($usageSummary['websites']['current'] / max(1, $usageSummary['websites']['limit']) * 100)) : null;
$navIndex = 0;
$isPjax = !empty($_SERVER['HTTP_X_PJAX']);
// the sidebar link that is active for this page – the client re-uses it after an AJAX navigation
$activeHref = '';
foreach ($sections as $items) foreach ($items as $item) { if (!empty($item['children'])) { foreach ($item['children'] as $child) if (!empty($child['active'])) { $activeHref = url($child['url']); break 3; } } elseif (!empty($item['active'])) { $activeHref = url($item['url']); break 2; } }
ob_start();
if (!empty($breadcrumbs)): ?>
          <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= url($platformArea ? 'platform/index.php' : 'dashboard/index.php') ?>" aria-label="Home"><i class="bi bi-house-door"></i></a></li>
            <?php foreach ($breadcrumbs as $bc): ?>
              <?php if (!empty($bc['url'])): ?><li class="breadcrumb-item"><a href="<?= url($bc['url']) ?>"><?= e($bc['label']) ?></a></li>
              <?php else: ?><li class="breadcrumb-item active" aria-current="page"><?= e($bc['label']) ?></li><?php endif; ?>
            <?php endforeach; ?>
          </ol></nav>
<?php else: ?>
          <span class="fw-600"><?= e($pageTitle) ?></span>
<?php endif;
$topbarTitleHtml = ob_get_clean();
if ($isPjax) { header('X-PJAX: 1'); header('Cache-Control: no-store'); header('Vary: X-PJAX'); }
?>
<?php if ($isPjax): ?>
<div id="pjax-meta" hidden data-title="<?= e($pageTitle) ?> · <?= e($appName) ?>" data-nav="<?= e($activeHref) ?>" data-platform="<?= $platformArea ? 1 : 0 ?>"><?= $topbarTitleHtml ?></div>
<?php else: ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0b0b0b">
<title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
<script>try { if (localStorage.getItem('om.sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>
<link rel="icon" href="<?= asset('images/icon.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdn.datatables.net" crossorigin>
<link rel="preload" href="<?= asset('fonts/inter-latin-wght.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<?php if (!empty($useDtButtons)): ?><link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css"><?php endif; ?>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= APP_VERSION ?>">
<!-- jQuery is loaded here (not in the footer) because modal partials and shared scripts included in the page body use $ at load time -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
</head>
<body data-platform="<?= $platformArea ? 1 : 0 ?>">
<div class="pjax-bar" id="pjaxBar" aria-hidden="true"></div>
<div class="bg-orbs" aria-hidden="true"></div>
<div class="app-wrapper">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
      <a href="<?= url($platformArea ? 'platform/index.php' : 'dashboard/index.php') ?>" aria-label="<?= e($appName) ?>"><img src="<?= company_logo_url('light') ?>" alt="<?= e($appName) ?>" class="brand-logo"><span class="brand-mark"><?= e(strtoupper(mb_substr($appName, 0, 1))) ?></span></a>
      <button class="btn-icon d-lg-none" id="sidebarClose" aria-label="Close menu"><i class="bi bi-x-lg"></i></button>
    </div>
    <button class="sidebar-collapse-btn" id="sidebarCollapse" aria-label="Collapse navigation" title="Collapse / expand"><i class="bi bi-chevron-left"></i></button>
    <nav class="sidebar-nav">
      <?php foreach ($sections as $secLabel => $items): if (!$items) continue; ?>
      <div class="nav-section"><?= e($secLabel) ?></div>
      <ul>
        <?php foreach ($items as $item): $navIndex++; ?>
          <?php if (!empty($item['children'])): ?>
            <li class="nav-group <?= $item['active'] ? 'open active' : '' ?>">
              <a href="#navgrp<?= $navIndex ?>" class="nav-link nav-toggle" data-bs-toggle="collapse" aria-expanded="<?= $item['active'] ? 'true' : 'false' ?>" data-tip="<?= e($item['label']) ?>">
                <i class="bi <?= $item['icon'] ?>"></i><span><?= e($item['label']) ?></span><i class="bi bi-chevron-down caret"></i>
              </a>
              <ul class="collapse nav-sub <?= $item['active'] ? 'show' : '' ?>" id="navgrp<?= $navIndex ?>">
                <?php foreach ($item['children'] as $child): ?>
                  <li><a href="<?= url($child['url']) ?>" class="nav-link <?= $child['active'] ? 'active' : '' ?>" <?= $child['attr'] ?? '' ?>>
                    <span><?= e($child['label']) ?></span>
                    <?php if (!empty($child['badge'])): ?><span class="nav-badge bg-<?= $child['badgeClass'] ?>"><?= $child['badge'] ?></span><?php endif; ?>
                  </a></li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php else: ?>
            <li><a href="<?= url($item['url']) ?>" class="nav-link <?= $item['active'] ? 'active' : '' ?>" data-tip="<?= e($item['label']) ?>" <?= $item['active'] ? 'aria-current="page"' : '' ?> <?= $item['attr'] ?? '' ?>>
              <i class="bi <?= $item['icon'] ?>"></i><span><?= e($item['label']) ?></span>
              <?php if (!empty($item['badge'])): ?><span class="nav-badge bg-<?= $item['badgeClass'] ?>"><?= $item['badge'] ?></span><?php endif; ?>
            </a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
      <?php endforeach; ?>
      <ul class="mt-3"><li><a href="<?= url('auth/logout.php') ?>" class="nav-link" id="logoutLink" data-tip="Logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li></ul>
    </nav>
    <div class="sidebar-footer">
      <?php if (!$platformArea && $plan): ?>
        <a href="<?= url('billing/index.php') ?>" class="plan-mini" title="<?= $usagePct !== null ? 'Websites ' . $usageSummary['websites']['current'] . ' / ' . $usageSummary['websites']['limit'] . ' · Forms ' . $usageSummary['forms']['current'] . ' / ' . ($usageSummary['forms']['limit'] ?? '∞') : 'Billing & plan' ?>">
          <div><span class="pm-l">Current plan</span><span class="pm-v"><?= e($plan['name']) ?><?= $trialDays !== null ? ' <span class="badge bg-warning text-dark">' . max(0, $trialDays) . 'd trial</span>' : '' ?><i class="bi bi-arrow-right-short"></i></span><?php if ($usagePct !== null): ?><span class="usage-mini"><span style="width:<?= $usagePct ?>%"></span></span><?php endif; ?></div>
        </a>
      <?php elseif ($platformArea): ?><div class="plan-mini"><div><span class="pm-l">Signed in as</span><span class="pm-v"><i class="bi bi-shield-shaded"></i><?= Auth::isPlatformOwner() ? 'Owner · Super Admin' : 'Super Admin' ?></span></div></div><?php endif; ?>
      <div class="version-mini">v<?= APP_VERSION ?></div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="btn-icon d-lg-none" id="sidebarToggle" aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div class="topbar-title d-none d-md-block" id="topbarTitle"><?= $topbarTitleHtml ?></div>
      <div class="topbar-search" id="globalSearch" role="search">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control" placeholder="Search clients, websites, pages, forms…" autocomplete="off" id="globalSearchInput" aria-label="Search">
        <kbd>Ctrl K</kbd>
        <div class="search-results" id="globalSearchResults"></div>
      </div>
      <div class="topbar-actions">
        <?php if ($badges['down'] > 0): ?>
          <a href="<?= url('websites/health.php?status=down') ?>" class="topbar-alert" title="<?= $badges['down'] ?> website(s) down"><i class="bi bi-exclamation-octagon-fill"></i><span class="d-none d-sm-inline"><?= $badges['down'] ?> down</span></a>
        <?php endif; ?>
        <?php if ($badges['pages'] > 0): ?>
          <a href="<?= url('websites/pages.php?status=failed') ?>" class="topbar-alert d-none d-lg-inline-flex" title="<?= $badges['pages'] ?> page(s) failed"><i class="bi bi-file-earmark-x-fill"></i><span><?= $badges['pages'] ?> page<?= $badges['pages'] > 1 ? 's' : '' ?> failed</span></a>
        <?php endif; ?>
        <span class="topbar-status d-none d-lg-inline-flex" data-scheduler-state="<?= e($schedulerState['mode']) ?>" title="<?= e($schedulerState['message']) ?>"><span class="live-dot <?= !empty($schedulerState['healthy']) ? '' : 'danger' ?>"></span><?= !empty($schedulerState['healthy']) ? 'Monitoring live' : 'Monitoring paused' ?></span>
        <div class="dropdown">
          <button class="btn-icon position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notifications" id="notifBell">
            <i class="bi bi-bell"></i>
            <span class="notif-count <?= $unreadCount ? '' : 'd-none' ?>" id="notifCount"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-dropdown">
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
              <strong>Notifications</strong>
              <a href="#" class="small" id="notifMarkAll">Mark all read</a>
            </div>
            <div class="notif-list" id="notifList"><div class="p-3"><div class="skeleton skeleton-line w-75"></div><div class="skeleton skeleton-line w-50"></div><div class="skeleton skeleton-line w-75"></div></div></div>
            <a href="<?= url('notifications/index.php') ?>" class="d-block text-center small py-2 border-top fw-600">View all alerts</a>
          </div>
        </div>
        <div class="dropdown">
          <button class="user-menu" data-bs-toggle="dropdown" aria-label="User menu">
            <span class="avatar"><?= e(strtoupper(mb_substr($currentUser['name'], 0, 1))) ?></span>
            <span class="d-none d-md-inline-block"><span class="user-name"><?= e($currentUser['name']) ?></span><small class="user-role"><?= e($roleLabel) ?><?= $tenantRow ? ' · ' . e(truncate($tenantRow['name'], 22)) : '' ?></small></span>
            <i class="bi bi-chevron-down d-none d-md-inline small"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px">
            <li><span class="dropdown-item-text small text-muted"><?= e($currentUser['email']) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= url('users/profile.php') ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
            <?php if (Auth::isOwner() || Auth::isAdmin()): ?><li><a class="dropdown-item" href="<?= url('billing/index.php') ?>"><i class="bi bi-credit-card me-2"></i>Billing &amp; Plan</a></li><?php endif; ?>
            <?php if (Auth::isAdmin()): ?><li><a class="dropdown-item" href="<?= url('settings/index.php') ?>"><i class="bi bi-gear me-2"></i>Workspace Settings</a></li><?php endif; ?>
            <?php if ($isSuper): ?><li><a class="dropdown-item" href="<?= url('platform/index.php') ?>"><i class="bi bi-shield-shaded me-2 text-brand"></i>Super Admin Console</a></li><?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= url('auth/logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </header>
<?php endif; ?>
    <main class="content" id="main" data-page="<?= e($section . '/' . $script) ?>">
      <?php if ($isSuper && !empty($_SESSION['act_as_tenant']) && !$platformArea): ?>
        <div class="alert alert-dark d-flex align-items-center gap-3 py-2 mb-3" role="alert"><i class="bi bi-eye-fill fs-5 text-brand"></i><div class="flex-grow-1"><strong>Viewing as customer:</strong> <?= e(Tenant::name()) ?> <span class="text-white-50">(support access – every action is logged)</span></div><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"act_as_stop"}'>Exit</button></div>
      <?php endif; ?>
      <?php if ($isSuper && setting('maintenance_mode', 0) && !($platformArea && in_array($script, ['index.php', 'system.php'], true))): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-3 py-2 mb-3" role="alert"><i class="bi bi-tools fs-5"></i><div class="flex-grow-1"><strong>🟠 Maintenance mode is ON</strong> – customers see the 503 maintenance page; Super Admins keep access. <a href="<?= url('platform/index.php') ?>" class="alert-link">Platform dashboard</a></div><button class="btn btn-success btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"maintenance_toggle","enable":0}' data-confirm="End maintenance mode? Customers regain access immediately." data-confirm-btn="Go live" data-icon="question" data-reload="1"><i class="bi bi-play-circle me-1"></i>Go live</button></div>
      <?php endif; ?>
      <?php if (empty($schedulerState['healthy']) && $isSuper): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3 py-2 mb-3 cron-warning" role="alert">
          <i class="bi bi-exclamation-triangle-fill fs-5"></i>
          <div><strong>Scheduler:</strong> <?= e($schedulerState['message']) ?> <a href="<?= url('platform/system.php') ?>" class="alert-link">Scheduler health</a></div>
        </div>
      <?php endif; ?>
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?> alert-dismissible fade show py-2 mb-3" role="alert"><?= e($f['message']) ?><button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button></div>
      <?php endforeach; ?>
      <?php if (!empty($pageTitle) && empty($hidePageHeader)): ?>
      <div class="page-header <?= e($pageHeaderClass ?? '') ?>">
        <div>
          <h1 class="page-title"><?= e($pageTitle) ?></h1>
          <?php if (!empty($pageSubtitle)): ?><div class="page-subtitle"><?= $pageSubtitle ?></div><?php endif; ?>
        </div>
        <?php if (!empty($pageActions)): ?><div class="page-actions"><?= $pageActions ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
