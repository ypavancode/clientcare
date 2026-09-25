<?php /** Client add/edit modal. */ ?>
<div class="modal fade" id="clientModal" tabindex="-1" data-auto-open <?= !empty($clientModalStay) ? '' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form class="ajax-form" action="<?= url('api/clients.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <?php if (!empty($clientModalStay)): ?><input type="hidden" name="stay" value="1"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title" data-add="Add Client">Add Client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Client name *</label><input type="text" name="name" class="form-control" required maxlength="150"></div>
            <div class="col-md-6"><label class="form-label">Company name</label><input type="text" name="company" class="form-control" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="190"></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" maxlength="30"></div>
            <div class="col-md-3"><label class="form-label">WhatsApp</label><input type="text" name="whatsapp" class="form-control" maxlength="30"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Status</label>
              <select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></div>
            <div class="col-md-4"><label class="form-label">Assigned staff</label>
              <?= remote_select('assigned_user_id', 'users', null, 'Unassigned – type a name…') ?></div>
            <div class="col-md-4 d-flex flex-column justify-content-end">
              <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="monitoring_enabled" id="c_mon" checked><label class="form-check-label small" for="c_mon">Monitoring enabled</label></div>
              <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_client" id="c_notify" checked><label class="form-check-label small" for="c_notify">Email client on alerts (down, form failed, expiry)</label></div>
            </div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Client</button>
        </div>
      </form>
    </div>
  </div>
</div>
