<?php
/** Billing & plan: current subscription, usage, plan comparison and (payment-free) plan changes / trials. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
if (!Auth::isOwner() && !Auth::isAdmin()) http_error(403, 'Only the workspace owner or an admin can manage billing.');
$tenant = Tenant::current();
$plan = Tenant::plan();
$plans = Tenant::allPlans(true);
$usage = Tenant::usageSummary();
$trialDays = Tenant::trialDaysLeft();
$subs = DB::fetchAll("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 20", [Tenant::id()]);
$usedTrials = array_column(array_filter($subs, fn($s) => $s['status'] !== 'active' || $s['trial_ends_at']), 'plan_id');
$upgrade = get('upgrade', '');

$pageTitle = 'Billing & Plan';
$breadcrumbs = [['label' => 'Billing & Plan']];
$pageSubtitle = 'Current plan: <strong>' . e($plan['name']) . '</strong> · ' . e(ucfirst(str_replace('_', ' ', $tenant['subscription_status'] ?? 'free'))) . ($trialDays !== null ? ' · ' . max(0, $trialDays) . ' trial day(s) left' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<?php if ($upgrade): ?><div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Select the <strong><?= e(ucfirst($upgrade)) ?></strong> plan below to continue.</div><?php endif; ?>
<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-credit-card me-1"></i>Current subscription</span></div>
      <div class="card-body">
        <div class="d-flex align-items-baseline gap-2 mb-1"><span class="fs-3 fw-700"><?= e($plan['name']) ?></span><span class="text-muted"><?= $plan['price_monthly'] === null ? 'custom pricing' : ($plan['price_monthly'] == 0 ? 'free' : '₹' . number_format((int) $plan['price_monthly']) . ' / month') ?></span></div>
        <div class="mb-2"><?= status_pill(['trial' => 'warning', 'active' => 'success', 'free' => 'secondary', 'past_due' => 'danger', 'cancelled' => 'dark', 'expired' => 'danger'][$tenant['subscription_status']] ?? 'secondary', strtoupper(str_replace('_', ' ', $tenant['subscription_status'])), false) ?>
          <?php if ($trialDays !== null): ?> <span class="small text-muted">trial ends <?= format_date($tenant['trial_ends_at']) ?> (<?= max(0, $trialDays) ?> days)</span><?php endif; ?></div>
        <?php if (!empty($plan['expired_from'])): ?><div class="alert alert-warning py-2 small">Your <?= e($plan['expired_from']) ?> trial has ended. The workspace now uses the Free plan limits – existing websites and history are kept.</div><?php endif; ?>
        <dl class="dl-grid mb-3">
          <dt>Workspace</dt><dd><?= e($tenant['name']) ?></dd>
          <dt>Billing email</dt><dd><?= e($tenant['billing_email'] ?: Auth::user()['email']) ?></dd>
          <dt>Member since</dt><dd><?= format_date($tenant['created_at']) ?></dd>
          <dt>Payments</dt><dd><span class="text-muted">Online payments are being enabled. Until then every plan can be started as a free trial and upgrades are activated by our team – <a href="<?= url('public/contact-sales.php') ?>">contact us</a>.</span></dd>
        </dl>
        <div class="section-title">Usage</div>
        <div class="row g-3"><?php foreach ($usage as $k => $u): if ($k === 'status_pages' && $u['limit'] === 0) continue; ?><div class="col-6"><?= usage_bar($u) ?></div><?php endforeach; ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><span><i class="bi bi-list-check me-1"></i>What your plan includes</span></div>
      <div class="card-body">
        <ul class="small mb-0"><?php foreach ($plan['highlights'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
        <div class="small text-muted mt-3">Monitoring: websites every <?= (int) $plan['website_interval'] ?> min · pages every <?= (int) $plan['page_interval'] ?> min · forms every <?= (int) $plan['form_interval'] ?> min · SSL every <?= (int) $plan['ssl_interval'] ?> min · data retention <?= (int) $plan['retention_days'] ?> days</div>
      </div>
    </div>
  </div>
</div>

<h2 class="h5 fw-bold mb-2">Change plan</h2>
<div class="row g-3 mb-3">
  <?php foreach ($plans as $p): $current = $p['code'] === $plan['code'] && empty($plan['expired_from']); $trialUsed = in_array($p['id'], $usedTrials); ?>
    <div class="col-md-6 col-xl"><div class="card h-100 <?= $p['is_popular'] ? 'border-brand' : '' ?> <?= $upgrade === $p['code'] ? 'shadow' : '' ?>"><div class="card-body d-flex flex-column">
      <div class="fw-600"><?= e($p['name']) ?><?= $p['is_popular'] ? ' <span class="badge bg-brand">Most popular</span>' : '' ?></div>
      <div class="fs-4 fw-700"><?= $p['price_monthly'] === null ? 'Custom' : '₹' . number_format((int) $p['price_monthly']) . '<small class="text-muted fs-6 fw-normal">/mo</small>' ?></div>
      <div class="small text-muted mb-2"><?= e($p['tagline']) ?></div>
      <ul class="small ps-3 mb-3 flex-grow-1"><?php foreach (array_slice($p['highlights'], 0, 6) as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
      <?php if ($current): ?><button class="btn btn-outline-secondary w-100" disabled>Current plan</button>
      <?php elseif ($p['price_monthly'] === null): ?><a href="<?= url('public/contact-sales.php') ?>" class="btn btn-outline-dark w-100">Contact sales</a>
      <?php elseif ((int) $p['price_monthly'] === 0): ?><button class="btn btn-light w-100 btn-action" data-url="api/billing.php" data-params='{"action":"change_plan","plan":"free"}' data-confirm="Switch to the Free plan? Limits drop to <?= (int) $p['max_websites'] ?> websites / <?= (int) $p['max_forms'] ?> forms and monitoring runs every <?= (int) $p['website_interval'] ?> minutes. Existing data is kept." data-confirm-btn="Switch" data-reload="1">Switch to Free</button>
      <?php elseif (!$trialUsed && Auth::isOwner()): ?><button class="btn <?= $p['is_popular'] ? 'btn-brand' : 'btn-dark' ?> w-100 btn-action" data-url="api/billing.php" data-params='{"action":"change_plan","plan":"<?= $p['code'] ?>"}' data-confirm="Start a <?= (int) ($p['trial_days'] ?: setting('trial_days', 14)) ?>-day free trial of <?= e($p['name']) ?>?" data-icon="question" data-confirm-btn="Start trial" data-reload="1">Start <?= (int) ($p['trial_days'] ?: setting('trial_days', 14)) ?>-day trial</button>
      <?php else: ?><button class="btn <?= $p['is_popular'] ? 'btn-brand' : 'btn-dark' ?> w-100 btn-action" data-url="api/billing.php" data-params='{"action":"request_upgrade","plan":"<?= $p['code'] ?>"}' data-reload="1"><i class="bi bi-arrow-up-circle me-1"></i>Request upgrade</button><?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Subscription history</span></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0 small">
    <thead><tr><th>Plan</th><th>Status</th><th>Started</th><th>Trial ends</th><th>Period</th><th>Amount</th><th>Notes</th></tr></thead>
    <tbody><?php foreach ($subs as $s): ?><tr><td><?= e($s['plan_name']) ?></td><td><?= status_pill(['trial' => 'warning', 'active' => 'success', 'past_due' => 'danger', 'cancelled' => 'secondary', 'expired' => 'dark'][$s['status']] ?? 'secondary', ucfirst($s['status']), false) ?></td><td><?= format_date($s['started_at']) ?></td><td><?= $s['trial_ends_at'] ? format_date($s['trial_ends_at']) : '—' ?></td><td><?= $s['current_period_start'] ? format_date($s['current_period_start']) . ($s['current_period_end'] ? ' → ' . format_date($s['current_period_end']) : '') : '—' ?></td><td><?= $s['amount'] ? '₹' . number_format((int) $s['amount']) . ' / ' . e($s['billing_interval']) : 'Free' ?></td><td class="text-muted"><?= e($s['notes'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if (!$subs): ?><tr><td colspan="7" class="text-center text-muted py-3">No subscription records</td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';