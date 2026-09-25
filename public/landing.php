<?php
require_once __DIR__ . '/../includes/init.php';
// Maintenance mode: public pages answer 503 too (Super Admins who are signed in still see them)
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) setting('maintenance_message', ''));
if (Auth::check() && Auth::user()) redirect('dashboard/index.php');
$platform = setting('platform_name', 'Outline Monitor');
$plans = Tenant::allPlans(true);
ob_start();
?>
<section class="hero">
  <div class="dots" aria-hidden="true"></div>
  <div class="container">
    <div class="row align-items-center g-4 g-lg-5">
      <div class="col-lg-6">
        <span class="hero-pill"><span class="dot"></span>Website Monitoring Platform</span>
        <h1 class="mt-3">Every website, page and form – <em>monitored automatically.</em></h1>
        <p class="lead mt-3">Add a website and <?= e($platform) ?> discovers its pages, finds every form (including popups and AJAX forms), tests them, watches SSL, domain and hosting expiry and alerts you before your customers notice.</p>
        <div class="badge-line mt-3"><span><i class="bi bi-activity"></i>Uptime</span><span><i class="bi bi-files"></i>Every page</span><span><i class="bi bi-ui-checks"></i>Automatic form discovery</span><span><i class="bi bi-window-stack"></i>Popup forms</span><span><i class="bi bi-shield-check"></i>SSL</span><span><i class="bi bi-hdd-network"></i>Domain &amp; hosting</span></div>
        <div class="mt-4 d-flex flex-wrap gap-2">
          <a href="<?= url('auth/register.php') ?>" class="btn btn-brand btn-lg">Start free – no card needed<i class="bi bi-arrow-right ms-2"></i></a>
          <a href="<?= url('public/pricing.php') ?>" class="btn btn-outline-light btn-lg">See pricing</a>
        </div>
        <div class="small text-secondary mt-3">Free plan forever · 14-day trial of paid plans · Cancel anytime</div>
      </div>
      <div class="col-lg-6">
        <div class="scan-card">
          <div class="d-flex justify-content-between align-items-center mb-3"><span class="text-muted small">Scanning example.com…</span><span class="scan-spinner" aria-hidden="true"></span></div>
          <ul class="scan-list">
            <li><i class="bi bi-check2"></i>Website detected · HTTP 200 in 312 ms</li>
            <li><i class="bi bi-check2"></i>SSL valid · 74 days left</li>
            <li><i class="bi bi-check2"></i>42 pages discovered</li>
            <li><i class="bi bi-check2"></i>18 forms discovered (6 popup, 4 AJAX)</li>
            <li><i class="bi bi-check2"></i>2 CAPTCHA-protected forms detected</li>
          </ul>
          <div class="scan-live"><i class="bi bi-broadcast"></i>Monitoring started</div>
          <div class="scan-stats">
            <div><span class="l"><span class="live-dot"></span>Uptime</span><span class="v">99.9%</span></div>
            <div><span class="l">Pages</span><span class="v">42</span></div>
            <div><span class="l">Forms</span><span class="v">18</span></div>
            <div><span class="l"><span class="live-dot danger"></span>Issues</span><span class="v">0</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="container pub-section" id="features">
  <div class="sec-line"></div>
  <h2 class="section-h mb-1">One dashboard for the whole website</h2>
  <p class="text-muted mb-4">Not just an uptime checker – monitoring, forms, analytics and client management for every site you manage.</p>
  <div class="row g-3">
    <?php foreach ([
      ['bi-activity', 'Website uptime', 'HTTP checks with the real reason when a site is down: DNS, timeout, SSL, server error, parking page.'],
      ['bi-files', 'Every page monitored', 'Pages are discovered from the sitemap and internal links and checked individually.'],
      ['bi-radar', 'Automatic form discovery', 'Contact, quote, enquiry, career and newsletter forms are found on every page – no manual setup.'],
      ['bi-window-stack', 'Popup & AJAX forms', 'Popup triggers are opened in a headless browser; WordPress, Elementor, CF7 and AJAX forms are understood.'],
      ['bi-clipboard-check', 'Real form testing', 'Forms are submitted with safe test data; responses, success messages and validation errors are verified.'],
      ['bi-shield-lock', 'CAPTCHA-aware', 'reCAPTCHA, hCaptcha and Turnstile are detected and reported as blocked – never bypassed, never a false alarm.'],
      ['bi-shield-check', 'SSL monitoring', 'Certificate validity, chain, hostname and expiry with configurable warnings.'],
      ['bi-hdd-network', 'Domain & hosting', 'Expiry tracking with 90 / 30 / 14 / 7 / 1-day reminders and secure credential storage.'],
      ['bi-bar-chart-line', 'Website analytics', 'One tracking line per site: visitors, unique visitors, sessions, page views, bounce rate and time on page – cookie-less.'],
      ['bi-broadcast', 'Real-time visitors', 'Who is on the site right now, which page, from where and on which device – updated every few seconds.'],
      ['bi-geo-alt', 'Geographic & device analytics', 'Countries, states / regions and cities (approximate, IP-based), desktop / mobile / tablet, browsers and systems.'],
      ['bi-megaphone', 'Traffic sources & UTM', 'Search, social, direct, referral and campaign traffic with utm_source / medium / campaign / term / content.'],
      ['bi-envelope-check', 'Email & SMTP monitoring', 'Every alert is logged with the SMTP response; the platform checks its own SMTP connection and verifies form emails through a test mailbox.'],
      ['bi-bell', 'Alerts & recovery', 'Down and recovered notifications by email and in-app, incident history, status pages and reports.'],
      ['bi-buildings', 'Clients, plans & usage', 'Workspaces per client, plan limits and monitoring intervals enforced in the backend, usage dashboards and audit logs.'],
    ] as [$ic, $t, $d]): ?>
      <div class="col-sm-6 col-lg-3"><div class="feature"><div class="ic"><i class="bi <?= $ic ?>"></i></div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="pub-band">
  <div class="container">
    <h2 class="section-h mb-4">How it works</h2>
    <div class="hiw">
      <?php foreach ([['Add your website', 'Enter the URL. That is all the configuration you need.'], ['Automatic discovery', 'Pages, forms, popup forms, CAPTCHA and SSL are discovered within minutes.'], ['Continuous monitoring', 'Everything is tested on your plan\'s schedule by our workers – no browser needed.'], ['Alerts & reports', 'Failure and recovery emails, incidents, status pages and reports for you and your clients.']] as $i => [$t, $d]): ?>
        <?php if ($i): ?><div class="hiw-arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></div><?php endif; ?>
        <div class="step"><div class="num"><?= $i + 1 ?></div><div><div class="fw-600 text-white"><?= e($t) ?></div><div class="text-muted small"><?= e($d) ?></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="container pub-section" id="pricing">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
    <div><h2 class="section-h mb-1">Simple pricing</h2><p class="text-muted mb-0">Start free. Upgrade when you need more websites, pages, forms, users or faster checks.</p></div>
    <a href="<?= url('public/pricing.php') ?>" class="fw-600">Compare all plan features <i class="bi bi-arrow-right ms-1"></i></a>
  </div>
  <div class="row g-3">
    <?php foreach ($plans as $p): ?>
      <div class="col-md-6 col-xl"><div class="plan <?= $p['is_popular'] ? 'popular' : '' ?>"><?= $p['is_popular'] ? '<div class="pop">Most popular</div>' : '' ?>
        <div class="fw-600"><?= e($p['name']) ?></div>
        <div class="price"><?= $p['price_monthly'] === null ? 'Custom' : '₹' . number_format((int) $p['price_monthly']) . '<small>/month</small>' ?></div>
        <div class="small text-muted"><?= e($p['tagline']) ?></div>
        <ul><?php foreach (array_slice($p['highlights'], 0, 5) as $h): ?><li><i class="bi bi-check2"></i><span><?= e($h) ?></span></li><?php endforeach; ?></ul>
        <?php if ($p['price_monthly'] === null): ?><a href="<?= url('public/contact-sales.php') ?>" class="btn btn-outline-light w-100">Contact sales</a>
        <?php elseif ((int) $p['price_monthly'] === 0): ?><a href="<?= url('auth/register.php?plan=' . $p['code']) ?>" class="btn btn-brand w-100">Start free</a>
        <?php else: ?><a href="<?= url('auth/register.php?plan=' . $p['code']) ?>" class="btn btn-brand w-100">Start <?= (int) ($p['trial_days'] ?: setting('trial_days', 14)) ?>-day free trial</a><?php endif; ?>
      </div></div>
    <?php endforeach; ?>
  </div>
</section>
<?php
$publicContent = ob_get_clean();
$publicTitle = 'Website, page & form monitoring on autopilot';
include ROOT_PATH . '/includes/layout/public.php';
