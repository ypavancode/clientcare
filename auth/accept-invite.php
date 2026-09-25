<?php
require_once __DIR__ . '/../includes/init.php';
$token = (string) (get('token', '') ?: post('token', ''));
$inv = preg_match('~^[a-f0-9]{64}$~', $token) ? DB::fetch("SELECT i.*, t.name AS tenant_name FROM team_invitations i JOIN tenants t ON t.id = i.tenant_id WHERE i.token_hash = ? LIMIT 1", [hash('sha256', $token)]) : null;
$error = '';
if (!$inv) $error = 'This invitation link is not valid.';
elseif ($inv['accepted_at']) $error = 'This invitation has already been accepted. Please sign in.';
elseif (strtotime($inv['expires_at']) < time()) $error = 'This invitation has expired. Ask your workspace admin to invite you again.';
$errors = [];
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) post('name', '')); $password = (string) ($_POST['password'] ?? ''); $confirm = (string) ($_POST['password_confirm'] ?? '');
    if (!verify_csrf()) $errors['form'] = 'Your session expired. Please try again.';
    if ($name === '') $errors['name'] = 'Enter your name.';
    if ($p = Registration::passwordProblem($password)) $errors['password'] = $p; elseif ($password !== $confirm) $errors['password_confirm'] = 'The passwords do not match.';
    if (!post('terms')) $errors['terms'] = 'Please accept the terms.';
    if (DB::value("SELECT id FROM users WHERE email = ?", [$inv['email']])) $errors['form'] = 'A user with this email already exists. Please sign in.';
    if (!$errors) {
        if ($inv['role'] !== 'notify') { $can = Tenant::canAdd('users', 1, (int) $inv['tenant_id']); if (!$can['ok']) $errors['form'] = 'The workspace has reached its team member limit. Ask the owner to upgrade the plan.'; }
    }
    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $uid = DB::insert('users', ['tenant_id' => $inv['tenant_id'], 'name' => $name, 'email' => $inv['email'], 'username' => Tenant::usernameFor($inv['email']), 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $inv['role'], 'status' => 'active', 'email_verified_at' => $now, 'terms_accepted_at' => $now, 'invited_by' => $inv['invited_by'], 'created_at' => $now]);
        DB::update('team_invitations', ['accepted_at' => $now], 'id = ?', [$inv['id']]);
        ActivityLog::add('team_joined', $name . ' joined the workspace as ' . Registration::roleLabel($inv['role']), ['user_id' => $uid]);
        if ($inv['role'] === 'notify') { flash('success', 'Your notify-only account is set up. You will receive alert emails; dashboard sign-in is not available for this role.'); redirect('auth/login.php'); }
        Auth::loginAs($uid, 'invitation');
        redirect('dashboard/index.php');
    }
}
ob_start();
if ($error): ?>
<h1>Invitation</h1><p class="lead-text"><?= e($error) ?></p><a href="<?= url('auth/login.php') ?>" class="btn btn-dark w-100">Sign in</a>
<?php else: ?>
<h1>Join <?= e($inv['tenant_name']) ?></h1>
<p class="lead-text">You were invited as <strong><?= e(Registration::roleLabel($inv['role'])) ?></strong>. Set your password to finish.</p>
<?php if (!empty($errors['form'])): ?><div class="alert alert-danger py-2"><?= e($errors['form']) ?></div><?php endif; ?>
<form method="post" novalidate>
  <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= e($inv['email']) ?>" disabled></div>
  <div class="mb-2"><label class="form-label" for="name">Your name</label><input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" value="<?= e(post('name', $inv['name'] ?? '')) ?>" required><div class="invalid-feedback"><?= $errors['name'] ?? '' ?></div></div>
  <div class="row g-2"><div class="col-6 mb-2"><label class="form-label" for="password">Password</label><input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="password" name="password" required><div class="invalid-feedback"><?= $errors['password'] ?? '' ?></div></div>
    <div class="col-6 mb-2"><label class="form-label" for="password_confirm">Confirm</label><input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" id="password_confirm" name="password_confirm" required><div class="invalid-feedback"><?= $errors['password_confirm'] ?? '' ?></div></div></div>
  <div class="form-check mb-3"><input class="form-check-input <?= isset($errors['terms']) ? 'is-invalid' : '' ?>" type="checkbox" name="terms" value="1" id="terms"><label class="form-check-label small" for="terms">I accept the <a href="<?= url('public/terms.php') ?>" target="_blank">terms</a> and <a href="<?= url('public/privacy.php') ?>" target="_blank">privacy policy</a></label></div>
  <button class="btn btn-dark w-100 py-2">Join workspace</button>
</form>
<?php endif;
$authContent = ob_get_clean();
$authTitle = 'Accept invitation';
include __DIR__ . '/layout.php';
