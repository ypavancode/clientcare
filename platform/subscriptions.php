<?php
/** Super Admin → Subscriptions: plan, status, trial / renewal dates and billing contact per client; change plan, extend trial, set status, history. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$plans = DB::fetchAll("SELECT * FROM plans ORDER BY sort_order, id");
$c = Cache::remember('platform:subs:counts', 60, fn() => DB::fetch("SELECT SUM(subscription_status = 'active') AS active, SUM(subscription_status = 'trial') AS trial, SUM(subscription_status = 'free') AS free, SUM(subscription_status IN ('past_due','expired')) AS overdue, SUM(subscription_status = 'cancelled') AS cancelled, SUM(subscription_status = 'trial' AND trial_ends_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)) AS ending FROM tenants") ?: []);
$mrr = (int) DB::value("SELECT COALESCE(SUM(p.price_monthly),0) FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.subscription_status = 'active' AND p.price_monthly > 0");
$pageTitle = 'Subscriptions';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Subscriptions']];
$pageSubtitle = number_format((int) $c['active']) . ' paid · ' . number_format((int) $c['trial']) . ' trial (' . number_format((int) $c['ending']) . ' ending within 7 days) · ' . number_format((int) $c['free']) . ' free · ' . number_format((int) $c['overdue']) . ' past due / expired · ' . number_format((int) $c['cancelled']) . ' cancelled · MRR ₹' . number_format($mrr) . ' · no payment gateway: paid plans are activated manually';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="sub" class="form-select form-select-sm"><option value="">Any status</option><?php foreach (['active', 'trial', 'free', 'past_due', 'expired', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= get('sub') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select>
  <select name="plan_id" class="form-select form-select-sm"><option value="">Any plan</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= (int) get('plan_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <select name="ending" class="form-select form-select-sm"><option value="">Any trial end</option><option value="7" <?= get('ending') === '7' ? 'selected' : '' ?>>Trial ends within 7 days</option></select>
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_subscriptions" data-filters="#filters" data-order='[]' data-page-length="25" data-empty="No subscriptions match" data-empty-icon="bi-credit-card">
    <thead><tr><th>Client</th><th>Plan</th><th>Price</th><th>Status</th><th>Started</th><th>Trial ends / renews</th><th>Billing email</th><th class="no-sort text-end">Actions</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<div class="modal fade" id="planChangeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="table" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="set_plan"><input type="hidden" name="tenant_id" value="">
    <div class="modal-header"><h5 class="modal-title">Change plan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Plan</label><select name="plan_id" class="form-select"><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= $p['price_monthly'] === null ? 'custom' : '₹' . number_format((int) $p['price_monthly']) . '/mo' ?>)<?= $p['status'] !== 'active' ? ' – inactive' : '' ?></option><?php endforeach; ?></select></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label">Mode</label><select name="mode" class="form-select"><option value="active">Active (paid / manual)</option><option value="trial">Trial</option></select></div><div class="col-6"><label class="form-label">Trial days</label><input type="number" name="trial_days" class="form-control" value="14" min="1" max="365"></div></div>
      <div><label class="form-label">Note</label><input type="text" name="note" class="form-control" placeholder="Invoice #, payment reference…"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Apply plan</button></div>
  </form>
</div></div></div>
<div class="modal fade" id="subStatusModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form class="ajax-form" action="<?= url('api/platform.php') ?>" data-reload="table" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="set_subscription_status"><input type="hidden" name="tenant_id" value="">
    <div class="modal-header"><h5 class="modal-title">Subscription status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['active' => 'Active (paid)', 'trial' => 'Trial', 'past_due' => 'Past due (payment pending)', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'free' => 'Free'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="mb-2"><label class="form-label">Note</label><input type="text" name="note" class="form-control" placeholder="Reason / reference"></div>
      <div class="form-text">Expired, past-due and cancelled subscriptions fall back to the free limits (Tenant::plan). Use "Extend trial" on the client page to extend a trial.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-dark">Save status</button></div>
  </form>
</div></div></div>
<div class="modal fade" id="subHistoryModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Subscription history</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="subHistoryBody">Loading…</div>
</div></div></div>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-change-plan', function () { const f = $('#planChangeModal form')[0]; f.reset(); f.elements['tenant_id'].value = $(this).data('id'); f.elements['plan_id'].value = $(this).data('plan'); $('#planChangeModal .modal-title').text('Change plan – ' + $(this).data('name')); bootstrap.Modal.getOrCreateInstance($('#planChangeModal')[0]).show(); });
$(document).on('click', '.btn-sub-status', function () { const f = $('#subStatusModal form')[0]; f.reset(); f.elements['tenant_id'].value = $(this).data('id'); f.elements['status'].value = $(this).data('status'); $('#subStatusModal .modal-title').text('Subscription status – ' + $(this).data('name')); bootstrap.Modal.getOrCreateInstance($('#subStatusModal')[0]).show(); });
$(document).on('click', '.btn-sub-history', function () {
  bootstrap.Modal.getOrCreateInstance($('#subHistoryModal')[0]).show(); $('#subHistoryBody').html('<div class="text-muted">Loading…</div>');
  CRM.post('api/platform.php', { action: 'subscription_history', tenant_id: $(this).data('id') }).done(res => {
    if (!res.success) { $('#subHistoryBody').html('<div class="text-danger">' + CRM.esc(res.message) + '</div>'); return; }
    $('#subHistoryModal .modal-title').text('Subscription history – ' + res.tenant.name);
    if (!res.rows.length) { $('#subHistoryBody').html('<div class="text-muted small">No subscription records yet.</div>'); return; }
    let h = '<table class="table table-compact table-sm small"><thead><tr><th>Plan</th><th>Status</th><th>Started</th><th>Trial ends</th><th>Ended</th><th>Amount</th><th>Notes</th></tr></thead><tbody>';
    res.rows.forEach(r => { h += '<tr><td>' + CRM.esc(r.plan_name) + '</td><td>' + CRM.esc(r.status) + '</td><td>' + CRM.esc(r.started_at || '—') + '</td><td>' + CRM.esc(r.trial_ends_at || '—') + '</td><td>' + CRM.esc(r.ended_at || '—') + '</td><td>' + (r.amount ? '₹' + r.amount : '—') + '</td><td class="text-muted">' + CRM.esc(r.notes || '') + '</td></tr>'; });
    $('#subHistoryBody').html(h + '</tbody></table>');
  });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
