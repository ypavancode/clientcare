<?php
require_once __DIR__ . '/../includes/init.php';
// Maintenance mode: public pages answer 503 too (Super Admins who are signed in still see them)
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) setting('maintenance_message', ''));
$platform = setting('platform_name', 'Outline Monitor');
ob_start();
?>
<section class="container py-5" style="max-width:820px">
  <h1 class="section-h">Terms of service</h1>
  <p class="text-muted">Last updated <?= date('d M Y') ?></p>
  <h2 class="h5 mt-4">1. The service</h2><p><?= e($platform) ?> monitors websites, pages, forms, SSL certificates, domains and hosting that you add to your workspace and sends notifications about their status. Monitoring is performed with automated requests and safe test submissions; CAPTCHA and anti-bot protections are never bypassed.</p>
  <h2 class="h5 mt-4">2. Your account</h2><p>You are responsible for the accuracy of your registration details, for keeping your password secure and for the activity of team members you invite. You may only monitor websites you own or are authorised to monitor.</p>
  <h2 class="h5 mt-4">3. Plans, trials and limits</h2><p>Each plan defines limits (websites, pages, forms, users, monitoring intervals, retention). Limits are enforced by the service. Free trials convert to the Free plan limits when they end unless a paid subscription is activated. Prices are shown on the pricing page and may change with notice.</p>
  <h2 class="h5 mt-4">4. Acceptable use</h2><p>Do not use the service to attack, overload or scrape third-party websites, to bypass security controls, or to send unsolicited messages. We may suspend accounts that abuse the service.</p>
  <h2 class="h5 mt-4">5. Availability and liability</h2><p>The service is provided "as is". We aim for high availability but do not guarantee uninterrupted monitoring or the detection of every failure. Our liability is limited to the fees paid in the preceding month.</p>
  <h2 class="h5 mt-4">6. Data</h2><p>Monitoring history and logs are retained according to your plan and deleted automatically afterwards. See the privacy policy for details.</p>
</section>
<?php
$publicContent = ob_get_clean();
$publicTitle = 'Terms of service';
include ROOT_PATH . '/includes/layout/public.php';
