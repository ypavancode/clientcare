<?php
require_once __DIR__ . '/../includes/init.php';
if (Auth::check() && Auth::user()) redirect('dashboard/index.php');

$token = get('token', '');
$user = Auth::findByResetToken($token);
$error = '';
$done = false;

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password_confirm'] ?? '');
        if ($p = Registration::passwordProblem($p1)) {
            $error = $p;
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            DB::update('users', ['password' => password_hash($p1, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null], 'id = ?', [$user['id']]);
            DB::delete('remember_tokens', 'user_id = ?', [$user['id']]);
            ActivityLog::add('password_reset', 'Password reset completed', ['user_id' => (int) $user['id']]);
            $done = true;
        }
    }
}

ob_start();
?>
<?php if ($done): ?>
  <h1>Password updated</h1>
  <div class="alert alert-success py-2">Your password has been changed. You can now sign in.</div>
  <a href="<?= url('auth/login.php') ?>" class="btn btn-dark w-100">Go to login</a>
<?php elseif (!$user): ?>
  <h1>Invalid link</h1>
  <div class="alert alert-danger py-2">This password reset link is invalid or has expired.</div>
  <a href="<?= url('auth/forgot-password.php') ?>" class="btn btn-dark w-100">Request a new link</a>
<?php else: ?>
  <h1>Set a new password</h1>
  <p class="lead-text">Choose a strong password of at least 8 characters with letters and a number.</p>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="password">New password</label>
      <div class="input-group">
        <input type="password" class="form-control" id="password" name="password" minlength="8" required autofocus>
        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="password_confirm">Confirm password</label>
      <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-dark w-100 py-2">Update password</button>
  </form>
<?php endif; ?>
<?php
$authContent = ob_get_clean();
$authTitle = 'Reset password';
include __DIR__ . '/layout.php';
