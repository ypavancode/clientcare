<?php
/**
 * Super Admin → SMTP / Email: real connection status, Test SMTP (timed, step by step), SMTP settings, delivery logs,
 * queue and diagnostics. Every status shown here comes from email_service_state / email_logs – never from the UI.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;

$tab = in_array(get('tab'), ['status', 'settings', 'logs', 'queue'], true) ? get('tab') : 'status';
$s = settings_all(true);
$v = fn(string $k, $d = '') => e($s[$k] ?? $d);
$st = Mailer::status(true);
$dns = Mailer::senderDns();
$env = Mailer::environment();
$queue = DB::fetchAll("SELECT q.*, t.name AS tenant_name FROM email_queue q LEFT JOIN tenants t ON t.id = q.tenant_id WHERE q.status <> 'sent' ORDER BY FIELD(q.status,'pending','failed'), q.id DESC LIMIT 100");
$qc = $st['queue'];
$categories = DB::fetchAll("SELECT category, COUNT(*) AS n FROM email_logs GROUP BY category ORDER BY n DESC LIMIT 30");
$tenants = DB::fetchAll("SELECT id, name FROM tenants ORDER BY name LIMIT 500");
$fmt = fn($x) => $x ? date('d M Y, H:i:s', strtotime($x)) : '—';
$light = ['success' => '🟢', 'warning' => '🟠', 'danger' => '🔴', 'secondary' => '⚪'][$st['tone']] ?? '⚪';

$pageTitle = 'SMTP / Email';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'SMTP / Email']];
$pageSubtitle = 'Transport: PHPMailer / authenticated SMTP · sender <strong>' . e($st['from']) . '</strong>';
$pageActions = '<a href="' . url('platform/templates.php') . '" class="btn btn-light btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Email templates</a>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card scheduler-status smtp-status mb-3 border-<?= $st['tone'] ?>" id="smtpStatus" data-poll="<?= e(url('api/email.php?action=status')) ?>">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="ss-light bg-<?= $st['tone'] ?>" data-f="light"></div>
      <div class="flex-grow-1 min-w-0">
        <div class="small-xs text-muted text-uppercase" style="letter-spacing:.1em">Email service · SMTP</div>
        <div class="ss-headline text-<?= $st['tone'] ?>" data-f="headline"><?= $light ?> <?= e($st['headline']) ?></div>
        <div class="small text-muted" data-f="detail"><?= e($st['detail']) ?></div>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-brand btn-sm" id="btnTestSmtpTop" type="button"><i class="bi bi-send-check me-1"></i>Test SMTP</button>
        <div class="text-end small text-muted d-none d-md-block">Checked <span data-f="checked"><?= date('H:i:s') ?></span> · auto-refresh 30 s</div>
      </div>
    </div>
    <div class="ss-grid mt-3">
      <div><span class="l">Server</span><span class="v" data-f="host"><?= e($st['host'] ? $st['host'] . ':' . $st['port'] . ' · ' . $st['encryption'] : '—') ?></span></div>
      <div><span class="l">Last connection check</span><span class="v" data-f="last_check"><?= e($fmt($st['last_check_at'])) ?><?= $st['last_check_at'] ? ' (' . e(time_ago($st['last_check_at'])) . ')' : '' ?></span></div>
      <div><span class="l">Last successful connection</span><span class="v" data-f="last_success"><?= e($fmt($st['last_success_at'])) ?></span></div>
      <div><span class="l">Last failed connection</span><span class="v" data-f="last_failure"><?= e($fmt($st['last_failure_at'])) ?></span></div>
      <div><span class="l">Last successful email</span><span class="v" data-f="last_email_sent_full"><?= e($fmt($st['last_email_sent_at'])) ?></span></div>
      <div><span class="l">Last failed email</span><span class="v" data-f="last_email_failed_full"><?= e($fmt($st['last_email_failed_at'])) ?></span></div>
      <div><span class="l">Emails · 24 h</span><span class="v"><span data-f="sent_24h"><?= (int) $st['sent_24h'] ?></span> sent · <span class="<?= $st['failed_24h'] ? 'text-danger' : '' ?>" data-f="failed_24h"><?= (int) $st['failed_24h'] ?></span> failed</span></div>
      <div><span class="l">Queue</span><span class="v" data-f="queue"><?= (int) $qc['pending'] ?> pending · <?= (int) $qc['retrying'] ?> retrying · <?= (int) $qc['failed'] ?> failed</span></div>
    </div>
    <div class="mt-2 small" data-f="errors">
      <?php if ($st['last_error'] && $st['status'] === 'failed'): ?><div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><strong><?= e($st['last_error_label']) ?>:</strong> <?= e($st['last_error']) ?></div><?php endif; ?>
      <?php if ($st['last_response']): ?><div class="text-muted mono small-xs mt-1">SMTP response: <?= e($st['last_response']) ?></div><?php endif; ?>
      <?php if ($st['backoff_until']): ?><div class="text-warning small-xs mt-1"><i class="bi bi-pause-circle me-1"></i>Queue paused until <?= e($fmt($st['backoff_until'])) ?> after repeated transport failures (use "Process queue now" to force a retry).</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header p-0 border-0">
    <ul class="nav nav-tabs px-2" role="tablist">
      <li class="nav-item"><button class="nav-link <?= $tab === 'status' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabStatus" type="button"><i class="bi bi-activity me-1"></i>Test &amp; diagnostics</button></li>
      <li class="nav-item"><button class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabSettings" type="button"><i class="bi bi-gear me-1"></i>SMTP settings</button></li>
      <li class="nav-item"><button class="nav-link <?= $tab === 'logs' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabLogs" type="button"><i class="bi bi-journal-text me-1"></i>Email logs</button></li>
      <li class="nav-item"><button class="nav-link <?= $tab === 'queue' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabQueue" type="button"><i class="bi bi-hourglass-split me-1"></i>Queue<?= $qc['failed'] ? ' <span class="badge bg-danger-subtle">' . (int) $qc['failed'] . '</span>' : ($qc['pending'] ? ' <span class="badge bg-warning-subtle">' . (int) $qc['pending'] . '</span>' : '') ?></button></li>
    </ul>
  </div>
  <div class="card-body tab-content">

    <!-- ===== Test & diagnostics ===== -->
    <div class="tab-pane fade <?= $tab === 'status' ? 'show active' : '' ?>" id="tabStatus">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="section-title">Test SMTP</div>
          <p class="small text-muted mb-2">Runs a real check from this server: configuration → DNS → TCP → TLS → EHLO → authentication → a real test email → QUIT. Each step is timed and bounded by the configured timeouts, so a dead server fails within seconds and the result is never faked.</p>
          <form id="smtpTestForm" onsubmit="return false" autocomplete="off">
            <div class="input-group input-group-sm mb-2"><span class="input-group-text">Send to</span><input type="email" name="to" class="form-control" value="<?= e(Auth::user()['email']) ?>" required placeholder="you@company.com"></div>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="testUseForm"><label class="form-check-label small" for="testUseForm">Use the values currently typed in <em>SMTP settings</em> (test before saving; blank password = saved password)</label></div>
            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-brand btn-sm" type="button" id="btnTestSmtp"><i class="bi bi-send-check me-1"></i>Test SMTP &amp; send email</button>
              <button class="btn btn-light btn-sm" type="button" id="btnCheckSmtp"><i class="bi bi-plug me-1"></i>Check connection only</button>
              <span class="small text-muted align-self-center" id="testTimer"></span>
            </div>
          </form>
          <div id="smtpTestResult" class="mt-3"></div>
        </div>
        <div class="col-lg-5">
          <div class="section-title">Server environment</div>
          <table class="table table-compact table-sm mb-3 small"><tbody>
            <tr><td class="text-muted">PHP</td><td><?= e($env['php']) ?> · <?= e($env['sapi']) ?> · <?= e($env['os']) ?></td></tr>
            <tr><td class="text-muted">OpenSSL</td><td><?= $env['openssl'] === 'MISSING' ? '<span class="text-danger">missing – SSL/TLS impossible</span>' : e($env['openssl']) ?></td></tr>
            <tr><td class="text-muted">Sockets</td><td><?= $env['stream_socket_client'] ? '<span class="text-success">stream_socket_client available</span>' : ($env['fsockopen'] ? '<span class="text-warning">fsockopen only</span>' : '<span class="text-danger">no socket functions – SMTP impossible</span>') ?></td></tr>
            <tr><td class="text-muted">PHP time limit</td><td><?= $env['max_execution_time'] === 0 ? 'unlimited' : $env['max_execution_time'] . ' s' ?> <?= $env['time_limit_ok'] ? '<span class="text-success">ok</span>' : '<span class="text-danger">too low for the configured timeouts</span>' ?></td></tr>
            <tr><td class="text-muted">Timeouts</td><td>connect <?= (int) setting('smtp_connect_timeout', 10) ?> s · command <?= (int) setting('smtp_timeout', 20) ?> s · back-off <?= (int) setting('smtp_backoff_minutes', 5) ?> min</td></tr>
            <tr><td class="text-muted">Server</td><td><?= e($env['server']) ?></td></tr>
          </tbody></table>
          <div class="section-title">Sender domain · <?= e($dns['domain'] ?: '—') ?></div>
          <table class="table table-compact table-sm mb-0 small"><tbody>
            <tr><td class="text-muted">MX</td><td><?= $dns['mx'] ? '<span class="text-success">present</span>' : '<span class="text-warning">none found</span>' ?></td></tr>
            <tr><td class="text-muted">SPF</td><td><?= $dns['spf'] ? '<span class="text-success">present</span> <span class="mono small-xs text-muted">' . e(truncate($dns['spf'], 70)) . '</span>' : '<span class="text-warning">missing – add a v=spf1 TXT record that authorises your SMTP provider</span>' ?></td></tr>
            <tr><td class="text-muted">DMARC</td><td><?= $dns['dmarc'] ? '<span class="text-success">present</span>' : '<span class="text-muted">not published (recommended)</span>' ?></td></tr>
          </tbody></table>
        </div>
      </div>
    </div>

    <!-- ===== SMTP settings ===== -->
    <div class="tab-pane fade <?= $tab === 'settings' ? 'show active' : '' ?>" id="tabSettings">
      <form class="ajax-form" id="smtpSettingsForm" action="<?= url('api/email.php') ?>" data-reload="1" novalidate autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_smtp">
        <div class="row g-3">
          <div class="col-lg-7">
            <div class="row g-3">
              <div class="col-12 d-flex flex-wrap align-items-center gap-3"><div class="section-title mb-0">SMTP server</div><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtpEnabled" <?= setting('smtp_enabled', 1) ? 'checked' : '' ?>><label class="form-check-label small" for="smtpEnabled">Email sending enabled</label></div></div>
              <div class="col-md-8"><label class="form-label">SMTP host</label><input type="text" name="smtp_host" class="form-control" value="<?= $v('smtp_host') ?>" placeholder="smtp.gmail.com / mail.yourdomain.com" required></div>
              <div class="col-md-4"><label class="form-label">Port</label><input type="number" name="smtp_port" class="form-control" value="<?= $v('smtp_port', 587) ?>" min="1" max="65535"></div>
              <div class="col-md-6"><label class="form-label">Username</label><input type="text" name="smtp_username" class="form-control" value="<?= $v('smtp_username') ?>" placeholder="usually the full email address"></div>
              <div class="col-md-6"><label class="form-label">Password</label><input type="password" name="smtp_password" class="form-control" placeholder="<?= !empty($s['smtp_password']) ? '•••••••• saved – leave blank to keep' : '' ?>" autocomplete="new-password">
                <?php if (!empty($s['smtp_password'])): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_smtp_password" value="1" id="clearPw"><label class="form-check-label small-xs" for="clearPw">Remove the saved password</label></div><?php endif; ?></div>
              <div class="col-md-4"><label class="form-label">Encryption</label><select name="smtp_encryption" class="form-select"><?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL / TLS (port 465)', 'none' => 'None (port 25 – not recommended)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($s['smtp_encryption'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
              <div class="col-md-4"><label class="form-label">Connect timeout (s)</label><input type="number" name="smtp_connect_timeout" class="form-control" min="3" max="60" value="<?= $v('smtp_connect_timeout', 10) ?>"><div class="form-text">TCP connection.</div></div>
              <div class="col-md-4"><label class="form-label">Command timeout (s)</label><input type="number" name="smtp_timeout" class="form-control" min="5" max="120" value="<?= $v('smtp_timeout', 20) ?>"><div class="form-text">Each SMTP reply (greeting, AUTH, DATA).</div></div>
              <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="smtp_verify_peer" id="verifyPeer" <?= setting('smtp_verify_peer', 1) ? 'checked' : '' ?>><label class="form-check-label small" for="verifyPeer">Verify the server's TLS certificate</label></div><div class="form-text">Switch off only for a self-signed certificate (the test tells you).</div></div>
              <div class="col-md-6"><label class="form-label">Back-off after transport failure (min)</label><input type="number" name="smtp_backoff_minutes" class="form-control" min="1" max="60" value="<?= $v('smtp_backoff_minutes', 5) ?>"><div class="form-text">Queue pauses this long after a connection / auth / timeout failure.</div></div>
              <div class="col-12"><div class="section-title mt-1">Sender</div></div>
              <div class="col-md-4"><label class="form-label">From email</label><input type="email" name="from_email" class="form-control" value="<?= $v('from_email', Mailer::DEFAULT_FROM_EMAIL) ?>" required><div class="form-text">Must belong to the authenticated account / domain.</div></div>
              <div class="col-md-4"><label class="form-label">From name</label><input type="text" name="from_name" class="form-control" value="<?= $v('from_name', Mailer::DEFAULT_FROM_NAME) ?>"></div>
              <div class="col-md-4"><label class="form-label">Reply-To email <span class="text-muted fw-normal">(optional)</span></label><input type="email" name="reply_to_email" class="form-control" value="<?= $v('reply_to_email') ?>"></div>
              <div class="col-12"><div class="section-title mt-1">Notifications</div></div>
              <div class="col-12"><label class="form-label">Platform admin notification email(s)</label><input type="text" name="notification_email" class="form-control" value="<?= $v('notification_email') ?>" placeholder="you@company.com, ops@company.com"><div class="form-text">Comma separated. Leave blank to notify every active Super Admin.</div></div>
              <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_clients" id="nc" <?= !empty($s['notify_clients']) ? 'checked' : '' ?>><label class="form-check-label small" for="nc">Allow end-client notifications (per-client opt-in still required)</label></div></div>
              <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_system_errors" id="nse" <?= ($s['notify_system_errors'] ?? '1') ? 'checked' : '' ?>><label class="form-check-label small" for="nse">Email platform admins on application errors</label></div></div>
              <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-dark" data-loading-text="Saving…"><i class="bi bi-check2 me-1"></i>Save SMTP settings</button><button type="button" class="btn btn-light" id="btnSaveAndTest"><i class="bi bi-send-check me-1"></i>Save &amp; test</button></div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="section-title">Common providers</div>
            <table class="table table-compact table-sm small mb-3"><thead><tr><th>Provider</th><th>Host</th><th>Port</th><th>Encryption</th></tr></thead><tbody>
              <tr><td>cPanel / own domain</td><td class="mono">mail.yourdomain.com</td><td>465</td><td>SSL</td></tr>
              <tr><td>Gmail / Workspace</td><td class="mono">smtp.gmail.com</td><td>587</td><td>STARTTLS · App Password</td></tr>
              <tr><td>Microsoft 365</td><td class="mono">smtp.office365.com</td><td>587</td><td>STARTTLS · SMTP AUTH enabled</td></tr>
              <tr><td>Zoho Mail</td><td class="mono">smtp.zoho.in</td><td>465</td><td>SSL</td></tr>
              <tr><td>Brevo / SendGrid / SES</td><td class="mono">smtp-relay.brevo.com</td><td>587</td><td>STARTTLS · API key as password</td></tr>
            </tbody></table>
            <div class="alert alert-light border small mb-0">
              <strong>Live-server checklist.</strong> Shared hosts often block outbound ports 25 / 465 / 587 – when the test reports a <em>timeout</em> on the TCP step, ask the host to open the port or use their local mail server. Use <em>SSL with 465</em> or <em>STARTTLS with 587</em>; mixing them shows up as a TLS failure. The From address must be one the SMTP account is allowed to send as; publish SPF / DMARC for the sender domain so alerts land in the inbox. The password is stored encrypted with APP_KEY and is never displayed or logged.
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- ===== Email logs ===== -->
    <div class="tab-pane fade <?= $tab === 'logs' ? 'show active' : '' ?>" id="tabLogs">
      <form class="filter-bar" id="emailFilters" onsubmit="return false">
        <select name="status" class="form-select form-select-sm"><option value="">Sent + failed</option><option value="sent">Sent</option><option value="failed">Failed (any)</option><?php foreach (Mailer::KINDS as $k => $l): if ($k === 'other') continue; ?><option value="kind:<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
        <select name="category" class="form-select form-select-sm"><option value="">All types</option><?php foreach ($categories as $c): ?><option value="<?= e($c['category']) ?>"><?= e(Mailer::categoryLabel($c['category'])) ?> (<?= (int) $c['n'] ?>)</option><?php endforeach; ?></select>
        <select name="tenant_id" class="form-select form-select-sm"><option value="">All clients</option><option value="0">Platform (no client)</option><?php foreach ($tenants as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <input type="date" name="from" class="form-control form-control-sm" title="From date"><input type="date" name="to" class="form-control form-control-sm" title="To date">
      </form>
      <div class="dt-card">
        <table class="table table-hover datatable w-100 table-compact" data-source="platform_emails" data-filters="#emailFilters" data-order='[]' data-page-length="25" data-empty="No emails logged" data-empty-text="Every delivery attempt (sent or failed) appears here with its SMTP response." data-empty-icon="bi-envelope">
          <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th><th>Duration</th><th>Error / SMTP response</th><th class="no-sort text-end"></th></tr></thead><tbody></tbody>
        </table>
      </div>
    </div>

    <!-- ===== Queue ===== -->
    <div class="tab-pane fade <?= $tab === 'queue' ? 'show active' : '' ?>" id="tabQueue">
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="small text-muted me-auto"><strong><?= (int) $qc['pending'] ?></strong> pending · <strong><?= (int) $qc['retrying'] ?></strong> retrying · <strong class="text-danger"><?= (int) $qc['failed'] ?></strong> failed (after <?= Mailer::MAX_ATTEMPTS ?> attempts)</span>
        <button class="btn btn-brand btn-sm btn-action" data-url="api/email.php" data-params='{"action":"process_queue"}' data-reload="1" data-loading="1"><i class="bi bi-send me-1"></i>Process queue now</button>
        <?php if ($qc['failed']): ?><button class="btn btn-light btn-sm btn-action" data-url="api/email.php" data-params='{"action":"retry_failed"}' data-reload="1"><i class="bi bi-arrow-repeat me-1"></i>Retry all failed</button>
        <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/email.php" data-params='{"action":"clear_failed"}' data-confirm="Remove every failed email from the queue? The delivery log keeps the history." data-confirm-btn="Remove" data-reload="1"><i class="bi bi-trash me-1"></i>Clear failed</button><?php endif; ?>
      </div>
      <div class="table-responsive"><table class="table table-compact table-hover mb-0 small">
        <thead><tr><th>Queued</th><th>Client</th><th>To</th><th>Type</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Next try</th><th>Last error</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($queue as $q): $retrying = $q['status'] === 'pending' && $q['attempts'] > 0; ?>
          <tr><td class="text-nowrap"><?= format_datetime($q['created_at']) ?></td><td><?= e($q['tenant_name'] ?? '—') ?></td><td><?= e($q['to_email']) ?></td><td><span class="badge bg-secondary-subtle text-secondary"><?= e(Mailer::categoryLabel($q['category'])) ?></span></td><td><?= e(truncate($q['subject'], 60)) ?></td>
            <td><?= $q['status'] === 'failed' ? status_pill('danger', 'Failed') : ($retrying ? status_pill('warning', 'Retrying') : status_pill('info', 'Pending')) ?></td><td><?= (int) $q['attempts'] ?>/<?= Mailer::MAX_ATTEMPTS ?></td><td class="text-muted text-nowrap"><?= $q['status'] === 'pending' ? ($q['next_attempt_at'] && strtotime($q['next_attempt_at']) > time() ? e(time_ago($q['next_attempt_at'])) : 'next run') : '—' ?></td><td class="text-danger"><?= e(truncate((string) $q['last_error'], 80)) ?></td>
            <td class="row-actions text-end text-nowrap"><button class="btn btn-light btn-sm btn-view-email" data-id="<?= $q['id'] ?>" data-src="queue" title="View"><i class="bi bi-eye"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/email.php" data-params='{"action":"retry_email","id":<?= $q['id'] ?>}' data-reload="1" data-loading="1" title="Send now"><i class="bi bi-send"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/email.php" data-params='{"action":"delete_queued","id":<?= $q['id'] ?>}' data-confirm="Remove this email from the queue?" data-reload="1" title="Remove"><i class="bi bi-x-lg"></i></button></td></tr>
        <?php endforeach; ?>
        <?php if (!$queue): ?><tr><td colspan="10" class="text-center text-muted py-3">The queue is empty – every alert has been delivered.</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
</div>

<div class="modal fade" id="emailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Email details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="emailModalBody">Loading…</div>
</div></div></div>
<?php
$connectT = (int) setting('smtp_connect_timeout', 10); $cmdT = (int) setting('smtp_timeout', 20);
$maxWait = $connectT * 3 + min($cmdT, 15) * 5 + 25;
$pageScripts = <<<JS
<script>
(function () {
  const MAX_WAIT = {$maxWait};
  /* ---- live status card (real state from api/email.php) ---- */
  const \$c = \$('#smtpStatus');
  const tones = ['success', 'warning', 'danger', 'secondary'], lights = { success: '🟢', warning: '🟠', danger: '🔴', secondary: '⚪' };
  window.smtpApplyStatus = function (h) {
    tones.forEach(t => { \$c.removeClass('border-' + t); \$c.find('[data-f=light]').removeClass('bg-' + t); \$c.find('[data-f=headline]').removeClass('text-' + t); });
    \$c.addClass('border-' + h.tone); \$c.find('[data-f=light]').addClass('bg-' + h.tone); \$c.find('[data-f=headline]').addClass('text-' + h.tone).text(lights[h.tone] + ' ' + h.headline);
    \$c.find('[data-f=detail]').text(h.detail); \$c.find('[data-f=checked]').text(h.checked); \$c.find('[data-f=host]').text(h.host);
    \$c.find('[data-f=last_check]').text(h.last_check); \$c.find('[data-f=last_success]').text(h.last_success); \$c.find('[data-f=last_failure]').text(h.last_failure);
    \$c.find('[data-f=last_email_sent_full]').text(h.last_email_sent_full); \$c.find('[data-f=last_email_failed_full]').text(h.last_email_failed === 'never' ? '—' : h.last_email_failed);
    \$c.find('[data-f=sent_24h]').text(h.sent_24h); \$c.find('[data-f=failed_24h]').text(h.failed_24h).toggleClass('text-danger', h.failed_24h > 0);
    \$c.find('[data-f=queue]').text(h.queue.pending + ' pending · ' + h.queue.retrying + ' retrying · ' + h.queue.failed + ' failed');
    let err = '';
    if (h.last_error && h.status === 'failed') err += '<div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + CRM.esc(h.last_error) + '</div>';
    if (h.last_response) err += '<div class="text-muted mono small-xs mt-1">SMTP response: ' + CRM.esc(h.last_response) + '</div>';
    if (h.backoff_until) err += '<div class="text-warning small-xs mt-1"><i class="bi bi-pause-circle me-1"></i>Queue paused until ' + CRM.esc(h.backoff_until) + ' after repeated transport failures.</div>';
    \$c.find('[data-f=errors]').html(err);
  };
  const poll = () => { if (document.visibilityState !== 'visible' || window.__smtpTesting) return; \$.getJSON(\$c.data('poll')).done(h => { if (h.success) smtpApplyStatus(h); }); };
  setInterval(poll, 30000);

  /* ---- Test SMTP: bounded request, step list, never an endless spinner ---- */
  const icons = { ok: '<i class="bi bi-check-circle-fill text-success"></i>', failed: '<i class="bi bi-x-circle-fill text-danger"></i>', skipped: '<i class="bi bi-dash-circle text-muted"></i>', pending: '<i class="bi bi-circle text-muted"></i>' };
  function render(res, elapsed) {
    let h = '<div class="alert ' + (res.success ? 'alert-success' : 'alert-danger') + ' py-2 mb-2"><div class="fw-600">' + (res.success ? '🟢 ' : '🔴 ') + CRM.esc(res.headline || res.message) + '</div>';
    if (res.reason) h += '<div class="small">' + CRM.esc(res.reason) + '</div>';
    if (res.suggestion) h += '<div class="small mt-1"><i class="bi bi-lightbulb me-1"></i>' + CRM.esc(res.suggestion) + '</div>';
    if (res.smtp_response) h += '<div class="small-xs mono mt-1 text-muted">Server response: ' + CRM.esc(res.smtp_response) + '</div>';
    if (res.message_id) h += '<div class="small-xs mono text-muted">Message-ID: ' + CRM.esc(res.message_id) + '</div>';
    h += '<div class="small-xs text-muted mt-1">Completed in ' + ((res.ms || elapsed) / 1000).toFixed(2) + ' s' + (res.unsaved ? ' · using unsaved form values' : '') + '</div></div>';
    if (res.steps && res.steps.length) {
      h += '<ul class="diag-steps">';
      res.steps.forEach(s => { h += '<li class="' + s.status + '"><span class="ds-ico">' + icons[s.status] + '</span><span class="ds-main"><span class="ds-title">' + CRM.esc(s.label) + '</span>' + (s.detail ? '<span class="ds-detail">' + CRM.esc(s.detail) + '</span>' : '') + (s.response ? '<span class="ds-resp mono">' + CRM.esc(s.response) + '</span>' : '') + '</span><span class="ds-ms">' + (s.status === 'pending' || s.status === 'skipped' ? '' : s.ms + ' ms') + '</span></li>'; });
      h += '</ul>';
    }
    \$('#smtpTestResult').html(h);
  }
  let timer = null, t0 = 0;
  function runTest(action) {
    if (window.__smtpTesting) return;
    const \$out = \$('#smtpTestResult'), \$btns = \$('#btnTestSmtp, #btnCheckSmtp, #btnTestSmtpTop, #btnSaveAndTest');
    const data = { action: action, to: \$('#smtpTestForm [name=to]').val() };
    if (\$('#testUseForm').is(':checked')) { \$('#smtpSettingsForm').serializeArray().forEach(f => { if (f.name !== 'action' && f.name !== 'csrf_token') data[f.name] = f.value; }); data.use_form = 1; }
    window.__smtpTesting = true; \$btns.prop('disabled', true); t0 = Date.now();
    \$out.html('<div class="alert alert-light border py-2"><i class="bi bi-arrow-repeat spin me-1"></i>Connecting to the SMTP server… <span id="testElapsed">0</span> s (gives up after ' + MAX_WAIT + ' s)</div>');
    timer = setInterval(() => { \$('#testElapsed').text(Math.round((Date.now() - t0) / 1000)); \$('#testTimer').text(''); }, 500);
    const finish = () => { clearInterval(timer); window.__smtpTesting = false; \$btns.prop('disabled', false); poll(); };
    CRM.post('api/email.php', data, { timeout: MAX_WAIT * 1000 }).done(res => { finish(); render(res, Date.now() - t0); if (res.status) smtpApplyStatus(Object.assign({ checked: new Date().toLocaleTimeString() }, res.status, { host: res.status.host ? res.status.host + ':' + res.status.port + ' · ' + res.status.encryption : '—', last_check: res.status.last_check_at || '—', last_success: res.status.last_success_at || '—', last_failure: res.status.last_failure_at || '—', last_email_sent_full: res.status.last_email_sent_at || '—', last_email_failed: res.status.last_email_failed_at || 'never', last_error: res.status.last_error ? (res.status.last_error_label + ': ' + res.status.last_error) : '', last_response: res.status.last_response || '', backoff_until: res.status.backoff_until || '' })); })
      .fail(xhr => { finish(); const secs = Math.round((Date.now() - t0) / 1000);
        let msg = xhr.statusText === 'timeout' ? 'The request did not finish within ' + MAX_WAIT + ' s. The SMTP server (or this web server) is not responding – lower the timeouts, check the host/port and the server firewall.' : ((xhr.responseJSON && xhr.responseJSON.message) || ('The server returned HTTP ' + xhr.status + (xhr.status === 500 ? ' – PHP probably hit its execution time limit. Check logs/php-errors.log.' : '')));
        render({ success: false, headline: 'SMTP test did not complete', reason: msg, ms: secs * 1000 }, secs * 1000); });
  }
  \$('#btnTestSmtp, #btnTestSmtpTop').on('click', () => runTest('test_smtp'));
  \$('#btnCheckSmtp').on('click', () => runTest('check_smtp'));
  \$('#btnTestSmtpTop').on('click', () => { const t = document.querySelector('[data-bs-target="#tabStatus"]'); if (t) bootstrap.Tab.getOrCreateInstance(t).show(); });
  \$('#btnSaveAndTest').on('click', () => { const \$f = \$('#smtpSettingsForm'); \$f.data('callback', 'smtpSavedThenTest'); \$f.trigger('submit'); });
  window.smtpSavedThenTest = function (res, \$f) { \$f.removeData('callback'); CRM.toast(res.message || 'Saved'); const t = document.querySelector('[data-bs-target="#tabStatus"]'); if (t) bootstrap.Tab.getOrCreateInstance(t).show(); \$('#testUseForm').prop('checked', false); runTest('test_smtp'); };

  /* ---- email details ---- */
  \$(document).on('click', '.btn-view-email', function () {
    const \$b = \$(this);
    bootstrap.Modal.getOrCreateInstance(\$('#emailModal')[0]).show();
    \$('#emailModalBody').html('<div class="text-muted">Loading…</div>');
    CRM.post('api/email.php', { action: 'email_details', id: \$b.data('id'), src: \$b.data('src') }).done(function (res) {
      if (!res.success) { \$('#emailModalBody').html('<div class="text-danger">' + CRM.esc(res.message) + '</div>'); return; }
      const d = res.email, esc = s => CRM.esc(s == null || s === '' ? '—' : s);
      let h = '<dl class="dl-grid">';
      [['Client', d.tenant_name], ['To', d.to_email], ['CC', d.cc], ['BCC', d.bcc], ['From', d.from_email], ['Subject', d.subject], ['Type', d.category], ['Template', d.template], ['Status', d.status === 'failed' && d.error_kind_label ? d.error_kind_label : d.status], ['Attempt', d.attempt || d.attempts], ['Duration', d.duration_ms != null ? d.duration_ms + ' ms' : null], ['Message-ID', d.message_id], ['SMTP response', d.smtp_response], ['Error', d.error || d.last_error], ['Queued', d.created_at || d.queued_at], ['Sent / attempted', d.sent_at]].forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') h += '<dt>' + k + '</dt><dd>' + esc(String(v)) + '</dd>'; });
      h += '</dl>';
      if (d.history && d.history.length > 1) { h += '<div class="section-title mt-3">Delivery attempts</div><table class="table table-compact table-sm small"><thead><tr><th>#</th><th>When</th><th>Status</th><th>Duration</th><th>Error / response</th></tr></thead><tbody>'; d.history.forEach(a => { h += '<tr><td>' + a.attempt + '</td><td>' + esc(a.sent_at) + '</td><td>' + (a.status === 'sent' ? '<span class="text-success">sent</span>' : '<span class="text-danger">' + esc(a.error_kind || 'failed') + '</span>') + '</td><td>' + (a.duration_ms != null ? a.duration_ms + ' ms' : '—') + '</td><td>' + esc(a.error || a.smtp_response) + '</td></tr>'; }); h += '</tbody></table>'; }
      if (d.body) h += '<div class="section-title mt-3">Message</div><iframe style="width:100%;height:380px;border:1px solid var(--border);border-radius:8px;background:#fff" sandbox srcdoc="' + d.body.replace(/"/g, '&quot;') + '"></iframe>';
      \$('#emailModalBody').html(h);
    });
  });
  \$(document).on('click', '.btn-resend-log', function () {
    const \$b = \$(this); \$b.prop('disabled', true);
    CRM.post('api/email.php', { action: 'resend_log', id: \$b.data('id') }).done(res => { CRM.toast(res.message, res.success ? 'success' : 'error'); if (res.success) CRM.reloadTable(); }).always(() => \$b.prop('disabled', false));
  });
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
