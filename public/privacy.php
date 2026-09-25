<?php
require_once __DIR__ . '/../includes/init.php';
// Maintenance mode: public pages answer 503 too (Super Admins who are signed in still see them)
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) setting('maintenance_message', ''));
$platform = setting('platform_name', 'Outline Monitor');
ob_start();
?>
<section class="container py-5" style="max-width:820px">
  <h1 class="section-h">Privacy policy</h1>
  <p class="text-muted">Last updated <?= date('d M Y') ?></p>
  <h2 class="h5 mt-4">What we store</h2><p>Your name, company, email address and password hash; the websites, pages, forms, domains, hosting details and credentials you add; monitoring results, incidents, notifications and email delivery logs.</p>
  <h2 class="h5 mt-4">How it is used</h2><p>To provide monitoring and alerts to your workspace, to send account emails (verification, password reset, invitations, alerts) from <?= e(Mailer::fromEmail()) ?>, and to operate and improve the service.</p>
  <h2 class="h5 mt-4">Isolation and security</h2><p>Every record belongs to one workspace and is never visible to other customers. Stored credentials and SMTP passwords are encrypted. Access is protected by hashed passwords, CSRF protection, rate limiting and audit logs.</p>
  <h2 class="h5 mt-4">Retention</h2><p>Email, monitoring, request and notification logs are deleted automatically after 30 days. Monitoring history is kept according to your plan. Account data is kept while your workspace is active.</p>
  <h2 class="h5 mt-4">Contact</h2><p>Questions about privacy: <?= e(Mailer::fromEmail()) ?>.</p>
</section>
<?php
$publicContent = ob_get_clean();
$publicTitle = 'Privacy policy';
include ROOT_PATH . '/includes/layout/public.php';
