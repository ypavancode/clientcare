<?php
require_once __DIR__ . '/../includes/init.php';

if (Auth::check() && Auth::user()) {
    redirect('dashboard/index.php');
}

$error = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $login = post('login', '');
        $result = Auth::attempt($login, (string) ($_POST['password'] ?? ''), post('remember') === '1');
        if ($result['ok']) {
            $intended = $_SESSION['intended'] ?? '';
            unset($_SESSION['intended']);
            if ($intended && str_starts_with($intended, '/') && !str_starts_with($intended, '//') && !str_contains($intended, chr(92)) && !preg_match('~/(login|logout|register|verify|verify-pending|forgot-password|reset-password|accept-invite)(\?|$)|auth/~', $intended)) {
                header('Location: ' . $intended);
                exit;
            }
            redirect('dashboard/index.php');
        }
        $error = $result['message'];
    }
}
$flashes = get_flashes();
$registrationOpen = (bool) setting('registration_enabled', 1);

ob_start();
?>
<div class="mb-2 plan-pill-row"><span class="plan-pill"><i class="bi bi-shield-lock-fill"></i>Secure sign in</span></div>
<h1>Welcome back</h1>
<p class="lead-text">Sign in to your monitoring workspace to see what changed while you were away.</p>
<?php foreach ($flashes as $f): ?>
  <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?> py-2"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php if ($error): ?>
  <div class="alert alert-danger py-2 d-flex align-items-center gap-2" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($error) ?></span></div>
<?php endif; ?>
<form method="post" class="needs-validation" novalidate autocomplete="on">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="login">Email or username</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-envelope"></i></span>
      <input type="text" class="form-control" id="login" name="login" value="<?= e($login) ?>" required autofocus placeholder="you@company.com" autocomplete="username" inputmode="email">
      <div class="invalid-feedback">Please enter your email or username.</div>
    </div>
  </div>
  <div class="mb-2">
    <div class="d-flex justify-content-between align-items-center mb-1"><label class="form-label mb-0" for="password">Password</label><a href="<?= url('auth/forgot-password.php') ?>" class="small">Forgot password?</a></div>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-lock"></i></span>
      <input type="password" class="form-control" id="password" name="password" required placeholder="••••••••••" autocomplete="current-password">
      <button class="btn toggle-password" type="button" data-target="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
      <div class="invalid-feedback">Please enter your password.</div>
    </div>
  </div>
  <div class="form-check my-3">
    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
    <label class="form-check-label" for="remember">Keep me signed in on this device</label>
  </div>
  <button type="submit" class="btn btn-dark btn-lg w-100" data-loading="Signing in…"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
</form>
<?php if ($registrationOpen): ?>
<div class="auth-divider">new to <?= e(setting('platform_name', 'Outline Monitor')) ?>?</div>
<a href="<?= url('auth/register.php') ?>" class="btn btn-light w-100"><i class="bi bi-rocket-takeoff me-1"></i>Start your free trial</a>
<?php endif; ?>
<div class="auth-footer">&copy; <?= date('Y') ?> <?= e(setting('platform_name', 'Outline Monitor')) ?> · <a href="<?= url('public/pricing.php') ?>">Pricing</a> · <a href="<?= url('public/privacy.php') ?>">Privacy</a></div>
<?php
$authContent = ob_get_clean();
$authTitle = 'Sign in';
include __DIR__ . '/layout.php';
