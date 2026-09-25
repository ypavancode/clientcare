<?php
require_once __DIR__ . '/../includes/init.php';
// Maintenance mode: public pages answer 503 too (Super Admins who are signed in still see them)
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) setting('maintenance_message', ''));
$plans = Tenant::allPlans(true);
$platform = setting('platform_name', 'Outline Monitor');
$loggedIn = Auth::check() && Auth::user();
$currentCode = $loggedIn && Auth::tenantId() ? Tenant::plan()['code'] : null;
$rows = [
    ['Websites', fn($p) => $p['max_websites'] === null ? 'Custom' : number_format((int) $p['max_websites'])],
    ['Monitored pages', fn($p) => $p['max_pages'] === null ? 'Custom' : number_format((int) $p['max_pages'])],
    ['Forms (auto-discovered + monitored)', fn($p) => $p['max_forms'] === null ? 'Custom' : number_format((int) $p['max_forms'])],
    ['Team members', fn($p) => $p['max_users'] === null ? 'Unlimited / negotiated' : (int) $p['max_users']],
    ['Website monitoring interval', fn($p) => 'every ' . (int) $p['website_interval'] . ' min'],
    ['Form monitoring interval', fn($p) => 'every ' . (int) $p['form_interval'] . ' min'],
    ['SSL monitoring', fn($p) => 'every ' . (int) $p['ssl_interval'] . ' min'],
    ['Domain & hosting expiry reminders (recorded expiry dates, alerts at 30 / 10 days and on expiry)', fn($p) => true],
    ['Visitor analytics (cookie-less tracking snippet)', fn($p) => ($p['features']['analytics'] ?? 'none') === 'none' ? false : ucfirst($p['features']['analytics'])],
    ['Analytics page views / month', fn($p) => ($p['features']['analytics'] ?? 'none') === 'none' ? false : ($p['max_pageviews_month'] === null ? 'Unlimited' : number_format((int) $p['max_pageviews_month']))],
    ['Page-level analytics (visitors, views, time on page, bounce rate)', fn($p) => ($p['features']['analytics'] ?? 'none') !== 'none'],
    ['Traffic sources, referrers & UTM campaigns', fn($p) => ($p['features']['analytics'] ?? 'none') !== 'none'],
    ['Real-time visitors', fn($p) => ($p['features']['analytics'] ?? 'none') !== 'none' && (!array_key_exists('realtime', $p['features']) || !empty($p['features']['realtime']))],
    ['Geographic analytics', fn($p) => ($p['features']['analytics'] ?? 'none') === 'none' ? false : ['country' => 'Country', 'region' => 'Country + region', 'city' => 'Country, region + city'][$p['features']['geo'] ?? 'country'] ?? 'Country'],
    ['Custom events / month (clicks, downloads, CTAs)', fn($p) => ($p['features']['analytics'] ?? 'none') === 'none' ? false : ((int) ($p['features']['max_events_month'] ?? 0) ? number_format((int) $p['features']['max_events_month']) : 'Unlimited')],
    ['Analytics history', fn($p) => ($p['features']['analytics'] ?? 'none') === 'none' ? false : ((int) ($p['features']['analytics_retention'] ?? 30) >= 365 ? round((int) $p['features']['analytics_retention'] / 365) . ' year' . ((int) $p['features']['analytics_retention'] >= 730 ? 's' : '') : (int) ($p['features']['analytics_retention'] ?? 30) . ' days')],
    ['Automatic page discovery', fn($p) => true],
    ['Automatic form discovery', fn($p) => (bool) ($p['features']['form_discovery'] ?? 0)],
    ['Popup form discovery / monitoring', fn($p) => (bool) ($p['features']['popup_discovery'] ?? 0)],
    ['AJAX form monitoring', fn($p) => (bool) ($p['features']['ajax_forms'] ?? 0)],
    ['CAPTCHA detection', fn($p) => (bool) ($p['features']['captcha_detection'] ?? 0)],
    ['Down / recovered alerts (email + in-app, instant)', fn($p) => true],
    ['Form email delivery verification (IMAP test mailbox)', fn($p) => true],
    ['Monitoring history (uptime, incidents, form tests, alerts)', fn($p) => (int) $p['retention_days'] >= 365 ? round((int) $p['retention_days'] / 365) . ' year' . ((int) $p['retention_days'] >= 730 ? 's' : '') : (int) $p['retention_days'] . ' days'],
    ['Reports', fn($p) => ($p['features']['reports'] ?? 'none') === 'none' ? false : ucfirst($p['features']['reports'])],
    ['Status pages', fn($p) => (int) ($p['max_status_pages'] ?? 0) ? ((int) $p['max_status_pages'] > 1 ? (int) $p['max_status_pages'] . ' pages' : '1 page') : false],
    ['White-label status pages + custom domains', fn($p) => (bool) ($p['features']['custom_domain'] ?? 0)],
    ['API access', fn($p) => ($p['features']['api'] ?? 'none') === 'none' ? false : ucfirst($p['features']['api'])],
    ['Webhooks', fn($p) => (bool) ($p['features']['webhooks'] ?? 0)],
    ['Team roles (owner, admin, manager, viewer, notify-only)', fn($p) => true],
    ['Client alert contacts (opt-in per client)', fn($p) => true],
    ['White-label sender name on alert emails', fn($p) => (bool) ($p['features']['white_label'] ?? 0)],
    ['Priority support', fn($p) => (bool) ($p['features']['priority_support'] ?? 0)],
];
$cell = function ($v) { if ($v === true) return '<i class="bi bi-check-circle-fill text-success"></i>'; if ($v === false) return '<span class="text-muted">—</span>'; return e((string) $v); };
ob_start();
?>
<section class="container py-5">
  <div class="text-center mb-4">
    <h1 class="section-h">Plans for every size of business</h1>
    <p class="text-muted">Every plan includes website, page, form and SSL monitoring, domain & hosting expiry reminders and instant alerts; analytics, real-time visitors and faster checks grow with the plan. Paid plans start with a free trial – no card required.</p>
  </div>
  <div class="row g-3 mb-5">
    <?php foreach ($plans as $p): ?>
      <div class="col-md-6 col-xl"><div class="plan <?= $p['is_popular'] ? 'popular' : '' ?>"><?= $p['is_popular'] ? '<div class="pop">Most popular</div>' : '' ?>
        <div class="fw-600"><?= e($p['name']) ?></div>
        <div class="price"><?= $p['price_monthly'] === null ? 'Custom' : '₹' . number_format((int) $p['price_monthly']) . '<small>/month</small>' ?></div>
        <div class="small text-muted"><?= e($p['tagline']) ?></div>
        <ul><?php foreach ($p['highlights'] as $h): ?><li><i class="bi bi-check2"></i><span><?= e($h) ?></span></li><?php endforeach; ?></ul>
        <?php if ($currentCode === $p['code']): ?><button class="btn btn-outline-secondary w-100" disabled>Current plan</button>
        <?php elseif ($p['price_monthly'] === null): ?><a href="<?= url('public/contact-sales.php') ?>" class="btn btn-outline-dark w-100">Contact sales</a>
        <?php elseif ($loggedIn): ?><a href="<?= url('billing/index.php?upgrade=' . $p['code']) ?>" class="btn <?= $p['is_popular'] ? 'btn-brand' : 'btn-dark' ?> w-100"><?= (int) $p['price_monthly'] === 0 ? 'Switch to Free' : 'Choose ' . e($p['name']) ?></a>
        <?php elseif ((int) $p['price_monthly'] === 0): ?><a href="<?= url('auth/register.php?plan=' . $p['code']) ?>" class="btn btn-dark w-100">Start free</a>
        <?php else: ?><a href="<?= url('auth/register.php?plan=' . $p['code']) ?>" class="btn <?= $p['is_popular'] ? 'btn-brand' : 'btn-dark' ?> w-100">Start <?= (int) ($p['trial_days'] ?: setting('trial_days', 14)) ?>-day free trial</a><?php endif; ?>
      </div></div>
    <?php endforeach; ?>
  </div>
  <h2 class="h4 fw-bold mb-3">Compare features</h2>
  <div class="table-responsive"><table class="table table-bordered align-middle small">
    <thead class="table-light"><tr><th style="min-width:230px">Feature</th><?php foreach ($plans as $p): ?><th class="text-center"><?= e($p['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as [$label, $fn]): ?><tr><td><?= e($label) ?></td><?php foreach ($plans as $p): ?><td class="text-center"><?= $cell($fn($p)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="small text-muted mt-3">Prices in INR per month. Monitoring intervals are the plan minimum; the platform may run checks less often during maintenance. Limits are enforced in the backend – when a limit is reached you are asked to upgrade, nothing is created silently.</div>
</section>
<?php
$publicContent = ob_get_clean();
$publicTitle = 'Pricing';
include ROOT_PATH . '/includes/layout/public.php';
