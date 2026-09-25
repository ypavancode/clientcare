<?php
/** Public (marketing) layout: $publicTitle, $publicContent (HTML), optional $publicDescription */
$platform = setting('platform_name', 'Outline Monitor');
$tagline = setting('platform_tagline', 'Website, page, form, SSL, domain and hosting monitoring – on autopilot');
$loggedIn = Auth::check() && Auth::user();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$canonical = url(clean_route(str_replace('\\', '/', ltrim(substr((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), strlen(str_replace('\\', '/', ROOT_PATH))), '/'))));
$canonical = preg_replace('~/index\.php$~', '', $canonical) ?: url('');
$ogImage = url('assets/images/logo-dark.svg');
$isPublicIndexable = !$loggedIn;
if (!$loggedIn && !headers_sent()) header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
?>
<title><?= e($publicTitle ?? $platform) ?> · <?= e($platform) ?></title>
<meta name="description" content="<?= e($publicDescription ?? $tagline) ?>">
<meta name="robots" content="<?= $isPublicIndexable ? 'index, follow, max-image-preview:large' : 'noindex, nofollow' ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#0b0b0b">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($platform) ?>">
<meta property="og:title" content="<?= e($publicTitle ?? $platform) ?>">
<meta property="og:description" content="<?= e($publicDescription ?? $tagline) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($publicTitle ?? $platform) ?>">
<meta name="twitter:description" content="<?= e($publicDescription ?? $tagline) ?>">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Organization', 'name' => company_name(), 'url' => url(''), 'logo' => $ogImage],
    ['@type' => 'SoftwareApplication', 'name' => $platform, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'description' => $publicDescription ?? $tagline, 'url' => url(''), 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR', 'url' => url('public/pricing.php')]],
    ['@type' => 'WebSite', 'name' => $platform, 'url' => url('')],
]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<link rel="icon" href="<?= asset('images/icon.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preload" href="<?= asset('fonts/inter-latin-wght.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= APP_VERSION ?>">
<style>
  body.public { background: var(--bg); color: var(--text); overflow-x: hidden; }
  .pub-nav { position: sticky; top: 0; z-index: 100; background: rgba(11, 11, 11, .84); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); padding: 12px 0; border-bottom: 1px solid var(--border-soft); }
  .pub-nav a { color: #d0d0d0; font-weight: 500; font-size: var(--fs-small); transition: color var(--dur); }
  .pub-nav a:hover { color: #fff; }
  a, a:hover, .btn, .btn:hover { text-decoration: none !important; }
  .pub-nav .brand img { height: 30px; }
  .pub-nav .btn { color: var(--ink); }
  .hero { position: relative; overflow: hidden; background: radial-gradient(900px 600px at 0% 100%, rgba(252, 175, 23, .18), transparent 60%), radial-gradient(700px 500px at 100% 0%, rgba(252, 175, 23, .1), transparent 60%), var(--bg); color: #fff; padding: 64px 0 56px; border-bottom: 1px solid var(--border-soft); }
  .hero::before { content: ""; position: absolute; inset: 0; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cpath d='M0 40h80M40 0v80' stroke='rgba(255,255,255,.03)'/%3E%3C/svg%3E"); pointer-events: none; }
  .hero > * { position: relative; z-index: 1; }
  .hero h1 { font-size: clamp(1.6rem, 3vw, 2.4rem); font-weight: 700; color: #fff; letter-spacing: -.03em; line-height: 1.12; }
  .hero h1 em { font-style: normal; color: var(--brand); }
  .hero .lead { color: #c9c9c9; font-size: .9375rem; max-width: 600px; }
  .hero .badge-line span { display: inline-flex; align-items: center; gap: 6px; background: rgba(255, 255, 255, .05); border: 1px solid var(--border); border-radius: 999px; padding: 7px 13px; font-size: var(--fs-xs); margin: 4px 6px 0 0; color: #ddd; }
  .feature { border-radius: var(--radius); padding: 18px; border: 1px solid var(--border); background: linear-gradient(180deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, .012)), var(--surface); height: 100%; transition: transform var(--dur) var(--ease), box-shadow var(--dur), border-color var(--dur); }
  .feature:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--brand-line); }
  .feature .ic { width: 44px; height: 44px; border-radius: 12px; background: var(--brand-soft); color: var(--brand); border: 1px solid var(--brand-line); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 12px; }
  .feature h3 { font-size: var(--fs-card); margin-bottom: 6px; color: #fff; }
  .feature p { color: var(--muted); font-size: var(--fs-small); margin: 0; }
  .plan { border-radius: var(--radius-lg); padding: 22px 20px; border: 1px solid var(--border); background: linear-gradient(180deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, .012)), var(--surface); height: 100%; position: relative; display: flex; flex-direction: column; transition: transform var(--dur) var(--ease), box-shadow var(--dur), border-color var(--dur); }
  .plan:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--border-strong); }
  .plan.popular { border-color: var(--brand-line); box-shadow: 0 18px 50px -14px var(--brand-glow); }
  .plan .pop { position: absolute; top: -13px; left: 50%; transform: translateX(-50%); background: var(--brand); color: #111; font-size: .625rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; box-shadow: var(--shadow-glow); white-space: nowrap; }
  .plan .price { font-family: var(--font-heading); font-size: 1.6rem; font-weight: 700; color: #fff; letter-spacing: -.03em; }
  .plan .price small { font-size: var(--fs-small); color: var(--muted); font-weight: 500; }
  .plan ul { list-style: none; padding: 0; margin: 16px 0 20px; font-size: var(--fs-small); flex: 1; }
  .plan ul li { padding: 7px 0; border-bottom: 1px dashed var(--border); display: flex; gap: 10px; align-items: flex-start; color: var(--text-2); }
  .plan ul li i { color: var(--success-text); margin-top: 3px; }
  .hero .dots { position: absolute; right: -6%; bottom: -10%; width: 58%; height: 80%; pointer-events: none; opacity: .55; background-image: radial-gradient(rgba(252, 175, 23, .7) 1px, transparent 1.6px); background-size: 13px 13px; -webkit-mask-image: radial-gradient(ellipse 70% 55% at 60% 80%, #000 20%, transparent 70%); mask-image: radial-gradient(ellipse 70% 55% at 60% 80%, #000 20%, transparent 70%); animation: dotsDrift 18s ease-in-out infinite alternate; }
  @keyframes dotsDrift { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(-18px, -10px, 0); } }
  .hero-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px 6px 10px; border-radius: 999px; background: rgba(255, 255, 255, .05); border: 1px solid var(--border-strong); color: #e6e6e6; font-size: var(--fs-small); font-weight: 500; }
  .hero-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 10px var(--brand); }
  .hero .badge-line span i { color: var(--brand); }
  .scan-card { position: relative; border-radius: var(--radius-lg); padding: 22px 22px 18px; background: rgba(18, 18, 18, .88); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border: 1px solid var(--brand-line); box-shadow: 0 30px 80px -30px rgba(0, 0, 0, .8), 0 0 60px -20px var(--brand-glow); max-width: 520px; margin-left: auto; }
  .scan-spinner { width: 22px; height: 22px; border-radius: 50%; border: 2px solid rgba(252, 175, 23, .25); border-top-color: var(--brand); animation: spin 1.1s linear infinite; }
  .scan-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; font-size: var(--fs-small); color: #e6e6e6; }
  .scan-list i { color: var(--success-text); margin-right: 8px; }
  .scan-live { color: var(--success-text); font-weight: 600; font-size: var(--fs-small); margin-top: 12px; padding-bottom: 14px; border-bottom: 1px solid var(--border); }
  .scan-live i { margin-right: 6px; }
  .scan-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 14px; }
  .scan-stats > div { background: rgba(255, 255, 255, .04); border: 1px solid var(--border); border-radius: 10px; padding: 9px 11px; min-width: 0; }
  .scan-stats .l { display: flex; align-items: center; gap: 5px; font-size: var(--fs-xs); color: var(--muted); white-space: nowrap; }
  .scan-stats .v { display: block; font-family: var(--font-heading); font-size: 1.05rem; font-weight: 700; color: #fff; margin-top: 4px; }
  .pub-section { padding: 56px 12px; }
  .sec-line { width: 36px; height: 3px; border-radius: 3px; background: var(--brand); margin-bottom: 14px; box-shadow: 0 0 12px var(--brand-glow); }
  .feature { border-color: rgba(252, 175, 23, .16); }
  .pub-band { background: var(--bg-2); border-top: 1px solid var(--border-soft); border-bottom: 1px solid var(--border-soft); padding: 44px 0; }
  .hiw { display: flex; align-items: flex-start; gap: 14px; }
  .hiw .step { display: flex; gap: 14px; flex: 1 1 0; min-width: 0; }
  .hiw .num { width: 36px; height: 36px; border-radius: 50%; background: var(--brand); color: #111; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: var(--font-heading); box-shadow: var(--shadow-glow); }
  .hiw-arrow { color: var(--brand); font-size: 1.1rem; padding-top: 6px; flex: 0 0 auto; }
  .pub-footer .links { display: flex; flex-wrap: wrap; gap: 6px 18px; }
  @media (max-width: 991.98px) { .hiw { flex-wrap: wrap; } .hiw .step { flex-basis: calc(50% - 7px); } .hiw-arrow { display: none; } .scan-card { margin-left: 0; max-width: none; } }
  @media (max-width: 575.98px) { .hiw .step { flex-basis: 100%; } .scan-stats { grid-template-columns: 1fr 1fr; } .pub-section { padding: 40px 12px; } }
  .pub-footer { background: var(--bg-2); color: #9a9a9a; padding: 30px 0; font-size: var(--fs-small); margin-top: 64px; border-top: 1px solid var(--border-soft); }
  .pub-footer a { color: #cfcfcf; }
  .section-h { font-size: clamp(1.2rem, 2vw, 1.5rem); font-weight: 700; letter-spacing: -.02em; color: #fff; }
  @media (max-width: 767.98px) { .hero { padding: 48px 0 40px; } .plan { padding: 22px 18px; } }
</style>
</head>
<body class="public">
<nav class="pub-nav">
  <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
    <a href="<?= url('') ?>" class="brand d-flex align-items-center gap-2"><img src="<?= company_logo_url('light') ?>" alt="<?= e($platform) ?>" width="120" height="34" fetchpriority="high"></a>
    <div class="d-flex align-items-center gap-3">
      <a href="<?= url('') ?>#features">Features</a>
      <a href="<?= url('public/pricing.php') ?>">Pricing</a>
      <?php if ($loggedIn): ?><a href="<?= url('dashboard/index.php') ?>" class="btn btn-brand btn-sm">Dashboard</a>
      <?php else: ?><a href="<?= url('auth/login.php') ?>">Sign in</a><a href="<?= url('auth/register.php') ?>" class="btn btn-brand btn-sm">Get Started</a><?php endif; ?>
    </div>
  </div>
</nav>
<?= $publicContent ?? '' ?>
<footer class="pub-footer">
  <div class="container d-flex flex-wrap justify-content-between gap-2">
    <div>&copy; <?= date('Y') ?> <?= e($platform) ?>. All rights reserved.</div>
    <div class="links"><a href="<?= url('') ?>#features">Features</a><a href="<?= url('public/pricing.php') ?>">Pricing</a><a href="<?= url('public/contact-sales.php') ?>">Contact</a><a href="<?= url('public/privacy.php') ?>">Privacy</a><a href="<?= url('public/terms.php') ?>">Terms</a><a href="<?= url('auth/login.php') ?>">Sign in</a></div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
