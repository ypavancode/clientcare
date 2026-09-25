<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$plans = DB::fetchAll("SELECT p.*, (SELECT COUNT(*) FROM tenants t WHERE t.plan_id = p.id) AS customers FROM plans p ORDER BY p.sort_order, p.id");
$pageTitle = 'Plans & Features';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Plans']];
$pageSubtitle = 'Every limit, monitoring interval and feature flag here drives the backend (Tenant::limit / interval / feature) and the public pricing page. Prices in INR / month (empty = custom pricing).';
$isOwner = Auth::isPlatformOwner();
$pageActions = $isOwner ? '<button class="btn btn-dark btn-sm" data-open-modal="#planModal"><i class="bi bi-plus-lg me-1"></i>New plan</button>' : '<span class="badge bg-secondary-subtle">Read only – plans and pricing are managed by the platform Owner</span>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card dt-card">
  <div class="table-responsive"><table class="table table-hover table-compact mb-0">
    <thead><tr><th>Plan</th><th>Price</th><th>Websites</th><th>Pages</th><th>Forms</th><th>Users</th><th>Status pages</th><th>Views / mo</th><th>Analytics</th><th>Intervals (site/page/form/ssl)</th><th>Retention</th><th>Trial</th><th>Clients</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($plans as $p): ?>
      <tr><td class="fw-500"><?= e($p['name']) ?><?= $p['is_popular'] ? ' <span class="badge bg-brand">popular</span>' : '' ?><div class="small-xs text-muted mono"><?= e($p['code']) ?></div></td><td><?= $p['price_monthly'] === null ? 'Custom' : '₹' . number_format((int) $p['price_monthly']) ?></td>
        <td><?= $p['max_websites'] ?? '∞' ?></td><td><?= $p['max_pages'] ?? '∞' ?></td><td><?= $p['max_forms'] ?? '∞' ?></td><td><?= $p['max_users'] ?? '∞' ?></td><td><?= $p['max_status_pages'] ?? '∞' ?></td><td><?= $p['max_pageviews_month'] !== null ? number_format((int) $p['max_pageviews_month']) : '∞' ?></td>
        <td class="small"><?php $fx = json_decode((string) $p['features'], true) ?: []; ?><?= e(ucfirst((string) ($fx['analytics'] ?? 'none'))) ?><?= ($fx['analytics'] ?? 'none') !== 'none' ? ' · ' . e((string) ($fx['geo'] ?? 'country')) . (!empty($fx['realtime']) ? ' · live' : '') . ' · ' . (int) ($fx['analytics_retention'] ?? 30) . ' d' : '' ?></td>
        <td class="small"><?= (int) $p['website_interval'] ?> / <?= (int) $p['page_interval'] ?> / <?= (int) $p['form_interval'] ?> / <?= (int) $p['ssl_interval'] ?> min</td><td><?= (int) $p['retention_days'] ?> d</td><td><?= (int) $p['trial_days'] ?> d</td><td><?= (int) $p['customers'] ?></td>
        <td><?= status_pill($p['status'] === 'active' ? 'success' : 'secondary', ucfirst($p['status']), false) ?><?= !$p['is_public'] ? '<div class="small-xs text-muted">hidden</div>' : '' ?></td>
        <td class="text-end text-nowrap"><?php if ($isOwner): ?><button class="btn btn-light btn-sm btn-edit-plan" data-plan='<?= e(json_encode($p, JSON_UNESCAPED_SLASHES)) ?>' title="Edit"><i class="bi bi-pencil"></i></button> <?php if (!$p['customers'] && $p['code'] !== 'free'): ?><button class="btn btn-light btn-sm text-danger btn-action" data-url="api/platform.php" data-params='{"action":"plan_delete","id":<?= $p['id'] ?>}' data-confirm="Delete the plan <?= e($p['name']) ?>?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button><?php endif; ?><?php else: ?><span class="text-muted small-xs">owner only</span><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<div class="modal fade" id="planModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="1" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="plan_save"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="New plan">New plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-2">
      <div class="col-md-4"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label">Code <span class="text-muted fw-normal">(new only)</span></label><input type="text" name="code" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">Price ₹/mo</label><input type="number" name="price_monthly" class="form-control" placeholder="custom"></div>
      <div class="col-md-3"><label class="form-label">Trial days</label><input type="number" name="trial_days" class="form-control" value="14"></div>
      <div class="col-12"><label class="form-label">Tagline</label><input type="text" name="tagline" class="form-control"></div>
      <?php foreach (['max_websites' => 'Websites', 'max_pages' => 'Pages', 'max_forms' => 'Forms', 'max_users' => 'Users', 'max_status_pages' => 'Status pages', 'max_pageviews_month' => 'Views / mo'] as $k => $l): ?><div class="col-md-2 col-4"><label class="form-label small"><?= $l ?></label><input type="number" name="<?= $k ?>" class="form-control form-control-sm" placeholder="∞"></div><?php endforeach; ?>
      <div class="col-md-2 col-4"><label class="form-label small">Retention d</label><input type="number" name="retention_days" class="form-control form-control-sm" value="30"></div>
      <?php foreach (['website_interval' => 'Site min', 'page_interval' => 'Page min', 'form_interval' => 'Form min', 'ssl_interval' => 'SSL min', 'sort_order' => 'Sort'] as $k => $l): ?><div class="col-md-2 col-4"><label class="form-label small"><?= $l ?></label><input type="number" name="<?= $k ?>" class="form-control form-control-sm" value="<?= $k === 'sort_order' ? 100 : 60 ?>"></div><?php endforeach; ?>
      <div class="col-md-2 col-4"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_public" id="pl_pub" checked><label class="form-check-label small" for="pl_pub">Shown on the pricing page</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_popular" id="pl_pop"><label class="form-check-label small" for="pl_pop">"Most popular" badge</label></div></div>
      <div class="col-12"><div class="section-title">Analytics &amp; reporting tier</div></div>
      <div class="col-md-2 col-6"><label class="form-label small">Analytics</label><select name="f_analytics" class="form-select form-select-sm"><option value="none">None</option><option value="basic">Basic</option><option value="full">Full</option></select></div>
      <div class="col-md-2 col-6"><label class="form-label small">History (days)</label><input type="number" name="f_analytics_retention" class="form-control form-control-sm" min="7" max="730" value="30"></div>
      <div class="col-md-2 col-6"><label class="form-label small">Geo detail</label><select name="f_geo" class="form-select form-select-sm"><option value="country">Country</option><option value="region">Country + region</option><option value="city">Country + region + city</option></select></div>
      <div class="col-md-2 col-6"><label class="form-label small">Events / mo</label><input type="number" name="f_max_events_month" class="form-control form-control-sm" min="0" placeholder="0 = ∞"></div>
      <div class="col-md-2 col-6"><label class="form-label small">Reports</label><select name="f_reports" class="form-select form-select-sm"><option value="none">None</option><option value="basic">Basic</option><option value="standard">Standard</option><option value="advanced">Advanced</option></select></div>
      <div class="col-md-2 col-6 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="f_realtime" id="pl_rt"><label class="form-check-label small" for="pl_rt">Real-time</label></div></div>
      <div class="col-md-6"><label class="form-label">Highlights <span class="text-muted fw-normal">(one per line)</span></label><textarea name="highlights" class="form-control form-control-sm" rows="6"></textarea></div>
      <div class="col-md-6"><label class="form-label">Other features (JSON)</label><textarea name="features" class="form-control form-control-sm mono" rows="6">{"form_discovery":1,"popup_discovery":1,"ajax_forms":1,"captcha_detection":1,"hosting":1,"reports":"basic","status_pages":0,"white_label":0,"custom_domain":0,"webhooks":0,"api":"none","notification_rules":0,"maintenance_windows":0,"client_contacts":0,"team_permissions":0,"priority_support":0,"incident_history":"basic","analytics":"basic","analytics_retention":30}</textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Save plan</button></div>
  </form>
</div></div></div>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-plan', function () {
  const p = $(this).data('plan'); const f = $('#planModal form')[0]; f.reset();
  Object.keys(p).forEach(k => { const el = f.elements[k]; if (!el) return; if (el.type === 'checkbox') el.checked = p[k] == 1; else el.value = p[k] === null ? '' : p[k]; });
  f.elements['highlights'].value = (JSON.parse(p.highlights || '[]')).join('\n'); const fx = JSON.parse(p.features || '{}'); f.elements['features'].value = JSON.stringify(fx, null, 1);
  f.elements['f_analytics'].value = fx.analytics || 'none'; f.elements['f_analytics_retention'].value = fx.analytics_retention || 30; f.elements['f_geo'].value = fx.geo || 'country'; f.elements['f_max_events_month'].value = fx.max_events_month || ''; f.elements['f_reports'].value = fx.reports || 'basic'; f.elements['f_realtime'].checked = !!(fx.realtime === undefined ? (fx.analytics && fx.analytics !== 'none') : fx.realtime);
  $('#planModal .modal-title').text('Edit plan – ' + p.name); bootstrap.Modal.getOrCreateInstance($('#planModal')[0]).show();
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
