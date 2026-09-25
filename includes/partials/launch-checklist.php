<?php
/**
 * Launch readiness card (Super Admin → System Health). Real checks only: production flags in config, default secrets,
 * SMTP verified, engine alive, HTTPS, PHP extensions, writable folders, leftover test data. Nothing here is decorative.
 */
$lc = [];
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~', $host) === 1;
$lc[] = ['APP_ENV = production', APP_ENV === 'production', APP_ENV === 'production' ? 'Debug details are hidden from visitors.' : 'Currently "' . APP_ENV . '": error details and per-request profiling are exposed. Set APP_ENV to production in config/config.php before launch.'];
$lc[] = ['APP_KEY changed from the default', !str_contains(APP_KEY, 'change-this'), !str_contains(APP_KEY, 'change-this') ? 'Custom key in use.' : 'The default key encrypts stored SMTP / WordPress passwords – replace it with a long random string (re-enter stored passwords afterwards).'];
$lc[] = ['CRON_KEY changed from the default', !str_contains(CRON_KEY, 'change-this'), !str_contains(CRON_KEY, 'change-this') ? 'Custom key in use.' : 'Only needed for the optional health-check URL; set a random value anyway.'];
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$lc[] = ['HTTPS', $https || $isLocal, $https ? 'Served over HTTPS.' : ($isLocal ? 'Local development host – not applicable.' : 'The application is served over plain HTTP; enable SSL on the domain.')];
$ms = Mailer::status();
$lc[] = ['SMTP verified', $ms['status'] === 'connected', $ms['headline'] . ($ms['last_success_at'] ? ' · last success ' . time_ago($ms['last_success_at']) : '')];
$eng = Engine::status();
$lc[] = ['Execution engine running', $eng['tone'] === 'success', $eng['label'] . ' – ' . $eng['text']];
$ext = array_filter(['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json'], fn($e) => !extension_loaded($e));
$lc[] = ['PHP ' . PHP_VERSION . ' + extensions', !$ext && version_compare(PHP_VERSION, '8.0', '>='), $ext ? 'Missing: ' . implode(', ', $ext) : 'pdo_mysql, curl, openssl, mbstring, json loaded' . (extension_loaded('imap') ? ' · imap available' : ' · imap not loaded (form email verification stays "unknown")')];
$dirs = array_filter([LOG_PATH, ROOT_PATH . '/uploads', ROOT_PATH . '/cache'], fn($d) => !is_dir($d) || !is_writable($d));
$lc[] = ['Writable logs / uploads / cache', !$dirs, $dirs ? 'Not writable: ' . implode(', ', array_map('basename', $dirs)) : 'All writable.'];
$adminDefault = (bool) DB::value("SELECT COUNT(*) FROM users WHERE is_platform_admin = 1 AND email = 'admin@example.com'");
$lc[] = ['Super Admin uses a real email address', !$adminDefault, $adminDefault ? 'admin@example.com is the setup account – change the email and password under Profile before launch.' : 'OK'];
$testish = (int) DB::value("SELECT COUNT(*) FROM tenants WHERE name LIKE '%test%' OR slug LIKE '%test%' OR slug LIKE 'e2e-%'") + (int) DB::value("SELECT COUNT(*) FROM users WHERE email LIKE '%@example.com' AND is_platform_admin = 0") + (int) DB::value("SELECT COUNT(*) FROM websites WHERE url LIKE '%localhost%' OR url LIKE '%127.0.0.1%' OR url LIKE '%example.com%'");
$lc[] = ['No test data left', $testish === 0, $testish ? $testish . ' test-looking workspace / user / website row(s) – run database/production-reset.php after the final tests.' : 'No test-looking workspaces, users or websites.'];
$okCount = count(array_filter($lc, fn($c) => $c[1]));
?>
<div class="card mb-3" id="launchChecklist">
  <div class="card-header"><span><i class="bi bi-rocket-takeoff me-2"></i>Launch readiness</span><span class="small text-muted"><?= $okCount ?> / <?= count($lc) ?> checks pass · evaluated live on every load</span></div>
  <div class="card-body p-0"><ul class="diag-steps" style="border:0;border-radius:0">
    <?php foreach ($lc as [$label, $ok, $detail]): ?>
    <li class="<?= $ok ? 'ok' : 'failed' ?>"><span class="ds-ico"><?= $ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></span><span class="ds-main"><span class="ds-title"><?= e($label) ?></span><span class="ds-detail"><?= e($detail) ?></span></span></li>
    <?php endforeach; ?>
  </ul></div>
</div>
