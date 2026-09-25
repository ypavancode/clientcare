<?php
/** Workspace settings: profile & alert recipients, alert thresholds, API keys, webhooks. (Platform settings live under /platform/settings) */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('settings');
$tid = Tenant::id();
$t = Tenant::current();
$plan = Tenant::plan();
$tab = in_array(get('tab'), ['workspace', 'alerts', 'api', 'webhooks'], true) ? get('tab') : 'workspace';
$ts = fn(string $k, $d = '') => e((string) Tenant::setting($k, $d));
$apiKeys = DB::fetchAll("SELECT k.*, u.name AS user_name FROM api_keys k LEFT JOIN users u ON u.id = k.user_id WHERE k.tenant_id = ? ORDER BY k.id DESC", [$tid]);
$hooks = DB::fetchAll("SELECT * FROM webhooks WHERE tenant_id = ? ORDER BY id DESC", [$tid]);
$apiLevel = (string) Tenant::feature('api');
$hasApi = $apiLevel !== '' && $apiLevel !== 'none' && $apiLevel !== '0';
$hasWebhooks = (bool) Tenant::feature('webhooks');
$newKey = $_SESSION['new_api_key'] ?? null; unset($_SESSION['new_api_key']);

$pageTitle = 'Settings';
$breadcrumbs = [['label' => 'Settings']];
$pageSubtitle = 'Workspace: <strong>' . e($t['name']) . '</strong> · plan <strong>' . e($plan['name']) . '</strong>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="card">
  <div class="card-header p-0 border-0"><ul class="nav nav-tabs px-2" role="tablist">
    <li class="nav-item"><button class="nav-link <?= $tab === 'workspace' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabWorkspace" type="button"><i class="bi bi-building me-1"></i>Workspace</button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabAlerts" type="button"><i class="bi bi-bell me-1"></i>Alerts</button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'api' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabApi" type="button"><i class="bi bi-key me-1"></i>API keys</button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'webhooks' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabWebhooks" type="button"><i class="bi bi-plug me-1"></i>Webhooks</button></li>
  </ul></div>
  <div class="card-body tab-content">
    <div class="tab-pane fade <?= $tab === 'workspace' ? 'show active' : '' ?>" id="tabWorkspace">
      <form class="ajax-form" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_workspace">
        <div class="row g-3" style="max-width:820px">
          <div class="col-md-6"><label class="form-label">Workspace / company name</label><input type="text" name="name" class="form-control" value="<?= e($t['name']) ?>" required></div>
          <div class="col-md-6"><label class="form-label">Timezone</label><select name="timezone" class="form-select"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= $tz ?>" <?= ($t['timezone'] ?: setting('timezone', 'Asia/Kolkata')) === $tz ? 'selected' : '' ?>><?= $tz ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Billing email</label><input type="email" name="billing_email" class="form-control" value="<?= e($t['billing_email'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($t['phone'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label">Extra alert recipients <span class="text-muted fw-normal">(comma separated)</span></label><input type="text" name="alert_emails" class="form-control" value="<?= e($t['alert_emails'] ?? '') ?>" placeholder="ops@company.com, support@company.com"><div class="form-text">Alerts always go to the owner, admins and notify-only members; add extra mailboxes here. Client contacts (Clients → notify client) receive the alerts for their own websites.</div></div>
          <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_clients" id="ncl" <?= Tenant::setting('notify_clients', 1) ? 'checked' : '' ?>><label class="form-check-label small" for="ncl">Email client contacts about their websites</label></div></div>
          <?php if (Tenant::feature('white_label')): ?>
          <div class="col-12"><div class="section-title">White label (Business / Agency)</div></div>
          <div class="col-md-6"><label class="form-label">Sender name on alert emails</label><input type="text" name="white_label_from_name" class="form-control" value="<?= e($t['white_label_from_name'] ?? '') ?>" placeholder="<?= e(Mailer::fromName()) ?>"><div class="form-text">The sender address stays <?= e(Mailer::fromEmail()) ?> (SPF / DKIM).</div></div>
          <div class="col-md-6"><label class="form-label">Brand name in the dashboard</label><input type="text" name="white_label_name" class="form-control" value="<?= e($t['white_label_name'] ?? '') ?>"></div>
          <?php endif; ?>
          <div class="col-12"><button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save workspace</button></div>
        </div>
      </form>
    </div>
    <div class="tab-pane fade <?= $tab === 'alerts' ? 'show active' : '' ?>" id="tabAlerts">
      <form class="ajax-form" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_workspace_alerts">
        <div class="row g-3" style="max-width:760px">
          <div class="col-12"><p class="small text-muted">Day thresholds at which an expiry alert email is sent (comma separated). One email per threshold, per certificate / domain / hosting.</p></div>
          <div class="col-md-6"><label class="form-label">SSL alert days</label><input type="text" name="ssl_alert_days" class="form-control" value="<?= $ts('ssl_alert_days', '30,15,7') ?>"></div>
          <div class="col-md-6"><label class="form-label">Show SSL as "Expiring Soon" within (days)</label><input type="number" name="ssl_warning_days" class="form-control" min="1" value="<?= $ts('ssl_warning_days', '30') ?>"></div>
          <div class="col-md-6"><label class="form-label">Domain alert days</label><input type="text" name="domain_alert_days" class="form-control" value="<?= $ts('domain_alert_days', '90,30,14,7,1') ?>"></div>
          <div class="col-md-6"><label class="form-label">Hosting alert days</label><input type="text" name="hosting_alert_days" class="form-control" value="<?= $ts('hosting_alert_days', '90,30,14,7,1') ?>"></div>
          <div class="col-12"><button class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save alert settings</button></div>
        </div>
      </form>
    </div>
    <div class="tab-pane fade <?= $tab === 'api' ? 'show active' : '' ?>" id="tabApi">
      <?php if (!$hasApi): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i>API access is available on the <strong>Professional</strong> plan and above. <a href="<?= url('billing/index.php') ?>">Upgrade</a> to create API keys.</div><?php endif; ?>
      <?php if ($newKey): ?><div class="alert alert-success"><strong>New API key created.</strong> Copy it now – it is shown only once:<div class="input-group mt-2"><input type="text" class="form-control mono" readonly value="<?= e($newKey) ?>" onclick="this.select()"><button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= e($newKey) ?>')">Copy</button></div></div><?php endif; ?>
      <p class="small text-muted">Base URL: <code><?= e(url('api/v1')) ?></code> · Header: <code>Authorization: Bearer &lt;key&gt;</code> · Endpoints: <code>GET /websites</code>, <code>GET /websites/{id}</code>, <code>POST /websites</code>, <code>POST /websites/{id}/check</code>, <code>POST /websites/{id}/pause</code>, <code>POST /websites/{id}/resume</code>, <code>GET /forms</code>, <code>GET /incidents</code>, <code>GET /ssl</code>, <code>GET /domains</code>, <code>GET /hosting</code>, <code>GET /status</code>. Rate limit: <?= (int) setting('api_rate_limit_per_min', 60) ?> requests / minute per key.</p>
      <?php if ($hasApi): ?>
      <form class="ajax-form row g-2 mb-3" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate style="max-width:700px">
        <?= csrf_field() ?><input type="hidden" name="action" value="api_key_create">
        <div class="col-md-5"><input type="text" name="name" class="form-control" placeholder="Key name (e.g. Zapier)" required></div>
        <div class="col-md-4"><select name="scopes" class="form-select"><option value="read">Read only</option><option value="read,write">Read + write (create websites, trigger checks)</option></select></div>
        <div class="col-md-3"><button class="btn btn-dark w-100"><i class="bi bi-plus-lg me-1"></i>Create key</button></div>
      </form>
      <?php endif; ?>
      <div class="table-responsive"><table class="table table-compact mb-0 small">
        <thead><tr><th>Name</th><th>Key</th><th>Scopes</th><th>Created by</th><th>Last used</th><th>Requests</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($apiKeys as $k): ?><tr><td><?= e($k['name']) ?></td><td class="mono"><?= e($k['key_prefix']) ?>…</td><td><?= e($k['scopes']) ?></td><td><?= e($k['user_name'] ?? '—') ?></td><td><?= e(time_ago($k['last_used_at'])) ?></td><td><?= number_format((int) $k['request_count']) ?></td><td><?= status_pill($k['status'] === 'active' ? 'success' : 'secondary', ucfirst($k['status']), false) ?></td>
          <td class="text-end"><?php if ($k['status'] === 'active'): ?><button class="btn btn-light btn-sm text-danger btn-action" data-url="api/settings.php" data-params='{"action":"api_key_revoke","id":<?= $k['id'] ?>}' data-confirm="Revoke this API key?" data-confirm-btn="Revoke" data-reload="1"><i class="bi bi-x-circle"></i></button><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$apiKeys): ?><tr><td colspan="8" class="text-center text-muted py-3">No API keys yet</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
    <div class="tab-pane fade <?= $tab === 'webhooks' ? 'show active' : '' ?>" id="tabWebhooks">
      <?php if (!$hasWebhooks): ?><div class="alert alert-light border"><i class="bi bi-lock me-1"></i>Webhooks are available on the <strong>Business</strong> and <strong>Agency</strong> plans. <a href="<?= url('billing/index.php') ?>">Upgrade</a> to receive events in your own systems.</div><?php endif; ?>
      <p class="small text-muted">Events: <code><?= implode('</code> <code>', Webhooks::EVENTS) ?></code>. Each delivery is a JSON POST signed with <code>X-Webhook-Signature: sha256=HMAC(body, secret)</code>; 3 attempts.</p>
      <?php if ($hasWebhooks): ?>
      <form class="ajax-form row g-2 mb-3" action="<?= url('api/settings.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="webhook_save">
        <div class="col-md-3"><input type="text" name="name" class="form-control" placeholder="Name" required></div>
        <div class="col-md-5"><input type="url" name="url" class="form-control" placeholder="https://hooks.example.com/monitor" required></div>
        <div class="col-md-2"><input type="text" name="secret" class="form-control" placeholder="Secret (optional)"></div>
        <div class="col-md-2"><button class="btn btn-dark w-100"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
        <div class="col-12 small text-muted">Events (leave all unchecked = every event): <?php foreach (Webhooks::EVENTS as $ev): ?><label class="me-2"><input type="checkbox" name="events[]" value="<?= $ev ?>"> <?= $ev ?></label><?php endforeach; ?></div>
      </form>
      <?php endif; ?>
      <div class="table-responsive"><table class="table table-compact mb-0 small">
        <thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Status</th><th>Last delivery</th><th></th></tr></thead>
        <tbody><?php foreach ($hooks as $h): $ev = json_decode((string) $h['events'], true) ?: []; ?><tr><td><?= e($h['name']) ?></td><td class="mono"><?= e(truncate($h['url'], 60)) ?></td><td><?= $ev ? e(implode(', ', $ev)) : 'all' ?></td><td><?= status_pill(['active' => 'success', 'paused' => 'secondary', 'failed' => 'danger'][$h['status']] ?? 'secondary', ucfirst($h['status']), false) ?><?= $h['failures'] ? ' <span class="text-danger small-xs">' . (int) $h['failures'] . ' failures</span>' : '' ?></td><td><?= e(time_ago($h['last_delivered_at'])) ?><?= $h['last_status_code'] ? ' <span class="text-muted">(HTTP ' . (int) $h['last_status_code'] . ')</span>' : '' ?></td>
          <td class="text-end text-nowrap"><button class="btn btn-light btn-sm btn-action" data-url="api/settings.php" data-params='{"action":"webhook_test","id":<?= $h['id'] ?>}' title="Send test"><i class="bi bi-send"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/settings.php" data-params='{"action":"webhook_toggle","id":<?= $h['id'] ?>}' data-reload="1" title="<?= $h['status'] === 'active' ? 'Pause' : 'Activate' ?>"><i class="bi <?= $h['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/settings.php" data-params='{"action":"webhook_delete","id":<?= $h['id'] ?>}' data-confirm="Delete this webhook?" data-confirm-btn="Delete" data-reload="1"><i class="bi bi-trash"></i></button></td></tr><?php endforeach; ?>
        <?php if (!$hooks): ?><tr><td colspan="6" class="text-center text-muted py-3">No webhooks configured</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';