<?php /** Website add/edit modal (website, WordPress login, page monitoring, optional domain & hosting details). Variable: $selectedClientId */
$selectedClientId = $selectedClientId ?? null;
?>
<div class="modal fade" id="websiteModal" tabindex="-1" data-auto-open>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form class="ajax-form" action="<?= url('api/websites.php') ?>" data-reload="1" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <?php if (!empty($websiteModalStay)): ?><input type="hidden" name="stay" value="1"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title" data-add="Add Website">Add Website</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#wsTabMain" type="button">Website</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#wsTabWp" type="button" id="wsTabWpBtn"><i class="bi bi-wordpress me-1"></i>WordPress Login</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#wsTabDomain" type="button">Domain</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#wsTabHosting" type="button">Hosting</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#wsTabDesign" type="button"><i class="bi bi-vector-pen me-1"></i>Design &amp; References</button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="wsTabMain">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Client *</label>
                  <?= remote_select('client_id', 'clients', $selectedClientId, 'Type the client name…', ['required' => 1] + ($selectedClientId ? ['keep' => 1] : [])) ?></div>
                <div class="col-md-6"><label class="form-label">Website name *</label><input type="text" name="name" class="form-control" required maxlength="150" placeholder="ABC Company Website"></div>
                <div class="col-md-6"><label class="form-label">Website URL *</label><input type="url" name="url" class="form-control" required placeholder="https://example.com"></div>
                <div class="col-md-6"><label class="form-label">Admin URL</label><input type="url" name="admin_url" class="form-control" placeholder="https://example.com/admin"></div>
                <div class="col-md-4"><label class="form-label">Technology</label>
                  <select name="technology" class="form-select" id="w_technology"><?php foreach (technologies() as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?></select>
                  <div class="form-text" id="w_tech_hint"></div></div>
                <div class="col-md-8"><label class="form-label">Hosting login reference</label><input type="text" name="hosting_login_ref" class="form-control" placeholder="e.g. cPanel user – store the actual password under Login Credentials on the client page"></div>
                <div class="col-12"><label class="form-label">Expected text on homepage <span class="text-muted fw-normal">(optional)</span></label><input type="text" name="expect_text" class="form-control" maxlength="190" placeholder="e.g. the company name or a heading that must appear on the live site">
                  <div class="form-text">If set, the check fails with <strong>Content Missing</strong> when this text is not found. Parking / placeholder pages (GoDaddy lander, "domain for sale", default Apache/nginx pages, suspended-account pages) are always detected automatically.</div></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                <div class="col-12"><div class="section-title">Monitoring</div></div>
                <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="monitoring_enabled" id="w_mon" checked><label class="form-check-label small" for="w_mon">Uptime &amp; SSL monitoring</label></div></div>
                <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="page_monitoring_enabled" id="w_pmon" checked><label class="form-check-label small" for="w_pmon">Check all pages (page-level monitoring)</label></div></div>
                <div class="col-md-4"><div class="input-group input-group-sm"><span class="input-group-text">Max pages</span><input type="number" name="max_pages" class="form-control" min="0" max="500" placeholder="<?= (int) setting('page_max_pages', 50) ?> (default)"></div><div class="form-text">0 = use the global limit. Pages are discovered from the sitemap and internal links.</div></div>
                <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="check_now" id="w_check" checked><label class="form-check-label small" for="w_check">Run first check &amp; page discovery after saving</label></div></div>
              </div>
            </div>
            <div class="tab-pane fade" id="wsTabWp">
              <div class="alert alert-light border small mb-3" id="wpRequiredNote">
                <i class="bi bi-wordpress me-1"></i><strong>WordPress websites:</strong> login URL, username and password are <strong>required</strong> and the website cannot be saved without them. For other technologies these fields are optional. The password is stored encrypted and only shown to administrators.
              </div>
              <div class="row g-3">
                <div class="col-12"><label class="form-label">WordPress login URL <span class="wp-req text-danger">*</span></label><input type="url" name="wp_login_url" class="form-control" placeholder="https://example.com/wp-admin"></div>
                <div class="col-md-6"><label class="form-label">WordPress username / user ID <span class="wp-req text-danger">*</span></label><input type="text" name="wp_username" class="form-control" placeholder="admin@example.com" maxlength="190" autocomplete="off"></div>
                <div class="col-md-6"><label class="form-label">WordPress password <span class="wp-req text-danger">*</span> <span class="text-muted fw-normal" id="wpPassHint"></span></label>
                  <div class="input-group"><input type="password" name="wp_password" class="form-control" id="w_wp_password" autocomplete="new-password" placeholder="••••••••"><button class="btn btn-outline-secondary toggle-password" type="button" data-target="#w_wp_password" title="Show / hide"><i class="bi bi-eye"></i></button></div></div>
              </div>
            </div>
            <div class="tab-pane fade" id="wsTabDomain">
              <p class="text-muted small mb-3">Optional. Creates/updates the domain record shown under <strong>Domains &amp; Hosting</strong> and on the client page.</p>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Domain name</label><input type="text" name="domain_name" class="form-control" placeholder="example.com (auto-filled from URL)"></div>
                <div class="col-md-6"><label class="form-label">Registrar / provider</label><input type="text" name="domain_provider" class="form-control" placeholder="GoDaddy, Namecheap…"></div>
                <div class="col-md-4"><label class="form-label">Registration date</label><input type="date" name="domain_registration_date" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Expiry date</label><input type="date" name="domain_expiry" class="form-control"></div>
                <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="domain_auto_renew" id="w_dar"><label class="form-check-label small" for="w_dar">Auto-renew enabled</label></div></div>
                <div class="col-12"><label class="form-label">Registrar login reference</label><input type="text" name="domain_login_ref" class="form-control" placeholder="Account name / email at the registrar (passwords go under Login Credentials)"></div>
              </div>
            </div>
            <div class="tab-pane fade" id="wsTabHosting">
              <p class="text-muted small mb-3">Optional. Creates/updates the hosting record shown under <strong>Domains &amp; Hosting</strong> and on the client page.</p>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Hosting provider</label><input type="text" name="hosting_provider" class="form-control" placeholder="Hostinger, GoDaddy, AWS…"></div>
                <div class="col-md-6"><label class="form-label">Server / IP</label><input type="text" name="server_ip" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Plan</label><input type="text" name="hosting_plan" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Start date</label><input type="date" name="hosting_start_date" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Expiry date</label><input type="date" name="hosting_expiry" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Renewal status</label>
                  <select name="hosting_renewal_status" class="form-select"><option value="manual">Manual renewal</option><option value="auto">Auto-renew</option><option value="cancelled">Cancelled</option></select></div>
              </div>
            </div>
            <div class="tab-pane fade" id="wsTabDesign">
              <p class="text-muted small mb-3">Optional. Links to the design files and demos of this website – shown on the website page with one-click <strong>Open</strong> buttons (new tab). Not every website has all of them.</p>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label"><i class="bi bi-vector-pen me-1"></i>Figma Design URL</label><input type="url" name="figma_url" class="form-control" maxlength="500" placeholder="https://www.figma.com/design/…"></div>
                <div class="col-md-6"><label class="form-label"><i class="bi bi-palette me-1"></i>Adobe XD Design URL</label><input type="url" name="xd_url" class="form-control" maxlength="500" placeholder="https://xd.adobe.com/view/…"></div>
                <div class="col-md-6"><label class="form-label"><i class="bi bi-window-stack me-1"></i>HTML / Static Demo URL</label><input type="url" name="demo_url" class="form-control" maxlength="500" placeholder="https://demo.example.com/…"></div>
                <div class="col-md-6"><label class="form-label"><i class="bi bi-link-45deg me-1"></i>Other Reference URL</label><input type="url" name="reference_url" class="form-control" maxlength="500" placeholder="https://…"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2 me-1"></i>Save Website</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  const $m = $('#websiteModal'), $f = $m.find('form');
  // WordPress = the three login fields become mandatory (server side enforces it too)
  function applyWp(hasPassword) {
    const wp = $f.find('[name=technology]').val() === 'WordPress';
    $f.find('[name=wp_login_url], [name=wp_username]').prop('required', wp);
    $f.find('[name=wp_password]').prop('required', wp && !hasPassword);
    $f.find('.wp-req').toggle(wp);
    $('#wsTabWpBtn').toggleClass('text-danger fw-semibold', wp);
    $('#w_tech_hint').text(wp ? 'WordPress: login URL, username and password are required.' : '');
    if (wp && !$f.find('[name=wp_login_url]').val()) {
      const u = ($f.find('[name=url]').val() || '').replace(/\/+$/, '');
      if (u) $f.find('[name=wp_login_url]').val(u + '/wp-admin');
    }
  }
  $f.on('change', '[name=technology]', () => applyWp($f.data('hasWpPassword')));
  $f.on('change', '[name=url]', function () { if ($f.find('[name=technology]').val() === 'WordPress' && !$f.find('[name=wp_login_url]').val()) $f.find('[name=wp_login_url]').val(this.value.replace(/\/+$/, '') + '/wp-admin'); });
  // When a required field on a hidden tab blocks submit, show that tab
  $f.on('submit', function (e) {
    if (this.checkValidity && !this.checkValidity()) {
      const bad = $f.find(':invalid').first();
      if (bad.length) { const pane = bad.closest('.tab-pane'); if (pane.length) $m.find('[data-bs-target="#' + pane.attr('id') + '"]').tab('show'); }
    }
  });
  $m.on('hidden.bs.modal', function () { $f.data('hasWpPassword', false); $('#wpPassHint').text(''); applyWp(false); });
  $m.on('shown.bs.modal', function () { applyWp(!!$f.data('hasWpPassword')); });

  // Populate everything when editing (WordPress password is never sent to the browser – blank = keep current)
  window.fillWebsiteModal = function (w) {
    $f[0].reset();
    CRM.fillForm($f, w);
    $f.find('[name=check_now]').prop('checked', false);
    $f.find('[name=max_pages]').val(w.max_pages && w.max_pages > 0 ? w.max_pages : '');
    $f.data('hasWpPassword', !!w.has_wp_password);
    $('#wpPassHint').text(w.has_wp_password ? '(saved – leave blank to keep the current password)' : '');
    if (w.domain) {
      CRM.fillForm($f, { domain_name: w.domain.domain_name, domain_provider: w.domain.registrar, domain_registration_date: w.domain.registration_date, domain_expiry: w.domain.expiry_date, domain_auto_renew: w.domain.auto_renew, domain_login_ref: w.domain.login_ref });
    }
    if (w.hosting) {
      CRM.fillForm($f, { hosting_provider: w.hosting.provider, server_ip: w.hosting.server_ip, hosting_plan: w.hosting.plan, hosting_start_date: w.hosting.start_date, hosting_expiry: w.hosting.expiry_date, hosting_renewal_status: w.hosting.renewal_status, hosting_login_ref: w.hosting.login_ref || w.hosting_login_ref });
    }
    $m.find('.modal-title').text('Edit Website');
    $m.find('.nav-tabs .nav-link').first().tab('show');
    applyWp(!!w.has_wp_password);
    bootstrap.Modal.getOrCreateInstance($m[0]).show();
  };
})();
</script>
