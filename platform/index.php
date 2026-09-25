<?php
/** Super Admin dashboard – compact SaaS overview: platform KPIs, email service (real SMTP state), system status, attention items, recent audit activity. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;

$o = Cache::remember('platform:overview', 60, function () {
    $t = DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'pending') AS pending, SUM(status IN ('suspended','cancelled')) AS inactive, SUM(subscription_status = 'trial') AS trial, SUM(subscription_status IN ('active','trial')) AS subs, SUM(subscription_status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)) AS trial_ending, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_7d FROM tenants") ?: [];
    $w = DB::fetch("SELECT COUNT(*) AS total, SUM(monitoring_enabled = 1 AND status <> 'paused') AS active, SUM(status IN (" . down_statuses_sql() . ")) AS down, SUM(analytics_enabled = 1) AS tracked FROM websites") ?: [];
    $plans = (int) DB::value("SELECT COUNT(*) FROM plans WHERE status = 'active'");
    $pv = (int) DB::value("SELECT COALESCE(SUM(pageviews),0) FROM analytics_daily WHERE day >= ?", [date('Y-m-01')]);
    $jobs = DB::fetch("SELECT SUM(status = 'dead') AS dead, SUM(status = 'failed') AS failed, SUM(status = 'pending' AND available_at <= NOW()) AS due FROM jobs") ?: [];
    $users = (int) DB::value("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $expiring = (int) DB::value("SELECT COUNT(*) FROM tenants WHERE (subscription_status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)) OR subscription_status IN ('past_due','expired')");
    $f = DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'working') AS working, SUM(status = 'failed') AS failed, SUM(status = 'captcha_blocked') AS captcha, SUM(status = 'not_tested') AS untested FROM forms WHERE status <> 'removed'") ?: [];
    $byPlan = DB::fetchAll("SELECT p.name, COUNT(t.id) AS n FROM plans p LEFT JOIN tenants t ON t.plan_id = p.id GROUP BY p.id ORDER BY p.sort_order");
    $an = ['events_24h' => (int) DB::value("SELECT COUNT(*) FROM analytics_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'receiving' => (int) DB::value("SELECT COUNT(*) FROM websites WHERE analytics_enabled = 1 AND analytics_last_event_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")];
    return ['t' => array_map('intval', $t), 'w' => array_map('intval', $w), 'plans' => $plans, 'pv' => $pv, 'jobs' => array_map('intval', $jobs), 'users' => $users, 'expiring' => $expiring, 'forms' => array_map('intval', $f), 'by_plan' => $byPlan, 'an' => $an, 'at' => date('Y-m-d H:i:s')];
});
$email = Mailer::status();
$sched = Scheduler::state();
$recent = DB::fetchAll("SELECT a.id, a.action, a.description, a.target, a.result, a.created_at, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.is_platform = 1 ORDER BY a.id DESC LIMIT 10");
$newest = DB::fetchAll("SELECT t.id, t.name, t.status, t.subscription_status, t.created_at, p.name AS plan_name, u.email AS owner_email FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id LEFT JOIN users u ON u.id = t.owner_user_id ORDER BY t.id DESC LIMIT 6");
$t = $o['t']; $w = $o['w'];

$attention = [];
if ($t['pending']) $attention[] = ['warning', 'bi-person-exclamation', $t['pending'] . ' client' . ($t['pending'] > 1 ? 's' : '') . ' pending verification', 'platform/customers.php?status=pending'];
if ($t['trial_ending']) $attention[] = ['warning', 'bi-hourglass-split', $t['trial_ending'] . ' trial' . ($t['trial_ending'] > 1 ? 's' : '') . ' ending within 3 days', 'platform/subscriptions.php?ending=7'];
if ($w['down']) $attention[] = ['danger', 'bi-exclamation-octagon', $w['down'] . ' website' . ($w['down'] > 1 ? 's' : '') . ' down right now', 'platform/websites.php?status=down'];
if ($email['status'] === 'failed') $attention[] = ['danger', 'bi-envelope-x', 'SMTP failing: ' . ($email['last_error_label'] ?? 'error'), 'platform/emails.php'];
elseif ($email['status'] === 'incomplete') $attention[] = ['warning', 'bi-envelope-exclamation', 'SMTP configuration incomplete', 'platform/emails.php?tab=settings'];
elseif ($email['status'] === 'unknown') $attention[] = ['secondary', 'bi-envelope', 'SMTP has not been tested yet', 'platform/emails.php'];
if ($email['failed_24h']) $attention[] = ['danger', 'bi-envelope-x', $email['failed_24h'] . ' email' . ($email['failed_24h'] > 1 ? 's' : '') . ' failed in the last 24 h', 'platform/emails.php?tab=logs'];
if ($email['queue']['failed']) $attention[] = ['warning', 'bi-hourglass', $email['queue']['failed'] . ' email' . ($email['queue']['failed'] > 1 ? 's' : '') . ' gave up after ' . Mailer::MAX_ATTEMPTS . ' attempts', 'platform/emails.php?tab=queue'];
if (!$sched['healthy']) $attention[] = ['danger', 'bi-robot', 'Scheduler: ' . $sched['message'], 'platform/system.php'];
if ($o['jobs']['dead']) $attention[] = ['danger', 'bi-x-octagon', $o['jobs']['dead'] . ' dead-lettered job' . ($o['jobs']['dead'] > 1 ? 's' : ''), 'platform/system.php'];

$pageTitle = 'Dashboard';
$breadcrumbs = [['label' => 'Platform']];
$pageSubtitle = e(setting('platform_name', 'Outline Monitor')) . ' · ' . number_format($t['active']) . ' active clients · ' . number_format($w['total']) . ' websites · ' . number_format($o['users']) . ' users · updated ' . e(time_ago($o['at']));
$pageActions = '<a href="' . url('platform/customers.php') . '" class="btn btn-brand btn-sm"><i class="bi bi-buildings me-1"></i>Clients</a><a href="' . url('platform/websites.php') . '" class="btn btn-light btn-sm"><i class="bi bi-globe2 me-1"></i>Websites</a><a href="' . url('platform/emails.php') . '" class="btn btn-light btn-sm"><i class="bi bi-envelope-paper me-1"></i>SMTP / Email</a>';
include ROOT_PATH . '/includes/layout/header.php';
function pk(string $label, $v, string $icon, string $tint, string $link = '', ?string $sub = null): string {
    $inner = '<div class="stat-icon ' . $tint . '"><i class="bi ' . $icon . '"></i></div><div class="min-w-0"><div class="stat-value">' . (is_string($v) ? $v : number_format((int) $v)) . '</div><div class="stat-label">' . e($label) . '</div>' . ($sub ? '<div class="stat-trend text-muted">' . $sub . '</div>' : '') . '</div>';
    return $link ? '<a href="' . url($link) . '" class="stat-card">' . $inner . '</a>' : '<div class="stat-card">' . $inner . '</div>';
}
$emailLight = ['success' => '🟢', 'warning' => '🟠', 'danger' => '🔴', 'secondary' => '⚪'][$email['tone']] ?? '⚪';
?>
<?php include __DIR__ . '/maintenance-card.php'; ?>
<div class="section-title">Platform overview</div>
<div class="kpi-grid compact mb-3">
  <?= pk('Total clients', $t['total'], 'bi-buildings', 'tint-brand', 'platform/customers.php', $t['new_7d'] ? '+' . $t['new_7d'] . ' this week' : null) ?>
  <?= pk('Active clients', $t['active'], 'bi-building-check', 'tint-success', 'platform/customers.php?status=active', $t['pending'] ? $t['pending'] . ' pending' : null) ?>
  <?= pk('Suspended clients', $t['inactive'], 'bi-building-x', $t['inactive'] ? 'tint-warning' : 'tint-secondary', 'platform/customers.php?status=suspended', 'suspended + cancelled') ?>
  <?= pk('Total websites', $w['total'], 'bi-globe2', 'tint-dark', 'platform/websites.php') ?>
  <?= pk('Active websites', $w['active'], 'bi-broadcast', $w['down'] ? 'tint-danger' : 'tint-success', 'platform/websites.php?monitoring=1', $w['down'] ? '<span class="text-danger">' . $w['down'] . ' down</span>' : 'all reachable') ?>
  <?= pk('Active subscriptions', $t['subs'], 'bi-credit-card', 'tint-info', 'platform/subscriptions.php', $t['trial'] . ' on trial') ?>
  <?= pk('Expiring / overdue', $o['expiring'], 'bi-hourglass-split', $o['expiring'] ? 'tint-warning' : 'tint-secondary', 'platform/subscriptions.php?ending=7', 'trial ends ≤ 7 d, past due, expired') ?>
  <?= pk('Active plans', $o['plans'], 'bi-tags', 'tint-secondary', 'platform/plans.php') ?>
  <?= pk('Page views · month', $o['pv'], 'bi-bar-chart-line', 'tint-brand', 'platform/usage.php', $w['tracked'] . ' tracked sites') ?>
  <?= pk('System status', $sched['healthy'] ? 'OK' : 'Issue', 'bi-activity', $sched['healthy'] ? 'tint-success' : 'tint-danger', 'platform/system.php', e($sched['mode'] === 'none' ? 'no runner' : $sched['mode'])) ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="card h-100 scheduler-status border-<?= $email['tone'] ?>" id="smtpStatus" data-poll="<?= e(url('api/email.php?action=status')) ?>">
      <div class="card-header"><span><i class="bi bi-envelope-paper me-2"></i>Email service</span><a href="<?= url('platform/emails.php') ?>" class="small">Manage</a></div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-2">
          <div class="ss-light bg-<?= $email['tone'] ?>" data-f="light"></div>
          <div class="min-w-0 flex-grow-1"><div class="ss-headline text-<?= $email['tone'] ?>" data-f="headline"><?= $emailLight ?> <?= e($email['headline']) ?></div><div class="small text-muted text-truncate" data-f="detail" title="<?= e($email['detail']) ?>"><?= e($email['detail']) ?></div></div>
        </div>
        <div class="status-strip">
          <div><span class="l">Last checked</span><span class="v" data-f="last_check_ago"><?= $email['last_check_at'] ? e(time_ago($email['last_check_at'])) : 'never' ?></span></div>
          <div><span class="l">Last email</span><span class="v" data-f="last_email"><?= $email['last_email_sent_at'] ? 'Sent ' . e(time_ago($email['last_email_sent_at'])) : 'none sent yet' ?></span></div>
          <div><span class="l">Failed · 24 h</span><span class="v <?= $email['failed_24h'] ? 'text-danger' : '' ?>" data-f="failed_24h"><?= (int) $email['failed_24h'] ?></span></div>
          <div><span class="l">Queue</span><span class="v" data-f="queue"><?= (int) $email['queue']['pending'] ?> pending · <?= (int) $email['queue']['failed'] ?> failed</span></div>
        </div>
        <div class="small text-danger mt-2" data-f="error"><?= $email['status'] === 'failed' && $email['last_error'] ? '<i class="bi bi-exclamation-triangle me-1"></i>' . e($email['last_error_label'] . ': ' . $email['last_error']) : '' ?></div>
        <div class="d-flex gap-2 mt-2"><a href="<?= url('platform/emails.php') ?>" class="btn btn-brand btn-sm"><i class="bi bi-send-check me-1"></i>Test SMTP</a><a href="<?= url('platform/emails.php?tab=logs') ?>" class="btn btn-light btn-sm">Email logs</a></div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-exclamation-diamond me-2"></i>Needs attention</span><span class="small text-muted"><?= count($attention) ?> item<?= count($attention) === 1 ? '' : 's' ?></span></div>
      <div class="card-body">
        <?php if (!$attention): ?><div class="small text-success"><i class="bi bi-check-circle me-1"></i>Everything is in order – no pending clients, no failing emails, scheduler healthy.</div>
        <?php else: ?><ul class="mini-list"><?php foreach ($attention as [$tone, $icon, $text, $link]): ?><li><span class="ml-ico tint-<?= $tone ?>"><i class="bi <?= $icon ?>"></i></span><span class="ml-main"><a href="<?= url($link) ?>" class="ml-title"><?= e($text) ?></a></span><span class="ml-side"><i class="bi bi-chevron-right"></i></span></li><?php endforeach; ?></ul><?php endif; ?>
        <div class="section-title mt-3">Platform health</div>
        <div class="status-strip">
          <div><span class="l">Monitoring</span><span class="v <?= $sched['healthy'] ? 'text-success' : 'text-danger' ?>"><?= $sched['healthy'] ? 'Running' : 'Not running' ?> · <?= e($sched['mode'] === 'none' ? 'no runner' : $sched['mode']) ?></span><span class="small-xs text-muted"><?= number_format($o['jobs']['due']) ?> jobs due · <?= number_format($o['jobs']['dead']) ?> dead · <?= $w['down'] ?> site<?= $w['down'] === 1 ? '' : 's' ?> down</span></div>
          <div><span class="l">Forms</span><span class="v"><?= number_format($o['forms']['working']) ?> working · <span class="<?= $o['forms']['failed'] ? 'text-danger' : '' ?>"><?= number_format($o['forms']['failed']) ?> failed</span></span><span class="small-xs text-muted"><?= number_format($o['forms']['captcha']) ?> CAPTCHA-protected · <?= number_format($o['forms']['untested']) ?> not tested</span></div>
          <div><span class="l">Email service</span><span class="v text-<?= $email['tone'] ?>"><?= e($email['headline']) ?></span><span class="small-xs text-muted"><?= (int) $email['sent_24h'] ?> sent · <?= (int) $email['failed_24h'] ?> failed · 24 h</span></div>
          <div><span class="l">Analytics</span><span class="v"><?= number_format($o['an']['events_24h']) ?> events · 24 h</span><span class="small-xs text-muted"><?= number_format($o['an']['receiving']) ?> of <?= number_format($w['tracked']) ?> tracked sites receiving data</span></div>
          <div><span class="l">Registration</span><span class="v"><?= setting('registration_enabled', 1) ? 'open' : 'closed' ?><?= setting('maintenance_mode', 0) ? ' · <span class="text-danger">maintenance</span>' : '' ?></span><span class="small-xs text-muted">trial <?= (int) setting('trial_days', 14) ?> days</span></div>
        </div>
        <?php if ($o['by_plan']): ?><div class="section-title mt-3">Plan distribution</div><div class="d-flex flex-wrap gap-2"><?php foreach ($o['by_plan'] as $bp): ?><a href="<?= url('platform/customers.php?plan_id=') ?>" class="badge bg-secondary-subtle text-decoration-none"><?= e($bp['name']) ?> <b><?= (int) $bp['n'] ?></b></a><?php endforeach; ?></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-clipboard-data me-2"></i>Recent activity</span><a href="<?= url('platform/audit.php') ?>" class="small">Audit log</a></div>
      <div class="card-body">
        <?php if (!$recent): ?><div class="small text-muted">No Super Admin actions recorded yet.</div><?php else: ?>
        <ul class="mini-list"><?php foreach ($recent as $a): $res = $a['result'] ?: 'ok'; ?><li><span class="ml-ico"><i class="bi <?= ActivityLog::icon($a['action']) ?>"></i></span><span class="ml-main"><span class="ml-title" title="<?= e($a['description']) ?>"><?= e($a['description']) ?></span><span class="ml-sub"><?= e($a['user_name'] ?? 'System') ?> · <?= e(ActivityLog::label($a['action'])) ?><?= $a['target'] ? ' · ' . e($a['target']) : '' ?></span></span><span class="ml-side"><?= $res !== 'ok' ? '<span class="text-' . ($res === 'denied' ? 'warning' : 'danger') . '">' . e($res) . '</span><br>' : '' ?><?= e(time_ago($a['created_at'])) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-buildings me-2"></i>Newest clients</span><a href="<?= url('platform/customers.php') ?>" class="small">All clients</a></div>
      <div class="card-body">
        <?php if (!$newest): ?><div class="small text-muted">No clients yet.</div><?php else: ?>
        <ul class="mini-list"><?php foreach ($newest as $r): ?><li><span class="avatar avatar-sm"><?= e(strtoupper(mb_substr($r['name'], 0, 1))) ?></span><span class="ml-main"><a href="<?= url('platform/customer.php?id=' . $r['id']) ?>" class="ml-title"><?= e($r['name']) ?></a><span class="ml-sub"><?= e($r['owner_email'] ?? '—') ?> · <?= e($r['plan_name'] ?? '—') ?></span></span><span class="ml-side"><?= status_pill(['active' => 'success', 'pending' => 'warning', 'suspended' => 'danger', 'cancelled' => 'dark'][$r['status']] ?? 'secondary', ucfirst($r['status']), false) ?><br><?= e(time_ago($r['created_at'])) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const $c = $('#smtpStatus'); if (!$c.length) return;
  const lights = { success: '🟢', warning: '🟠', danger: '🔴', secondary: '⚪' };
  const poll = () => { if (document.visibilityState !== 'visible') return; $.getJSON($c.data('poll')).done(h => {
    if (!h.success) return;
    ['success', 'warning', 'danger', 'secondary'].forEach(t => { $c.removeClass('border-' + t); $c.find('[data-f=light]').removeClass('bg-' + t); $c.find('[data-f=headline]').removeClass('text-' + t); });
    $c.addClass('border-' + h.tone); $c.find('[data-f=light]').addClass('bg-' + h.tone); $c.find('[data-f=headline]').addClass('text-' + h.tone).text(lights[h.tone] + ' ' + h.headline);
    $c.find('[data-f=detail]').text(h.detail).attr('title', h.detail); $c.find('[data-f=last_check_ago]').text(h.last_check === '—' ? 'never' : h.last_check.replace(/^.*\((.*)\)$/, '$1'));
    $c.find('[data-f=last_email]').text(h.last_email_sent === 'never' ? 'none sent yet' : 'Sent ' + h.last_email_sent); $c.find('[data-f=failed_24h]').text(h.failed_24h).toggleClass('text-danger', h.failed_24h > 0);
    $c.find('[data-f=queue]').text(h.queue.pending + ' pending · ' + h.queue.failed + ' failed'); $c.find('[data-f=error]').html(h.status === 'failed' && h.last_error ? '<i class="bi bi-exclamation-triangle me-1"></i>' + CRM.esc(h.last_error) : '');
  }); };
  setInterval(poll, 30000);
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
