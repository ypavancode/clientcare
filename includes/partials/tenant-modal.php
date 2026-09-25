<?php /** Super Admin: client (workspace) edit modal + edit / delete scripts. Include on pages with .btn-edit-tenant / .btn-delete-tenant buttons. */ ?>
<div class="modal fade" id="tenantModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="<?= !empty($tenantModalReloadTable) ? 'table' : '1' ?>" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="tenant_save"><input type="hidden" name="tenant_id" value="">
    <div class="modal-header"><h5 class="modal-title">Edit client</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Client / workspace name *</label><input type="text" name="name" class="form-control" required maxlength="150"></div>
      <div class="col-md-3"><label class="form-label">Account status</label><select name="status" class="form-select"><option value="active">Active</option><option value="pending">Pending verification</option><option value="suspended">Suspended (deactivated)</option><option value="cancelled">Cancelled</option></select></div>
      <div class="col-md-3"><label class="form-label">Slug</label><input type="text" class="form-control" name="slug_display" disabled></div>
      <div class="col-md-6"><label class="form-label">Billing email</label><input type="email" name="billing_email" class="form-control" maxlength="190"></div>
      <div class="col-md-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" maxlength="30"></div>
      <div class="col-md-3"><label class="form-label">Country</label><input type="text" name="country" class="form-control" maxlength="60"></div>
      <div class="col-md-6"><label class="form-label">Timezone</label><select name="timezone" class="form-select"><option value="">Platform default</option><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= $tz ?>"><?= $tz ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">White-label name</label><input type="text" name="white_label_name" class="form-control" maxlength="120" placeholder="Shown to their clients (Business+ plans)"></div>
      <div class="col-12"><label class="form-label">Alert recipients <span class="text-muted fw-normal">(comma separated, in addition to owners / admins)</span></label><input type="text" name="alert_emails" class="form-control"></div>
      <div class="col-12"><label class="form-label">Internal notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save client</button></div>
  </form>
</div></div></div>
<script>
$(document).on('click', '.btn-edit-tenant', function () {
  const id = $(this).data('id');
  CRM.post('api/platform.php', { action: 'tenant_get', tenant_id: id }).done(res => {
    if (!res.success) { CRM.toast(res.message, 'error'); return; }
    const t = res.tenant, f = $('#tenantModal form')[0]; f.reset();
    f.elements['tenant_id'].value = t.id; f.elements['name'].value = t.name || ''; f.elements['status'].value = t.status; f.elements['slug_display'].value = t.slug || '';
    f.elements['billing_email'].value = t.billing_email || ''; f.elements['phone'].value = t.phone || ''; f.elements['country'].value = t.country || ''; f.elements['timezone'].value = t.timezone || '';
    f.elements['white_label_name'].value = t.white_label_name || ''; f.elements['alert_emails'].value = t.alert_emails || ''; f.elements['notes'].value = t.notes || '';
    $('#tenantModal .modal-title').text('Edit client – ' + t.name); f.elements['status'].disabled = (parseInt(t.id, 10) === 1);
    bootstrap.Modal.getOrCreateInstance($('#tenantModal')[0]).show();
  });
});
$(document).on('click', '.btn-delete-tenant', async function () {
  const $b = $(this), slug = String($b.data('slug'));
  const r = await Swal.fire({ title: 'Delete ' + $b.data('name') + '?', html: 'This permanently deletes the client workspace with <strong>every website, page, form, user, incident and report</strong>. It cannot be undone.<br><br>Type <code>' + CRM.esc(slug) + '</code> to confirm.', input: 'text', inputPlaceholder: slug, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete permanently', cancelButtonText: 'Cancel', reverseButtons: true, focusCancel: true, inputValidator: v => v === slug ? null : 'Type the slug exactly: ' + slug });
  if (!r.isConfirmed) return;
  $b.prop('disabled', true);
  CRM.post('api/platform.php', { action: 'delete_tenant', tenant_id: $b.data('id'), confirm: r.value }).done(res => {
    CRM.toast(res.message, res.success ? 'success' : 'error');
    if (!res.success) return;
    if ($b.closest('table.datatable[data-source]').length) CRM.reloadTable(); else CRM.navigate(res.redirect || CRM.url('platform/customers.php'));
  }).always(() => $b.prop('disabled', false));
});
</script>
