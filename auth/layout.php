<?php
/**
 * Shared shell for the authentication screens – dark premium split layout.
 * Usage: $authTitle, $authContent (HTML string), optional $authScripts, $authAside (custom aside HTML), $authWide (bool)
 */
$platformName = function_exists('setting') ? (string) setting('platform_name', 'Outline Monitor') : 'Outline Monitor';
$loggedIn = class_exists('Auth') && Auth::check() && Auth::user();
$registrationOpen = (bool) setting('registration_enabled', 1);
$isLoginPage = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), 'auth/login.php');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0b0b0b">
<title><?= e($authTitle ?? 'Login') ?> · <?= e($platformName) ?></title>
<link rel="icon" href="<?= asset('images/icon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= asset('fonts/inter-latin-wght.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= APP_VERSION ?>">
<style>
  body.auth { min-height: 100vh; min-height: 100dvh; background: #0b0b0b; color: #f1f1f1; overflow-x: hidden; --g: min(46vw, 740px, 53vh); }
  .auth-bg { position: fixed; inset: 0; z-index: 0; overflow: hidden; background: radial-gradient(900px 600px at 0% 100%, rgba(252, 175, 23, .22), transparent 60%), radial-gradient(700px 500px at 100% 0%, rgba(252, 175, 23, .10), transparent 60%), #0b0b0b; }
  .auth-bg .grid { position: absolute; inset: 0; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cpath d='M0 40h80M40 0v80' stroke='rgba(255,255,255,.03)'/%3E%3C/svg%3E"); }
  .auth-bg .globe { position: absolute; left: calc(var(--g) * -0.22); bottom: calc(var(--g) * -0.32); width: var(--g); height: var(--g); border-radius: 50%; background: url("<?= asset('images/globe.png') ?>") center / contain no-repeat; filter: drop-shadow(0 0 28px rgba(252, 175, 23, .45)); animation: spinGlobe 180s linear infinite; will-change: transform; opacity: .95; }
  .auth-bg .orbit { position: absolute; left: calc(var(--g) * -0.22); bottom: calc(var(--g) * -0.32); width: var(--g); height: var(--g); border-radius: 50%; border: 1px solid rgba(252, 175, 23, .22); box-shadow: 0 0 40px rgba(252, 175, 23, .08); animation: spinOrbit 60s linear infinite; }
  .auth-bg .orbit::before { content: ""; position: absolute; top: 6%; left: 50%; width: 9px; height: 9px; border-radius: 50%; background: #FCAF17; box-shadow: 0 0 14px 4px rgba(252, 175, 23, .7); transform: translateX(-50%); }
  .auth-bg .orbit.o2 { transform: scale(1.18); animation: spinOrbitRev 90s linear infinite; border-color: rgba(252, 175, 23, .14); }
  .auth-bg .orbit.o2::before { width: 6px; height: 6px; top: 10%; }
  .auth-bg .orbit.o3 { transform: scale(1.36); animation-duration: 120s; border-color: rgba(252, 175, 23, .09); }
  .auth-bg .orbit.o3::before { width: 5px; height: 5px; top: 3%; }
  .auth-bg .halo { position: absolute; left: calc(var(--g) * -0.22); bottom: calc(var(--g) * -0.32); width: var(--g); height: var(--g); border-radius: 50%; box-shadow: 0 0 120px 30px rgba(252, 175, 23, .18); animation: breathe 6s ease-in-out infinite; pointer-events: none; }
  .auth-bg .glow { position: absolute; left: 0; bottom: 0; width: 60vw; height: 40vh; background: radial-gradient(ellipse at 20% 100%, rgba(252, 175, 23, .28), transparent 65%); }
  .auth-bg .spark { position: absolute; width: 22px; height: 22px; border-radius: 50%; background: rgba(252, 175, 23, .55); filter: blur(6px); right: 4%; top: 30%; animation: breathe 4s ease-in-out infinite; }
  .auth-bg .ray { position: absolute; right: -10%; top: -10%; width: 60vw; height: 60vw; background: radial-gradient(circle at 100% 0%, rgba(252, 175, 23, .16), transparent 55%); }
  @keyframes spinGlobe { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  @keyframes spinOrbit { from { transform: scale(1) rotate(0deg); } to { transform: scale(1) rotate(360deg); } }
  @keyframes spinOrbitRev { from { transform: scale(1.18) rotate(360deg); } to { transform: scale(1.18) rotate(0deg); } }
  @keyframes breathe { 0%, 100% { opacity: .75; } 50% { opacity: 1; } }
  .auth-bg .orbit.o3 { animation-name: spinOrbit3; }
  @keyframes spinOrbit3 { from { transform: scale(1.36) rotate(0deg); } to { transform: scale(1.36) rotate(360deg); } }
  .auth-top { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 56px 0; }
  .auth-top .logo { height: 34px; }
  .auth-top .lang { display: inline-flex; align-items: center; gap: 6px; color: #d7d7d7; font-size: .8125rem; background: transparent; border: 0; }
  .auth-top .btn-outline-brand { border: 1px solid rgba(252, 175, 23, .8); color: var(--brand); background: transparent; border-radius: 9px; padding: .42rem .95rem; font-size: .8125rem; font-weight: 600; }
  .auth-top .btn-outline-brand:hover { background: var(--brand); color: #111; text-decoration: none; }
  .auth-wrap { position: relative; z-index: 1; min-height: calc(100vh - 78px); display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); align-items: center; gap: 24px; padding: 24px 56px 40px; }
  .auth-aside { display: flex; flex-direction: column; justify-content: flex-start; gap: 20px; max-width: 520px; align-self: start; padding-top: 4vh; padding-bottom: 30px; margin-bottom: 30px; position: relative; z-index: 2; }
  .auth-main { align-self: center; }
  .auth-eyebrow { color: var(--brand); font-size: .6875rem; letter-spacing: .28em; text-transform: uppercase; font-weight: 700; }
  .auth-aside h2 { color: #fff; font-size: clamp(1.7rem, 2.6vw, 2.35rem); font-weight: 700; line-height: 1.15; letter-spacing: -.02em; margin: 0; }
  .auth-aside h2 em { font-style: normal; color: var(--brand); }
  .auth-aside .lead { color: #cfcfcf; font-size: .875rem; margin: 0; line-height: 1.6; }
  .auth-features { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
  .auth-features .f i { width: 34px; height: 34px; border-radius: 9px; background: rgba(252, 175, 23, .16); color: var(--brand); display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; margin-bottom: 8px; }
  .auth-features .f b { display: block; color: #fff; font-size: .8125rem; font-weight: 700; }
  .auth-features .f span { color: #a9a9a9; font-size: .75rem; }
  .auth-live { display: inline-flex; align-items: center; gap: 10px; font-size: .75rem; color: #cfcfcf; background: rgba(255, 255, 255, .05); border: 1px solid rgba(255, 255, 255, .08); border-radius: 10px; padding: 9px 14px; width: fit-content; }
  .auth-live .sep { color: #555; }
  .auth-main { display: flex; align-items: center; justify-content: center; }
  .auth-card { width: 100%; max-width: 510px; border-radius: 18px; padding: 28px 30px 24px; background: rgba(18, 18, 18, .88); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border: 1px solid rgba(252, 175, 23, .45); box-shadow: 0 30px 80px -30px rgba(0, 0, 0, .8), 0 0 0 1px rgba(252, 175, 23, .06), 0 0 60px -20px rgba(252, 175, 23, .35); color: #ececec; animation: cardIn .5s var(--ease); }
  .auth-card.wide { max-width: 600px; }
  @keyframes cardIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
  .auth-card h1 { color: #fff; font-size: 1.625rem; margin-bottom: 4px; }
  .auth-card .lead-text { color: #b5b5b5; font-size: .875rem; margin-bottom: 16px; }
  .auth-card .form-label { color: #e6e6e6; font-size: .875rem; margin-bottom: 5px; }
  .auth-card .form-control, .auth-card .form-select { min-height: 44px; background: #1c1c1c; border: 1px solid rgba(255, 255, 255, .1); color: #fff; font-size: .875rem; }
  .auth-card .form-control::placeholder { color: #6f6f6f; }
  .auth-card .form-control:focus, .auth-card .form-select:focus { background: #202020; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(252, 175, 23, .18); color: #fff; }
  .auth-card .form-control.is-invalid { border-color: #ef4444; }
  .auth-card .form-control:disabled { background: #161616; color: #9a9a9a; }
  .auth-card .input-group-text { background: #242424; border-color: rgba(255, 255, 255, .1); color: #9a9a9a; }
  .auth-card .input-group > .btn { border-color: rgba(255, 255, 255, .1); background: #242424; color: #9a9a9a; }
  .auth-card .input-group > .btn:hover { background: #2e2e2e; color: #fff; }
  .auth-card .input-group:focus-within .input-group-text, .auth-card .input-group:focus-within > .btn { border-color: var(--brand); }
  .auth-card .form-check-input { background-color: #1c1c1c; border-color: rgba(255, 255, 255, .25); }
  .auth-card .form-check-input:checked { background-color: var(--brand); border-color: var(--brand); }
  .auth-card .form-check-label { color: #cfcfcf; font-size: .875rem; }
  .auth-card .form-text { color: #8f8f8f; }
  .auth-card .btn-lg { min-height: 46px; font-size: .9375rem; }
  .auth-card .btn-dark { background: var(--brand); border-color: var(--brand); color: #111; }
  .auth-card .btn-dark:hover { background: var(--brand-600); border-color: var(--brand-600); color: #111; }
  .auth-card .btn-brand { color: #111; }
  .auth-card .btn-light { background: transparent; border: 1px solid rgba(252, 175, 23, .6); color: #fff; }
  .auth-card .btn-light:hover { background: rgba(252, 175, 23, .12); color: #fff; }
  .auth-card .alert { padding: 9px 12px; font-size: .8125rem; border-radius: 10px; }
  .auth-card .alert-success { background: rgba(22, 163, 74, .16); border-color: rgba(22, 163, 74, .5); color: #bdf0cf; }
  .auth-card .alert-danger { background: rgba(239, 68, 68, .14); border-color: rgba(239, 68, 68, .45); color: #ffc7c7; }
  .auth-card .alert-warning { background: rgba(245, 158, 11, .14); border-color: rgba(245, 158, 11, .45); color: #ffe0a6; }
  .auth-card .alert-light, .auth-card .alert-info { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .1); color: #d6d6d6; }
  .auth-card a { color: var(--brand); } .auth-card a:hover { color: #ffc44d; }
  a, a:hover, .btn, .btn:hover { text-decoration: none !important; }
  .auth-card .text-muted { color: #9a9a9a !important; }
  .auth-card .invalid-feedback { color: #ff9b9b; }
  .auth-card .pw-meter span { background: rgba(255, 255, 255, .12); }
  .auth-card code { color: var(--brand); background: rgba(255, 255, 255, .06); padding: 1px 5px; border-radius: 4px; }
  .auth-card .list-unstyled { color: #b5b5b5; }
  .auth-footer { text-align: center; font-size: .75rem; color: #8a8a8a; margin-top: 14px; }
  .auth-mobile-brand { display: none; text-align: center; margin-bottom: 18px; }
  .auth-mobile-brand img { height: 30px; }
  .auth-divider { display: flex; align-items: center; gap: 12px; color: #8a8a8a; font-size: .75rem; margin: 14px 0; }
  .auth-divider::before, .auth-divider::after { content: ""; flex: 1; height: 1px; background: rgba(255, 255, 255, .12); }
  .plan-pill { background: rgba(252, 175, 23, .16); color: var(--brand); border: 1px solid rgba(252, 175, 23, .3); }
  @media (max-width: 991.98px) {
    .auth-top { padding: 14px 16px 0; } .auth-top .logo { height: 26px; } .auth-top .btn-outline-brand { white-space: nowrap; padding: .32rem .7rem; font-size: .75rem; } .auth-top .lang span { display: none; }
    .auth-wrap { grid-template-columns: 1fr; padding: 16px 16px 32px; min-height: calc(100vh - 66px); align-items: start; }
    .auth-aside { display: none; }
    .auth-mobile-brand { display: block; }
    .auth-card { padding: 20px 16px 16px; border-radius: 16px; }
    body.auth { --g: min(86vw, 64vh); } .auth-bg .globe, .auth-bg .orbit, .auth-bg .halo { left: calc(var(--g) * -0.28); bottom: calc(var(--g) * -0.35); width: var(--g); height: var(--g); } .auth-bg .globe { opacity: .5; } .auth-bg .orbit.o2, .auth-bg .orbit.o3 { display: none; }
  }
  .auth-card .mb-3 { margin-bottom: .6rem !important; } .auth-card .my-3 { margin-top: .5rem !important; margin-bottom: .5rem !important; } .auth-card .mb-2 { margin-bottom: .4rem !important; } .auth-card .row.g-3 { --bs-gutter-y: .55rem; }
  .auth-card .btn-lg { min-height: 44px; }
  @media (max-height: 880px), (max-width: 991.98px) { .auth-steps, .auth-card .form-text { display: none; } .auth-card { padding: 18px 20px 14px; } .auth-card h1 { font-size: 1.25rem; margin-bottom: 2px; } .auth-card .lead-text { margin-bottom: 10px; } .auth-divider { margin: 10px 0; } .auth-footer { margin-top: 8px; } .auth-card .form-control, .auth-card .form-select { min-height: 40px; } .auth-card .btn-lg { min-height: 42px; } .auth-wrap { padding-top: 12px; padding-bottom: 16px; } }
  @media (max-width: 575.98px) { .auth-top { padding-top: 8px; } .auth-top .logo { height: 22px; } .auth-wrap { padding: 6px 10px 8px; min-height: calc(100dvh - 52px); } .auth-card { padding: 12px 12px 10px; border-radius: 12px; } .auth-card h1 { font-size: 1.125rem; } .auth-card .lead-text { font-size: .75rem; margin-bottom: 8px; } .plan-pill { font-size: .625rem; padding: 3px 8px; } .auth-card .form-label { margin-bottom: 2px; font-size: .75rem; } .auth-card .form-control { min-height: 34px; } .auth-card .btn-lg { min-height: 36px; font-size: .8125rem; } .auth-divider { margin: 8px 0; } .auth-footer { margin-top: 6px; font-size: .6875rem; } }
  @media (max-width: 575.98px) and (max-height: 640px) { .auth-card .lead-text, .auth-card .plan-pill-row { display: none; } .auth-card .form-check-label { font-size: .75rem; } .auth-card .mb-2 { margin-bottom: .3rem !important; } .auth-card .form-label { margin-bottom: 1px; } .auth-card .form-control { min-height: 32px; } .auth-card .mb-3 { margin-bottom: .35rem !important; } .auth-card .row.g-3 { --bs-gutter-y: .35rem; } .auth-card h1 { font-size: 1rem; margin-bottom: 4px; } .auth-card .btn-lg { min-height: 34px; } .auth-footer { display: none; } }
  @media (max-width: 1199.98px) and (min-width: 992px) { .auth-top { padding: 18px 28px 0; } .auth-wrap { padding: 16px 28px 32px; } }
  @media (prefers-reduced-motion: reduce) { .auth-card, .auth-bg .globe, .auth-bg .orbit, .auth-bg .halo, .auth-bg .spark { animation: none; } }
</style>
</head>
<body class="auth">
<div class="auth-bg" aria-hidden="true"><div class="grid"></div><div class="ray"></div><div class="glow"></div><div class="globe"></div><div class="halo"></div><div class="orbit"></div><div class="orbit o2"></div><div class="orbit o3"></div><div class="spark"></div></div>
<header class="auth-top">
  <a href="<?= url('') ?>"><img src="<?= company_logo_url('light') ?>" alt="<?= e($platformName) ?>" class="logo"></a>
  <div class="d-flex align-items-center gap-3">
    <div class="dropdown"><button class="lang" data-bs-toggle="dropdown" aria-label="Language"><i class="bi bi-globe2"></i> <span>English</span> <i class="bi bi-chevron-down small"></i></button><ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item active" href="#">English</a></li></ul></div>
    <?php if ($isLoginPage && $registrationOpen): ?><a href="<?= url('auth/register.php') ?>" class="btn-outline-brand">Create account</a><?php elseif (!$isLoginPage && !$loggedIn): ?><a href="<?= url('auth/login.php') ?>" class="btn-outline-brand">Sign in</a><?php endif; ?>
  </div>
</header>
<div class="auth-wrap">
  <aside class="auth-aside">
    <?php if (!empty($authAside)): ?><?= $authAside ?><?php else: ?>
    <div class="auth-eyebrow">Harish Monitor &nbsp;•&nbsp; Protect &nbsp;•&nbsp; Grow</div>
    <h2>Every website, page and form – <em>watched around the clock.</em></h2>
    <p class="lead">Uptime, page health, form testing, SSL, domain and hosting expiry in one calm, fast dashboard. Alerts reach the right people before your clients notice.</p>
    <div class="auth-features">
      <div class="f"><i class="bi bi-lightning-charge-fill"></i><b>Automatic monitoring</b><span>No manual setup</span></div>
      <div class="f"><i class="bi bi-shield-check"></i><b>CAPTCHA-aware testing</b><span>No spam in your inbox</span></div>
      <div class="f"><i class="bi bi-bell-fill"></i><b>Instant alerts</b><span>Be the first to know</span></div>
    </div>
    <div class="auth-live"><span class="live-dot"></span> Monitoring engine online <span class="sep">|</span> &copy; <?= date('Y') ?> <?= e($platformName) ?></div>
    <?php endif; ?>
  </aside>
  <main class="auth-main">
    <div class="auth-card <?= !empty($authWide) ? 'wide' : '' ?>">
      <?= $authContent ?? '' ?>
    </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  $(document).on('click', '.toggle-password', function () {
    const $i = $($(this).data('target'));
    const show = $i.attr('type') === 'password';
    $i.attr('type', show ? 'text' : 'password');
    $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    $(this).attr('aria-label', show ? 'Hide password' : 'Show password');
  });
  (function () {
    document.querySelectorAll('.needs-validation').forEach(f => f.addEventListener('submit', e => {
      if (!f.checkValidity()) { e.preventDefault(); e.stopPropagation(); f.classList.add('was-validated'); return; }
      const b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (b.dataset.loading || 'Please wait…'); }
    }));
    const score = v => { let s = 0; if (!v) return 0; if (v.length >= 8) s++; if (v.length >= 12) s++; if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++; return Math.min(4, Math.max(0, s - (v.length < 8 ? 2 : 1))); };
    document.querySelectorAll('.pw-strength').forEach(inp => inp.addEventListener('input', () => {
      const v = inp.value, sc = score(v), m = document.querySelector(inp.dataset.meter), h = document.querySelector(inp.dataset.hint);
      if (m) m.setAttribute('data-level', v ? Math.max(1, sc) : 0);
      if (h) h.textContent = !v ? '' : ['Too weak – use at least 8 characters', 'Weak – add numbers and capital letters', 'Fair – add a symbol or make it longer', 'Good password', 'Strong password'][sc];
    }));
  })();
</script>
<?= $authScripts ?? '' ?>
</body>
</html>
