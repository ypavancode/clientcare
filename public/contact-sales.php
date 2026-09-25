<?php
require_once __DIR__ . '/../includes/init.php';
// Maintenance mode: public pages answer 503 too (Super Admins who are signed in still see them)
if (setting('maintenance_mode', 0) && !Auth::isPlatformAdmin()) http_error(503, (string) setting('maintenance_message', ''));
$platform = setting('platform_name', 'Outline Monitor');
$sent = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) $error = 'Your session expired. Please try again.';
    elseif (Registration::throttled('contact', 5, 60)) $error = 'Too many requests. Please try again later.';
    else {
        $name = trim((string) post('name', '')); $company = trim((string) post('company', '')); $email = strtolower(trim((string) post('email', ''))); $phone = trim((string) post('phone', '')); $msg = trim((string) post('message', '')); $sites = trim((string) post('websites', ''));
        if ($name === '' || $company === '' || $msg === '') $error = 'Please fill in your name, company and requirements.';
        elseif ($p = Registration::emailProblem($email)) $error = $p;
        else {
            $body = Mailer::template('Agency / Enterprise enquiry', 'A new enterprise enquiry was submitted through the pricing page.', ['Name' => $name, 'Company' => $company, 'Email' => $email, 'Phone' => $phone ?: '—', 'Websites to monitor' => $sites ?: '—', 'Requirements' => $msg], '', null, '#0d6efd');
            foreach (Notifier::platformAdminRecipients() as $r) Mailer::send($r['email'], 'Enterprise enquiry – ' . $company, $body, $r['name'], 'contact_sales');
            Notifier::create('info', 'contact_sales', 'Enterprise enquiry: ' . $company, $name . ' <' . $email . '> ' . ($phone ? '· ' . $phone . ' ' : '') . '– ' . mb_substr($msg, 0, 300), ['tenant_id' => null]);
            $sent = true;
        }
    }
}
ob_start();
?>
<section class="container py-5" style="max-width:760px">
  <h1 class="section-h">Contact sales</h1>
  <p class="text-muted">Agencies and enterprises: tell us how many websites, pages and forms you need to monitor and we will set up a custom plan – custom limits, white-label dashboard and emails, client portals, API, webhooks and priority support.</p>
  <?php if ($sent): ?><div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>Thank you – we will get back to you within one business day.</div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-md-6"><label class="form-label">Your name *</label><input type="text" name="name" class="form-control" required value="<?= e(post('name', '')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Company *</label><input type="text" name="company" class="form-control" required value="<?= e(post('company', '')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Work email *</label><input type="email" name="email" class="form-control" required value="<?= e(post('email', '')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e(post('phone', '')) ?>"></div>
    <div class="col-12"><label class="form-label">How many websites / pages / forms?</label><input type="text" name="websites" class="form-control" placeholder="e.g. 250 client websites, ~15,000 pages" value="<?= e(post('websites', '')) ?>"></div>
    <div class="col-12"><label class="form-label">Requirements *</label><textarea name="message" class="form-control" rows="4" required><?= e(post('message', '')) ?></textarea></div>
    <div class="col-12"><button class="btn btn-brand"><i class="bi bi-send me-1"></i>Send enquiry</button></div>
  </form>
  <?php endif; ?>
</section>
<?php
$publicContent = ob_get_clean();
$publicTitle = 'Contact sales';
include ROOT_PATH . '/includes/layout/public.php';
