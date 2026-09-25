<?php
/** Visitor analytics overview across every website of the workspace. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$level = Analytics::level();
$range = in_array(get('range'), ['today', 'yesterday', '7d', '30d', '90d', '180d'], true) ? get('range') : '30d';
[$from, $to] = Analytics::range($range);
$o = $level !== 'none' ? Analytics::overview(Tenant::id(), $from, $to) : null;
$quota = Analytics::quota();
$retention = Analytics::retentionDays();
$pageTitle = 'Visitor Analytics';
$breadcrumbs = [['label' => 'Analytics']];
$pageSubtitle = $level === 'none' ? 'Included from the Starter plan' : 'Who visits your client websites – every device counted once, no cookies · history ' . $retention . ' days';
$ranges = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '180d' => '180 days'];
$pageActions = '<div class="btn-group">';
foreach ($ranges as $k => $l) { if (['today' => 1, 'yesterday' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '180d' => 180][$k] > $retention) continue; $pageActions .= '<a href="' . url('analytics/index.php?range=' . $k) . '" class="btn btn-sm ' . ($range === $k ? 'btn-dark' : 'btn-light') . '">' . $l . '</a>'; }
$pageActions .= '</div>';
$useCharts = true;
include ROOT_PATH . '/includes/layout/header.php';
if ($level === 'none') {
    echo '<div class="card border-brand"><div class="card-body d-flex flex-wrap align-items-center gap-3"><div class="stat-icon tint-brand"><i class="bi bi-bar-chart-line"></i></div><div class="flex-grow-1"><div class="fw-600">Visitor analytics is a paid feature</div><div class="text-muted small">Visitors, page views, countries, devices and traffic sources for every client website – one script tag, no cookies. Included from the Starter plan.</div></div><a href="' . url('billing/index.php') . '" class="btn btn-brand btn-sm">Upgrade plan</a></div></div>';
    include ROOT_PATH . '/includes/layout/footer.php'; exit;
}
$t = $o['totals'];
$flag = fn(string $c) => Analytics::country($c);
$bars = function (array $rows, callable $label, string $key): string {
    if (!$rows) return '';
    $max = max(1, (int) $rows[0][$key]); $tot = max(1, array_sum(array_column($rows, $key))); $h = '<div class="bar-list">';
    foreach ($rows as $r) $h .= '<div class="bl"><div class="d-flex justify-content-between"><span class="text-truncate">' . $label($r) . '</span><span class="text-nowrap"><b>' . number_format((int) $r[$key]) . '</b> <span class="text-muted small-xs">' . round($r[$key] / $tot * 100) . '%</span></span></div><div class="progress"><div class="progress-bar bg-warning" style="width:' . max(3, (int) round($r[$key] / $max * 100)) . '%"></div></div></div>';
    return $h . '</div>';
};
?>
<div class="kpi-grid stagger mb-3">
  <div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-people"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $t['visitors'] ?>">0</div><div class="stat-label">Visitors</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-eye"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $t['pageviews'] ?>">0</div><div class="stat-label">Page views</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-broadcast"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $t['active_now'] ?>">0</div><div class="stat-label">Active now</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-code-slash"></i></div><div><div class="stat-value count-up" data-count="<?= (int) $t['tracking'] ?>">0</div><div class="stat-label">Websites tracked</div></div></div>
  <div class="stat-card"><div class="stat-icon <?= $quota['state'] === 'full' ? 'tint-danger' : ($quota['state'] === 'warn' ? 'tint-warning' : 'tint-secondary') ?>"><i class="bi bi-speedometer2"></i></div><div class="min-w-0"><div class="stat-value"><?= $quota['limit'] !== null ? $quota['pct'] . '%' : number_format($quota['current']) ?></div><div class="stat-label">Monthly quota used</div><div class="stat-trend text-muted"><?= $quota['limit'] !== null ? number_format($quota['current']) . ' / ' . number_format($quota['limit']) . ' · resets ' . date('d M', strtotime($quota['resets'])) : 'unlimited page views' ?></div></div></div>
</div>
<?php if ($quota['state'] !== 'ok'): ?><div class="alert <?= $quota['state'] === 'full' ? 'alert-danger' : 'alert-warning' ?> d-flex flex-wrap align-items-center gap-2 mb-3"><i class="bi bi-exclamation-triangle"></i><span class="flex-grow-1"><?= $quota['state'] === 'full' ? 'The monthly analytics limit is reached – new page views are not recorded until ' . format_date($quota['resets']) . '.' : 'You have used ' . $quota['pct'] . '% of this month\'s analytics page views.' ?></span><a href="<?= url('billing/index.php') ?>" class="btn btn-sm btn-dark">Upgrade</a></div><?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card chart-card h-100"><div class="card-header"><span><i class="bi bi-graph-up me-2"></i>All websites · visitors &amp; page views</span><span class="small text-muted"><?= $from === $to ? e($from) : e($from . ' → ' . $to) ?></span></div><div class="card-body"><div class="chart-box"><canvas id="chAll"></canvas></div><div class="chart-legend mt-2"><span style="color:#FCAF17">Visitors</span><span style="color:#e5e7eb">Page views</span></div></div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="card-header"><span><i class="bi bi-geo-alt me-2"></i>Countries, devices &amp; sources</span></div><div class="card-body">
    <?php if (!$o['dims']['country'] && !$o['dims']['device']): ?><?= empty_state('bi-geo', 'No data yet', 'Countries and devices appear once visitors arrive.', '', 'py-3') ?>
    <?php else: ?><?= $bars($o['dims']['country'], fn($r) => $flag($r['value'])[0] . ' ' . e($flag($r['value'])[1]), 'visitors') ?>
    <?php if ($o['dims']['device']): ?><div class="section-title mt-3">Devices</div><?= $bars($o['dims']['device'], fn($r) => e(ucfirst($r['value'])), 'visitors') ?><?php endif; ?>
    <?php if ($o['dims']['source']): ?><div class="section-title mt-3">Sources</div><?= $bars($o['dims']['source'], fn($r) => e(ucfirst($r['value'])), 'visitors') ?><?php endif; ?>
    <?php endif; ?>
  </div></div></div>
</div>
<div class="card"><div class="card-header"><span><i class="bi bi-globe2 me-2"></i>Websites</span><span class="small text-muted"><?= count($o['sites']) ?> website(s) · <?= $t['tracking'] ?> tracked</span></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover table-compact mb-0">
    <thead><tr><th>Website</th><th>Tracking</th><th class="text-end">Visitors</th><th class="text-end">Page views</th><th class="text-end">New devices</th><th class="text-end">Active now</th><th class="text-end">Last event</th><th></th></tr></thead>
    <tbody><?php foreach ($o['sites'] as $s): [$k, $lbl, $tone] = Analytics::installStatus($s); ?><tr>
      <td><a href="<?= url('websites/analytics.php?id=' . $s['id']) ?>" class="fw-600"><?= e($s['name']) ?></a><div class="small-xs text-muted"><?= e(host_from_url($s['url'])) ?></div></td>
      <td><?= status_pill($tone, $lbl, $k === 'active') ?></td>
      <td class="text-end fw-600"><?= number_format((int) $s['visitors']) ?></td><td class="text-end"><?= number_format((int) $s['pageviews']) ?></td><td class="text-end"><?= number_format((int) $s['new_visitors']) ?></td><td class="text-end"><?= (int) ($o['active'][$s['id']] ?? 0) ?: '<span class="text-muted">0</span>' ?></td>
      <td class="text-end text-muted small"><?= $s['analytics_last_event_at'] ? e(time_ago($s['analytics_last_event_at'])) : '—' ?></td>
      <td class="text-end row-actions"><a href="<?= url('websites/analytics.php?id=' . $s['id']) ?>" class="btn btn-light btn-sm"><i class="bi bi-bar-chart-line me-1"></i><?= $s['analytics_enabled'] ? 'Open' : 'Set up' ?></a></td>
    </tr><?php endforeach; ?>
    <?php if (!$o['sites']): ?><tr><td colspan="8"><?= empty_state('bi-globe2', 'No websites yet', 'Add a website first, then enable analytics on it.', '<a href="' . url('websites/add.php') . '" class="btn btn-brand btn-sm">Add website</a>') ?></td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php
$series = $o['series']; $labels = array_map(fn($r) => date('d M', strtotime($r['day'])), $series);
$pageScripts = '<script>(function(){const l=' . json_encode($labels) . ',v=' . json_encode(array_map('intval', array_column($series, 'visitors'))) . ',p=' . json_encode(array_map('intval', array_column($series, 'pageviews'))) . ';if(!l.length){$("#chAll").closest(".chart-box").html(\'<div class="empty-state py-3"><i class="bi bi-graph-up"></i><div class="es-title">No visits yet</div><div class="es-text mb-0">Enable tracking on a website and add the snippet.</div></div>\');return;}CRM.lineChart(document.getElementById("chAll"),l,[{label:"Visitors",data:v,color:CRM.palette.brand,pointRadius:2,pointBackgroundColor:CRM.palette.brand},{label:"Page views",data:p,color:CRM.palette.ink,fill:false}],{plugins:{legend:{display:false}},scales:{y:{ticks:{precision:0,maxTicksLimit:5}}}});})();</script>';
include ROOT_PATH . '/includes/layout/footer.php';
