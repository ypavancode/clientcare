<?php
/** Branded error page. Variables: $httpErrorCode (int), $httpErrorMessage (string), $detail (dev only) */
$httpErrorCode = $httpErrorCode ?? 500;
$texts = [
    404 => ['Page not found', 'The page you are looking for does not exist or has moved.', 'bi-compass'],
    403 => ['Access denied', 'You do not have permission to view this page.', 'bi-shield-lock'],
    429 => ['Too many requests', 'Please slow down and try again in a moment.', 'bi-hourglass-split'],
    500 => ['Something went wrong', 'The error has been logged. Please try again or contact support.', 'bi-exclamation-triangle'],
    503 => ['Scheduled maintenance', 'We are performing maintenance. Please check back shortly.', 'bi-tools'],
];
[$title, $text, $icon] = $texts[$httpErrorCode] ?? $texts[500];
if (!empty($httpErrorMessage)) $text = $httpErrorMessage;
$name = function_exists('setting') ? (string) setting('platform_name', 'Outline Monitor') : 'Outline Monitor';
$home = defined('BASE_URL') ? BASE_URL . '/' : '/';
$css = defined('BASE_URL') ? BASE_URL . '/assets/css/app.css?v=' . (defined('APP_VERSION') ? APP_VERSION : '1') : '/assets/css/app.css';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($name) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
<style>
  body.err { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; background: var(--bg); color: #fff; overflow-x: hidden; }
  .err-bg { position: fixed; inset: 0; background: radial-gradient(900px 600px at 0% 100%, rgba(252,175,23,.2), transparent 60%), radial-gradient(700px 500px at 100% 0%, rgba(252,175,23,.1), transparent 60%); }
  .err-card { position: relative; max-width: 520px; width: 100%; border-radius: 18px; padding: 36px 32px; text-align: center; background: rgba(18,18,18,.9); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border: 1px solid var(--brand-line); color: var(--text); box-shadow: var(--shadow-lg), 0 0 80px -30px var(--brand-glow); animation: fadein .5s var(--ease); }
  .err-code { font-family: var(--font-heading); font-size: clamp(3rem, 8vw, 4.5rem); font-weight: 700; color: var(--brand); line-height: 1; letter-spacing: -.04em; }
  .err-icon { width: 64px; height: 64px; border-radius: 18px; background: var(--brand-soft); border: 1px solid var(--brand-line); color: var(--brand); display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; margin: 14px 0; animation: floatY 5s ease-in-out infinite; }
  .err-card h1 { font-size: var(--fs-h2); }
  @media (max-width: 575.98px) { .err-card { padding: 30px 22px; border-radius: 20px; } }
</style>
</head>
<body class="err">
<div class="err-bg" aria-hidden="true"></div>
<div class="err-card">
  <div class="err-code"><?= (int) $httpErrorCode ?></div>
  <div class="err-icon"><i class="bi <?= $icon ?>"></i></div>
  <h1><?= htmlspecialchars($title) ?></h1>
  <p class="text-muted mb-4"><?= htmlspecialchars($text) ?></p>
  <?php if (!empty($detail) && defined('APP_ENV') && APP_ENV === 'development'): ?><pre class="text-start small bg-light p-3 rounded border mb-4"><?= htmlspecialchars($detail) ?></pre><?php endif; ?>
  <div class="d-flex flex-wrap justify-content-center gap-2">
    <a href="javascript:history.back()" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Go back</a>
    <a href="<?= htmlspecialchars($home) ?>" class="btn btn-dark"><i class="bi bi-house-door me-1"></i>Home</a>
    <?php if ((int) ($httpErrorCode ?? 0) === 503): ?><a href="<?= htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/login') ?>" class="btn btn-light ms-2"><i class="bi bi-box-arrow-in-right me-1"></i>Administrator sign in</a><?php endif; ?>
  </div>
  <div class="small text-muted mt-4"><?= htmlspecialchars($name) ?></div>
</div>
</body>
</html>
