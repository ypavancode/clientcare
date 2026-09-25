<?php
require_once __DIR__ . '/../includes/init.php';
$r = Registration::verify((string) get('token', ''));
if ($r['ok'] && $r['user']) {
    $uid = (int) $r['user']['id'];
    if (!empty($r['already'])) {
        // Link was already used: never sign anyone in from it (a forwarded / leaked email must not become a permanent login link).
        if (Auth::check() && Auth::id() === $uid) redirect('dashboard/index.php');
        flash('info', 'Your email address is already verified. Please sign in.');
        redirect('auth/login.php');
    }
    if (!Auth::check() || Auth::id() !== $uid) Auth::loginAs($uid, 'email verification');
    Tenant::forget();
    $days = Tenant::trialDaysLeft((int) $r['user']['tenant_id']);
    flash('success', 'Email verified – welcome to ' . setting('platform_name', 'Outline Monitor') . '!' . ($days ? ' Your ' . $days . '-day free trial has started.' : '') . ' Add your first website to begin monitoring.');
    redirect('dashboard/index.php?welcome=1');
}
ob_start();
?>
<h1>Verification failed</h1>
<p class="lead-text"><?= e($r['message']) ?></p>
<div class="d-grid gap-2">
  <?php if (Auth::check() && Auth::user() && !Auth::isVerified()): ?><a href="<?= url('auth/verify-pending.php') ?>" class="btn btn-dark">Request a new verification email</a><?php else: ?><a href="<?= url('auth/login.php') ?>" class="btn btn-dark">Sign in</a><?php endif; ?>
</div>
<div class="auth-footer">&copy; <?= date('Y') ?> <?= e(setting('platform_name', 'Outline Monitor')) ?></div>
<?php
$authContent = ob_get_clean();
$authTitle = 'Email verification';
include __DIR__ . '/layout.php';
