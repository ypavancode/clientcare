<?php
/** Website → Forms: the automatically discovered form inventory of one website. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('forms.view');

$id = (int) get('id', 0);
$w = DB::fetch("SELECT w.*, c.name AS client_name FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.id = ? AND w.tenant_id = ?", [$id, Tenant::id()]);
if (!$w) http_error(404, 'Website not found.');
$filter = get('status', '');
$forms = DB::fetchAll("SELECT f.*, p.clean_url AS page_clean_url, (SELECT COUNT(*) FROM form_pages fp WHERE fp.form_id = f.id) AS page_count
    FROM forms f LEFT JOIN website_pages p ON p.id = f.page_id WHERE f.website_id = ?
    ORDER BY FIELD(f.status,'failed','captcha_blocked','not_tested','working','disabled','removed'), f.form_kind, f.name", [$id]);
$scans = DB::fetchAll("SELECT * FROM form_scans WHERE website_id = ? ORDER BY id DESC LIMIT 8", [$id]);
$lastScan = $scans[0] ?? null;
$c = ['total' => 0, 'working' => 0, 'failed' => 0, 'captcha_blocked' => 0, 'not_tested' => 0, 'disabled' => 0, 'removed' => 0, 'normal' => 0, 'popup' => 0, 'ajax' => 0, 'captcha' => 0, 'auto' => 0, 'manual' => 0];
foreach ($forms as $f) {
    $c[$f['status']] = ($c[$f['status']] ?? 0) + 1;
    if ($f['status'] === 'removed') continue;
    $c['total']++;
    $c[$f['form_kind'] === 'popup' ? 'popup' : (in_array($f['form_kind'], ['ajax', 'wordpress'], true) ? 'ajax' : 'normal')]++;
    if ($f['captcha_detected'] || ($f['captcha_type'] ?? 'none') !== 'none') $c['captcha']++;
    $c[$f['source'] === 'auto' ? 'auto' : 'manual']++;
}
$pagesTotal = (int) $w['pages_total'];
$next = FormDiscovery::nextScanAt($w);
$interval = max(1, (int) setting('form_check_interval', 10));

$pageTitle = 'Forms · ' . $w['name'];
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => $w['name'], 'url' => 'websites/view.php?id=' . $id], ['label' => 'Forms']];
$pageSubtitle = '<a href="' . e($w['url']) . '" target="_blank" rel="noopener">' . e($w['url']) . '</a> · Client: <a href="' . url('clients/view.php?id=' . $w['client_id']) . '">' . e($w['client_name']) . '</a> · every form found on the website is tested automatically every <strong>' . $interval . ' min</strong>';
$pageActions = (Auth::can('monitor') ? '<button class="btn btn-brand btn-sm btn-action" data-url="api/websites.php" data-params=\'{"action":"discover_forms","id":' . $id . '}\' data-loading="1" data-reload="1" title="Scan every page of the website for forms now (pages, popups, iframes)"><i class="bi bi-search me-1"></i>Scan Website for Forms</button>
<button class="btn btn-outline-primary btn-sm btn-action" data-url="api/forms.php" data-params=\'{"action":"test_website","website_id":' . $id . '}\' data-loading="1" data-reload="1" data-confirm="Test all forms on this website now?" data-icon="question"><i class="bi bi-clipboard-check me-1"></i>Test All Forms</button>' : '')
 . '<button class="btn btn-light btn-sm" data-open-modal="#formModal"><i class="bi bi-plus-lg me-1"></i>Add Form Manually</button>'
 . '<a href="' . url('websites/view.php?id=' . $id) . '" class="btn btn-light btn-sm"><i class="bi bi-globe2 me-1"></i>Website</a>';
$selectedWebsiteId = $id;
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>" class="stat-card <?= $filter === '' ? 'border-dark' : '' ?>"><div class="stat-icon tint-dark"><i class="bi bi-ui-checks"></i></div><div><div class="stat-value"><?= $c['total'] ?></div><div class="stat-label">Total Forms</div></div></a></div>
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>&status=working" class="stat-card <?= $filter === 'working' ? 'border-dark' : '' ?>"><div class="stat-icon tint-success"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= $c['working'] ?></div><div class="stat-label">Working</div></div></a></div>
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>&status=failed" class="stat-card <?= $c['failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-x-octagon"></i></div><div><div class="stat-value"><?= $c['failed'] ?></div><div class="stat-label">Failed</div></div></a></div>
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>&status=captcha_blocked" class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-shield-lock"></i></div><div><div class="stat-value"><?= $c['captcha_blocked'] ?></div><div class="stat-label">CAPTCHA Blocked</div></div></a></div>
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>&status=not_tested" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-question-circle"></i></div><div><div class="stat-value"><?= $c['not_tested'] ?></div><div class="stat-label">Not Tested Yet</div></div></a></div>
  <div class="col-6 col-md-3 col-xl"><a href="?id=<?= $id ?>&status=removed" class="stat-card"><div class="stat-icon tint-secondary"><i class="bi bi-eraser"></i></div><div><div class="stat-value"><?= $c['removed'] ?></div><div class="stat-label">Removed / Not Found</div></div></a></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-radar me-1"></i>Automatic Form Discovery</span><span class="small fw-normal"><?= $w['form_discovery_enabled'] ? '<span class="text-success">ENABLED</span>' : '<span class="text-secondary">DISABLED</span>' ?><?php if (Auth::can('websites')): ?> · <button class="btn btn-link btn-sm p-0 align-baseline btn-action" data-url="api/websites.php" data-params='{"action":"toggle_form_discovery","id":<?= $id ?>}' data-reload="1"><?= $w['form_discovery_enabled'] ? 'disable' : 'enable' ?></button><?php endif; ?></span></div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Total Pages</div><div class="val"><?= $pagesTotal ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Normal Forms</div><div class="val"><?= $c['normal'] ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Popup Forms</div><div class="val"><?= $c['popup'] ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">AJAX / Plugin Forms</div><div class="val"><?= $c['ajax'] ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">With CAPTCHA</div><div class="val"><?= $c['captcha'] ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Discovered / Manual</div><div class="val"><?= $c['auto'] ?> / <?= $c['manual'] ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Last Form Scan</div><div class="val small"><?= $w['last_form_scan_at'] ? format_datetime($w['last_form_scan_at']) : 'Never' ?></div><div class="small-xs text-muted"><?= e(time_ago($w['last_form_scan_at'])) ?></div></div></div>
          <div class="col-6 col-md-3"><div class="info-box"><div class="lbl">Next Form Scan</div><div class="val small"><?= $next ? ($next === 'at the next cron run' ? 'Next cron run' : format_datetime($next)) : '—' ?></div><div class="small-xs text-muted">re-scan every <?= (int) setting('form_scan_interval_hours', 24) ?> h</div></div></div>
        </div>
        <?php if ($lastScan): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i><?= e($lastScan['summary']) ?></div><?php endif; ?>
        <div class="small text-muted mt-1">Every page (sitemap, internal links, WordPress pages, landing pages) is opened and inspected for <code>&lt;form&gt;</code> elements, hidden popup / modal forms, WordPress plugin forms, AJAX forms, same-origin iframes and third-party embeds. Headless Chrome is used for JavaScript-rendered forms and popup triggers when it is available: <strong><?= Browser::available() ? 'available' : 'not available on this server' ?></strong>. CAPTCHA is detected and never bypassed.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Recent Scans</span></div>
      <div class="card-body p-0">
        <?php if (!$scans): ?><div class="empty-state py-3"><i class="bi bi-radar"></i>No scan yet – click <strong>Scan Website for Forms</strong> or wait for the cron.</div>
        <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0 small">
          <thead><tr><th>When</th><th>Pages</th><th>Forms</th><th>New</th><th>Removed</th><th>Engine</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($scans as $s): ?>
            <tr title="<?= e($s['summary']) ?>"><td class="text-nowrap"><?= format_datetime($s['started_at']) ?><div class="small-xs text-muted"><?= e($s['trigger_by']) ?><?= $s['duration_ms'] ? ' · ' . round($s['duration_ms'] / 1000, 1) . 's' : '' ?></div></td><td><?= (int) $s['pages_scanned'] ?><?= $s['pages_failed'] ? ' <span class="text-danger">(' . (int) $s['pages_failed'] . ' failed)</span>' : '' ?></td><td><?= (int) $s['forms_found'] ?></td><td><?= (int) $s['forms_new'] ?></td><td><?= (int) $s['forms_removed'] ?></td><td><?= e($s['engine']) ?><?= $s['popups_found'] ? '<div class="small-xs text-muted">' . (int) $s['popups_found'] . ' popup(s)</div>' : '' ?></td><td><?= status_pill(['done' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'running' => 'info'][$s['status']] ?? 'secondary', ucfirst($s['status']), false) ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card dt-card">
  <div class="card-header"><span><i class="bi bi-list-check me-1"></i>Form Inventory<?= $filter ? ' · ' . e(ucwords(str_replace('_', ' ', $filter))) : '' ?></span><span class="small fw-normal text-muted"><?= count(array_filter($forms, fn($f) => $filter === '' ? $f['status'] !== 'removed' : $f['status'] === $filter)) ?> form(s)</span></div>
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[]'>
    <thead><tr><th>Form</th><th>Page</th><th>Type</th><th>Technology</th><th>Fields</th><th>CAPTCHA</th><th>Status</th><th>Last Checked</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($forms as $f): if ($filter === '' ? $f['status'] === 'removed' : $f['status'] !== $filter) continue;
      $fields = json_decode((string) $f['fields_json'], true) ?: [];
      $visibleFields = array_values(array_filter($fields, fn($x) => empty($x['hidden'])));
      $captcha = $f['captcha_detected'] ?: (($f['captcha_type'] ?? 'none') !== 'none' ? $f['captcha_type'] : null);
      $pageDisplay = page_display_url($f['page_clean_url'], $f['page_url']); ?>
      <tr data-row-id="<?= $f['id'] ?>" class="<?= $f['status'] === 'removed' ? 'text-muted' : '' ?>">
        <td><span class="fw-500"><?= e($f['name']) ?></span> <?= form_source_badge($f['source']) ?>
          <div class="small-xs text-muted"><?= $f['form_title'] && $f['form_title'] !== $f['name'] ? e($f['form_title']) . ' · ' : '' ?><?= $f['form_dom_id'] ? '#' . e($f['form_dom_id']) . ' · ' : '' ?><?= $f['submit_label'] ? 'button "' . e($f['submit_label']) . '"' : '' ?></div>
          <details class="small-xs mt-1"><summary class="text-muted">details</summary>
            <div class="mono small-xs mt-1">selector: <?= e($f['form_selector'] ?: 'auto') ?><?= $f['popup_trigger'] ? '<br>popup trigger: ' . e($f['popup_trigger']) : '' ?><?= $f['popup_selector'] ? '<br>popup: ' . e($f['popup_selector']) : '' ?><br>action: <?= e($f['form_url'] ?: ($f['action_url'] ?: '(page URL)')) ?> · <?= e($f['method']) ?><?= $f['ajax'] ? ' · AJAX' : '' ?><?= $f['in_iframe'] ? '<br>iframe: ' . e(truncate($f['iframe_src'] ?? '', 70)) : '' ?><br>first seen: <?= format_datetime($f['first_discovered_at'] ?: $f['created_at']) ?> · last seen: <?= $f['last_discovered_at'] ? format_datetime($f['last_discovered_at']) : '—' ?><?= $f['page_count'] > 1 ? '<br>appears on ' . (int) $f['page_count'] . ' pages' : '' ?></div>
          </details></td>
        <td><a href="<?= e($pageDisplay) ?>" target="_blank" rel="noopener"><?= e(truncate($f['page_title'] ?: Monitor::pathLabel((string) parse_url($f['page_url'], PHP_URL_PATH)), 34)) ?></a><div class="small-xs text-muted mono"><?= e(truncate((string) (parse_url($pageDisplay, PHP_URL_PATH) ?: '/'), 40)) ?></div></td>
        <td><?= form_kind_badge($f['form_kind']) ?><div class="small-xs text-muted"><?= e($f['form_type']) ?></div></td>
        <td class="small"><?= e($f['technology'] ?: ($f['wp_plugin'] ? FormTester::pluginLabel($f['wp_plugin']) : '—')) ?></td>
        <td class="small"><?php if ($visibleFields): ?><span title="<?= e(implode(', ', array_map(fn($x) => $x['label'] ?: $x['name'], $visibleFields))) ?>"><?= e(implode(', ', array_slice(array_map(fn($x) => $x['label'] ?: $x['name'], $visibleFields), 0, 4))) ?><?= count($visibleFields) > 4 ? ' +' . (count($visibleFields) - 4) : '' ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><?= $captcha ? '<span class="text-info"><i class="bi bi-shield-lock"></i> ' . e(form_captcha_label($captcha)) . '</span>' : '<span class="text-muted">No</span>' ?></td>
        <td><?= form_status_badge($f['status']) ?><?php if ($f['status'] === 'failed'): ?><div class="small-xs text-danger"><?= e($f['last_failure_reason'] ?: '') ?></div><?php elseif ($f['status'] === 'captcha_blocked'): ?><div class="small-xs text-muted">no failure alert sent</div><?php elseif ($f['status'] === 'removed'): ?><div class="small-xs text-muted">since <?= format_datetime($f['removed_at']) ?></div><?php elseif (!$f['auto_test'] && $f['status'] !== 'disabled'): ?><div class="small-xs text-muted">inventory only</div><?php endif; ?></td>
        <td class="text-muted small"><?= e(time_ago($f['last_tested_at'])) ?><?= $f['last_outcome'] ? '<div class="small-xs">' . form_outcome_badge($f['last_outcome'], false) . '</div>' : '' ?></td>
        <td class="row-actions text-end text-nowrap">
          <?php if ($f['status'] !== 'removed' && $f['status'] !== 'disabled'): ?><button class="btn btn-light btn-sm btn-test-form" data-id="<?= $f['id'] ?>" title="Test now"><i class="bi bi-play-circle"></i></button> <?php endif; ?>
          <button class="btn btn-light btn-sm btn-history" data-id="<?= $f['id'] ?>" data-name="<?= e($f['name']) ?>" title="Test history"><i class="bi bi-clock-history"></i></button>
          <?php if (Auth::can('forms')): ?> <button class="btn btn-light btn-sm btn-edit-form" data-id="<?= $f['id'] ?>" title="Edit configuration"><i class="bi bi-pencil"></i></button><?php endif; ?>
          <a href="<?= e($f['page_url']) ?>" target="_blank" rel="noopener" class="btn btn-light btn-sm" title="Open page"><i class="bi bi-box-arrow-up-right"></i></a>
          <?php if (Auth::isAdmin()): ?> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/forms.php" data-params='{"action":"delete","id":<?= $f['id'] ?>}' data-confirm="Delete this form and its test history?<?= $f['source'] === 'auto' && $f['status'] !== 'removed' ? ' It will be re-discovered at the next scan unless it is removed from the website.' : '' ?>" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="historyModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Test History</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-0" id="historyBody"><div class="p-4 text-center text-muted">Loading…</div></div>
</div></div></div>
<?php
include ROOT_PATH . '/includes/partials/form-modal.php';
include ROOT_PATH . '/includes/partials/form-test-script.php';
include ROOT_PATH . '/includes/layout/footer.php';
