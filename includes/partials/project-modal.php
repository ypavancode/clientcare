<?php /** Website project add/edit modal. Variables: $projectDefaults (array) */
$projectDefaults = $projectDefaults ?? [];
$deptOptions = departments_options();
$typeOptions = website_types_options();
$users = all_users_options();
?>
<div class="modal fade" id="projectModal" tabindex="-1" data-auto-open>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form class="ajax-form" action="<?= url('api/projects.php') ?>" data-reload="1" novalidate autocomplete="off" id="projectForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <?php if (!empty($projectModalStay)): ?><input type="hidden" name="stay" value="1"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title" data-add="Add Website Project">Add Website Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="section-title mt-0">1. Client</div>
          <div class="btn-group btn-group-sm mb-3" role="group">
            <input type="radio" class="btn-check" name="client_mode" id="cm_existing" value="existing" checked><label class="btn btn-outline-primary" for="cm_existing"><i class="bi bi-person-check me-1"></i>Existing Client</label>
            <input type="radio" class="btn-check" name="client_mode" id="cm_new" value="new"><label class="btn btn-outline-primary" for="cm_new"><i class="bi bi-person-plus me-1"></i>Add New Client</label>
          </div>
          <div id="clientExisting" class="row g-3 mb-3">
            <div class="col-md-8"><label class="form-label">Select client *</label><?= remote_select('client_id', 'clients', $projectDefaults['client_id'] ?? null, 'Type the client name…', !empty($projectDefaults['client_id']) ? ['keep' => 1] : []) ?></div>
          </div>
          <div id="clientNew" class="row g-3 mb-3 d-none">
            <div class="col-md-6"><label class="form-label">Client name *</label><input type="text" name="new_client_name" class="form-control" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label">Company name</label><input type="text" name="new_client_company" class="form-control" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="new_client_email" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="new_client_phone" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">WhatsApp</label><input type="text" name="new_client_whatsapp" class="form-control"></div>
            <div class="col-12 form-text">The client is created in the CRM and linked to this project. If a client with the same email or name already exists it is reused – no duplicates.</div>
          </div>

          <div class="section-title">2. Project</div>
          <div class="btn-group btn-group-sm mb-3" role="group">
            <input type="radio" class="btn-check" name="project_kind" id="pk_new" value="new" checked><label class="btn btn-outline-primary" for="pk_new"><i class="bi bi-stars me-1"></i>New Website</label>
            <input type="radio" class="btn-check" name="project_kind" id="pk_existing" value="existing"><label class="btn btn-outline-primary" for="pk_existing"><i class="bi bi-globe2 me-1"></i>Existing Website</label>
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Project name *</label><input type="text" name="project_name" class="form-control" required maxlength="150" placeholder="ABC School Website"></div>
            <div class="col-md-6"><label class="form-label">Website name</label><input type="text" name="website_name" class="form-control" maxlength="150" placeholder="ABC School"></div>
            <div class="col-md-6"><label class="form-label">Website URL <span id="urlReq" class="text-muted fw-normal">(optional until the domain is known)</span></label><input type="url" name="website_url" class="form-control" placeholder="https://abcschool.com"></div>
            <div class="col-md-6"><label class="form-label">Status</label>
              <select name="status" class="form-select"><?php foreach (project_statuses() as $k => [$lbl, $cls]): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
              <div class="form-text">New projects start at <strong>Design</strong>; existing websites default to <strong>Live</strong>.</div></div>
            <div class="col-md-4"><label class="form-label">Department / industry</label>
              <select name="department_id" class="form-select" id="p_department"><option value="">— Select —</option><?php foreach ($deptOptions as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Website type</label>
              <select name="website_type_id" class="form-select" id="p_type"><option value="">— Select —</option><?php foreach ($typeOptions as $t): ?><option value="<?= $t['id'] ?>" data-department="<?= (int) $t['department_id'] ?>"><?= e($t['name']) ?><?= $t['department_name'] ? ' (' . e($t['department_name']) . ')' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Technology</label>
              <select name="technology" class="form-select"><option value="">— Select —</option><?php foreach (technologies() as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Start date</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label">Expected launch date</label><input type="date" name="expected_launch_date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Actual launch date</label><input type="date" name="actual_launch_date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Assigned person</label>
              <select name="assigned_user_id" class="form-select"><option value="">— Unassigned —</option><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $u['id'] === Auth::id() ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 d-flex align-items-end" id="monitoringOpt">
              <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enable_monitoring" id="p_monitor" value="1"><label class="form-check-label small" for="p_monitor">Enable website monitoring now <span class="text-muted">(uptime, pages, SSL, forms)</span></label></div></div>
            <div class="col-12"><label class="form-label">Project notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Scope, requirements, credentials reference, decisions…"></textarea></div>
            <div class="col-12 d-none" id="statusNoteWrap"><label class="form-label">Status change note <span class="text-muted fw-normal">(optional, saved in the status history)</span></label><input type="text" name="status_note" class="form-control" maxlength="500"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Project</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  const $f = $('#projectForm');
  let originalStatus = null;
  function syncClientMode() { const nw = $f.find('[name=client_mode]:checked').val() === 'new'; $('#clientNew').toggleClass('d-none', !nw); $('#clientExisting').toggleClass('d-none', nw); $f.find('[name=new_client_name]').prop('required', nw); }
  function syncKind(fromUser) {
    const ex = $f.find('[name=project_kind]:checked').val() === 'existing';
    $('#urlReq').text(ex ? '*' : '(optional until the domain is known)');
    $f.find('[name=website_url]').prop('required', ex);
    if (fromUser && !$f.find('[name=id]').val()) { $f.find('[name=status]').val(ex ? 'live' : 'design'); $('#p_monitor').prop('checked', ex); }
  }
  function syncTypes() {
    const dept = $('#p_department').val();
    $('#p_type option').each(function () { const d = String($(this).data('department') || ''); const show = !$(this).val() || !dept || d === '' || d === '0' || d === String(dept); $(this).toggle(show).prop('disabled', !show); });
    const cur = $('#p_type option:selected'); if (cur.length && cur.prop('disabled')) $('#p_type').val('');
  }
  $f.on('change', '[name=client_mode]', syncClientMode);
  $f.on('change', '[name=project_kind]', () => syncKind(true));
  $f.on('change', '#p_department', syncTypes);
  $f.on('change', '[name=status]', function () { $('#statusNoteWrap').toggleClass('d-none', !originalStatus || $(this).val() === originalStatus); });
  $f.on('crm:filled', function (e, data) {
    originalStatus = data.status || null;
    $f.find('[name=client_mode][value=existing]').prop('checked', true);
    $f.find('[name=project_kind][value=' + (data.project_kind || 'new') + ']').prop('checked', true);
    syncClientMode(); syncKind(false); syncTypes(); $('#statusNoteWrap').addClass('d-none');
    $('#monitoringOpt').addClass('d-none'); // monitoring is toggled from the row / detail page when editing
  });
  $('#projectModal').on('hidden.bs.modal', function () { originalStatus = null; $f.find('[name=client_mode][value=existing]').prop('checked', true); $f.find('[name=project_kind][value=new]').prop('checked', true); syncClientMode(); syncKind(false); syncTypes(); $('#monitoringOpt').removeClass('d-none'); $('#statusNoteWrap').addClass('d-none'); $f.find('[name=start_date]').val(new Date().toISOString().slice(0, 10)); });
  syncClientMode(); syncKind(false); syncTypes();
  window.openProjectEdit = function (id) {
    CRM.post('api/projects.php', { action: 'get', id: id }).done(res => { if (res.success) CRM.openEdit('#projectModal', res.project, 'Edit Website Project'); });
  };
})();
</script>
