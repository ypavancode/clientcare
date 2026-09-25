<?php
/** Super Admin → Client detail: account, plan & subscription, usage vs limits, websites (with edit / enable / delete), members, analytics summary, audit trail. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$id = (int) get('id', 0);
$t = DB::fetch("SELECT t.*, p.name AS plan_name, p.price_monthly, p.max_pageviews_month FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ?", [$id]);
if (!$t) http_error(404, 'Client not found.');
$plans = DB::fetchAll("SELECT * FROM plans ORDER BY sort_order");
$users = DB::fetchAll("SELECT id, name, email, role, status, email_verified_at, last_login_at FROM users WHERE tenant_id = ? ORDER BY FIELD(role,'owner','admin','manager','staff','viewer','notify'), name", [$id]);
$subs = DB::fetchAll("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 10", [$id]);
$sites = DB::fetchAll("SELECT w.id, w.name, w.url, w.status, w.monitoring_enabled, w.analytics_enabled, w.analytics_key, w.analytics_last_event_at, w.forms_total, w.pages_total, w.last_checked_at, c.name AS client_name FROM websites w LEFT JOIN clients c ON c.id = w.client_id WHERE w.tenant_id = ? ORDER BY w.name LIMIT 300", [$id]);
$usage = Tenant::usageSummary($id);
$pv = DB::fetch("SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS v FROM analytics_daily WHERE tenant_id = ? AND day >= ?", [$id, date('Y-m-01')]) ?: ['pv' => 0, 'v' => 0];
$emails = DB::fetch("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM email_logs WHERE tenant_id = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$id]) ?: ['sent' => 0, 'failed' => 0];
$activity = DB::fetchAll("SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 20", [$id]);
$trialDays = $t['subscription_status'] === 'trial' && $t['trial_ends_at'] ? (int) ceil((strtotime($t['trial_ends_at']) - time()) / 86400) : null;
$stCls = ['active' => 'success', 'pending' => 'warning', 'suspended' => 'danger', 'cancelled' => 'dark'][$t['status']] ?? 'secondary';
$subCls = ['trial' => 'warning', 'active' => 'success', 'free' => 'secondary', 'past_due' => 'danger', 'cancelled' => 'dark', 'expired' => 'danger'][$t['subscription_status']] ?? 'secondary';

$pageTitle = $t['name'];
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Clients', 'url' => 'platform/customers.php'], ['label' => $t['name']]];
$pageSubtitle = status_pill($stCls, ucfirst($t['status']), false) . ' ' . status_pill($subCls, strtoupper(str_replace('_', ' ', $t['subscription_status'])), false) . ' · workspace #' . $id . ' · <span class="mono">' . e($t['slug']) . '</span> · registered ' . format_datetime($t['created_at']) . ' · last active ' . e(time_ago($t['last_active_at']));
$pageActions = '<button class="btn btn-dark btn-sm btn-edit-tenant" data-id="' . $id . '"><i class="bi bi-pencil me-1"></i>Edit</button>'
    . ($t['status'] === 'active' ? ($id !== 1 ? '<button class="btn btn-light btn-sm text-warning btn-action" data-url="api/platform.php" data-params=\'{"action":"set_status","tenant_id":' . $id . ',"status":"suspended"}\' data-confirm="Deactivate ' . e($t['name']) . '? Its users cannot sign in and monitoring pauses." data-confirm-btn="Deactivate" data-reload="1"><i class="bi bi-pause-circle me-1"></i>Deactivate</button>' : '') : '<button class="btn btn-light btn-sm text-success btn-action" data-url="api/platform.php" data-params=\'{"action":"set_status","tenant_id":' . $id . ',"status":"active"}\' data-confirm="Activate ' . e($t['name']) . '?" data-icon="question" data-confirm-btn="Activate" data-reload="1"><i class="bi bi-play-circle me-1"></i>Activate</button>')
    . '<button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params=\'{"action":"act_as","tenant_id":' . $id . '}\'><i class="bi bi-box-arrow-in-right me-1"></i>View as client</button>'
    . ($id !== 1 ? '<button class="btn btn-light btn-sm text-danger btn-delete-tenant" data-id="' . $id . '" data-name="' . e($t['name']) . '" data-slug="' . e($t['slug']) . '"><i class="bi bi-trash me-1"></i>Delete</button>' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="kpi-grid compact mb-3">
  <div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-tags"></i></div><div class="min-w-0"><div class="stat-value"><?= e($t['plan_name'] ?? '—') ?></div><div class="stat-label"><?= $t['price_monthly'] === null ? 'custom pricing' : '₹' . number_format((int) $t['price_monthly']) . ' / month' ?></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-<?= $subCls === 'dark' ? 'secondary' : $subCls ?>"><i class="bi bi-credit-card"></i></div><div class="min-w-0"><div class="stat-value"><?= e(ucfirst(str_replace('_', ' ', $t['subscription_status']))) ?></div><div class="stat-label"><?= $trialDays !== null ? 'trial ends ' . format_date($t['trial_ends_at']) . ' (' . max(0, $trialDays) . ' d)' : 'subscription' ?></div></div></div>
  <div class="stat-card"><div class="stat-icon tint-dark"><i class="bi bi-globe2"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($usage['websites']['current']) ?><?= $usage['websites']['limit'] !== null ? ' <span class="text-muted small">/ ' . number_format($usage['websites']['limit']) . '</span>' : '' ?></div><div class="stat-label">websites</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-info"><i class="bi bi-bar-chart-line"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format((int) $pv['pv']) ?><?= $t['max_pageviews_month'] !== null ? ' <span class="text-muted small">/ ' . number_format((int) $t['max_pageviews_month']) . '</span>' : '' ?></div><div class="stat-label">page views this month · <?= number_format((int) $pv['v']) ?> visitors</div></div></div>
  <div class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-people"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format($usage['users']['current']) ?><?= $usage['users']['limit'] !== null ? ' <span class="text-muted small">/ ' . number_format($usage['users']['limit']) . '</span>' : '' ?></div><div class="stat-label">team members</div></div></div>
  <div class="stat-card"><div class="stat-icon <?= $emails['failed'] ? 'tint-danger' : 'tint-secondary' ?>"><i class="bi bi-envelope"></i></div><div class="min-w-0"><div class="stat-value"><?= number_format((int) $emails['sent']) ?></div><div class="stat-label">emails · 30 d<?= $emails['failed'] ? ' · <span class="text-danger">' . (int) $emails['failed'] . ' failed</span>' : '' ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header">Account</div><div class="card-body">
      <dl class="dl-grid mb-0">
        <dt>Owner</dt><dd><?php $owner = array_values(array_filter($users, fn($u) => $u['role'] === 'owner'))[0] ?? null; ?><?= $owner ? e($owner['name']) . '<div class="small-xs text-muted">' . e($owner['email']) . '</div>' : '—' ?></dd>
        <dt>Billing email</dt><dd><?= e($t['billing_email'] ?: '—') ?></dd>
        <dt>Phone</dt><dd><?= e($t['phone'] ?: '—') ?></dd>
        <dt>Country / TZ</dt><dd><?= e($t['country'] ?: '—') ?> · <?= e($t['timezone'] ?: 'default') ?></dd>
        <dt>Alert emails</dt><dd><?= e($t['alert_emails'] ?: '—') ?></dd>
        <dt>White label</dt><dd><?= e($t['white_label_name'] ?: '—') ?></dd>
        <dt>Notes</dt><dd><?= $t['notes'] ? nl2br(e($t['notes'])) : '—' ?></dd>
      </dl>
    </div></div>
    <div class="card mb-3"><div class="card-header">Usage vs plan limits</div><div class="card-body">
      <?php foreach ($usage as $u): ?><div class="usage-row"><span class="u-lbl"><?= e($u['label']) ?></span><div class="progress"><div class="progress-bar <?= $u['state'] === 'full' ? 'bg-danger' : ($u['state'] === 'warn' ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $u['limit'] === null ? 4 : max(2, $u['pct']) ?>%"></div></div><span class="u-val"><?= number_format($u['current']) ?> / <?= $u['limit'] === null ? '∞' : number_format($u['limit']) ?></span></div><?php endforeach; ?>
      <div class="usage-row"><span class="u-lbl">Page views</span><div class="progress"><div class="progress-bar <?= $t['max_pageviews_month'] && $pv['pv'] >= $t['max_pageviews_month'] ? 'bg-danger' : 'bg-success' ?>" style="width:<?= $t['max_pageviews_month'] ? max(2, min(100, (int) round($pv['pv'] / max(1, $t['max_pageviews_month']) * 100))) : 4 ?>%"></div></div><span class="u-val"><?= number_format((int) $pv['pv']) ?> / <?= $t['max_pageviews_month'] !== null ? number_format((int) $t['max_pageviews_month']) : '∞' ?></span></div>
      <div class="small-xs text-muted mt-2">Full breakdown: <a href="<?= url('platform/usage.php') ?>">Usage</a></div>
    </div></div>
    <div class="card mb-3"><div class="card-header">Plan &amp; subscription</div><div class="card-body">
      <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="set_plan"><input type="hidden" name="tenant_id" value="<?= $id ?>">
        <div class="mb-2"><label class="form-label">Plan</label><select name="plan_id" class="form-select form-select-sm"><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= (int) $t['plan_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= $p['price_monthly'] === null ? 'custom' : '₹' . $p['price_monthly'] ?>)</option><?php endforeach; ?></select></div>
        <div class="row g-2 mb-2"><div class="col-6"><select name="mode" class="form-select form-select-sm"><option value="active">Active (paid / manual)</option><option value="trial">Trial</option></select></div><div class="col-6"><input type="number" name="trial_days" class="form-control form-control-sm" value="14" min="1" max="365" placeholder="trial days"></div></div>
        <div class="mb-2"><input type="text" name="note" class="form-control form-control-sm" placeholder="Note (invoice #, payment ref)"></div>
        <button class="btn btn-dark btn-sm w-100"><i class="bi bi-check2 me-1"></i>Apply plan</button>
      </form>
      <hr class="my-2">
      <form class="ajax-form row g-2" action="<?= url('api/platform.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="extend_trial"><input type="hidden" name="tenant_id" value="<?= $id ?>">
        <div class="col-6"><input type="number" name="days" class="form-control form-control-sm" value="14" min="1" max="365"></div><div class="col-6"><button class="btn btn-light btn-sm w-100">Extend trial</button></div>
      </form>
      <hr class="my-2">
      <form class="ajax-form row g-2" action="<?= url('api/platform.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="set_subscription_status"><input type="hidden" name="tenant_id" value="<?= $id ?>">
        <div class="col-6"><select name="status" class="form-select form-select-sm"><?php foreach (['active', 'trial', 'past_due', 'expired', 'cancelled', 'free'] as $s): ?><option value="<?= $s ?>" <?= $t['subscription_status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div><div class="col-6"><button class="btn btn-light btn-sm w-100">Set status</button></div>
      </form>
      <?php if ($t['status'] === 'pending'): ?><hr class="my-2"><button class="btn btn-light btn-sm w-100 btn-action" data-url="api/platform.php" data-params='{"action":"verify_owner","tenant_id":<?= $id ?>}' data-reload="1"><i class="bi bi-patch-check me-1"></i>Mark owner verified &amp; activate</button><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header"><span>Websites (<?= count($sites) ?>)</span><a href="<?= url('platform/websites.php?tenant_id=' . $id) ?>" class="small">All websites</a></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-compact table-hover mb-0 small"><thead><tr><th>Website</th><th>Status</th><th>Monitoring</th><th>Tracking</th><th>Last check</th><th class="text-end"></th></tr></thead><tbody>
      <?php foreach ($sites as $w): ?><tr data-row-id="<?= $w['id'] ?>"><td><span class="fw-500"><?= e($w['name']) ?></span><div class="small-xs text-muted"><?= e(host_from_url($w['url'])) ?> · <?= e($w['client_name'] ?? '—') ?> · <?= (int) $w['pages_total'] ?> pages · <?= (int) $w['forms_total'] ?> forms</div></td><td><?= website_status_badge($w['status']) ?></td><td><?= $w['monitoring_enabled'] ? status_pill('success', 'Enabled', false) : status_pill('secondary', 'Disabled', false) ?></td>
        <td><?= !$w['analytics_key'] ? '<span class="text-muted">—</span>' : ($w['analytics_enabled'] ? ($w['analytics_last_event_at'] && strtotime($w['analytics_last_event_at']) > time() - 7 * 86400 ? '<span class="text-success">receiving</span>' : '<span class="text-warning">no data</span>') : '<span class="text-muted">paused</span>') ?></td><td class="text-muted"><?= e(time_ago($w['last_checked_at'])) ?></td>
        <td class="row-actions text-end text-nowrap"><button class="btn btn-light btn-sm btn-edit-website" data-id="<?= $w['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button> <?= $w['monitoring_enabled'] ? '<button class="btn btn-light btn-sm text-warning btn-action" data-url="api/platform.php" data-params=\'{"action":"website_toggle","id":' . $w['id'] . ',"enable":0}\' data-confirm="Disable monitoring for ' . e($w['name']) . '?" data-confirm-btn="Disable" data-reload="1" title="Disable"><i class="bi bi-pause-circle"></i></button>' : '<button class="btn btn-light btn-sm text-success btn-action" data-url="api/platform.php" data-params=\'{"action":"website_toggle","id":' . $w['id'] . ',"enable":1}\' data-reload="1" title="Enable"><i class="bi bi-play-circle"></i></button>' ?> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/platform.php" data-params='{"action":"website_delete","id":<?= $w['id'] ?>}' data-confirm="Delete <?= e($w['name']) ?> with all its pages, forms, incidents and analytics? This cannot be undone." data-confirm-btn="Delete website" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button></td></tr><?php endforeach; ?>
      <?php if (!$sites): ?><tr><td colspan="6" class="text-center text-muted py-3">No websites yet</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
    <div class="card mb-3"><div class="card-header"><span>Members (<?= count($users) ?>)</span><a href="<?= url('platform/users.php?tenant_id=' . $id) ?>" class="small">Manage users</a></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0 small"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Verified</th><th>Last login</th></tr></thead><tbody>
      <?php foreach ($users as $u): ?><tr><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e(Registration::roleLabel($u['role'])) ?></td><td><?= e($u['status']) ?></td><td><?= $u['email_verified_at'] ? '<i class="bi bi-check-circle text-success"></i>' : '<span class="text-warning">no</span>' ?></td><td class="text-muted"><?= e(time_ago($u['last_login_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <div class="row g-3">
      <div class="col-xl-6"><div class="card h-100"><div class="card-header">Subscription history</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0 small"><thead><tr><th>Plan</th><th>Status</th><th>Started</th><th>Trial ends</th><th>Notes</th></tr></thead><tbody>
        <?php foreach ($subs as $s): ?><tr><td><?= e($s['plan_name']) ?></td><td><?= e($s['status']) ?></td><td><?= format_date($s['started_at']) ?></td><td><?= $s['trial_ends_at'] ? format_date($s['trial_ends_at']) : '—' ?></td><td class="text-muted"><?= e(truncate($s['notes'] ?? '', 50)) ?></td></tr><?php endforeach; ?>
        <?php if (!$subs): ?><tr><td colspan="5" class="text-center text-muted py-3">No subscription records</td></tr><?php endif; ?>
      </tbody></table></div></div></div></div>
      <div class="col-xl-6"><div class="card h-100"><div class="card-header"><span>Recent activity</span><a href="<?= url('platform/audit.php?tenant=' . $id) ?>" class="small">Audit log</a></div><div class="card-body">
        <?php if (!$activity): ?><div class="text-muted small">No activity yet</div><?php else: ?><ul class="mini-list"><?php foreach ($activity as $a): ?><li><span class="ml-ico"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><span class="ml-main"><span class="ml-title" title="<?= e($a['description']) ?>"><?= e($a['description']) ?></span><span class="ml-sub"><?= e($a['user_name'] ?? 'System') ?> · <?= format_datetime($a['created_at']) ?></span></span></li><?php endforeach; ?></ul><?php endif; ?>
      </div></div></div>
    </div>
  </div>
</div>
<?php include ROOT_PATH . '/includes/partials/tenant-modal.php'; include ROOT_PATH . '/includes/partials/platform-website-modal.php'; ?>
<?php include ROOT_PATH . '/includes/layout/footer.php';
