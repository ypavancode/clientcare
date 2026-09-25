<?php /** Super Admin: website edit modal (any workspace) + edit / tracking scripts. Include on pages with .btn-edit-website buttons. */ ?>
<div class="modal fade" id="pwModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="<?= !empty($websiteModalReloadTable) ? 'table' : '1' ?>" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="website_save"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title">Edit website</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-12 small text-muted" id="pwOwner"></div>
      <div class="col-md-6"><label class="form-label">Website name *</label><input type="text" name="name" class="form-control" required maxlength="150"></div>
      <div class="col-md-6"><label class="form-label">URL *</label><input type="url" name="url" class="form-control" required placeholder="https://example.com"><div class="form-text">Changing the URL resets the monitoring state and the discovered page list.</div></div>
      <div class="col-md-6"><label class="form-label">End client (within the workspace)</label><select name="client_id" class="form-select"></select></div>
      <div class="col-md-6"><label class="form-label">Technology</label><select name="technology" class="form-select"><?php foreach (technologies() as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="monitoring_enabled" id="pw_mon"><label class="form-check-label small" for="pw_mon">Monitoring enabled</label></div></div>
      <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="page_monitoring_enabled" id="pw_pages"><label class="form-check-label small" for="pw_pages">Page monitoring</label></div></div>
      <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="form_discovery_enabled" id="pw_forms"><label class="form-check-label small" for="pw_forms">Form discovery</label></div></div>
      <div class="col-md-4"><label class="form-label">Max pages monitored</label><input type="number" name="max_pages" class="form-control" min="0" max="500"><div class="form-text">0 = platform default.</div></div>
      <div class="col-md-8"><label class="form-label">Expected text on the home page <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="expect_text" class="form-control" maxlength="190"></div>
      <div class="col-12"><div class="section-title">Visitor tracking</div><div class="d-flex flex-wrap align-items-center gap-2 small" id="pwTracking"></div></div>
      <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save website</button></div>
  </form>
</div></div></div>
<script>
window.pwRenderTracking = function (w) {
  const key = w.analytics_key, on = parseInt(w.analytics_enabled, 10) === 1;
  const snippet = key ? '<script async src="' + CRM.baseUrl + '/analytics/' + key + '.js"><\/script>' : '';
  let h = key ? '<span class="badge ' + (on ? 'bg-success-subtle' : 'bg-warning-subtle') + '">' + (on ? 'tracking on' : 'tracking paused') + '</span><span class="mono">' + CRM.esc(key) + '</span> <i class="bi bi-clipboard copy-btn" data-copy="' + CRM.esc(key) + '" title="Copy tracking ID"></i> <button type="button" class="btn btn-light btn-sm copy-btn" data-copy="' + CRM.esc(snippet) + '"><i class="bi bi-code-slash me-1"></i>Copy tracking code</button>' : '<span class="text-muted">Tracking not set up.</span>';
  h += '<span class="text-muted">' + (w.analytics_last_event_at ? 'last event ' + CRM.esc(w.analytics_last_event_at) : 'no events yet') + ' · ' + (w.pv_month || 0) + ' views this month</span>';
  h += '<span class="ms-auto d-flex gap-1">';
  h += on ? '<button type="button" class="btn btn-light btn-sm pw-track" data-enable="0"><i class="bi bi-pause me-1"></i>Pause tracking</button>' : '<button type="button" class="btn btn-light btn-sm pw-track" data-enable="1"><i class="bi bi-play me-1"></i>Enable tracking</button>';
  if (key) h += '<button type="button" class="btn btn-light btn-sm text-warning pw-regen"><i class="bi bi-arrow-repeat me-1"></i>Regenerate ID</button>';
  h += '</span>';
  $('#pwTracking').html(h);
};
$(document).on('click', '.btn-edit-website', function () {
  const id = $(this).data('id');
  CRM.post('api/platform.php', { action: 'website_get', id: id }).done(res => {
    if (!res.success) { CRM.toast(res.message, 'error'); return; }
    const w = res.website, f = $('#pwModal form')[0]; f.reset();
    f.elements['id'].value = w.id; f.elements['name'].value = w.name || ''; f.elements['url'].value = w.url || ''; f.elements['technology'].value = w.technology || 'Other';
    f.elements['monitoring_enabled'].checked = w.monitoring_enabled == 1; f.elements['page_monitoring_enabled'].checked = w.page_monitoring_enabled == 1; f.elements['form_discovery_enabled'].checked = w.form_discovery_enabled == 1;
    f.elements['max_pages'].value = w.max_pages || 0; f.elements['expect_text'].value = w.expect_text || ''; f.elements['notes'].value = w.notes || '';
    const $c = $(f.elements['client_id']).empty(); (w.clients || []).forEach(c => $c.append('<option value="' + c.id + '"' + (c.id == w.client_id ? ' selected' : '') + '>' + CRM.esc(c.name) + '</option>'));
    $('#pwOwner').html('<i class="bi bi-buildings me-1"></i>Client workspace: <strong>' + CRM.esc(w.tenant_name || '—') + '</strong> · website #' + w.id + ' · status ' + CRM.esc(w.status));
    $('#pwModal').data('site', w); pwRenderTracking(w);
    $('#pwModal .modal-title').text('Edit website – ' + w.name); bootstrap.Modal.getOrCreateInstance($('#pwModal')[0]).show();
  });
});
$(document).on('click', '.pw-track', function () {
  const w = $('#pwModal').data('site'), en = $(this).data('enable');
  CRM.post('api/platform.php', { action: 'website_tracking', id: w.id, enable: en }).done(res => { CRM.toast(res.message, res.success ? 'success' : 'error'); if (res.success) { w.analytics_enabled = en; if (res.key) w.analytics_key = res.key; pwRenderTracking(w); } });
});
$(document).on('click', '.pw-regen', async function () {
  const w = $('#pwModal').data('site');
  const ok = await CRM.confirm({ title: 'Regenerate the tracking ID?', text: 'The current snippet on ' + w.name + ' stops collecting immediately; the website must be updated with the new snippet. Collected data is kept.', confirmButtonText: 'Regenerate' });
  if (!ok) return;
  CRM.post('api/platform.php', { action: 'website_regenerate_key', id: w.id }).done(res => { CRM.toast(res.message, res.success ? 'success' : 'error'); if (res.success) { w.analytics_key = res.key; pwRenderTracking(w); } });
});
</script>
