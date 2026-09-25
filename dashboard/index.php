<?php
/**
 * Workspace overview (compact): greeting, health banner, KPI cards, charts with side legends, plan usage,
 * monitoring engine, recent incidents and activity. Numbers come from Stats (grouped, cached, cron-warmed);
 * chart series load after paint from api/stats.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$interval = max(1, (int) setting('website_check_interval', 5));
$s = Stats::dashboard();
$panels = Stats::panels();
$downSites = $panels['down']; $failedPages = $panels['failed_pages']; $failedForms = $panels['failed_forms']; $sslList = $panels['ssl']; $expiring = $panels['expiring']; $recentActivity = $panels['activity'];
$lastCheck = Cache::remember('health:lastscan', 20, fn() => DB::value("SELECT last_done_at FROM queue_state WHERE queue = 'website'"));
$usage = Tenant::usageSummary();
$plan = Tenant::plan();
$trialDays = Tenant::trialDaysLeft();
$noSites = ($usage['websites']['current'] ?? 0) === 0;
$issues = $s['sites_down'] + $s['pages_failed'] + $s['forms_failed'] + $s['ssl_issues'] + $s['domain_expiring'] + $s['hosting_expiring'];
$checkedSites = $s['sites_online'] + $s['sites_down'];
$healthPct = $checkedSites ? (int) round(($s['sites_online'] / $checkedSites) * 100) : 100;
$healthColor = $s['sites_down'] ? 'var(--danger)' : ($issues ? 'var(--brand)' : 'var(--success)');
$schedulerState = Scheduler::state();
$jobs = DB::fetchAll("SELECT * FROM scheduler_jobs WHERE enabled = 1 ORDER BY priority");
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim(Auth::user()['name']))[0];
$recentIncidents = DB::fetchAll("SELECT * FROM (
    SELECT 'Website' AS kind, w.name AS label, i.started_at, i.resolved_at, i.failure_reason AS reason, i.error_message AS detail, w.id AS website_id FROM website_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ?
    UNION ALL SELECT 'Form', f.name, i.started_at, i.resolved_at, i.failure_reason, i.error_message, i.website_id FROM form_incidents i JOIN forms f ON f.id = i.form_id WHERE i.tenant_id = ?
    UNION ALL SELECT 'SSL', w.name, i.started_at, i.resolved_at, i.status, i.error_message, i.website_id FROM ssl_incidents i JOIN websites w ON w.id = i.website_id WHERE i.tenant_id = ?
  ) x ORDER BY started_at DESC LIMIT 6", [Tenant::id(), Tenant::id(), Tenant::id()]);
$analyticsLevel = Analytics::level();

$pageTitle = 'Overview';
$hidePageHeader = true;
$useCharts = true;
include ROOT_PATH . '/includes/layout/header.php';

function kpi(string $label, $value, string $icon, string $tint, string $link, ?string $sub = null, bool $alert = false): string
{
    $cls = $alert && $value > 0 ? ' alert-card border-' . str_replace('tint-', '', $tint) : '';
    return '<a href="' . url($link) . '" class="stat-card' . $cls . '"><div class="stat-icon ' . $tint . '"><i class="bi ' . $icon . '"></i></div><div><div class="stat-value count-up" data-count="' . (int) $value . '">0</div><div class="stat-label">' . e($label) . '</div>' . ($sub ? '<div class="stat-trend text-muted">' . $sub . '</div>' : '') . '</div></a>';
}
$jobIcons = ['check-websites' => 'bi-globe2', 'check-pages' => 'bi-files', 'check-ssl' => 'bi-shield-check', 'check-forms' => 'bi-ui-checks', 'process-notifications' => 'bi-send', 'discover-forms' => 'bi-search', 'check-expiry' => 'bi-calendar-check', 'housekeeping' => 'bi-broom'];
$every = fn(int $m) => $m >= 1440 ? intdiv($m, 1440) . ' d' : ($m >= 60 ? intdiv($m, 60) . ' h' : $m . ' min');
?>
<div class="dashboard-page">

<div class="greet-row">
  <div class="d-flex align-items-center gap-3 min-w-0">
    <span class="g-icon"><i class="bi <?= $hour < 18 ? 'bi-brightness-high' : 'bi-moon-stars' ?>"></i></span>
    <div class="min-w-0"><h1><?= e($greet) ?>, <?= e($firstName) ?></h1>
      <div class="g-sub"><?php if ($noSites): ?>Your workspace is ready – add a website and monitoring starts within minutes.<?php elseif (!$issues): ?>Your workspace is healthy. All systems are running smoothly.<?php else: ?><?= number_format($issues) ?> item<?= $issues > 1 ? 's need' : ' needs' ?> attention. Last check <?= e(time_ago($lastCheck)) ?>.<?php endif; ?></div></div>
  </div>
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <div class="g-date"><?= date('D, d M Y') ?><br><?= date('h:i A') ?></div>
    <div class="quick-actions d-flex flex-wrap gap-2">
      <a href="<?= url('websites/add.php') ?>" class="btn btn-brand btn-sm"><i class="bi bi-plus-lg me-1"></i>Add website</a>
      <button class="btn btn-light btn-sm" data-open-modal="#clientModal"><i class="bi bi-person-plus me-1"></i>Add client</button>
      <div class="dropdown"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-arrow-repeat me-1"></i>Check now</button>
        <ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item run-check" href="#" data-check="check_websites"><i class="bi bi-globe2 me-2"></i>Check all websites</a></li><li><a class="dropdown-item run-check" href="#" data-check="check_pages"><i class="bi bi-files me-2"></i>Scan all pages</a></li><li><a class="dropdown-item run-check" href="#" data-check="check_forms"><i class="bi bi-ui-checks me-2"></i>Test all forms</a></li></ul></div>
    </div>
  </div>
</div>

<?php if ($trialDays !== null): ?>
<div class="alert <?= $trialDays <= 3 ? 'alert-warning' : 'alert-light' ?> d-flex flex-wrap align-items-center gap-2 mb-3"><i class="bi bi-hourglass-split"></i><span class="flex-grow-1"><strong><?= e($plan['name']) ?> trial</strong> · <?= max(0, $trialDays) ?> day<?= $trialDays === 1 ? '' : 's' ?> left<?= $trialDays <= 0 ? ' – the workspace will switch to the Free plan limits' : '' ?>.</span><a href="<?= url('billing/index.php') ?>" class="btn btn-sm btn-dark">Choose a plan</a></div>
<?php elseif (!empty($plan['expired_from'])): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-3"><i class="bi bi-exclamation-triangle"></i><span class="flex-grow-1">Your <?= e($plan['expired_from']) ?> trial has ended – the workspace runs with the <strong>Free</strong> plan limits.</span><a href="<?= url('billing/index.php') ?>" class="btn btn-sm btn-brand">Upgrade</a></div>
<?php endif; ?>

<section class="hero-panel">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <div class="health-ring" style="--p:<?= $healthPct ?>;--c:<?= $healthColor ?>" role="img" aria-label="Website health <?= $healthPct ?> percent"><div><div class="hr-val"><?= $healthPct ?>%</div><div class="hr-lbl">health</div></div></div>
    <div class="flex-grow-1 min-w-0">
      <h1><?= $noSites ? 'No websites yet' : ($s['sites_down'] ? $s['sites_down'] . ' website' . ($s['sites_down'] > 1 ? 's are' : ' is') . ' down' : ($issues ? 'Some items need attention' : 'Your websites are running smoothly')) ?></h1>
      <div class="h-line"><span><b><?= $s['sites_down'] ?></b> websites down</span><span>·</span><span><b><?= number_format($s['pages_failed']) ?></b> pages failing</span><span>·</span><span><b><?= $s['forms_failed'] ?></b> forms failing</span><span>·</span><span><b><?= $s['ssl_issues'] ?></b> SSL issues</span><?php if (!$noSites): ?><span>·</span><span>last check <?= e(time_ago($lastCheck)) ?></span><?php endif; ?></div>
      <?php if ($noSites): ?><a href="<?= url('websites/add.php') ?>" class="btn btn-brand btn-sm mt-2"><i class="bi bi-plus-lg me-1"></i>Add your first website</a><?php endif; ?>
    </div>
    <div class="h-quote"><div class="q1">Monitor today.</div><div class="q2">A more reliable tomorrow.</div></div>
  </div>
</section>

<div class="kpi-grid stagger mb-3">
  <?= kpi('Clients', $s['clients_total'], 'bi-people', 'tint-dark', 'clients/index.php') ?>
  <?= kpi('Websites', $s['sites_total'], 'bi-globe2', 'tint-brand', 'websites/index.php') ?>
  <?= kpi('Healthy websites', $s['sites_online'], 'bi-wifi', 'tint-success', 'websites/health.php?status=online') ?>
  <?= kpi('Websites down', $s['sites_down'], 'bi-wifi-off', 'tint-danger', 'websites/health.php?status=down', null, true) ?>
  <?= kpi('Pages monitored', $s['pages_total'], 'bi-files', 'tint-dark', 'websites/pages.php') ?>
  <?= kpi('Forms monitored', $s['forms_total'], 'bi-ui-checks', 'tint-dark', 'forms/index.php') ?>
  <?= kpi('SSL issues', $s['ssl_issues'], 'bi-shield-exclamation', 'tint-warning', 'websites/health.php?ssl=issues', null, true) ?>
  <?= kpi('Domain expiries', $s['domain_expiring'], 'bi-calendar-x', 'tint-warning', 'domains/index.php?filter=expiring', null, true) ?>
  <?= kpi('Team members', $usage['users']['current'] ?? 1, 'bi-person-badge', 'tint-info', 'users/index.php') ?>
  <?= kpi('Failed forms', $s['forms_failed'], 'bi-x-octagon', 'tint-danger', 'forms/monitoring.php?status=failed', null, true) ?>
  <?php if (!empty($s['forms_blocked'])): ?><?= kpi('CAPTCHA-protected forms', $s['forms_blocked'], 'bi-shield-lock', 'tint-info', 'forms/monitoring.php?status=captcha_blocked') ?><?php endif; ?>
</div>

<?php if ($downSites || $failedPages || $failedForms || $sslList || $expiring): ?>
<div class="row g-3 mb-3 attention-row" id="attention">
  <?php if ($downSites): ?><div class="col-xl col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-exclamation-octagon text-danger me-2"></i>Websites down</span><a href="<?= url('websites/health.php?status=down') ?>" class="small">View all</a></div><div class="card-body list-cards"><?php foreach (array_slice($downSites, 0, 5) as $w): ?><a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="list-card"><span class="lc-icon tint-danger"><i class="bi bi-wifi-off"></i></span><span class="lc-main"><span class="lc-title"><?= e($w['name']) ?></span><span class="lc-sub"><?= e($w['failure_reason'] ?: truncate($w['error_message'] ?? '', 50)) ?></span></span><span class="lc-side"><?= e(time_ago($w['down_since'] ?: $w['last_failed_at'])) ?></span></a><?php endforeach; ?></div></div></div><?php endif; ?>
  <?php if ($failedForms): ?><div class="col-xl col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-x-octagon text-danger me-2"></i>Failed forms</span><a href="<?= url('forms/monitoring.php?status=failed') ?>" class="small">View all</a></div><div class="card-body list-cards"><?php foreach (array_slice($failedForms, 0, 5) as $f): ?><a href="<?= url('forms/index.php?website_id=' . $f['website_id'] . '&highlight=' . $f['id']) ?>" class="list-card"><span class="lc-icon tint-danger"><i class="bi bi-ui-checks"></i></span><span class="lc-main"><span class="lc-title"><?= e($f['name']) ?></span><span class="lc-sub"><?= e($f['website_name']) ?> · <?= e(truncate($f['last_error'] ?? 'Failed', 50)) ?></span></span><span class="lc-side"><?= e(time_ago($f['last_tested_at'])) ?></span></a><?php endforeach; ?></div></div></div><?php endif; ?>
  <?php if ($failedPages): ?><div class="col-xl col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-file-earmark-x text-danger me-2"></i>Failed pages</span><a href="<?= url('websites/pages.php?status=failed') ?>" class="small">View all</a></div><div class="card-body list-cards"><?php foreach (array_slice($failedPages, 0, 5) as $p): ?><a href="<?= url('websites/view.php?id=' . $p['website_id'] . '&tab=pages&filter=failed') ?>" class="list-card"><span class="lc-icon tint-danger"><i class="bi bi-file-earmark-x"></i></span><span class="lc-main"><span class="lc-title"><?= e($p['title'] ?: Monitor::pathLabel($p['path'])) ?></span><span class="lc-sub"><?= e($p['website_name']) ?> · <?= e($p['http_code'] ?: 'No response') ?> <?= e($p['failure_reason']) ?></span></span><span class="lc-side"><?= e(time_ago($p['last_failed_at'])) ?></span></a><?php endforeach; ?></div></div></div><?php endif; ?>
  <?php if ($sslList || $expiring): ?><div class="col-xl col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-shield-exclamation text-warning me-2"></i>SSL, domains &amp; hosting</span><a href="<?= url('websites/ssl.php') ?>" class="small">SSL overview</a></div><div class="card-body list-cards"><?php foreach (array_slice($sslList, 0, 3) as $w): ?><a href="<?= url('websites/view.php?id=' . $w['id']) ?>" class="list-card"><span class="lc-icon tint-warning"><i class="bi bi-shield-exclamation"></i></span><span class="lc-main"><span class="lc-title"><?= e($w['name']) ?></span><span class="lc-sub">SSL · <?= e($w['ssl_error'] ? truncate($w['ssl_error'], 50) : ($w['ssl_expires_at'] ? 'expires ' . format_date($w['ssl_expires_at']) : '')) ?></span></span><span class="lc-side"><?= ssl_status_badge($w['ssl_status'], $w['ssl_days_left']) ?></span></a><?php endforeach; ?><?php foreach (array_slice($expiring, 0, 4) as $x): ?><a href="<?= url(($x['kind'] === 'Domain' ? 'domains' : 'hosting') . '/index.php?highlight=' . $x['id']) ?>" class="list-card"><span class="lc-icon tint-warning"><i class="bi <?= $x['kind'] === 'Domain' ? 'bi-hdd-network' : 'bi-server' ?>"></i></span><span class="lc-main"><span class="lc-title"><?= e($x['label']) ?></span><span class="lc-sub"><?= e($x['kind']) ?> · <?= e($x['client_name']) ?> · <?= format_date($x['expiry_date']) ?></span></span><span class="lc-side"><?= expiry_badge($x['expiry_date']) ?></span></a><?php endforeach; ?></div></div></div><?php endif; ?>
</div>
<?php endif; ?>

<div id="dashCharts">
<div class="row g-3 mb-3">
  <div class="col-xl-4 col-md-6">
    <div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-activity me-2"></i>Uptime &amp; response time</span><select class="form-select form-select-sm w-auto" id="uptimeRange" aria-label="Range"><option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option><option value="90">Last 90 days</option></select></div>
      <div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chUptime" class="d-none"></canvas></div><div class="chart-legend mt-2"><span style="color:#FCAF17">Response time (ms)</span><span style="color:#fcd98a">Uptime (%)</span></div></div></div>
  </div>
  <div class="col-xl-4 col-md-6">
    <div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-grid me-2"></i>Website health</span><a href="<?= url('websites/health.php') ?>" class="small">Details ›</a></div>
      <div class="card-body"><div class="chart-split"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chSites" class="d-none"></canvas><div class="donut-center d-none" id="chSitesCenter"></div></div><div class="legend" id="legSites"></div></div></div></div>
  </div>
  <div class="col-xl-4 col-md-12">
    <div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-activity me-2"></i>Incidents per day</span><a href="<?= url('incidents/index.php') ?>" class="small">All incidents ›</a></div>
      <div class="card-body"><div class="chart-box sm"><div class="skeleton skeleton-chart"></div><canvas id="chIncidents" class="d-none"></canvas></div><div class="chart-legend mt-2"><span style="color:#ef4444">Website</span><span style="color:#FCAF17">Page</span><span style="color:#3b82f6">Form</span><span style="color:#9aa1ac">SSL</span></div></div></div>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-xl-8">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-ui-checks me-2"></i>Form health</span><a href="<?= url('forms/monitoring.php') ?>" class="small">Monitor ›</a></div>
          <div class="card-body"><div class="chart-split"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chForms" class="d-none"></canvas><div class="donut-center d-none" id="chFormsCenter"></div></div><div class="legend" id="legForms"></div></div></div></div>
      </div>
      <div class="col-md-6">
        <div class="card h-100"><div class="card-header"><span><i class="bi bi-speedometer me-2"></i>Plan usage · <?= e($plan['name']) ?></span><a href="<?= url('billing/index.php') ?>" class="small">Billing ›</a></div>
          <div class="card-body d-grid gap-2">
            <?php $ic = ['websites' => 'bi-globe2', 'pages' => 'bi-files', 'forms' => 'bi-ui-checks', 'users' => 'bi-people', 'status_pages' => 'bi-broadcast-pin']; foreach (['websites', 'pages', 'forms', 'users', 'status_pages'] as $k): if (!isset($usage[$k])) continue; if ($k === 'status_pages' && $usage[$k]['limit'] === 0) continue; $u = $usage[$k]; ?>
              <div class="usage-row"><span class="u-icon"><i class="bi <?= $ic[$k] ?>"></i></span><span class="u-lbl"><?= e($u['label']) ?></span><div class="progress"><div class="progress-bar <?= $u['state'] === 'full' ? 'bg-danger' : ($u['state'] === 'warn' ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $u['limit'] === null ? 4 : max(2, $u['pct']) ?>%"></div></div><span class="u-val"><?= number_format($u['current']) ?> / <?= $u['limit'] === null ? 'unlimited' : number_format($u['limit']) ?></span></div>
            <?php endforeach; ?>
            <?php if ($analyticsLevel !== 'none'): $q = Analytics::quota(); ?><div class="usage-row"><span class="u-icon"><i class="bi bi-bar-chart-line"></i></span><span class="u-lbl">Analytics views</span><div class="progress"><div class="progress-bar <?= $q['state'] === 'full' ? 'bg-danger' : ($q['state'] === 'warn' ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $q['limit'] === null ? 4 : max(2, $q['pct']) ?>%"></div></div><span class="u-val"><?= number_format($q['current']) ?> / <?= $q['limit'] === null ? 'unlimited' : number_format($q['limit']) ?></span></div><?php endif; ?>
            <?php $limited = array_filter($usage, fn($u) => $u['state'] !== 'ok'); $hitFull = (bool) array_filter($limited, fn($u) => $u['state'] === 'full'); if ($limited): ?><div class="small-xs <?= $hitFull ? 'text-danger' : 'text-warning' ?> fw-600 mt-1"><i class="bi bi-exclamation-circle me-1"></i><?= $hitFull ? 'Plan limit reached' : 'Approaching a plan limit' ?> – <a href="<?= url('billing/index.php') ?>">upgrade to continue</a></div><?php endif; ?>
            <div class="small-xs text-muted mt-1">Checks every <?= Tenant::interval('website') ?> min · forms every <?= Tenant::interval('form') ?> min · history <?= (int) $plan['retention_days'] ?> days</div>
          </div></div>
      </div>
      <div class="col-md-6">
        <div class="card h-100"><div class="card-header"><span><i class="bi bi-lightning-charge me-2"></i>Recent incidents</span><a href="<?= url('incidents/index.php') ?>" class="small">View all ›</a></div>
          <div class="card-body p-0">
            <?php if (!$recentIncidents): ?><?= empty_state('bi-emoji-smile', 'No incidents yet', 'All systems are running smoothly.', '', 'py-3') ?>
            <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0"><thead><tr><th>Type</th><th>Website</th><th>Message</th><th class="text-end">Time</th></tr></thead><tbody>
              <?php foreach ($recentIncidents as $i): ?><tr><td><span class="badge <?= $i['resolved_at'] ? 'bg-success-subtle' : 'bg-danger-subtle' ?>"><?= e($i['kind']) ?></span></td><td><a href="<?= url('websites/view.php?id=' . $i['website_id']) ?>" class="fw-600"><?= e(truncate($i['label'], 28)) ?></a></td><td class="text-muted"><?= e(truncate(ucwords(str_replace('_', ' ', (string) ($i['reason'] ?: $i['detail']))), 40)) ?></td><td class="text-end text-muted text-nowrap"><?= e(time_ago($i['resolved_at'] ?: $i['started_at'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
          </div></div>
      </div>
      <div class="col-md-6">
        <div class="card h-100"><div class="card-header"><span><i class="bi bi-clock-history me-2"></i>Recent activity</span><a href="<?= url('activity/index.php') ?>" class="small">View all ›</a></div>
          <div class="card-body">
            <?php if (!$recentActivity): ?><?= empty_state('bi-activity', 'No activity yet', 'Actions by your team and the engine appear here.', '', 'py-3') ?>
            <?php else: ?><ul class="timeline"><?php foreach (array_slice($recentActivity, 0, 6) as $a): ?><li><span class="tl-icon"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><div class="tl-title"><?= e(truncate($a['description'], 70)) ?></div><div class="tl-meta"><?= e($a['user_name'] ?? 'System') ?> · <?= e(time_ago($a['created_at'])) ?></div></li><?php endforeach; ?></ul><?php endif; ?>
          </div></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100 monitoring-status"><div class="card-header"><span><i class="bi bi-robot me-2"></i>Monitoring engine</span><span class="small"><span class="live-dot <?= $schedulerState['healthy'] ? '' : 'danger' ?>"></span> <?= $schedulerState['healthy'] ? 'Live' : 'Paused' ?></span></div>
      <div class="card-body">
        <div class="small text-muted mb-2"><?= e($schedulerState['message']) ?></div>
        <div class="list-cards">
          <?php foreach ($jobs as $j): $stale = $j['last_finished_at'] && strtotime($j['last_finished_at']) < time() - max(15, (int) $j['interval_minutes'] * 3) * 60; ?>
            <div class="list-card"><span class="lc-icon <?= $j['status'] === 'failed' ? 'tint-danger' : ($stale || !$j['last_finished_at'] ? 'tint-secondary' : 'tint-success') ?>"><i class="bi <?= $jobIcons[$j['name']] ?? 'bi-gear' ?>"></i></span><span class="lc-main"><span class="lc-title"><?= e($j['label']) ?></span><span class="lc-sub"><?= $j['last_finished_at'] ? 'last run ' . e(time_ago($j['last_finished_at'])) : 'not run yet' ?> · every <?= $every((int) $j['interval_minutes']) ?></span></span><span class="lc-side"><?= $j['status'] === 'running' ? '<span class="badge bg-info-subtle">running</span>' : ($j['status'] === 'failed' ? '<span class="badge bg-danger-subtle">failed</span>' : ($stale || !$j['last_finished_at'] ? '<span class="badge bg-secondary-subtle">idle</span>' : '<span class="badge bg-success-subtle">OK</span>')) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div></div>
  </div>
</div>
</div>

<?php $ps = Stats::projects(); if ($ps['total']): $pStatuses = project_statuses(); ?>
<div class="card mb-3"><div class="card-header"><span><i class="bi bi-kanban me-2"></i>Website Projects</span><span class="small"><a href="<?= url('projects/index.php') ?>">All projects</a> · <a href="<?= url('projects/index.php?status=in_progress') ?>"><?= number_format($ps['in_progress']) ?> in progress</a></span></div>
  <div class="card-body d-flex flex-wrap gap-2"><a href="<?= url('projects/index.php') ?>" class="badge badge-lg bg-dark text-white text-decoration-none">Total <?= number_format($ps['total']) ?></a><?php foreach (['design', 'development', 'testing', 'client_review', 'changes_required', 'ready_for_launch', 'live'] as $k): [$lbl, $cls] = $pStatuses[$k]; ?><a href="<?= url('projects/index.php?status=' . $k) ?>" class="badge badge-lg <?= $cls ?> text-decoration-none"><?= e($lbl) ?> <strong><?= number_format($ps['by_status'][$k] ?? 0) ?></strong></a><?php endforeach; ?></div></div>
<?php endif; ?>

</div>
<?php
$clientModalStay = false;
include ROOT_PATH . '/includes/partials/client-modal.php';
include ROOT_PATH . '/includes/partials/website-modal.php';
include ROOT_PATH . '/includes/partials/form-modal.php';
include ROOT_PATH . '/includes/partials/run-check-script.php';
$pageScripts = ($pageScripts ?? '') . <<<'JS'
<script>
(function () {
  const P = CRM.palette; let uptimeChart = null;
  const show = id => { const c = document.getElementById(id); c.classList.remove('d-none'); $(c).siblings('.skeleton').remove(); return c; };
  const legend = (sel, rows) => { const tot = rows.reduce((a, r) => a + r[1], 0) || 1; $(sel).html(rows.map(r => '<div class="legend-row"><span class="dot" style="background:' + r[2] + '"></span><span class="lbl">' + r[0] + '</span><span class="val">' + r[1].toLocaleString() + '</span><span class="pct">' + Math.round(r[1] / tot * 100) + '%</span></div>').join('')); };
  function drawUptime(d) {
    if (uptimeChart) { uptimeChart.destroy(); uptimeChart = null; }
    const box = $('#chUptime').closest('.chart-box');
    if (!d.checks_total) { box.html('<div class="empty-state py-3"><i class="bi bi-activity"></i><div class="es-text mb-0">No checks yet – the first monitoring run fills this chart.</div></div>'); return; }
    if (!document.getElementById('chUptime')) box.html('<canvas id="chUptime"></canvas>');
    uptimeChart = CRM.lineChart(show('chUptime'), d.labels, [
      { label: 'Response time (ms)', data: d.response, color: P.brand, yAxisID: 'y', pointRadius: 2.5, pointBackgroundColor: P.brand, borderWidth: 2 },
      { label: 'Uptime %', data: d.uptime, color: '#fcd98a', yAxisID: 'y1', fill: false, spanGaps: true, borderWidth: 1.5, borderDash: [3, 3] }
    ], { plugins: { legend: { display: false } }, scales: { y: { min: 0, ticks: { callback: v => v + 'ms', maxTicksLimit: 4 } }, y1: { position: 'right', min: Math.max(0, Math.min.apply(null, d.uptime.filter(v => v !== null).concat([99])) - 2), max: 100, grid: { display: false }, border: { display: false }, ticks: { callback: v => v + '%', maxTicksLimit: 4 } } } });
  }
  CRM.lazyCharts('#dashCharts', 'api/stats.php', { action: 'charts', days: 30 }, function (d) {
    drawUptime(d);
    const wh = d.website_health, wt = wh.online + wh.down + wh.other;
    CRM.donut(show('chSites'), ['Online', 'Down', 'Paused / unknown'], [wh.online, wh.down, wh.other], [P.success, P.danger, P.muted], { plugins: { legend: { display: false } }, cutout: '72%' });
    $('#chSitesCenter').removeClass('d-none').html('<div class="v">' + (wt ? Math.round(wh.online / wt * 100) : 100) + '%</div><div class="l">online</div>');
    legend('#legSites', [['Online', wh.online, P.success], ['Down', wh.down, P.danger], ['Paused', wh.other, P.muted]]);
    CRM.barChart(show('chIncidents'), d.labels, [{ label: 'Website', data: d.incidents.website, color: P.danger }, { label: 'Page', data: d.incidents.page, color: P.brand }, { label: 'Form', data: d.incidents.form, color: P.info }, { label: 'SSL', data: d.incidents.ssl, color: P.muted }], { stacked: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { precision: 0, maxTicksLimit: 4 } } } });
    const fh = d.form_health, ft = fh.working + fh.failed + fh.blocked + fh.other;
    CRM.donut(show('chForms'), ['Working', 'Failed', 'CAPTCHA-protected', 'Other'], [fh.working, fh.failed, fh.blocked, fh.other], [P.success, P.danger, P.info, P.muted], { plugins: { legend: { display: false } }, cutout: '72%' });
    $('#chFormsCenter').removeClass('d-none').html('<div class="v">' + (ft ? Math.round(fh.working / ft * 100) : 100) + '%</div><div class="l">working</div>');
    legend('#legForms', [['Working', fh.working, P.success], ['Failed', fh.failed, P.danger], ['CAPTCHA-protected', fh.blocked, P.info], ['Other', fh.other, P.muted]]);
  });
  $('#uptimeRange').on('change', function () { $('#chUptime').closest('.chart-box').html('<div class="skeleton skeleton-chart"></div><canvas id="chUptime" class="d-none"></canvas>'); CRM.get('api/stats.php', { action: 'charts', days: this.value }).done(drawUptime); });
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
