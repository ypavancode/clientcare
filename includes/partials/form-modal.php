<?php /** Form add/edit modal. Variable: $selectedWebsiteId */
$selectedWebsiteId = $selectedWebsiteId ?? null;
?>
<div class="modal fade" id="formModal" tabindex="-1" data-auto-open>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form class="ajax-form" action="<?= url('api/forms.php') ?>" data-reload="1" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <div class="modal-header">
          <h5 class="modal-title" data-add="Add Form">Add Form</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#fmTabMain" type="button">Form Details</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#fmTabTest" type="button">Automated Testing</button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="fmTabMain">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Website *</label>
                  <?= remote_select('website_id', 'websites', $selectedWebsiteId, 'Type the website name…', ['required' => 1] + ($selectedWebsiteId ? ['keep' => 1] : [])) ?></div>
                <div class="col-md-6"><label class="form-label">Form name *</label><input type="text" name="name" class="form-control" required placeholder="Contact Us"></div>
                <div class="col-md-6"><label class="form-label">Page URL *</label><input type="url" name="page_url" class="form-control" required placeholder="https://example.com/contact"></div>
                <div class="col-md-6"><label class="form-label">Form action URL <span class="text-muted fw-normal">(blank = page URL)</span></label><input type="url" name="form_url" class="form-control" placeholder="https://example.com/send-mail.php"></div>
                <div class="col-md-4"><label class="form-label">Form type</label>
                  <select name="form_type" class="form-select"><?php foreach (form_types() as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">SMTP / mail provider</label><input type="text" name="smtp_provider" class="form-control" placeholder="PHP mail(), SMTP Gmail, SendGrid…"></div>
                <div class="col-md-4"><label class="form-label">Status</label>
                  <select name="status" class="form-select"><option value="enabled">Enabled (monitored)</option><option value="disabled">Disabled</option></select></div>
                <div class="col-md-4"><label class="form-label">Recipient email</label><input type="text" name="recipient_email" class="form-control" placeholder="info@client.com"></div>
                <div class="col-md-4"><label class="form-label">CC email</label><input type="text" name="cc_email" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">BCC email</label><input type="text" name="bcc_email" class="form-control"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
              </div>
            </div>
            <div class="tab-pane fade" id="fmTabTest">
              <div class="alert alert-light border small mb-3">
                <strong>How testing works:</strong> every test first checks the page loads and contains a form. If you add a <em>test payload</em>, the CRM will also submit the form with test data and verify the response. Test submissions use the configured test email address, never real customer emails, and carry the header <code>X-CRM-Form-Test: 1</code>.
              </div>
              <div class="row g-3">
                <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="auto_test" id="f_auto" checked><label class="form-check-label small" for="f_auto">Include in scheduled tests</label></div></div>
                <div class="col-md-4"><label class="form-label">Method</label><select name="method" class="form-select"><option value="POST">POST</option><option value="GET">GET</option></select></div>
                <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="ajax" id="f_ajax"><label class="form-check-label small" for="f_ajax">Form submits via AJAX</label></div></div>
                <div class="col-md-7"><label class="form-label">Test payload <span class="text-muted fw-normal">(one field per line: name=value)</span></label>
                  <textarea name="test_payload" class="form-control mono" rows="6" placeholder="name={{name}}&#10;email={{test_email}}&#10;phone={{phone}}&#10;message={{message}}&#10;submit=1"></textarea>
                  <div class="form-text">Placeholders: <code>{{test_email}}</code> <code>{{name}}</code> <code>{{phone}}</code> <code>{{message}}</code> <code>{{token}}</code>. Hidden fields (nonce/CSRF) are picked up from the page automatically.</div></div>
                <div class="col-md-5">
                  <label class="form-label">Expected success message</label>
                  <input type="text" name="success_match" class="form-control mb-2" placeholder="Thank you for contacting us">
                  <label class="form-label">Expected redirect URL <span class="text-muted fw-normal">(or fragment)</span></label>
                  <input type="text" name="expect_redirect" class="form-control mb-2" placeholder="/thank-you">
                  <div class="row g-2">
                    <div class="col-6"><label class="form-label">Expected HTTP code</label><input type="number" name="expect_http_code" class="form-control" placeholder="any 2xx/3xx" min="100" max="599"></div>
                    <div class="col-6"><label class="form-label">Test every (minutes)</label><input type="number" name="test_interval" class="form-control" placeholder="global" min="0" max="10080"></div>
                  </div>
                  <div class="form-text">Leave blank to use the global interval and only check for HTTP errors / failure messages.</div>
                  <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="test_now" id="f_testnow" checked><label class="form-check-label small" for="f_testnow">Run a test after saving</label></div></div>

                <div class="col-12"><div class="section-title"><i class="bi bi-shield-lock me-1"></i>CAPTCHA / Anti-Bot Protection</div></div>
                <div class="col-12"><div class="alert alert-light border small mb-0">
                  The CRM <strong>never bypasses, defeats or solves CAPTCHA</strong>. reCAPTCHA, hCaptcha, Cloudflare Turnstile, CAPTCHA fields and anti-bot plugins are detected automatically before the form is submitted.
                  A protected form is reported as <strong>Blocked by CAPTCHA</strong> (separate status, <em>no</em> form-failure alert) unless the site owner provides an approved test method below.
                </div></div>
                <div class="col-md-4"><label class="form-label">CAPTCHA / anti-bot on this form</label>
                  <select name="captcha_type" class="form-select"><?php foreach (form_captcha_types() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
                  <div class="form-text">"None" still auto-detects CAPTCHA on the page.</div></div>
                <div class="col-md-8"><label class="form-label">Approved test method</label>
                  <select name="captcha_mode" class="form-select" id="f_captcha_mode">
                    <option value="detect">Report as "Blocked by CAPTCHA" – availability check only, no submission (default)</option>
                    <option value="test_url">Owner-approved test / staging page without CAPTCHA</option>
                    <option value="test_field">Owner-approved test parameter (site owner allows CRM tests with a key)</option>
                  </select></div>
                <div class="col-md-6 captcha-opt captcha-opt-test_url d-none"><label class="form-label">Test / staging page URL</label><input type="url" name="captcha_test_url" class="form-control" placeholder="https://staging.example.com/contact">
                  <div class="form-text">A legitimate test environment provided by the site owner where the same form runs without CAPTCHA. Submission tests use this page instead of the live page.</div></div>
                <div class="col-md-6 captcha-opt captcha-opt-test_field d-none"><label class="form-label">Approved test parameter(s) <span class="text-muted fw-normal">(name=value per line)</span></label><textarea name="captcha_test_field" class="form-control mono" rows="2" placeholder="crm_test_key=ABC123"></textarea>
                  <div class="form-text">Only for forms whose owner configured an allow-listed test key for the CRM. The CAPTCHA itself is not touched; if the handler still rejects the submission the test is reported as blocked.</div></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Form</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function syncCaptchaMode() {
  const mode = $('#f_captcha_mode').val();
  $('#formModal .captcha-opt').addClass('d-none');
  $('#formModal .captcha-opt-' + mode).removeClass('d-none');
}
$(document).on('change', '#f_captcha_mode', syncCaptchaMode);
$('#formModal').on('hidden.bs.modal shown.bs.modal', syncCaptchaMode);
window.fillFormModal = function (f) {
  const $m = $('#formModal'), $f = $m.find('form');
  $f[0].reset();
  CRM.fillForm($f, f);
  syncCaptchaMode();
  $f.find('[name=status]').val(f.status === 'disabled' ? 'disabled' : 'enabled');
  $f.find('[name=test_now]').prop('checked', false);
  $m.find('.modal-title').text('Edit Form');
  $m.find('.nav-tabs .nav-link').first().tab('show');
  bootstrap.Modal.getOrCreateInstance($m[0]).show();
};
</script>
