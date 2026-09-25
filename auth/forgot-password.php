<?php
require_once __DIR__ . '/../includes/init.php';
if (Auth::check() && Auth::user()) redirect('dashboard/index.php');

$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = post('email', '');
        // Rate limit: max 3 requests per IP per 15 minutes
        $recent = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE login = ? AND ip_address = ? AND attempted_at > ?", ['password-reset', client_ip(), date('Y-m-d H:i:s', time() - 900)]);
        if ($recent >= 3) {
            $error = 'Too many reset requests. Please try again later.';
        } elseif (!valid_email($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            Auth::recordAttempt('password-reset', true);
            $t0 = microtime(true); // constant response time whether or not the address exists (no account enumeration by timing)
            $user = DB::fetch("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$email]);
            if ($user) {
                $token = Auth::createResetToken((int) $user['id']);
                $link = url('auth/reset-password.php?token=' . $token);
                $html = Mailer::template('Reset your password', 'We received a request to reset the password for your CRM account. The link below is valid for 1 hour.', [
                    'Account' => $user['email'],
                    'Requested from IP' => client_ip(),
                ], 'If you did not request this, you can ignore this email.', $link, '#FCAF17');
                $r = Mailer::send($user['email'], 'Password reset – ' . company_name() . ' CRM', $html, $user['name'], 'password_reset');
                if (!$r['ok']) {
                    app_log('warning', 'Password reset email failed: ' . $r['error']);
                }
                ActivityLog::add('password_reset_requested', 'Password reset requested for ' . $user['email'], ['user_id' => (int) $user['id']]);
            }
            $sent = true; // always show the same message to avoid account enumeration
            $pad = 1500000 - (int) ((microtime(true) - $t0) * 1000000); if ($pad > 0) usleep($pad); // fixed 1.5 s floor covers the inline SMTP send
        }
    }
}

ob_start();
?>
<h1>Forgot password</h1>
<p class="lead-text">Enter your account email and we will send you a reset link.</p>
<?php if ($sent): ?>
  <div class="alert alert-success py-2"><i class="bi bi-envelope-check me-1"></i>If an account exists for that email, a reset link has been sent. Please check your inbox.</div>
  <a href="<?= url('auth/login.php') ?>" class="btn btn-dark w-100">Back to login</a>
<?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="email">Email address</label>
      <input type="email" class="form-control" id="email" name="email" required autofocus placeholder="you@company.com">
      <div class="invalid-feedback">Please enter a valid email.</div>
    </div>
    <button type="submit" class="btn btn-dark w-100 py-2">Send reset link</button>
  </form>
  <div class="auth-footer"><a href="<?= url('auth/login.php') ?>"><i class="bi bi-arrow-left me-1"></i>Back to login</a></div>
<?php endif; ?>
<?php
$authContent = ob_get_clean();
$authTitle = 'Forgot password';
include __DIR__ . '/layout.php';
