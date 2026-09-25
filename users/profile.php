<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$me = DB::fetch("SELECT id, name, email, username, role, phone, last_login_at, last_login_ip, created_at FROM users WHERE id = ?", [Auth::id()]);
$recent = DB::fetchAll("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 20", [Auth::id()]);

$pageTitle = 'My Profile';
$breadcrumbs = [['label' => 'My Profile']];
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Profile</div>
      <div class="card-body">
        <form class="ajax-form" action="<?= url('api/users.php') ?>" data-reload="1" novalidate>
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="mb-3"><label class="form-label">Full name</label><input type="text" name="name" class="form-control" value="<?= e($me['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($me['email']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($me['phone']) ?>"></div>
          <div class="mb-3"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= e($me['username']) ?>" disabled><div class="form-text">Role: <?= e(ucfirst($me['role'])) ?> · Member since <?= format_date($me['created_at']) ?></div></div>
          <button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Profile</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Change Password</div>
      <div class="card-body">
        <form class="ajax-form" action="<?= url('api/users.php') ?>" novalidate autocomplete="off" data-callback="pwChanged">
          <?= csrf_field() ?><input type="hidden" name="action" value="my_password">
          <div class="mb-3"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label">New password</label><div class="input-group"><input type="password" name="password" class="form-control" id="np" minlength="8" required autocomplete="new-password"><button class="btn btn-outline-secondary toggle-password" type="button" data-target="#np"><i class="bi bi-eye"></i></button></div></div>
          <div class="mb-3"><label class="form-label">Confirm new password</label><input type="password" name="password_confirm" class="form-control" minlength="8" required autocomplete="new-password"></div>
          <button class="btn btn-dark"><i class="bi bi-key me-1"></i>Update Password</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><span>My Recent Activity</span><span class="small fw-normal text-muted">Last login: <?= format_datetime($me['last_login_at']) ?> from <?= e($me['last_login_ip'] ?: '—') ?></span></div>
      <div class="card-body">
        <?php if (!$recent): ?><div class="empty-state"><i class="bi bi-activity"></i>No activity yet</div>
        <?php else: ?><ul class="timeline"><?php foreach ($recent as $a): ?>
          <li><span class="tl-icon"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><div class="tl-title"><?= e($a['description']) ?></div><div class="tl-meta"><?= format_datetime($a['created_at']) ?> · <?= e($a['ip_address']) ?></div></li>
        <?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$pageScripts = <<<'JS'
<script>window.pwChanged = function (res, $f) { $f[0].reset(); };</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
