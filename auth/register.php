<?php
require_once __DIR__ . '/../includes/init.php';
if (Auth::check() && Auth::user()) redirect('dashboard/index.php');
if (!setting('registration_enabled', 1)) http_error(403, 'Registration is currently closed.');
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) (setting('maintenance_message', '') ?: 'We are performing maintenance. Registration will reopen shortly.'));

$plans = Tenant::allPlans(true);
$planCode = get('plan', '') ?: post('plan', '') ?: setting('trial_plan', 'professional');
$plan = Tenant::planByCode($planCode);
if (!$plan || $plan['price_monthly'] === null) { $plan = Tenant::planByCode(setting('trial_plan', 'professional')) ?: Tenant::planByCode('free'); }
$errors = [];
$in = ['name' => '', 'company' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $k => $v) $in[$k] = trim((string) post($k, ''));
    $in['email'] = strtolower($in['email']);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if (!verify_csrf()) $errors['form'] = 'Your session expired. Please try again.';
    elseif (Registration::throttled('register', 5, 60)) $errors['form'] = 'Too many registrations from this connection. Please try again later.';
    if ($in['name'] === '' || mb_strlen($in['name']) > 120) $errors['name'] = 'Enter your full name.';
    if ($in['company'] === '' || mb_strlen($in['company']) > 150) $errors['company'] = 'Enter your company or workspace name.';
    if ($p = Registration::emailProblem($in['email'])) $errors['email'] = $p;
    elseif (DB::value("SELECT id FROM users WHERE email = ?", [$in['email']])) $errors['email'] = 'An account with this email already exists. <a href="' . url('auth/login.php') . '">Sign in</a> or <a href="' . url('auth/forgot-password.php') . '">reset your password</a>.';
    if ($p = Registration::passwordProblem($password)) $errors['password'] = $p;
    elseif ($password !== $confirm) $errors['password_confirm'] = 'The passwords do not match.';
    if (!post('terms')) $errors['terms'] = 'Please accept the terms of service and privacy policy.';
    if (!$errors) {
        [$tenantId, $userId] = Tenant::register($in + ['password' => $password, 'plan' => $plan['code']]);
        ActivityLog::add('tenant_registered', 'Workspace registered: ' . $in['company'] . ' (' . $plan['name'] . ($plan['code'] === 'free' ? '' : ' trial') . ')', ['user_id' => $userId]);
        Registration::sendVerification($userId);
        Auth::loginAs($userId, 'registration');
        redirect('auth/verify-pending.php');
    }
}
ob_start();
$isFree = $plan['code'] === 'free' || (int) $plan['price_monthly'] === 0;
$trialDays = (int) ($plan['trial_days'] ?: setting('trial_days', 14));
?>
<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2 plan-pill-row">
  <span class="plan-pill"><i class="bi bi-stars"></i><?= e($plan['name']) ?> plan · <?= $isFree ? 'free forever' : $trialDays . '-day free trial' ?></span>
  <a href="<?= url('public/pricing.php') ?>" class="small fw-600">Change plan</a>
</div>
<h1>Create your workspace</h1>
<p class="lead-text"><?= $isFree ? 'No card, no time limit – upgrade whenever you need more.' : 'No credit card needed. Monitoring starts the moment your email is verified.' ?></p>
<?php if (!empty($errors['form'])): ?><div class="alert alert-danger py-2 d-flex align-items-center gap-2"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($errors['form']) ?></span></div><?php endif; ?>
<form method="post" class="needs-validation" novalidate autocomplete="on">
  <?= csrf_field() ?>
  <input type="hidden" name="plan" value="<?= e($plan['code']) ?>">
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="name">Full name</label><input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" value="<?= e($in['name']) ?>" required autofocus autocomplete="name" placeholder="Jane Doe"><div class="invalid-feedback"><?= $errors['name'] ?? 'Enter your full name.' ?></div></div>
    <div class="col-md-6"><label class="form-label" for="company">Company / workspace</label><input type="text" class="form-control <?= isset($errors['company']) ? 'is-invalid' : '' ?>" id="company" name="company" value="<?= e($in['company']) ?>" required autocomplete="organization" placeholder="Acme Studio"><div class="invalid-feedback"><?= $errors['company'] ?? 'Enter your company name.' ?></div></div>
    <div class="col-12"><label class="form-label" for="email">Work email</label><div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span><input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" value="<?= e($in['email']) ?>" required placeholder="you@company.com" autocomplete="email" inputmode="email"><div class="invalid-feedback"><?= $errors['email'] ?? 'Enter a valid work email.' ?></div></div><div class="form-text">We send a one-time verification link to this address.</div></div>
    <div class="col-md-6"><label class="form-label" for="password">Password</label><div class="input-group"><input type="password" class="form-control pw-strength <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="password" name="password" required minlength="8" autocomplete="new-password" data-meter="#pwMeter" data-hint="#pwHint" placeholder="8+ characters"><button class="btn toggle-password" type="button" data-target="#password" aria-label="Show password"><i class="bi bi-eye"></i></button><div class="invalid-feedback"><?= $errors['password'] ?? 'Use at least 8 characters with a number.' ?></div></div><div class="pw-meter" id="pwMeter" data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div><div class="pw-hint" id="pwHint" aria-live="polite"></div></div>
    <div class="col-md-6"><label class="form-label" for="password_confirm">Confirm password</label><input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" id="password_confirm" name="password_confirm" required autocomplete="new-password" placeholder="Repeat password"><div class="invalid-feedback"><?= $errors['password_confirm'] ?? 'Please confirm your password.' ?></div></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input <?= isset($errors['terms']) ? 'is-invalid' : '' ?>" type="checkbox" name="terms" value="1" id="terms" required <?= post('terms') ? 'checked' : '' ?>><label class="form-check-label small" for="terms">I accept the <a href="<?= url('public/terms.php') ?>" target="_blank">terms of service</a> and <a href="<?= url('public/privacy.php') ?>" target="_blank">privacy policy</a></label><div class="invalid-feedback"><?= $errors['terms'] ?? 'Please accept the terms to continue.' ?></div></div></div>
    <div class="col-12"><button type="submit" class="btn btn-brand btn-lg w-100" data-loading="Creating your workspace…"><i class="bi bi-rocket-takeoff me-1"></i><?= $isFree ? 'Create free account' : 'Start my free trial' ?></button></div>
  </div>
</form>
<div class="auth-steps"><div class="auth-divider">what happens next</div>
<ul class="list-unstyled small text-muted d-grid gap-1 mb-0">
  <li><i class="bi bi-1-circle me-2 text-brand"></i>Verify your email with the link we send you</li>
  <li><i class="bi bi-2-circle me-2 text-brand"></i>Add your first website – pages &amp; forms are discovered automatically</li>
  <li><i class="bi bi-3-circle me-2 text-brand"></i>Invite your team and choose who receives alerts</li>
</ul></div>
<div class="auth-footer">Already have an account? <a href="<?= url('auth/login.php') ?>" class="fw-600">Sign in</a></div>
<?php
$authContent = ob_get_clean();
$authTitle = 'Create account';
$authWide = true;
include __DIR__ . '/layout.php';
