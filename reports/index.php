<?php
require_once __DIR__ . '/../includes/init.php';
require_once ROOT_PATH . '/includes/Reports.php';
Auth::requireLogin();
Auth::requireAbility('reports');
if ((string) (Tenant::feature('reports') ?? 'none') === 'none') http_error(403, 'Reports are not included in the ' . e(Tenant::plan()['name'] ?? 'current') . ' plan. Upgrade to Starter or above to unlock reports.');

$reports = Reports::list();
$key = get('report', '');
$filters = ['from' => get('from', date('Y-m-d', strtotime('-30 days'))), 'to' => get('to', date('Y-m-d')), 'client_id' => (int) get('client_id', 0)];
$result = ($key && isset($reports[$key])) ? Reports::run($key, $filters) : null;

$pageTitle = $result ? $reports[$key]['title'] : 'Reports';
$breadcrumbs = $result ? [['label' => 'Reports', 'url' => 'reports/index.php'], ['label' => $reports[$key]['title']]] : [['label' => 'Reports']];
$qs = http_build_query(['report' => $key] + $filters);
$pageActions = $result ? '<a href="' . url('reports/export.php?format=csv&' . $qs) . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-filetype-csv me-1"></i>CSV</a><a href="' . url('reports/export.php?format=xls&' . $qs) . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a><a href="' . url('reports/export.php?format=print&' . $qs) . '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF / Print</a>' : '';
include ROOT_PATH . '/includes/layout/header.php';
?>
<?php if (!$result): ?>
<div class="row g-3">
  <?php foreach ($reports as $k => $r): ?>
    <div class="col-md-6 col-xl-4"><a href="?report=<?= $k ?>" class="stat-card"><div class="stat-icon tint-brand"><i class="bi <?= $r['icon'] ?>"></i></div><div><div class="fw-600 fw-semibold"><?= e($r['title']) ?></div><div class="stat-label"><?= e($r['desc']) ?></div></div></a></div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<form class="filter-bar" method="get">
  <input type="hidden" name="report" value="<?= e($key) ?>">
  <select name="report" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:220px">
    <?php foreach ($reports as $k => $r): ?><option value="<?= $k ?>" <?= $k === $key ? 'selected' : '' ?>><?= e($r['title']) ?></option><?php endforeach; ?>
  </select>
  <?php if (Reports::usesDateRange($key)): ?>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filters['from']) ?>">
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filters['to']) ?>">
  <?php endif; ?>
  <?php if ($key !== 'emails'): ?>
  <?= remote_select('client_id', 'clients', $filters['client_id'] ?: null, 'All clients…', ['small' => 1]) ?>
  <?php endif; ?>
  <button class="btn btn-dark btn-sm">Apply</button>
  <span class="ms-auto text-muted small"><?= number_format(count($result['rows'])) ?> row(s)</span>
</form>
<?php if (!empty($result['limited'])): ?><div class="alert alert-warning py-2 small"><i class="bi bi-info-circle me-1"></i>Showing the first <?= number_format($result['limit']) ?> rows. Narrow the date range or choose a client to see everything, or export (up to <?= number_format(Reports::MAX_EXPORT_ROWS) ?> rows).</div><?php endif; ?>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50">
    <thead><tr><?php foreach ($result['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $row): ?>
      <tr><?php foreach ($result['columns'] as $col => $label): $v = $row[$col] ?? ''; ?>
        <td><?php
          if ($col === 'status' && in_array($key, ['health'], true)) echo website_status_badge($v);
          elseif ($col === 'ssl_status') echo ssl_status_badge($v);
          elseif ($col === 'page_status') echo page_status_badge($v);
          elseif ($col === 'page_health') echo page_health_badge($v);
          elseif ($col === 'status' && $key === 'clients') echo client_status_badge($v);
          elseif ($col === 'result') echo $v === 'success' ? status_pill('success', 'Success') : status_pill('danger', 'Failed');
          elseif (in_array($col, ['expiry_date', 'domain_expiry', 'hosting_expiry', 'ssl_expires'], true)) echo format_date($v) . ' ' . ($v ? expiry_badge($v) : '');
          elseif (in_array($col, ['tested_at', 'started_at', 'last_checked', 'last_scan', 'last_failed_at', 'ssl_checked_at', 'last_tested_at', 'last_success_at', 'created_at', 'sent_at'], true) || ($col === 'resolved_at' && $v !== 'Ongoing')) echo format_datetime($v);
          elseif (in_array($col, ['registration_date', 'start_date', 'due_date', 'created'], true)) echo format_date($v);
          elseif ($col === 'uptime') echo $v !== '' ? '<span class="fw-500 ' . ($v >= 99.5 ? 'text-success' : ($v >= 97 ? 'text-warning' : 'text-danger')) . '">' . e($v) . '%</span>' : '—';
          elseif ($col === 'url' || $col === 'page_url') echo '<a href="' . e($v) . '" target="_blank" rel="noopener">' . e(truncate($v, 40)) . '</a>';
          else echo e(is_null($v) ? '—' : (string) $v);
        ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php include ROOT_PATH . '/includes/layout/footer.php'; ?>
