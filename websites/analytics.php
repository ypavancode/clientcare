<?php
/** Visitor analytics for one website (v3.7): KPIs incl. bounce rate / engagement, trend, sources + UTM, devices, locations (country / region / city), page table, live visitors, setup with tracking URL. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$id = (int) get('id', 0);
$w = DB::fetch("SELECT w.*, c.name AS client_name FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.id = ? AND w.tenant_id = ?", [$id, Tenant::id()]);
if (!$w) http_error(404, 'Website not found.');
$level = Analytics::level();
$range = in_array(get('range'), ['today', 'yesterday', '7d', '30d', '90d', '180d', '12m', 'custom'], true) ? get('range') : '30d';
if ($level !== 'full' && in_array($range, ['12m', '180d', 'custom'], true)) $range = '90d';
[$from, $to] = Analytics::range($range, get('from'), get('to'));
$quota = Analytics::quota();
$canManage = Auth::can('websites');
[$stKey, $stLabel, $stTone, $stHelp] = Analytics::installStatus($w);
$retention = Analytics::retentionDays();
$geoLevel = Analytics::geoLevel();
$realtime = Analytics::realtimeAllowed();
$canExport = Tenant::feature('reports') && !in_array(Tenant::feature('reports'), ['none', 'basic'], true);
$lastPage = $w['analytics_key'] ? DB::fetch("SELECT path, created_at FROM analytics_events WHERE website_id = ? AND event = 'pageview' ORDER BY id DESC LIMIT 1", [$id]) : null;
$pageTitle = 'Analytics · ' . $w['name'];
$breadcrumbs = [['label' => 'Analytics', 'url' => 'analytics/index.php'], ['label' => $w['name']]];
$pageSubtitle = '<a href="' . e($w['url']) . '" target="_blank" rel="noopener">' . e(host_from_url($w['url'])) . ' <i class="bi bi-box-arrow-up-right small"></i></a> · ' . e($w['client_name']) . ' · ' . status_pill($stTone, $stLabel, $stKey === 'active') . ($w['analytics_last_event_at'] ? ' <span class="text-muted">last page view ' . e(time_ago($w['analytics_last_event_at'])) . '</span>' : '');
$ranges = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days'] + ($level === 'full' ? ['180d' => '180 days', '12m' => '12 months'] : []);
$pageActions = '<div class="btn-group">';
foreach ($ranges as $k => $l) { if (['today' => 1, 'yesterday' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '180d' => 180, '12m' => 365][$k] > $retention) continue; $pageActions .= '<a href="' . url('websites/analytics.php?id=' . $id . '&range=' . $k) . '" class="btn btn-sm ' . ($range === $k ? 'btn-dark' : 'btn-light') . '">' . $l . '</a>'; }
$pageActions .= '</div>';
if ($level === 'full') $pageActions .= '<form class="d-inline-flex align-items-center gap-1" method="get" action="' . url('websites/analytics.php') . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="range" value="custom"><input type="date" name="from" class="form-control form-control-sm" value="' . e($range === 'custom' ? $from : '') . '" max="' . date('Y-m-d') . '"><input type="date" name="to" class="form-control form-control-sm" value="' . e($range === 'custom' ? $to : '') . '" max="' . date('Y-m-d') . '"><button class="btn btn-sm btn-light"><i class="bi bi-calendar-range"></i></button></form>';
if ($canExport) $pageActions .= '<a href="' . url('api/analytics.php?action=export&id=' . $id . '&range=' . $range . '&from=' . $from . '&to=' . $to) . '" class="btn btn-sm btn-light" title="Export CSV"><i class="bi bi-download"></i></a>';
$pageActions .= '<a href="' . url('websites/view.php?id=' . $id) . '" class="btn btn-light btn-sm"><i class="bi bi-globe2"></i></a>';
$useCharts = true;
include ROOT_PATH . '/includes/layout/header.php';
?>
<?php if ($level === 'none'): ?>
<div class="card border-brand"><div class="card-body d-flex flex-wrap align-items-center gap-3"><div class="stat-icon tint-brand"><i class="bi bi-bar-chart-line"></i></div><div class="flex-grow-1"><div class="fw-600">Visitor analytics is a paid feature</div><div class="text-muted small">See who visits every client website – visitors, pages, countries, devices and traffic sources – with one small script tag. Included from the Starter plan.</div></div><a href="<?= url('billing/index.php') ?>" class="btn btn-brand btn-sm">Upgrade plan</a></div></div>
<?php include ROOT_PATH . '/includes/layout/footer.php'; exit; endif; ?>

<?php if ($stKey !== 'active'): ?>
<div class="card mb-3 <?= $w['analytics_enabled'] ? '' : 'border-brand' ?>" id="installCard"><div class="card-body">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <div class="stat-icon tint-brand"><i class="bi bi-code-slash"></i></div>
    <div class="flex-grow-1 min-w-0">
      <div class="fw-600 d-flex align-items-center gap-2 flex-wrap"><?= $w['analytics_enabled'] ? 'Add the tracking script to start collecting' : 'Create the analytics property for this website' ?> <?= status_pill($stTone, $stLabel, false) ?></div>
      <div class="text-muted small mb-2"><?= e($stHelp) ?> Paste one line before <code>&lt;/head&gt;</code> once (theme / layout header) – every page where the script loads is tracked automatically. No cookies, no personal data; every device is counted once.</div>
      <?php if ($w['analytics_enabled']): ?>
        <ol class="small text-muted mb-2 ps-3" id="installSteps"><li>Click <strong>Copy tracking code</strong>.</li><li>Open the website's theme / layout header (the template every page uses).</li><li>Paste the code just before <code>&lt;/head&gt;</code>.</li><li>Save / publish the website.</li><li>Come back here and click <strong>Verify installation</strong> – the status turns <em>Tracking Active</em> with the first page view.</li></ol>
        <div class="input-group input-group-sm mb-2" style="max-width:760px"><span class="input-group-text">Tracking code</span><input type="text" class="form-control mono" readonly value="<?= e(Analytics::snippet($w['analytics_key'])) ?>" onclick="this.select()"><button class="btn btn-dark copy-btn" data-copy="<?= e(Analytics::snippet($w['analytics_key'])) ?>"><i class="bi bi-clipboard me-1"></i>Copy tracking code</button></div>
        <div class="d-flex flex-wrap gap-2 align-items-center"><?php if ($canManage): ?><button class="btn btn-light btn-sm btn-action" data-url="api/analytics.php" data-params='{"action":"verify","id":<?= $id ?>}' data-loading="1"><i class="bi bi-patch-check me-1"></i>Verify installation</button><?php endif; ?><span class="small-xs text-muted">Tracking ID <code><?= e($w['analytics_key']) ?></code> · URL <code><?= e(Analytics::scriptUrl($w['analytics_key'])) ?></code> · WordPress: <em>Appearance → Theme file editor → header.php</em> or a "header scripts" plugin · GTM: Custom HTML tag.</span></div>
      <?php elseif ($canManage): ?>
        <button class="btn btn-brand btn-sm btn-action" data-url="api/analytics.php" data-params='{"action":"enable","id":<?= $id ?>}' data-reload="1"><i class="bi bi-plus-circle me-1"></i>Create analytics property &amp; get tracking code</button>
      <?php endif; ?>
    </div>
  </div>
</div></div>
<?php endif; ?>

<div id="anWrap" data-id="<?= $id ?>" data-range="<?= e($range) ?>" data-from="<?= e($from) ?>" data-to="<?= e($to) ?>" data-level="<?= e($level) ?>" data-geo="<?= e($geoLevel) ?>" data-realtime="<?= $realtime ? 1 : 0 ?>">
<div class="kpi-grid compact stagger mb-3">
  <div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-people"></i></div><div class="min-w-0"><div class="stat-value" id="kVisitors"><span class="skeleton d-inline-block" style="width:40px;height:14px"></span></div><div class="stat-label">Visitors</div><div class="stat-trend" id="tVisitors"></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-person-check"></i></div><div class="min-w-0"><div class="stat-value" id="kUnique">–</div><div class="stat-label">Unique visitors</div><div class="stat-trend text-muted" id="tUnique"></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-layers"></i></div><div class="min-w-0"><div class="stat-value" id="kSessions">–</div><div class="stat-label">Sessions</div><div class="stat-trend" id="tSessions"></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-eye"></i></div><div class="min-w-0"><div class="stat-value" id="kPageviews">–</div><div class="stat-label">Page views</div><div class="stat-trend" id="tPageviews"></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-arrow-return-left"></i></div><div class="min-w-0"><div class="stat-value" id="kBounce">–</div><div class="stat-label">Bounce rate</div><div class="stat-trend text-muted">single-page sessions</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-secondary"><i class="bi bi-stopwatch"></i></div><div class="min-w-0"><div class="stat-value" id="kDuration">–</div><div class="stat-label">Avg. session</div><div class="stat-trend text-muted" id="tDuration">engaged time</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-broadcast"></i></div><div class="min-w-0"><div class="stat-value" id="kNow">–</div><div class="stat-label">Active now</div><div class="stat-trend text-muted">last <?= Analytics::REALTIME_MINUTES ?> min · <span id="kNewPct"></span></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-graph-up me-2"></i>Traffic</span><span class="small text-muted" id="chartMeta"></span></div><div class="card-body"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chTraffic" class="d-none"></canvas></div><div class="chart-legend mt-2"><span style="color:#FCAF17">Visitors</span><span style="color:#e5e7eb">Page views</span><span style="color:#3b82f6">Sessions</span><span style="color:#22c55e">New visitors</span></div></div></div></div>
  <div class="col-xl-4"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-phone me-2"></i>Devices</span></div><div class="card-body"><div class="chart-split"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chDevices" class="d-none"></canvas><div class="donut-center d-none" id="chDevicesCenter"></div></div><div class="legend" id="legDevices"></div></div><div id="listScreens" class="mt-2"></div></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-xl-4 col-md-6"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-signpost-split me-2"></i>Traffic sources</span></div><div class="card-body"><div class="chart-split"><div class="chart-box"><div class="skeleton skeleton-chart"></div><canvas id="chSources" class="d-none"></canvas></div><div class="legend" id="legSources"></div></div><div id="listReferrers" class="mt-2"></div></div></div></div>
  <div class="col-xl-4 col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-megaphone me-2"></i>Campaigns &amp; UTM</span></div><div class="card-body" id="listUtm"><?= skeleton_block(4) ?></div></div></div>
  <div class="col-xl-4 col-md-12"><div class="card h-100"><div class="card-header"><span><i class="bi bi-geo-alt me-2"></i>Locations</span><span class="small text-muted"><?= $geoLevel === 'city' ? 'country · region · city' : ($geoLevel === 'region' ? 'country · region' : 'country') ?> · approximate (IP-based)</span></div><div class="card-body" id="listCountries"><?= skeleton_block(5) ?></div></div></div>
</div>
<div class="card mb-3"><div class="card-header"><span><i class="bi bi-file-earmark-text me-2"></i>Pages</span><span class="small text-muted">visitors, views, average time on page, bounce rate</span></div>
  <div class="card-body p-0" id="tablePages"><div class="p-3"><?= skeleton_block(5) ?></div></div></div>
<div class="row g-3 mb-3">
  <div class="col-xl-4 col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-browser-chrome me-2"></i>Browsers &amp; systems</span></div><div class="card-body" id="listBrowsers"><?= skeleton_block(4) ?></div></div></div>
  <div class="col-xl-4 col-md-6"><div class="card h-100"><div class="card-header"><span><i class="bi bi-box-arrow-in-right me-2"></i>Entry &amp; exit pages</span></div><div class="card-body" id="listLanding"><?= skeleton_block(4) ?></div></div></div>
  <div class="col-xl-4 col-md-12"><div class="card h-100"><div class="card-header"><span><i class="bi bi-grid-3x3 me-2"></i>Activity by day &amp; hour</span><span class="small text-muted"><?= $level === 'full' ? 'page views' : 'Professional+' ?></span></div><div class="card-body" id="heatWrap"><?= skeleton_block(4) ?></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-xl-4"><div class="card h-100"><div class="card-header"><span><i class="bi bi-broadcast me-2"></i>Live</span><span class="small text-muted" id="liveMeta"><?= $realtime ? 'updates every 20 s' : 'Starter plan and above' ?></span></div><div class="card-body" id="liveWrap"><?= skeleton_block(3) ?></div></div></div>
  <div class="col-xl-8"><div class="card h-100"><div class="card-header"><span><i class="bi bi-people-fill me-2"></i>Visitors on the site now</span><span class="small text-muted" id="liveVisitorsMeta"></span></div><div class="card-body p-0" id="listLiveVisitors"><div class="p-3"><?= skeleton_block(4) ?></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-header"><span><i class="bi bi-clock-history me-2"></i>Recent page views</span><span class="small text-muted"><?= $level === 'full' ? 'latest 25' : 'Professional plan and above' ?></span></div><div class="card-body p-0" id="listRecent"><div class="p-3"><?= skeleton_block(4) ?></div></div></div>

<?php if ($w['analytics_enabled'] && $canManage): ?>
<div class="card"><div class="card-header"><span><i class="bi bi-gear me-2"></i>Tracking setup</span><?= status_pill($stTone, $stLabel, $stKey === 'active') ?></div><div class="card-body">
  <div class="row g-3">
    <div class="col-lg-7">
      <label class="form-label mb-1">Tracking code <span class="text-muted fw-normal">(paste once before <code>&lt;/head&gt;</code>)</span></label>
      <div class="input-group input-group-sm mb-2"><input type="text" class="form-control mono" readonly value="<?= e(Analytics::snippet($w['analytics_key'])) ?>" onclick="this.select()"><button class="btn btn-dark copy-btn" data-copy="<?= e(Analytics::snippet($w['analytics_key'])) ?>"><i class="bi bi-clipboard me-1"></i>Copy tracking code</button></div>
      <div class="row g-2 small">
        <div class="col-sm-6"><span class="text-muted">Tracking ID</span><div class="mono"><?= e($w['analytics_key']) ?> <i class="bi bi-clipboard copy-btn" data-copy="<?= e($w['analytics_key']) ?>" title="Copy"></i></div></div>
        <div class="col-sm-6"><span class="text-muted">Tracking URL</span><div class="mono text-truncate" title="<?= e(Analytics::scriptUrl($w['analytics_key'])) ?>"><?= e(Analytics::scriptUrl($w['analytics_key'])) ?> <i class="bi bi-clipboard copy-btn" data-copy="<?= e(Analytics::scriptUrl($w['analytics_key'])) ?>" title="Copy"></i></div></div>
      </div>
      <div class="form-text">Events accepted from <strong><?= e(host_from_url($w['url'])) ?></strong> and sub-domains<?= $w['analytics_any_origin'] ? ' <span class="text-warning">(currently: any domain)</span>' : '' ?> · history kept <?= $retention ?> days · quota <?= $quota['limit'] !== null ? number_format($quota['current']) . ' / ' . number_format($quota['limit']) : number_format($quota['current']) ?> views this month (resets <?= format_date($quota['resets']) ?>) · custom events: <code>omTrack('event', 'cta_click')</code>.</div>
    </div>
    <div class="col-lg-5">
      <div class="status-strip mb-2">
        <div><span class="l">Installation</span><span class="v text-<?= $stTone ?>"><?= e($stLabel) ?></span></div>
        <div><span class="l">First activity</span><span class="v"><?= $w['analytics_first_event_at'] ? e(format_datetime($w['analytics_first_event_at'])) : '—' ?></span></div>
        <div><span class="l">Last event received</span><span class="v"><?= $w['analytics_last_event_at'] ? e(time_ago($w['analytics_last_event_at'])) : '—' ?></span></div>
        <div><span class="l">Last page received</span><span class="v mono"><?= $lastPage ? e(truncate($lastPage['path'], 40)) : '—' ?></span></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-light btn-sm btn-action" data-url="api/analytics.php" data-params='{"action":"verify","id":<?= $id ?>}' data-loading="1"><i class="bi bi-patch-check me-1"></i>Verify installation</button>
        <button class="btn btn-light btn-sm btn-action" data-url="api/analytics.php" data-params='{"action":"any_origin","id":<?= $id ?>,"value":<?= $w['analytics_any_origin'] ? 0 : 1 ?>}' data-reload="1"><i class="bi bi-globe me-1"></i><?= $w['analytics_any_origin'] ? 'Own domain only' : 'Allow any domain' ?></button>
        <button class="btn btn-light btn-sm btn-action" data-url="api/analytics.php" data-params='{"action":"regenerate","id":<?= $id ?>}' data-confirm="Generate a new tracking ID? The old script stops working." data-reload="1"><i class="bi bi-arrow-repeat me-1"></i>New ID</button>
        <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/analytics.php" data-params='{"action":"disable","id":<?= $id ?>}' data-confirm="Pause tracking for this website? Collected data is kept." data-reload="1"><i class="bi bi-pause-circle me-1"></i>Pause</button>
      </div>
    </div>
  </div>
</div></div>
<?php endif; ?>
</div>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const wrap = $('#anWrap'), P = CRM.palette, id = wrap.data('id'), geo = wrap.data('geo'), realtime = wrap.data('realtime') == 1;
  const flag = c => { c = (c || 'XX').toUpperCase(); if (!/^[A-Z]{2}$/.test(c) || c === 'XX') return '<i class="bi bi-globe2 text-muted"></i>'; return '<img src="https://flagcdn.com/16x12/' + c.toLowerCase() + '.png" width="16" height="12" alt="" loading="lazy" style="vertical-align:-1px;border-radius:2px">'; };
  const names = {IN:'India',US:'United States',GB:'United Kingdom',AE:'UAE',CA:'Canada',AU:'Australia',DE:'Germany',FR:'France',SG:'Singapore',NL:'Netherlands',ES:'Spain',IT:'Italy',BR:'Brazil',JP:'Japan',CN:'China',PK:'Pakistan',BD:'Bangladesh',LK:'Sri Lanka',NP:'Nepal',SA:'Saudi Arabia',QA:'Qatar',MY:'Malaysia',ID:'Indonesia',PH:'Philippines',ZA:'South Africa',NG:'Nigeria',KE:'Kenya',EG:'Egypt',TR:'Turkey',RU:'Russia',MX:'Mexico',SE:'Sweden',NO:'Norway',PL:'Poland',IE:'Ireland',CH:'Switzerland',NZ:'New Zealand',KR:'South Korea',HK:'Hong Kong',TH:'Thailand',VN:'Vietnam',XX:'Unknown'};
  const fmt = n => Number(n || 0).toLocaleString();
  const dur = s => { if (s == null) return '—'; s = Math.round(s); return s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's'; };
  const trend = (cur, prev) => { if (!prev) return ''; const d = Math.round((cur - prev) / prev * 100); return '<span class="' + (d >= 0 ? 'text-success' : 'text-danger') + '"><i class="bi bi-arrow-' + (d >= 0 ? 'up' : 'down') + '-short"></i>' + Math.abs(d) + '%</span> <span class="text-muted">vs previous</span>'; };
  const bars = (rows, labelFn, valKey, empty, showPct) => {
    if (!rows || !rows.length) return '<div class="empty-state py-3"><i class="bi bi-inbox"></i><div class="es-text mb-0">' + empty + '</div></div>';
    const max = Math.max(...rows.map(r => +r[valKey] || 0), 1), tot = rows.reduce((a, r) => a + (+r[valKey] || 0), 0) || 1;
    return '<div class="bar-list">' + rows.map(r => '<div class="bl"><div class="d-flex justify-content-between"><span class="text-truncate">' + labelFn(r) + '</span><span class="text-nowrap"><b>' + fmt(r[valKey]) + '</b>' + (showPct ? ' <span class="text-muted small-xs">' + Math.round(r[valKey] / tot * 100) + '%</span>' : '') + '</span></div><div class="progress"><div class="progress-bar bg-warning" style="width:' + Math.max(3, Math.round(r[valKey] / max * 100)) + '%"></div></div></div>').join('') + '</div>';
  };
  const legend = (sel, rows) => { const tot = rows.reduce((a, r) => a + r[1], 0) || 1; $(sel).html(rows.map(r => '<div class="legend-row"><span class="dot" style="background:' + r[2] + '"></span><span class="lbl">' + r[0] + '</span><span class="val">' + fmt(r[1]) + '</span><span class="pct">' + Math.round(r[1] / tot * 100) + '%</span></div>').join('')); };
  const show = id => { const c = document.getElementById(id); c.classList.remove('d-none'); $(c).siblings('.skeleton').remove(); return c; };
  const emptyBox = (sel, icon, text) => $(sel).closest('.chart-box').html('<div class="empty-state py-2"><i class="bi ' + icon + '"></i><div class="es-text mb-0">' + text + '</div></div>');
  const loc = r => { const s = [geo === 'city' ? r.city : null, geo !== 'country' ? r.region : null, names[r.country] || r.country].filter(v => v && v !== 'XX').join(', '); return s ? CRM.esc(s) : '<span class="text-muted">Location unknown</span>'; };
  function renderLive(rt) {
    let h = '<div class="d-flex align-items-center gap-3 mb-2"><div class="health-ring" style="--p:100;--c:var(--success);width:56px;height:56px"><div><div class="hr-val">' + fmt(rt.active) + '</div></div></div><div><div class="fw-600">' + fmt(rt.active) + ' visitor' + (rt.active === 1 ? '' : 's') + ' online</div><div class="small text-muted">in the last ' + rt.minutes + ' minutes' + (rt.at ? ' · ' + rt.at : '') + '</div></div></div>';
    h += rt.pages && rt.pages.length ? '<div class="section-title">Pages being viewed</div>' + bars(rt.pages, r => '<span class="mono">' + CRM.esc(r.path) + '</span>', 'n', '') : '<div class="small text-muted">No one is on the site right now.</div>';
    if (rt.by) { const b = k => Object.entries(rt.by[k] || {}).slice(0, 4).map(([v, n]) => (k === 'country' ? flag(v) + ' ' + (names[v] || v) : CRM.esc(v)) + ' <b>' + n + '</b>').join(' · '); const s = ['country', 'device', 'source'].map(k => b(k)).filter(Boolean); if (s.length) h += '<div class="small-xs text-muted mt-2">' + s.join('<br>') + '</div>'; }
    $('#liveWrap').html(h);
  }
  function renderLiveVisitors(rt) {
    if (!realtime) { $('#listLiveVisitors').html('<div class="empty-state py-3"><i class="bi bi-broadcast"></i><div class="es-text mb-0">Real-time visitors are available from the Starter plan.</div></div>'); return; }
    const v = rt.visitors || [];
    $('#liveVisitorsMeta').text(v.length ? v.length + ' active' : '');
    if (!v.length) { $('#listLiveVisitors').html('<div class="empty-state py-3"><i class="bi bi-people"></i><div class="es-text mb-0">No visitors on the site in the last ' + rt.minutes + ' minutes.</div></div>'); return; }
    $('#listLiveVisitors').html('<div class="table-responsive"><table class="table table-compact mb-0"><thead><tr><th>Current page</th><th>Location <span class="text-muted fw-normal small-xs">(approx., IP-based)</span></th><th>Device</th><th>Source</th><th>Last activity</th></tr></thead><tbody>' + v.map(r => '<tr><td><span class="mono">' + CRM.esc(r.path) + '</span>' + (r.is_new_device == 1 ? ' <span class="badge bg-success-subtle">new</span>' : '') + '</td><td>' + flag(r.country) + ' ' + loc(r) + '</td><td>' + CRM.esc([r.device, r.browser, r.os].filter(Boolean).join(' · ')) + '</td><td>' + CRM.esc(r.referrer_host || r.source) + '</td><td class="text-muted text-nowrap">' + r.created_at.slice(11, 19) + '</td></tr>').join('') + '</tbody></table></div>');
    CRM.labelTables('#listLiveVisitors');
  }
  function renderRecent(recent, level) {
    if (recent && recent.length) {
      $('#listRecent').html('<div class="table-responsive"><table class="table table-compact mb-0"><thead><tr><th>When</th><th>Page</th><th>From</th><th>Location <span class="text-muted fw-normal small-xs">(approx.)</span></th><th>Device</th></tr></thead><tbody>' + recent.map(r => '<tr><td class="text-nowrap text-muted">' + r.created_at.slice(11, 16) + '<div class="small-xs">' + r.created_at.slice(0, 10) + '</div></td><td><span class="mono">' + CRM.esc(r.path) + '</span>' + (r.is_new_device == 1 ? ' <span class="badge bg-success-subtle">new</span>' : '') + '</td><td>' + CRM.esc(r.referrer_host || r.source) + '</td><td>' + flag(r.country) + ' ' + loc(r) + '</td><td>' + CRM.esc([r.device, r.browser, r.os].filter(Boolean).join(' · ')) + '</td></tr>').join('') + '</tbody></table></div>');
      CRM.labelTables('#listRecent');
    } else $('#listRecent').html('<div class="empty-state py-3"><i class="bi bi-clock-history"></i><div class="es-text mb-0">' + (level === 'full' ? 'Recent page views will be listed here.' : 'Upgrade to Professional to see individual visits.') + '</div></div>');
  }
  CRM.get('api/analytics.php', { action: 'report', id: id, range: wrap.data('range'), from: wrap.data('from'), to: wrap.data('to') }).done(function (d) {
    const t = d.totals;
    $('#kVisitors').text(fmt(t.visitors)); $('#tVisitors').html(trend(t.visitors, t.prev_visitors));
    $('#kUnique').text(fmt(t.unique_visitors)); $('#tUnique').text(t.new_visitors ? fmt(t.new_visitors) + ' new · ' + fmt(t.returning) + ' returning' : '');
    $('#kPageviews').text(fmt(t.pageviews)); $('#tPageviews').html(trend(t.pageviews, t.prev_pageviews) || (t.views_per_visitor ? '<span class="text-muted">' + t.views_per_visitor + ' views / visitor</span>' : ''));
    $('#kSessions').text(fmt(t.sessions)); $('#tSessions').html(trend(t.sessions, t.prev_sessions));
    $('#kBounce').text(t.bounce_rate == null ? '—' : t.bounce_rate + '%');
    $('#kDuration').text(dur(t.avg_session)); $('#tDuration').text(t.avg_time != null ? dur(t.avg_time) + ' per page' : 'engaged time');
    $('#kNow').text(fmt(t.active_now)); $('#kNewPct').text(t.visitors ? Math.round(t.new_visitors / t.visitors * 100) + '% new' : '');
    $('#chartMeta').text(d.from === d.to ? d.from : d.from + ' → ' + d.to);
    if (!t.pageviews) emptyBox('#chTraffic', 'bi-graph-up', 'No visits in this period. Install the tracking script and open the website – the first page view appears within seconds.');
    else CRM.lineChart(show('chTraffic'), d.labels, [{ label: 'Visitors', data: d.series.visitors, color: P.brand, pointRadius: 2, pointBackgroundColor: P.brand }, { label: 'Page views', data: d.series.pageviews, color: P.ink, fill: false }, { label: 'Sessions', data: d.series.sessions, color: P.info, fill: false, borderDash: [3, 3] }, { label: 'New', data: d.series.new_visitors, color: P.success, fill: false, borderDash: [2, 2] }], { plugins: { legend: { display: false } }, scales: { y: { ticks: { precision: 0, maxTicksLimit: 5 } } } });
    const dev = d.dims.device || []; const devMap = {}; dev.forEach(r => devMap[r.value] = +r.visitors);
    const dl = ['desktop', 'mobile', 'tablet'].filter(k => devMap[k]);
    if (dl.length) { CRM.donut(show('chDevices'), dl.map(k => k[0].toUpperCase() + k.slice(1)), dl.map(k => devMap[k]), [P.ink, P.brand, P.info], { plugins: { legend: { display: false } }, cutout: '72%' }); const tot = dl.reduce((a, k) => a + devMap[k], 0); $('#chDevicesCenter').removeClass('d-none').html('<div class="v">' + Math.round((devMap.mobile || 0) / tot * 100) + '%</div><div class="l">mobile</div>'); legend('#legDevices', dl.map((k, i) => [k[0].toUpperCase() + k.slice(1), devMap[k], [P.ink, P.brand, P.info][i]])); }
    else emptyBox('#chDevices', 'bi-phone', 'No data yet');
    if (d.dims.screen && d.dims.screen.length) $('#listScreens').html('<div class="section-title mt-2">Screen sizes</div>' + bars(d.dims.screen.slice(0, 5), r => CRM.esc(r.value) + ' px', 'visitors', '', true));
    const src = d.dims.source || [], sc = { direct: P.ink, search: P.brand, social: P.info, referral: P.success, campaign: '#a855f7' };
    if (src.length) { CRM.donut(show('chSources'), src.map(r => r.value), src.map(r => +r.visitors), src.map(r => sc[r.value] || P.muted), { plugins: { legend: { display: false } }, cutout: '68%' }); legend('#legSources', src.map(r => [r.value === 'search' ? 'Search (Google…)' : r.value[0].toUpperCase() + r.value.slice(1), +r.visitors, sc[r.value] || P.muted])); }
    else emptyBox('#chSources', 'bi-signpost', 'No source data yet');
    if (d.dims.referrer) $('#listReferrers').html((d.dims.referrer.length ? '<div class="section-title mt-2">Top referrers</div>' : '') + bars(d.dims.referrer.slice(0, 6), r => CRM.esc(r.value), 'visitors', '', true));
    let utm = '';
    [['utm_source', 'Source'], ['utm_medium', 'Medium'], ['utm_campaign', 'Campaign'], ['utm_term', 'Term'], ['utm_content', 'Content']].forEach(([k, l]) => { if (d.dims[k] && d.dims[k].length) utm += '<div class="section-title' + (utm ? ' mt-3' : '') + '">' + l + '</div>' + bars(d.dims[k].slice(0, 5), r => CRM.esc(r.value), 'visitors', '', true); });
    if (!utm && d.dims.campaign && d.dims.campaign.length) utm = '<div class="section-title">Campaigns</div>' + bars(d.dims.campaign.slice(0, 6), r => CRM.esc(r.value), 'visitors', '', true);
    $('#listUtm').html(utm || '<div class="empty-state py-3"><i class="bi bi-megaphone"></i><div class="es-text mb-0">No campaign traffic yet. Links with utm_source / utm_medium / utm_campaign (and gclid / fbclid) are grouped here.</div></div>');
    let countries = bars(d.dims.country, r => flag(r.value) + ' ' + (names[r.value] || r.value), 'visitors', 'No location data yet', true);
    if (d.dims.region && d.dims.region.length) countries += '<div class="section-title mt-3">States &amp; regions</div>' + bars(d.dims.region.slice(0, 6), r => CRM.esc(r.value), 'visitors', '', true);
    if (d.dims.city && d.dims.city.length) countries += '<div class="section-title mt-3">Cities</div>' + bars(d.dims.city.slice(0, 6), r => CRM.esc(r.value), 'visitors', '', true);
    $('#listCountries').html(countries);
    if (d.pages && d.pages.length) { $('#tablePages').html('<div class="table-responsive"><table class="table table-compact table-hover mb-0"><thead><tr><th>Page</th><th class="text-end">Visitors</th><th class="text-end">Page views</th><th class="text-end">Entries</th><th class="text-end">Avg. time</th><th class="text-end">Bounce rate</th></tr></thead><tbody>' + d.pages.map(r => '<tr><td><span class="mono">' + CRM.esc(r.path) + '</span>' + (r.title ? '<div class="small-xs text-muted text-truncate" style="max-width:420px">' + CRM.esc(r.title) + '</div>' : '') + '</td><td class="text-end fw-600">' + fmt(r.visitors) + '</td><td class="text-end">' + fmt(r.pageviews) + '</td><td class="text-end text-muted">' + fmt(r.entries) + '</td><td class="text-end">' + dur(r.avg_time) + '</td><td class="text-end">' + (r.bounce_rate == null ? '—' : r.bounce_rate + '%') + '</td></tr>').join('') + '</tbody></table></div>'); CRM.labelTables('#tablePages'); }
    else $('#tablePages').html('<div class="empty-state py-3"><i class="bi bi-file-earmark-text"></i><div class="es-text mb-0">Pages appear with the first page views.</div></div>');
    $('#listBrowsers').html(bars(d.dims.browser, r => CRM.esc(r.value), 'visitors', 'No data yet', true) + ((d.dims.os && d.dims.os.length) ? '<div class="section-title mt-3">Operating systems</div>' + bars(d.dims.os.slice(0, 5), r => CRM.esc(r.value), 'visitors', '', true) : ''));
    $('#listLanding').html((d.landing && d.landing.length ? '<div class="section-title">Entry pages</div>' + bars(d.landing, r => '<span class="mono">' + CRM.esc(r.path) + '</span>', 'entries', '', true) : '<div class="empty-state py-2"><i class="bi bi-box-arrow-in-right"></i><div class="es-text mb-0">Entry pages appear with the first sessions.</div></div>') + (d.exits && d.exits.length ? '<div class="section-title mt-3">Exit pages</div>' + bars(d.exits, r => '<span class="mono">' + CRM.esc(r.path) + '</span>', 'exits', '', true) : ''));
    if (d.level === 'full') {
      const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; let max = 1; Object.values(d.heat || {}).forEach(r => Object.values(r).forEach(v => { if (v > max) max = v; }));
      if (!Object.keys(d.heat || {}).length) $('#heatWrap').html('<div class="empty-state py-2"><i class="bi bi-grid-3x3"></i><div class="es-text mb-0">The heatmap fills as visits arrive.</div></div>');
      else { let h = '<div class="heat"><div></div>' + [...Array(24).keys()].map(i => '<div class="hd">' + i + '</div>').join(''); for (let dIdx = 0; dIdx < 7; dIdx++) { h += '<div class="hl">' + days[dIdx] + '</div>'; for (let hr = 0; hr < 24; hr++) { const v = (d.heat[dIdx] || {})[hr] || 0; h += '<div class="hc" title="' + days[dIdx] + ' ' + hr + ':00 · ' + v + ' views" style="background:rgba(252,175,23,' + (v ? (0.15 + 0.85 * v / max).toFixed(2) : 0.06) + ')"></div>'; } } $('#heatWrap').html(h + '</div>'); }
    } else $('#heatWrap').html('<div class="empty-state py-2"><i class="bi bi-grid-3x3"></i><div class="es-text mb-0">Upgrade to Professional for hourly activity.</div></div>');
    renderRecent(d.recent, d.level);
    renderLive({ active: t.active_now, pages: [], minutes: 5 });
    if (realtime) CRM.get('api/analytics.php', { action: 'realtime', id: id }).done(rt => { renderLive(rt); renderLiveVisitors(rt); }); else renderLiveVisitors({ visitors: [], minutes: 5 });
  });
  if (realtime) setInterval(function () { if (document.visibilityState !== 'visible') return; CRM.get('api/analytics.php', { action: 'realtime', id: id }).done(rt => { renderLive(rt); renderLiveVisitors(rt); $('#kNow').text(fmt(rt.active)); if (rt.recent && rt.recent.length) renderRecent(rt.recent, wrap.data('level')); }); }, 20000);
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
