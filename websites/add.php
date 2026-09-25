<?php
/** Add Website wizard: URL → automatic validation, SSL, page discovery, form discovery, monitoring started. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
Auth::requireAbility('websites');
$can = Tenant::canAdd('websites');
$clients = DB::fetchAll("SELECT id, name FROM clients WHERE tenant_id = ? AND status <> 'archived' ORDER BY name LIMIT 500", [Tenant::id()]);
$pageTitle = 'Add Website';
$breadcrumbs = [['label' => 'Websites', 'url' => 'websites/index.php'], ['label' => 'Add Website']];
$pageSubtitle = 'Enter the URL – pages, forms, popup forms, CAPTCHA and SSL are discovered automatically and monitoring starts right away.';
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row justify-content-center">
  <div class="col-lg-8 col-xl-7">
    <?php if (!$can['ok']): ?>
      <div class="card"><div class="card-body text-center py-5">
        <div class="display-6 mb-2"><i class="bi bi-lock text-warning"></i></div>
        <h2 class="h5">Plan limit reached</h2>
        <p class="text-muted"><?= e($can['message']) ?></p>
        <div class="fs-5 fw-600 mb-3"><?= $can['current'] ?> / <?= $can['limit'] ?> websites</div>
        <a href="<?= url('billing/index.php') ?>" class="btn btn-brand"><i class="bi bi-arrow-up-circle me-1"></i>Upgrade plan</a>
        <a href="<?= url('websites/index.php') ?>" class="btn btn-light">Back to websites</a>
      </div></div>
    <?php else: ?>
    <div class="card" id="wizardCard">
      <div class="card-body p-4">
        <form id="addSiteForm" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label fw-600">Website URL</label><input type="url" name="url" class="form-control form-control-lg" placeholder="https://example.com" required autofocus><div class="form-text">Usage: <?= usage_badge($can['current'], $can['limit']) ?> websites on the <?= e(Tenant::plan()['name']) ?> plan.</div></div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Display name <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="name" class="form-control" placeholder="Example Ltd website"></div>
            <div class="col-md-3"><label class="form-label">Technology</label><select name="technology" class="form-select"><?php foreach (technologies() as $t): ?><option value="<?= $t ?>" <?= $t === 'Other' ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Client <span class="text-muted fw-normal">(agencies)</span></label><select name="client_id" class="form-select"><option value="">My Websites</option><?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-brand btn-lg" id="startBtn"><i class="bi bi-rocket-takeoff me-1"></i>Add &amp; scan website</button><a href="<?= url('websites/index.php') ?>" class="btn btn-light btn-lg">Cancel</a></div>
          <div class="text-danger small mt-2 d-none" id="addError"></div>
        </form>
        <div id="progress" class="d-none">
          <h2 class="h5 mb-3"><i class="bi bi-radar me-1"></i>Scanning <span id="siteName"></span>…</h2>
          <ul class="list-unstyled wizard-steps" id="steps">
            <li data-step="validate"><span class="ic"><i class="bi bi-circle"></i></span><span class="txt">Detecting website</span></li>
            <li data-step="ssl"><span class="ic"><i class="bi bi-circle"></i></span><span class="txt">Checking SSL certificate</span></li>
            <li data-step="pages"><span class="ic"><i class="bi bi-circle"></i></span><span class="txt">Discovering pages (sitemap, internal links)</span></li>
            <li data-step="forms"><span class="ic"><i class="bi bi-circle"></i></span><span class="txt">Discovering forms, popup forms and CAPTCHA</span></li>
          </ul>
          <div id="done" class="d-none mt-3">
            <div class="alert alert-success"><i class="bi bi-broadcast me-1"></i><strong>Monitoring started.</strong> Uptime, pages, forms and SSL are now checked automatically on your plan's schedule; popup forms are completed by the next browser scan.</div>
            <a href="#" class="btn btn-dark" id="viewSite"><i class="bi bi-globe2 me-1"></i>Open website dashboard</a>
            <a href="<?= url('websites/add.php') ?>" class="btn btn-light">Add another website</a>
          </div>
        </div>
      </div>
    </div>
    <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Only monitor websites you own or are authorised to monitor. Form tests submit safe test data with the configured test email address; CAPTCHA is detected and never bypassed.</div>
    <?php endif; ?>
  </div>
</div>
<style>.wizard-steps li{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px dashed var(--border)}.wizard-steps .ic{width:22px;font-size:1.05rem}.wizard-steps li.running .ic i{color:var(--brand)}.wizard-steps li.ok .ic i{color:var(--success-text)}.wizard-steps li.fail .ic i{color:var(--danger-text)}.wizard-steps li.skip .ic i{color:var(--muted-2)}.wizard-steps .sub{display:block;font-size:.78rem;color:var(--muted)}</style>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const $f = $('#addSiteForm'); let siteId = 0;
  function mark(step, state, text, extra) { const $li = $('#steps li[data-step="' + step + '"]'); $li.removeClass('running ok fail skip').addClass(state);
    $li.find('.ic i').attr('class', 'bi ' + ({ running: 'bi-arrow-repeat spin', ok: 'bi-check-circle-fill', fail: 'bi-x-circle-fill', skip: 'bi-dash-circle' }[state] || 'bi-circle'));
    if (text) $li.find('.txt').html($('<div>').text(text).html() + (extra ? '<span class="sub">' + $('<div>').text(extra).html() + '</span>' : '')); }
  async function runStep(step) { mark(step, 'running'); try { const res = await CRM.post('api/websites.php', { action: 'wizard_step', id: siteId, step: step }); if (!res.success) { mark(step, 'fail', res.message); return; } mark(step, res.ok === null ? 'skip' : (res.ok ? 'ok' : 'fail'), res.text, res.extra); } catch (e) { mark(step, 'fail', 'Step failed – it will be retried by the automatic monitoring'); } }
  $f.on('submit', async function (e) {
    e.preventDefault(); $('#addError').addClass('d-none'); $('#startBtn').prop('disabled', true);
    const res = await CRM.post('api/websites.php', $f.serialize() + '&action=quick_add').catch(x => x.responseJSON || { success: false, message: 'Request failed' });
    if (!res || !res.success) { $('#addError').removeClass('d-none').html($('<div>').text((res && res.message) || 'Could not add the website').html() + (res && res.upgrade ? ' <a href="' + CRM.url('billing') + '">Upgrade plan</a>' : '')); $('#startBtn').prop('disabled', false); return; }
    siteId = res.id; $('#siteName').text(res.url); $('#viewSite').attr('href', res.view); $f.addClass('d-none'); $('#progress').removeClass('d-none');
    for (const s of ['validate', 'ssl', 'pages', 'forms']) await runStep(s);
    $('#done').removeClass('d-none');
  });
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
