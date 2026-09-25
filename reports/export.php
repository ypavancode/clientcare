<?php
require_once __DIR__ . '/../includes/init.php';
require_once ROOT_PATH . '/includes/Reports.php';
Auth::requireLogin();
Auth::requireAbility('reports');
if ((string) (Tenant::feature('reports') ?? 'none') === 'none') http_error(403, 'Reports are not included in your plan.');

$reports = Reports::list();
$key = get('report', '');
if (!isset($reports[$key])) {
    flash('error', 'Unknown report.');
    redirect('reports/index.php');
}
$format = in_array(get('format'), ['csv', 'xls', 'print'], true) ? get('format') : 'csv';
$filters = ['from' => get('from', ''), 'to' => get('to', ''), 'client_id' => (int) get('client_id', 0)];
set_time_limit(300);
$result = Reports::run($key, $filters, Reports::MAX_EXPORT_ROWS);
$title = $reports[$key]['title'];
$file = preg_replace('~[^a-z0-9]+~', '-', strtolower($title)) . '-' . date('Y-m-d');
ActivityLog::add('report_exported', 'Exported ' . $title . ' (' . $format . ')');

$fmt = function ($col, $v) {
    if ($v === null) return '';
    return (string) $v;
};

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, array_values($result['columns']));
    foreach ($result['rows'] as $row) {
        $line = [];
        foreach ($result['columns'] as $col => $label) {
            $v = $fmt($col, $row[$col] ?? '');
            // Prevent CSV formula injection
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v;
            $line[] = $v;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

if ($format === 'xls') {
    // Excel-compatible HTML table (.xls). Opens directly in Excel / LibreOffice / Google Sheets.
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"><style>td,th{font-family:Arial;font-size:10pt;vertical-align:top}th{background:#111;color:#fff;font-weight:bold}</style></head><body>';
    echo '<table border="1"><tr><th colspan="' . count($result['columns']) . '" style="background:#FCAF17;color:#111">' . e($title) . ' – ' . e(company_name()) . ' – ' . date('d-M-Y') . '</th></tr><tr>';
    foreach ($result['columns'] as $c) echo '<th>' . e($c) . '</th>';
    echo '</tr>';
    foreach ($result['rows'] as $row) {
        echo '<tr>';
        foreach ($result['columns'] as $col => $label) echo '<td style="mso-number-format:\'\@\'">' . e($fmt($col, $row[$col] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

// Print / PDF: printable page (use browser "Save as PDF")
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($title) ?> · <?= e(company_name()) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; margin: 24px; }
  .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #FCAF17; padding-bottom: 10px; margin-bottom: 14px; }
  .head h1 { font-size: 18px; margin: 0; }
  .head .meta { font-size: 11px; color: #666; text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; vertical-align: top; }
  th { background: #111; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
  tr:nth-child(even) td { background: #fafafa; }
  .foot { margin-top: 14px; font-size: 10px; color: #888; }
  .toolbar { margin-bottom: 14px; }
  .toolbar button { background: #111; color: #FCAF17; border: 0; padding: 8px 14px; border-radius: 6px; font-weight: bold; cursor: pointer; }
  @media print { .toolbar { display: none; } body { margin: 0; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="head">
  <div><h1><?= e($title) ?></h1><div><?= e(company_name()) ?> · Client Website CRM</div></div>
  <div class="meta">Generated <?= date('d-M-Y h:i A') ?><br><?= count($result['rows']) ?> record(s)<?php if (Reports::usesDateRange($key)): ?><br>Period: <?= e($filters['from'] ?: '-30 days') ?> to <?= e($filters['to'] ?: 'today') ?><?php endif; ?></div>
</div>
<table>
  <thead><tr><?php foreach ($result['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($result['rows'] as $row): ?>
    <tr><?php foreach ($result['columns'] as $col => $label): ?><td><?= e($fmt($col, $row[$col] ?? '')) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (!$result['rows']): ?><tr><td colspan="<?= count($result['columns']) ?>" style="text-align:center;color:#888">No records</td></tr><?php endif; ?>
  </tbody>
</table>
<div class="foot">Report generated by <?= e(Auth::user()['name']) ?> · <?= e(company_name()) ?> CRM</div>
<script>window.addEventListener('load', () => { if (location.search.includes('auto=1')) window.print(); });</script>
</body>
</html>
