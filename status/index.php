<?php
/** Status pages of the workspace (customer-facing public pages). */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('status_pages.view');
$tid = Tenant::id();
$pages = DB::fetchAll("SELECT * FROM status_pages WHERE tenant_id = ? ORDER BY name", [$tid]);
$sites = DB::fetchAll("SELECT id, name, url FROM websites WHERE tenant_id = ? ORDER BY name LIMIT 500", [$tid]);
$can = Tenant::canAdd('status_pages');
$canManage = Auth::can('status_pages');
$editId = (int) get('edit', 0);

$pageTitle = 'Status Pages';
$breadcrumbs = [['label' => 'Status Pages']];
$pageSubtitle = 'Public pages that show your website, form and SSL status with uptime and incident history · ' . usage_badge($can['current'], $can['limit']);
$pageActions = $canManage ? '<button class="btn btn-dark btn-sm" data-open-modal="#statusModal"><i class="bi bi-plus-lg me-1"></i>New status page</button>' : '';
include ROOT_PATH . '/includes/layout/header.php';
?>
<?php if ($can['limit'] === 0): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i>Status pages are included from the <strong>Professional</strong> plan (1 page) and the <strong>Business</strong> plan (5 pages, white-label, custom domains). <a href="<?= url('billing/index.php') ?>">Upgrade</a> to publish one.</div>
<?php elseif (!$can['ok']): ?><div class="alert alert-warning py-2"><?= e($can['message']) ?> <a href="<?= url('billing/index.php') ?>" class="alert-link">Upgrade</a></div><?php endif; ?>
<div class="row g-3">
  <?php foreach ($pages as $p): $ids = json_decode((string) $p['website_ids'], true) ?: []; ?>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start"><div><div class="fw-600"><?= e($p['name']) ?></div><div class="small text-muted"><?= e($p['description'] ?: '') ?></div></div><?= status_pill($p['is_public'] ? 'success' : 'secondary', $p['is_public'] ? 'Public' : 'Hidden', false) ?></div>
      <div class="mt-2 small"><i class="bi bi-link-45deg"></i> <a href="<?= url('status/public.php?slug=' . $p['slug']) ?>" target="_blank"><?= e(url('status/public.php?slug=' . $p['slug'])) ?></a><?= $p['custom_domain'] ? '<div><i class="bi bi-globe"></i> ' . e($p['custom_domain']) . ' <span class="text-muted">(point a CNAME to ' . e(host_from_url(BASE_URL)) . ')</span></div>' : '' ?></div>
      <div class="small text-muted mt-1"><?= $ids ? count($ids) . ' selected website(s)' : 'All websites' ?> · <?= $p['show_forms'] ? 'forms' : '' ?> <?= $p['show_ssl'] ? '· SSL' : '' ?> <?= $p['show_incidents'] ? '· incidents' : '' ?> <?= $p['show_uptime'] ? '· uptime' : '' ?></div>
      <?php if ($canManage): ?><div class="mt-3 d-flex gap-1"><button class="btn btn-light btn-sm btn-edit-status" data-id="<?= $p['id'] ?>"><i class="bi bi-pencil me-1"></i>Edit</button><a href="<?= url('status/public.php?slug=' . $p['slug']) ?>" target="_blank" class="btn btn-light btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a><button class="btn btn-light btn-sm text-danger btn-action ms-auto" data-url="api/status_pages.php" data-params='{"action":"delete","id":<?= $p['id'] ?>}' data-confirm="Delete this status page?" data-confirm-btn="Delete" data-reload="1"><i class="bi bi-trash"></i></button></div><?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
  <?php if (!$pages): ?><div class="col-12"><div class="empty-state"><i class="bi bi-broadcast-pin"></i>No status page yet.<?= $canManage && $can['ok'] ? ' Create one to share the health of your websites with your clients.' : '' ?></div></div><?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="statusModal" tabindex="-1" data-auto-open><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/status_pages.php') ?>" data-reload="1" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="New status page">New status page</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required placeholder="Acme websites"></div>
      <div class="col-md-6"><label class="form-label">Address *</label><div class="input-group"><span class="input-group-text small">/status/</span><input type="text" name="slug" class="form-control" required pattern="[a-z0-9-]{3,60}" placeholder="acme"></div></div>
      <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" maxlength="300"></div>
      <div class="col-12"><label class="form-label">Websites shown <span class="text-muted fw-normal">(none selected = all)</span></label><div class="row g-1" style="max-height:180px;overflow:auto"><?php foreach ($sites as $s): ?><div class="col-md-6"><label class="form-check small"><input class="form-check-input" type="checkbox" name="website_ids[]" value="<?= $s['id'] ?>"> <?= e($s['name']) ?> <span class="text-muted"><?= e(host_from_url($s['url'])) ?></span></label></div><?php endforeach; ?></div></div>
      <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_public" id="sp_public" checked><label class="form-check-label small" for="sp_public">Public</label></div></div>
      <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_uptime" id="sp_up" checked><label class="form-check-label small" for="sp_up">Uptime</label></div></div>
      <div class="col-md-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_forms" id="sp_forms" checked><label class="form-check-label small" for="sp_forms">Forms</label></div></div>
      <div class="col-md-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_ssl" id="sp_ssl" checked><label class="form-check-label small" for="sp_ssl">SSL</label></div></div>
      <div class="col-md-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="show_incidents" id="sp_inc" checked><label class="form-check-label small" for="sp_inc">Incidents</label></div></div>
      <div class="col-md-3"><label class="form-label">Theme colour</label><input type="color" name="theme_color" class="form-control form-control-color" value="#FCAF17"></div>
      <div class="col-md-9"><label class="form-label">Footer text</label><input type="text" name="footer_text" class="form-control" maxlength="300" placeholder="Support: support@acme.com"></div>
      <?php if (Tenant::feature('custom_domain')): ?><div class="col-md-6"><label class="form-label">Custom domain</label><input type="text" name="custom_domain" class="form-control" placeholder="status.acme.com"><div class="form-text">Create a CNAME to <?= e(host_from_url(BASE_URL)) ?> and we serve the page there.</div></div><?php endif; ?>
      <?php if (Tenant::feature('white_label')): ?><div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="hide_branding" id="sp_wl"><label class="form-check-label small" for="sp_wl">Hide "<?= e(setting('platform_name', 'Outline Monitor')) ?>" branding (white label)</label></div></div><?php endif; ?>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save status page</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php
$pageScripts = '<script>
$(document).on("click", ".btn-edit-status", function () { CRM.post("api/status_pages.php", { action: "get", id: $(this).data("id") }).done(res => { if (!res.success) return; const p = res.page; CRM.openEdit("#statusModal", p, "Edit status page"); $("#statusModal input[name=\'website_ids[]\']").prop("checked", false); (p.website_ids || []).forEach(id => $("#statusModal input[name=\'website_ids[]\'][value=" + id + "]").prop("checked", true)); }); });
' . ($editId ? '$(function () { $(".btn-edit-status[data-id=' . $editId . ']").trigger("click"); });' : '') . '
</script>';
include ROOT_PATH . '/includes/layout/footer.php';
