<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;

$s = settings_all(true);
$v = fn(string $k, $d = '') => e($s[$k] ?? $d);
$tab = in_array(get('tab'), ['general', 'monitoring', 'alerts'], true) ? get('tab') : 'general';
$hasImap = function_exists('imap_open');

$pageTitle = 'Platform Settings';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Settings']];
$pageSubtitle = 'Global engine and default alert settings for the whole platform. SMTP and sender settings live under <a href="' . url('platform/emails.php?tab=settings') . '">SMTP / Email</a>; customers manage their own workspace settings under Settings.';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card">
  <div class="card-header p-0 border-0">
    <ul class="nav nav-tabs px-2" role="tablist">
      <li class="nav-item"><button class="nav-link <?= $tab === 'general' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabGeneral" type="button"><i class="bi bi-sliders me-1"></i>General</button></li>
      <li class="nav-item"><button class="nav-link <?= $tab === 'monitoring' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabMonitoring" type="button"><i class="bi bi-activity me-1"></i>Monitoring</button></li>
      <li class="nav-item"><button class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabAlerts" type="button"><i class="bi bi-bell me-1"></i>Alerts</button></li>
      <li class="nav-item ms-auto"><a class="nav-link" href="<?= url('platform/system.php') ?>"><i class="bi bi-lightning-charge me-1"></i>Execution engine &amp; scheduler health</a></li>
    </ul>
  </div>
  <div class="card-body tab-content">

    <!-- General -->
    <div class="tab-pane fade <?= $tab === 'general' ? 'show active' : '' ?>" id="tabGeneral">
      <?php if (!Auth::isPlatformOwner()): ?><div class="alert alert-light border small py-2"><i class="bi bi-shield-lock me-1"></i>Product settings (platform name, trial plan, registration, analytics defaults) are managed by the platform <strong>Owner</strong>. You can view them here.</div><?php endif; ?>
      <form class="ajax-form" action="<?= url('api/settings.php') ?>" data-reload="1" enctype="multipart/form-data" novalidate<?= Auth::isPlatformOwner() ? '' : ' style="pointer-events:none;opacity:.7"' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
        <div class="row g-3" style="max-width:760px">
          <div class="col-md-6"><label class="form-label">Company name</label><input type="text" name="company_name" class="form-control" value="<?= $v('company_name', 'Outline Media') ?>" required></div>
          <div class="col-md-6"><label class="form-label">Timezone</label><select name="timezone" class="form-select"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= $tz ?>" <?= ($s['timezone'] ?? 'Asia/Kolkata') === $tz ? 'selected' : '' ?>><?= $tz ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Date format</label><select name="date_format" class="form-select"><?php foreach (['d-M-Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd M Y', 'M d, Y'] as $f): ?><option value="<?= $f ?>" <?= ($s['date_format'] ?? 'd-M-Y') === $f ? 'selected' : '' ?>><?= $f ?> → <?= date($f) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Logo (PNG/SVG/JPG, max 1 MB)</label><input type="file" name="logo" class="form-control" accept="image/*"><div class="form-text">Used on the sidebar and login page (dark background – a light/white logo works best).</div>
            <?php if (!empty($s['logo_file'])): ?><div class="mt-2 d-flex align-items-center gap-2"><img src="<?= url('uploads/' . $s['logo_file']) ?>" alt="logo" style="height:30px;background:#111;padding:4px;border-radius:4px"><div class="form-check"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="rmlogo"><label class="form-check-label small" for="rmlogo">Remove custom logo (use default)</label></div></div><?php endif; ?></div>
          <div class="col-12"><div class="section-title mt-2">Platform &amp; self-service</div></div>
          <div class="col-md-6"><label class="form-label">Platform name</label><input type="text" name="platform_name" class="form-control" value="<?= $v('platform_name', 'Outline Monitor') ?>" required><div class="form-text">Shown in the browser title, e-mails and the public pages.</div></div>
          <div class="col-md-3"><label class="form-label">Trial plan</label><select name="trial_plan" class="form-select"><?php foreach (Tenant::allPlans() as $p): if ($p['price_monthly'] === null) continue; ?><option value="<?= e($p['code']) ?>" <?= ($s['trial_plan'] ?? 'professional') === $p['code'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label">Trial days</label><input type="number" name="trial_days" class="form-control" min="1" max="90" value="<?= $v('trial_days', '14') ?>"></div>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="registration_enabled" id="regEnabled" <?= setting('registration_enabled', 1) ? 'checked' : '' ?>><label class="form-check-label" for="regEnabled">Public self-registration open</label></div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="web_heartbeat_enabled" id="hbEnabled" <?= setting('web_heartbeat_enabled', 1) ? 'checked' : '' ?>><label class="form-check-label" for="hbEnabled">Inbound-request fallback (dashboard heartbeats run a short tick when neither the engine chain nor a worker is alive)</label></div></div>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintMode" <?= setting('maintenance_mode', 0) ? 'checked' : '' ?>><label class="form-check-label text-danger fw-600" for="maintMode">Maintenance mode (customers see a 503 page, Super Admins keep access)</label></div><input type="text" name="maintenance_message" class="form-control form-control-sm mt-2" placeholder="Optional message shown on the maintenance page" value="<?= $v('maintenance_message', '') ?>"></div>
          <div class="col-12"><div class="section-title mt-2">Website analytics</div></div>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="analytics_enabled" id="anEnabled" <?= setting('analytics_enabled', 1) ? 'checked' : '' ?>><label class="form-check-label" for="anEnabled">Analytics feature available to customers (per plan limits)</label></div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="analytics_geo_lookup" id="anGeo" <?= setting('analytics_geo_lookup', 1) ? 'checked' : '' ?>><label class="form-check-label" for="anGeo">Look up visitor country / city from the IP (cached, IP stored hashed only)</label></div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="analytics_trust_proxy" id="anProxy" <?= setting('analytics_trust_proxy', 0) ? 'checked' : '' ?>><label class="form-check-label" for="anProxy">Behind Cloudflare / a proxy: trust CF-Connecting-IP / X-Forwarded-For</label></div></div>
          <div class="col-md-6"><label class="form-label">Raw event retention (days)</label><input type="number" name="analytics_raw_retention_days" class="form-control" min="7" max="365" value="<?= $v('analytics_raw_retention_days', '90') ?>"><div class="form-text">Individual page views (live view, recent visitors, exit pages, heatmap). Daily summaries follow each plan's history setting. Plan limits and history: Platform → Plans.</div></div>
          <div class="col-12"><button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save General Settings</button></div>
        </div>
      </form>
    </div>

    <!-- Monitoring -->
    <div class="tab-pane fade <?= $tab === 'monitoring' ? 'show active' : '' ?>" id="tabMonitoring">
      <form class="ajax-form" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_monitoring">
        <div class="row g-3" style="max-width:860px">
          <div class="col-12"><div class="section-title">Website checks</div></div>
          <div class="col-md-3"><label class="form-label">Check interval (minutes)</label><input type="number" name="website_check_interval" class="form-control" min="1" max="1440" value="<?= $v('website_check_interval', 5) ?>"><div class="form-text">Fallback when a workspace has no plan interval; the subscription plan is the source of truth (Plans &amp; Pricing).</div></div>
          <div class="col-md-3"><label class="form-label">Timeout (seconds)</label><input type="number" name="check_timeout" class="form-control" min="3" max="60" value="<?= $v('check_timeout', 15) ?>"></div>
          <div class="col-md-3"><label class="form-label">Retry attempts</label><input type="number" name="retry_attempts" class="form-control" min="0" max="5" value="<?= $v('retry_attempts', 2) ?>"><div class="form-text">Retries before marking a site down.</div></div>
          <div class="col-12"><div class="section-title">Form checks</div></div>
          <div class="col-md-3"><label class="form-label">Test interval (hours)</label><input type="number" name="form_check_interval" class="form-control" min="1" max="720" value="<?= $v('form_check_interval', 24) ?>"></div>
          <div class="col-md-3"><label class="form-label">"Not tested recently" after (hours)</label><input type="number" name="form_stale_hours" class="form-control" min="1" max="720" value="<?= $v('form_stale_hours', 48) ?>"></div>
          <div class="col-md-6"><label class="form-label">Test email address</label><input type="email" name="form_test_email" class="form-control" value="<?= $v('form_test_email', 'website-test@example.com') ?>" required><div class="form-text">Used as the email value in automated form submissions. Never a real customer address.</div></div>
          <div class="col-12"><div class="section-title">Email delivery verification (optional, IMAP)</div>
            <?php if (!$hasImap): ?><div class="alert alert-warning py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>The PHP <code>imap</code> extension is not enabled on this server, so email delivery verification is unavailable (tests still check page availability and form submission). On cPanel enable it under <em>Select PHP Version → Extensions → imap</em>.</div><?php endif; ?>
            <p class="small text-muted mb-2">If the test mailbox is reachable over IMAP, the CRM searches it for the unique test token after each submission and reports whether the email actually arrived.</p></div>
          <div class="col-md-4"><label class="form-label">IMAP host</label><input type="text" name="imap_host" class="form-control" value="<?= $v('imap_host') ?>" placeholder="mail.yourdomain.com"></div>
          <div class="col-md-2"><label class="form-label">Port</label><input type="number" name="imap_port" class="form-control" value="<?= $v('imap_port', 993) ?>"></div>
          <div class="col-md-2"><label class="form-label">Encryption</label><select name="imap_encryption" class="form-select"><?php foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($s['imap_encryption'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label">IMAP username</label><input type="text" name="imap_username" class="form-control" value="<?= $v('imap_username') ?>"></div>
          <div class="col-md-4"><label class="form-label">IMAP password</label><input type="password" name="imap_password" class="form-control" placeholder="<?= !empty($s['imap_password']) ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></div>
          <div class="col-md-3"><label class="form-label">Wait before checking (sec)</label><input type="number" name="imap_wait_seconds" class="form-control" min="0" max="60" value="<?= $v('imap_wait_seconds', 20) ?>"></div>
          <div class="col-md-5 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="form_email_required" id="fer" <?= !empty($s['form_email_required']) ? 'checked' : '' ?>><label class="form-check-label small" for="fer">Mark form as FAILED if test email is not received</label></div></div>
          <div class="col-12"><div class="section-title mt-2">Scheduler &amp; workers (v3.4 queue)</div></div>
          <div class="col-md-3"><label class="form-label">Dispatch batch per queue</label><input type="number" name="dispatch_batch_limit" class="form-control" min="100" max="20000" value="<?= $v("dispatch_batch_limit", 2000) ?>"><div class="form-text">Due targets queued per dispatcher tick (every minute).</div></div>
          <div class="col-md-3"><label class="form-label">Jobs claimed per round</label><input type="number" name="queue_claim_batch" class="form-control" min="1" max="200" value="<?= $v("queue_claim_batch", 50) ?>"><div class="form-text">Website checks in one concurrent batch per worker.</div></div>
          <div class="col-md-3"><label class="form-label">Job retry attempts</label><input type="number" name="job_max_attempts" class="form-control" min="1" max="10" value="<?= $v("job_max_attempts", 3) ?>"><div class="form-text">Exponential back-off 30 s → 15 min, then dead-lettered.</div></div>
          <div class="col-md-3"><label class="form-label">Worker heartbeat timeout (s)</label><input type="number" name="worker_stale_seconds" class="form-control" min="30" max="3600" value="<?= $v("worker_stale_seconds", 120) ?>"><div class="form-text">Silent workers are declared dead; their jobs are re-queued.</div></div>
          <div class="col-md-3"><label class="form-label">Max headless browsers (platform-wide)</label><input type="number" name="browser_max_concurrent" class="form-control" min="1" max="50" value="<?= $v("browser_max_concurrent", 2) ?>"><div class="form-text">Form tests / discovery wait for a free slot.</div></div>
          <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="analytics_async" id="anaAsync" <?= setting("analytics_async", 1) ? "checked" : "" ?>><label class="form-check-label" for="anaAsync">Roll up analytics in the worker queue (async ingestion)</label></div></div>
          <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="inline_fallback_enabled" id="inlineFb" <?= setting("inline_fallback_enabled", 1) ? "checked" : "" ?>><label class="form-check-label" for="inlineFb">Let the request chain / inbound requests process the queue inline when no worker process is alive</label></div></div>
          <div class="col-md-3 d-flex align-items-end"><a href="<?= url("platform/system.php") ?>" class="btn btn-light btn-sm"><i class="bi bi-activity me-1"></i>Scheduler health</a></div>
          <div class="col-12"><button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Monitoring Settings</button></div>
        </div>
      </form>
    </div>

    <!-- Alerts -->
    <div class="tab-pane fade <?= $tab === 'alerts' ? 'show active' : '' ?>" id="tabAlerts">
      <form class="ajax-form" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_alerts">
        <div class="row g-3" style="max-width:760px">
          <div class="col-12"><p class="small text-muted">Enter the day thresholds at which an alert email is sent (comma separated). One email is sent per threshold, per certificate/domain/hosting expiry date, so you are not spammed. Expired items always alert.</p></div>
          <div class="col-md-6"><label class="form-label">SSL alert days</label><input type="text" name="ssl_alert_days" class="form-control" value="<?= $v('ssl_alert_days', '30,15,7') ?>"><div class="form-text">e.g. 30,15,7</div></div>
          <div class="col-md-6"><label class="form-label">Show SSL as "Expiring Soon" within (days)</label><input type="number" name="ssl_warning_days" class="form-control" min="1" value="<?= $v('ssl_warning_days', 30) ?>"></div>
          <div class="col-md-6"><label class="form-label">Domain alert days</label><input type="text" name="domain_alert_days" class="form-control" value="<?= $v('domain_alert_days', '60,30,15,7') ?>"><div class="form-text">The largest value also drives the "expiring soon" dashboard count.</div></div>
          <div class="col-md-6"><label class="form-label">Hosting alert days</label><input type="text" name="hosting_alert_days" class="form-control" value="<?= $v('hosting_alert_days', '60,30,15,7') ?>"></div>
          <div class="col-12"><button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Alert Settings</button></div>
        </div>
      </form>
    </div>

  </div>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php'; ?>
