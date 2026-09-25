<?php
/** Team: members of the workspace, roles, invitations and the plan's seat limit. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('team.view');
$tid = Tenant::id();
$canManage = Auth::can('team');
$members = DB::fetchAll("SELECT id, name, email, role, status, phone, job_title, email_verified_at, last_login_at, created_at FROM users WHERE tenant_id = ? ORDER BY FIELD(role,'owner','admin','manager','viewer','notify'), name", [$tid]);
$invites = DB::fetchAll("SELECT i.*, u.name AS inviter FROM team_invitations i LEFT JOIN users u ON u.id = i.invited_by WHERE i.tenant_id = ? AND i.accepted_at IS NULL AND i.expires_at > NOW() ORDER BY i.id DESC", [$tid]);
$seat = Tenant::canAdd('users');
$roleDesc = Registration::roleDescriptions();

$pageTitle = 'Team';
$breadcrumbs = [['label' => 'Team']];
$pageSubtitle = 'Dashboard members: ' . usage_badge($seat['current'], $seat['limit']) . ' on the ' . e(Tenant::plan()['name']) . ' plan · notify-only members do not use a seat';
$pageActions = $canManage ? '<button class="btn btn-dark btn-sm" data-open-modal="#inviteModal"' . ($seat['ok'] ? '' : ' title="Seat limit reached – you can still add notify-only members"') . '><i class="bi bi-person-plus me-1"></i>Invite member</button>' : '';
include ROOT_PATH . '/includes/layout/header.php';
?>
<?php if (!$seat['ok']): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e($seat['message']) ?> <a href="<?= url('billing/index.php') ?>" class="alert-link">Upgrade</a> · Notify-only members (alerts by email, no login) can still be added.</div><?php endif; ?>
<div class="card dt-card mb-3">
  <table class="table table-hover datatable w-100 table-compact" data-page-length="50" data-order='[]'>
    <thead><tr><th>Member</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($members as $u): $isMe = $u['id'] == Auth::id(); ?>
      <tr data-row-id="<?= $u['id'] ?>">
        <td><span class="fw-500"><?= e($u['name']) ?></span><?= $isMe ? ' <span class="badge bg-brand">you</span>' : '' ?><?= $u['job_title'] ? '<div class="small-xs text-muted">' . e($u['job_title']) . '</div>' : '' ?></td>
        <td class="small"><?= e($u['email']) ?><?= $u['email_verified_at'] ? '' : ' <span class="badge bg-warning-subtle text-warning" title="Email not verified yet">unverified</span>' ?></td>
        <td><?= status_pill(['owner' => 'brand', 'admin' => 'dark', 'manager' => 'info', 'viewer' => 'secondary', 'staff' => 'secondary', 'notify' => 'warning'][$u['role']] ?? 'secondary', Registration::roleLabel($u['role']), false) ?><div class="small-xs text-muted"><?= e($roleDesc[$u['role']] ?? ($u['role'] === 'owner' ? 'Full account access incl. billing' : '')) ?></div></td>
        <td><?= status_pill($u['status'] === 'active' ? 'success' : 'secondary', ucfirst($u['status']), false) ?></td>
        <td class="small text-muted"><?= $u['role'] === 'notify' ? 'no login' : e(time_ago($u['last_login_at'])) ?></td>
        <td class="row-actions text-end text-nowrap"><?php if ($canManage): ?>
          <button class="btn btn-light btn-sm btn-edit-user" data-id="<?= $u['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
          <?php if ($u['role'] !== 'notify'): ?> <button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params='{"action":"reset_link","id":<?= $u['id'] ?>}' title="Send password reset link"><i class="bi bi-key"></i></button><?php endif; ?>
          <?php if (!$isMe && $u['role'] !== 'owner'): ?> <button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params='{"action":"status","id":<?= $u['id'] ?>}' data-reload="1" title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi <?= $u['status'] === 'active' ? 'bi-pause-circle text-warning' : 'bi-play-circle text-success' ?>"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/users.php" data-params='{"action":"delete","id":<?= $u['id'] ?>}' data-confirm="Remove <?= e($u['name']) ?> from the workspace?" data-confirm-btn="Remove" data-reload="1" title="Remove"><i class="bi bi-trash"></i></button><?php endif; ?>
          <?php if (Auth::isOwner() && !$isMe && $u['role'] !== 'notify' && $u['status'] === 'active'): ?> <button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params='{"action":"transfer_owner","id":<?= $u['id'] ?>}' data-confirm="Transfer workspace ownership to <?= e($u['name']) ?>? You will become an admin." data-icon="question" data-confirm-btn="Transfer" data-reload="1" title="Make owner"><i class="bi bi-award"></i></button><?php endif; ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
</div>

<div class="card">
  <div class="card-header"><span><i class="bi bi-envelope-paper me-1"></i>Pending invitations</span><span class="small fw-normal text-muted"><?= count($invites) ?></span></div>
  <div class="card-body p-0">
    <?php if (!$invites): ?><div class="empty-state py-3"><i class="bi bi-envelope-open"></i>No pending invitations</div>
    <?php else: ?><div class="table-responsive"><table class="table table-compact mb-0 small">
      <thead><tr><th>Email</th><th>Role</th><th>Invited by</th><th>Sent</th><th>Expires</th><th class="text-end"></th></tr></thead>
      <tbody><?php foreach ($invites as $i): ?><tr><td><?= e($i['email']) ?><?= $i['name'] ? ' <span class="text-muted">(' . e($i['name']) . ')</span>' : '' ?></td><td><?= e(Registration::roleLabel($i['role'])) ?></td><td><?= e($i['inviter'] ?? '—') ?></td><td><?= format_datetime($i['created_at']) ?></td><td><?= format_datetime($i['expires_at']) ?></td>
        <td class="row-actions text-end"><?php if ($canManage): ?><button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params='{"action":"resend_invite","id":<?= $i['id'] ?>}' title="Resend"><i class="bi bi-send"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/users.php" data-params='{"action":"cancel_invite","id":<?= $i['id'] ?>}' data-reload="1" title="Cancel"><i class="bi bi-x-lg"></i></button><?php endif; ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="inviteModal" tabindex="-1" data-auto-open><div class="modal-dialog"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/users.php') ?>" data-reload="1" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="invite">
    <div class="modal-header"><h5 class="modal-title">Invite a team member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-7"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required placeholder="colleague@company.com"></div>
      <div class="col-md-5"><label class="form-label">Name <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="name" class="form-control"></div>
      <div class="col-12"><label class="form-label">Role</label>
        <?php foreach (['admin', 'manager', 'viewer', 'notify'] as $r): ?><div class="form-check"><input class="form-check-input" type="radio" name="role" id="role_<?= $r ?>" value="<?= $r ?>" <?= $r === 'viewer' ? 'checked' : '' ?>><label class="form-check-label" for="role_<?= $r ?>"><strong><?= Registration::roleLabel($r) ?></strong> <span class="text-muted small">– <?= e($roleDesc[$r]) ?></span></label></div><?php endforeach; ?>
        <div class="form-text">Seats: <?= usage_badge($seat['current'], $seat['limit']) ?> · notify-only members never use a seat.</div></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-send me-1"></i>Send invitation</button></div>
  </form>
</div></div></div>
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/users.php') ?>" data-reload="1" novalidate autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="modal-header"><h5 class="modal-title" data-add="Edit member">Edit member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label">Job title</label><input type="text" name="job_title" class="form-control"></div>
      <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
      <div class="col-md-6"><label class="form-label">Role</label><select name="role" class="form-select"><?php foreach (['admin', 'manager', 'viewer', 'notify'] as $r): ?><option value="<?= $r ?>"><?= Registration::roleLabel($r) ?></option><?php endforeach; ?><option value="owner">Owner</option></select></div>
      <div class="col-md-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save</button></div>
  </form>
</div></div></div>
<?php endif; ?>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-edit-user', function () { CRM.post('api/users.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) CRM.openEdit('#userModal', res.user, 'Edit member'); }); });
$(document).ajaxSuccess(function (e, xhr, opts) {
  if (opts.url.indexOf('api/users') > -1 && xhr.responseJSON && xhr.responseJSON.link && !xhr.responseJSON.emailed) {
    Swal.fire({ icon: 'info', title: 'Reset link', html: '<p class="small">' + xhr.responseJSON.message + '</p><input class="form-control form-control-sm" readonly value="' + xhr.responseJSON.link + '" onclick="this.select()">' });
  }
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
