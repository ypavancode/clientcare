<?php
require_once __DIR__ . '/../includes/init.php';
if (!Auth::check() || !Auth::user()) redirect('auth/login.php');
if (Auth::isVerified()) redirect('dashboard/index.php');
$u = Auth::user();
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend') {
    if (!verify_csrf()) $err = 'Your session expired. Please try again.';
    elseif (Registration::throttled('resend', 3, 15)) $err = 'Please wait a few minutes before requesting another email.';
    else { Registration::sendVerification((int) $u['id']); $msg = 'A new verification email has been sent to ' . $u['email'] . '.'; }
}
ob_start();
?>
<h1>Verify your email address</h1>
<p class="lead-text">We sent a verification link to <strong><?= e($u['email']) ?></strong>. Click it to activate your workspace<?= Tenant::trialDaysLeft() ? ' and start your ' . Tenant::trialDaysLeft() . '-day free trial' : '' ?>.</p>
<?php if ($msg): ?><div class="alert alert-success py-2"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endif; ?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>The link is valid for <?= (int) setting('verification_token_hours', 48) ?> hours. Check your spam folder if it does not arrive within a few minutes.</div>
<form method="post" class="d-grid gap-2">
  <?= csrf_field() ?><input type="hidden" name="action" value="resend">
  <button class="btn btn-dark"><i class="bi bi-envelope me-1"></i>Resend verification email</button>
  <a href="<?= url('auth/logout.php') ?>" class="btn btn-light">Sign out</a>
</form>
<div class="auth-footer">&copy; <?= date('Y') ?> <?= e(setting('platform_name', 'Outline Monitor')) ?></div>
<?php
$authContent = ob_get_clean();
$authTitle = 'Verify your email';
include __DIR__ . '/layout.php';
