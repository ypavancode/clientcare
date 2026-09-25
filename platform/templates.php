<?php
/**
 * Email Templates studio (Super Admin) – v3.8.
 * Every template = card with a live thumbnail of the real email (lazy, rendered on scroll), Preview (desktop /
 * tablet / mobile), Edit (subject, content, variables, sender, CTA, image, footer, status – live preview) and
 * Send Test Email (real SMTP, real result). Recent template deliveries with duration + SMTP response at the bottom.
 * Data: api/templates.php. Page CSS is lazy (assets/css/email-templates.css) and no editor library is loaded.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;

$templates = Mailer::templateList();
$commonVars = ['company_name', 'sender_email', 'sender_name', 'date', 'time', 'link'];
$smtp = Mailer::status();
$active = count(array_filter($templates, fn($t) => $t['status'] === 'active'));
$custom = count(array_filter($templates, fn($t) => $t['customized']));
$me = Auth::user();
$editorData = [];
foreach ($templates as $type => $t) {
    $p = Mailer::previewTemplate($type);
    $t['subject_preview'] = $p['subject'];
    $t['preheader_preview'] = $p['preheader'];
    $templates[$type] = $t;
    $editorData[$type] = array_intersect_key($t, array_flip(['type', 'name', 'kind', 'tone', 'about', 'vars', 'subject', 'body', 'from_name', 'from_email', 'reply_to', 'preheader', 'cta_label', 'cta_url', 'header_image', 'footer_text', 'status', 'default_cta', 'customized', 'updated_at', 'default_subject', 'default_body', 'subject_preview']));
    $editorData[$type]['updated_label'] = $t['updated_at'] ? format_datetime($t['updated_at']) : null;
}
$lights = ['success' => '🟢', 'warning' => '🟠', 'danger' => '🔴', 'secondary' => '⚪'];

$pageTitle = 'Email Templates';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Email Templates']];
$pageSubtitle = 'Sent from <strong>' . e(Mailer::fromName()) . ' &lt;' . e(Mailer::fromEmail()) . '&gt;</strong> · every template below is the real email your clients receive.';
$pageActions = '<a href="' . url('platform/emails.php?tab=logs') . '" class="btn btn-light btn-sm"><i class="bi bi-journal-text me-1"></i>Email logs</a><a href="' . url('platform/emails.php?tab=settings') . '" class="btn btn-light btn-sm"><i class="bi bi-gear me-1"></i>SMTP settings</a>';
include ROOT_PATH . '/includes/layout/header.php';
?>
<link rel="stylesheet" href="<?= asset('css/email-templates.css') ?>">
<div class="tpl-strip stagger">
  <a class="ts tone-<?= e($smtp['tone']) ?>" href="<?= url('platform/emails.php') ?>" title="<?= e($smtp['detail']) ?>"><i class="bi bi-hdd-network"></i><div class="min-w-0"><div class="ts-v"><?= $lights[$smtp['tone']] ?? '' ?> <?= e($smtp['headline']) ?></div><div class="ts-l"><?= e($smtp['host'] ? $smtp['host'] . ':' . $smtp['port'] . ' · ' . $smtp['encryption'] : 'SMTP not configured') ?></div></div></a>
  <div class="ts tone-success"><i class="bi bi-send-check"></i><div class="min-w-0"><div class="ts-v" id="stripSent"><?= number_format($smtp['sent_24h']) ?></div><div class="ts-l">Sent in the last 24 h</div></div></div>
  <div class="ts <?= $smtp['failed_24h'] ? 'tone-danger' : '' ?>"><i class="bi bi-envelope-x"></i><div class="min-w-0"><div class="ts-v" id="stripFailed"><?= number_format($smtp['failed_24h']) ?></div><div class="ts-l">Failed in the last 24 h</div></div></div>
  <div class="ts"><i class="bi bi-file-earmark-text"></i><div class="min-w-0"><div class="ts-v"><span id="stripActive"><?= $active ?></span> / <?= count($templates) ?> active</div><div class="ts-l"><span id="stripCustom"><?= $custom ?></span> customised · <?= count($templates) - $custom ?> default</div></div></div>
</div>

<div class="tpl-toolbar">
  <input type="search" class="form-control form-control-sm" id="tplSearch" placeholder="Search templates…" autocomplete="off">
  <select class="form-select form-select-sm" id="tplKind"><option value="">All types</option><option value="Alert">Alerts</option><option value="Recovery">Recoveries</option><option value="Reminder">Reminders</option></select>
  <select class="form-select form-select-sm" id="tplStatus"><option value="">All statuses</option><option value="active">Active</option><option value="disabled">Disabled</option><option value="custom">Customised</option></select>
  <span class="tpl-count" id="tplCount"><?= count($templates) ?> templates</span>
</div>

<div class="tpl-grid stagger" id="tplGrid">
<?php foreach ($templates as $type => $t): ?>
  <article class="tpl-card <?= $t['status'] === 'disabled' ? 'is-disabled' : '' ?>" data-type="<?= e($type) ?>" data-name="<?= e(strtolower($t['name'] . ' ' . $t['subject_preview'] . ' ' . $t['about'])) ?>" data-kind="<?= e($t['kind']) ?>" data-status="<?= e($t['status']) ?>" data-custom="<?= $t['customized'] ? 1 : 0 ?>">
    <div class="tpl-tone <?= e($t['tone']) ?>"></div>
    <div class="tpl-thumb loading" data-type="<?= e($type) ?>" role="button" tabindex="0" aria-label="Preview <?= e($t['name']) ?>"><div class="tpl-thumb-inner"></div><span class="tpl-thumb-hint"><i class="bi bi-eye me-1"></i>Open preview</span></div>
    <div class="tpl-body">
      <div class="tpl-head"><div class="tpl-name"><?= e($t['name']) ?></div><span class="tpl-kind <?= e($t['tone']) ?>"><?= e($t['kind']) ?></span></div>
      <div class="tpl-subject" title="<?= e($t['subject_preview']) ?>"><b>Subject</b> <span data-f="subject"><?= e($t['subject_preview']) ?></span></div>
      <div class="tpl-meta">
        <div class="form-check form-switch" title="Active = emails of this type are sent"><input class="form-check-input tpl-status-switch" type="checkbox" role="switch" id="st_<?= e($type) ?>" <?= $t['status'] === 'active' ? 'checked' : '' ?>><label class="form-check-label" for="st_<?= e($type) ?>" data-f="status"><?= $t['status'] === 'active' ? 'Active' : 'Disabled' ?></label></div>
        <span data-f="updated" title="<?= $t['updated_at'] ? e(format_datetime($t['updated_at'])) : 'Built-in default text' ?>"><i class="bi bi-clock me-1"></i><?= $t['updated_at'] ? 'Updated ' . e(time_ago($t['updated_at'])) : 'Default' ?></span>
        <span data-f="sent" title="Deliveries in the last 30 days"><i class="bi bi-send me-1"></i><?= $t['last_sent_at'] ? 'Last sent ' . e(time_ago($t['last_sent_at'])) : 'Never sent' ?></span>
      </div>
      <div class="tpl-actions">
        <button class="btn btn-light btn-sm btn-tpl-preview" type="button"><i class="bi bi-eye"></i>Preview</button>
        <button class="btn btn-light btn-sm btn-tpl-edit" type="button"><i class="bi bi-pencil"></i>Edit</button>
        <button class="btn btn-dark btn-sm btn-tpl-test" type="button"><i class="bi bi-send"></i>Send Test</button>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>
<div id="tplEmpty" class="d-none"><?= empty_state('bi-search', 'No templates match', 'Try another search term or clear the filters.') ?></div>

<div class="card mt-3 reveal">
  <div class="card-header"><span><i class="bi bi-activity me-1"></i>Recent template deliveries</span><span class="d-flex gap-2 align-items-center"><span class="small fw-normal text-muted" id="recentMeta">real SMTP results · duration · server response</span><a href="<?= url('platform/emails.php?tab=logs') ?>" class="btn btn-light btn-sm">All logs</a></span></div>
  <div class="card-body py-2 px-3"><div class="recent-list" id="recentList"><?= skeleton_block(4) ?></div></div>
</div>

<!-- ============ PREVIEW ============ -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewTitle"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title" id="previewTitle"><i class="bi bi-eye me-2 text-brand"></i><span data-f="name">Preview</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body p-0">
    <div class="mail-head">
      <div class="mh-avatar" data-f="avatar">O</div>
      <div class="mh-main">
        <div class="mh-subject" data-f="subject">…</div>
        <div class="mh-line"><b data-f="from_name"></b> &lt;<span data-f="from_email"></span>&gt; · reply-to <span data-f="reply_to"></span></div>
        <div class="mh-line">to <span data-f="to">John Smith &lt;john.smith@acmedigital.com&gt;</span></div>
        <div class="mh-preheader" data-f="preheader"></div>
      </div>
      <div class="mh-side"><div data-f="date"><?= e(date('d M Y, h:i A')) ?></div><div class="mt-1"><span class="badge bg-secondary-subtle text-secondary" data-f="kind"></span></div></div>
    </div>
    <div class="preview-tools">
      <div class="device-switch" role="group" aria-label="Device">
        <button type="button" class="active" data-device="desktop"><i class="bi bi-display"></i>Desktop</button>
        <button type="button" data-device="tablet"><i class="bi bi-tablet"></i>Tablet <span>640</span></button>
        <button type="button" data-device="mobile"><i class="bi bi-phone"></i>Mobile <span>375</span></button>
      </div>
      <div class="pt-right">
        <button type="button" class="btn btn-light btn-sm" id="pvText"><i class="bi bi-file-text me-1"></i>Plain-text</button>
        <button type="button" class="btn btn-light btn-sm" id="pvEdit"><i class="bi bi-pencil me-1"></i>Edit</button>
        <button type="button" class="btn btn-dark btn-sm" id="pvTest"><i class="bi bi-send me-1"></i>Send Test Email</button>
      </div>
    </div>
    <div class="device-stage"><div class="device-frame" id="pvFrame"><iframe id="pvIframe" title="Email preview" sandbox="allow-same-origin"></iframe></div></div>
  </div>
</div></div></div>

<!-- ============ EDITOR ============ -->
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="editorTitle"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title" id="editorTitle"><i class="bi bi-pencil-square me-2 text-brand"></i>Edit <span data-f="name"></span> <span class="badge bg-secondary-subtle text-secondary ms-1" data-f="kind"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body p-0">
    <form id="editorForm" class="editor-split" novalidate autocomplete="off" onsubmit="return false">
      <input type="hidden" name="type" value="">
      <div class="editor-form">
        <ul class="nav nav-pills" role="tablist">
          <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="pill" data-bs-target="#edContent">Content</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#edSender">Sender</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#edDesign">Button &amp; design</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="pill" data-bs-target="#edStatus">Status</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="edContent">
            <div class="mb-3"><label class="form-label">Subject</label><input type="text" name="subject" class="form-control form-control-sm ed-live" maxlength="250" required></div>
            <div class="mb-2"><label class="form-label d-flex justify-content-between"><span>Email content</span><span class="field-hint fw-normal text-lowercase">plain text · blank line = new block · "Label:" + value line = detail row</span></label>
              <textarea name="body" class="form-control body-editor ed-live" required spellcheck="false"></textarea>
              <div class="form-text">The branded header, colour bar, button and footer are added automatically. A closing "Regards, …" block becomes the signature.</div></div>
            <div class="mb-1"><label class="form-label">Dynamic variables <span class="fw-normal text-lowercase">(click to insert at the cursor)</span></label><div class="var-chips" id="varChips"></div></div>
            <div class="mb-3"><label class="form-label">Inbox preview text <span class="fw-normal text-lowercase">(optional)</span></label><input type="text" name="preheader" class="form-control form-control-sm ed-live" maxlength="190" placeholder="Shown next to the subject in the inbox – defaults to the first sentence"></div>
          </div>
          <div class="tab-pane fade" id="edSender">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">From name</label><input type="text" name="from_name" class="form-control form-control-sm ed-live" maxlength="150" placeholder="<?= e(Mailer::fromName()) ?>"><div class="form-text">Leave blank to use the platform sender name.</div></div>
              <div class="col-md-6"><label class="form-label">From email</label><input type="email" name="from_email" class="form-control form-control-sm ed-live" maxlength="190" placeholder="<?= e(Mailer::fromEmail()) ?>"><div class="form-text">Blank = <?= e(Mailer::fromEmail()) ?>. Another domain can fail DMARC at the recipient – keep it on your sending domain.</div></div>
              <div class="col-md-6"><label class="form-label">Reply-To</label><input type="email" name="reply_to" class="form-control form-control-sm ed-live" maxlength="190" placeholder="<?= e(Mailer::replyTo()) ?>"><div class="form-text">Where client replies go. Blank = the platform reply-to.</div></div>
              <div class="col-md-6"><div class="field-hint mt-md-4 pt-md-3"><i class="bi bi-shield-check me-1 text-success"></i>The envelope sender (Return-Path) always stays the authenticated SMTP account, so SPF keeps passing.</div></div>
            </div>
          </div>
          <div class="tab-pane fade" id="edDesign">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label">Button label</label><input type="text" name="cta_label" class="form-control form-control-sm ed-live" maxlength="80" placeholder="Open in CRM"></div>
              <div class="col-md-6"><label class="form-label">Button link</label><input type="url" name="cta_url" class="form-control form-control-sm ed-live" maxlength="500" placeholder="{{link}} – the related CRM page"><div class="form-text">Blank or {{link}} = the CRM page of the website / form. Variables allowed.</div></div>
              <div class="col-12"><label class="form-label">Header image URL <span class="fw-normal text-lowercase">(optional)</span></label><input type="url" name="header_image" class="form-control form-control-sm ed-live" maxlength="500" placeholder="https://… (600 px wide, PNG/JPG, publicly reachable)"><div class="form-text">Shown full width above the content. Use an absolute https:// URL on your own domain – email clients do not load local files.</div></div>
              <div class="col-12"><label class="form-label">Footer text</label><input type="text" name="footer_text" class="form-control form-control-sm ed-live" maxlength="500" placeholder="Automated notification from {{company_name}} Client Care · {{sender_email}}"><div class="form-text">Variables allowed. URLs and email addresses become links.</div></div>
            </div>
          </div>
          <div class="tab-pane fade" id="edStatus">
            <div class="status-toggle mb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="status_active" id="edStatusSwitch" checked><label class="form-check-label fw-600" for="edStatusSwitch">Template active</label></div><span class="field-hint">When disabled, this alert type is still shown in the CRM but no email is sent.</span></div>
            <input type="hidden" name="status" value="active">
            <div class="field-hint mb-2"><i class="bi bi-info-circle me-1"></i><span data-f="about"></span></div>
            <div class="field-hint" data-f="history"></div>
          </div>
        </div>
      </div>
      <div class="editor-preview">
        <div class="ep-tools"><span class="ep-status" id="epStatus"><span class="dot"></span>Live preview</span>
          <div class="device-switch"><button type="button" class="active" data-device="desktop" title="Desktop"><i class="bi bi-display"></i></button><button type="button" data-device="mobile" title="Mobile"><i class="bi bi-phone"></i></button></div></div>
        <div class="mail-head py-2"><div class="mh-main"><div class="mh-subject" data-f="subject">…</div><div class="mh-line"><b data-f="from_name"></b> &lt;<span data-f="from_email"></span>&gt;</div><div class="mh-preheader" data-f="preheader"></div></div></div>
        <div class="device-stage"><div class="device-frame" id="edFrame"><iframe id="edIframe" title="Live preview" sandbox="allow-same-origin"></iframe></div></div>
      </div>
    </form>
  </div>
  <div class="editor-footer">
    <span class="small text-muted" id="edSaved"></span>
    <div class="ef-right">
      <button type="button" class="btn btn-light btn-sm" id="edReset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset to default</button>
      <button type="button" class="btn btn-light btn-sm" id="edTest"><i class="bi bi-send me-1"></i>Send test with these values</button>
      <button type="button" class="btn btn-dark btn-sm" id="edSave"><i class="bi bi-check2 me-1"></i>Save template</button>
    </div>
  </div>
</div></div></div>

<!-- ============ SEND TEST ============ -->
<div class="modal fade" id="testModal" tabindex="-1" aria-labelledby="testTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h5 class="modal-title" id="testTitle"><i class="bi bi-send me-2 text-brand"></i>Send Test Email · <span data-f="name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <form id="testForm" onsubmit="return false" autocomplete="off">
      <div class="mb-2"><label class="form-label small text-muted mb-1">Recipient</label><input type="email" name="to" class="form-control" required value="<?= e($me['email'] ?? '') ?>" placeholder="you@example.com"></div>
      <div class="small text-muted mb-3" id="testNote">The exact template is rendered with sample data and sent through the configured SMTP server (<?= e($smtp['host'] ? $smtp['host'] . ':' . $smtp['port'] . ' · ' . $smtp['encryption'] : 'not configured') ?>). Nothing is simulated.</div>
      <div class="d-flex gap-2 flex-wrap"><button type="submit" class="btn btn-dark" id="testSend"><i class="bi bi-send me-1"></i>Send test email</button><a class="btn btn-light" href="<?= url('platform/emails.php?tab=settings') ?>">SMTP settings</a></div>
    </form>
    <div id="testResult" class="mt-3"></div>
  </div>
</div></div></div>

<script type="application/json" id="tplData"><?= json_encode(['templates' => $editorData, 'common' => $commonVars, 'me' => $me['email'] ?? '', 'company' => company_name(), 'from_name' => Mailer::fromName(), 'from_email' => Mailer::fromEmail(), 'reply_to' => Mailer::replyTo(), 'logs_url' => url('platform/emails.php?tab=logs'), 'max_wait' => (int) setting('smtp_connect_timeout', 10) * 2 + (int) setting('smtp_timeout', 20) * 4 + 25], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  const D = JSON.parse(document.getElementById('tplData').textContent);
  const T = D.templates, cache = {}, esc = s => CRM.esc(s == null ? '' : String(s));
  const modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
  const card = type => document.querySelector('.tpl-card[data-type="' + type + '"]');

  /* ---------- previews (fetched lazily, cached until the template changes) ---------- */
  function fetchPreview(type, override) {
    if (!override && cache[type]) return $.Deferred().resolve(cache[type]).promise();
    const data = { action: 'preview', type: type };
    if (override) { data.use_form = 1; Object.assign(data, override); }
    return CRM.post('api/templates.php', data).then(res => { if (!res.success) throw new Error(res.message); if (!override) cache[type] = res.preview; return res.preview; });
  }
  function fitThumb(el) {
    const inner = el.querySelector('.tpl-thumb-inner'); if (!inner) return;
    const scale = el.clientWidth / 600; inner.style.transform = 'scale(' + scale + ')';
  }
  const io = ('IntersectionObserver' in window) ? new IntersectionObserver(entries => { entries.forEach(en => { if (!en.isIntersecting) return; io.unobserve(en.target); loadThumb(en.target); }); }, { rootMargin: '160px' }) : null;
  function loadThumb(el) {
    const type = el.dataset.type;
    fetchPreview(type).then(p => {
      const inner = el.querySelector('.tpl-thumb-inner'); inner.innerHTML = '';
      const f = document.createElement('iframe'); f.setAttribute('sandbox', ''); f.setAttribute('tabindex', '-1'); f.setAttribute('title', p.name + ' preview'); f.setAttribute('loading', 'lazy'); f.srcdoc = p.html;
      inner.appendChild(f); el.classList.remove('loading'); fitThumb(el);
    }).catch(err => { el.classList.remove('loading'); el.innerHTML = '<div class="tpl-thumb-err"><i class="bi bi-exclamation-triangle me-1"></i>' + esc(err.message || 'Preview failed') + '</div>'; });
  }
  document.querySelectorAll('.tpl-thumb').forEach(el => { io ? io.observe(el) : loadThumb(el); });
  window.addEventListener('resize', () => document.querySelectorAll('.tpl-thumb').forEach(fitThumb));
  function refreshThumb(type) { delete cache[type]; const el = card(type)?.querySelector('.tpl-thumb'); if (el) { el.classList.add('loading'); loadThumb(el); } }

  /* ---------- card helpers ---------- */
  function applyCard(t) {
    const c = card(t.type); if (!c) return;
    c.dataset.status = t.status; c.dataset.custom = t.customized ? 1 : 0; c.classList.toggle('is-disabled', t.status === 'disabled');
    c.querySelector('[data-f=subject]').textContent = t.subject || t.subject_preview || ''; c.querySelector('.tpl-subject').title = t.subject || '';
    c.querySelector('[data-f=status]').textContent = t.status === 'active' ? 'Active' : 'Disabled'; c.querySelector('.tpl-status-switch').checked = t.status === 'active';
    c.querySelector('[data-f=updated]').innerHTML = '<i class="bi bi-clock me-1"></i>' + (t.updated_ago ? 'Updated ' + esc(t.updated_ago) : 'Default');
    if (t.last_sent_ago) c.querySelector('[data-f=sent]').innerHTML = '<i class="bi bi-send me-1"></i>Last sent ' + esc(t.last_sent_ago);
    const cards = [...document.querySelectorAll('.tpl-card')];
    document.getElementById('stripActive').textContent = cards.filter(x => x.dataset.status === 'active').length;
    document.getElementById('stripCustom').textContent = cards.filter(x => x.dataset.custom === '1').length;
  }
  function filterCards() {
    const q = $('#tplSearch').val().toLowerCase().trim(), kind = $('#tplKind').val(), st = $('#tplStatus').val(); let n = 0;
    document.querySelectorAll('.tpl-card').forEach(c => {
      const show = (!q || c.dataset.name.includes(q)) && (!kind || c.dataset.kind === kind) && (!st || (st === 'custom' ? c.dataset.custom === '1' : c.dataset.status === st));
      c.classList.toggle('is-hidden', !show); if (show) n++;
    });
    $('#tplCount').text(n + ' template' + (n === 1 ? '' : 's')); $('#tplEmpty').toggleClass('d-none', n > 0);
    document.querySelectorAll('.tpl-thumb.loading').forEach(el => { if (!el.closest('.is-hidden') && io) io.observe(el); });
  }
  $('#tplSearch').on('input', filterCards); $('#tplKind, #tplStatus').on('change', filterCards);

  $(document).on('change', '.tpl-status-switch', function () {
    const c = this.closest('.tpl-card'), type = c.dataset.type, status = this.checked ? 'active' : 'disabled', sw = this; sw.disabled = true;
    CRM.post('api/templates.php', { action: 'status', type: type, status: status }).done(res => { if (res.success) { T[type].status = status; applyCard(res.template); CRM.toast(res.message); } else { sw.checked = !sw.checked; CRM.toast(res.message, 'error'); } }).fail(() => { sw.checked = !sw.checked; }).always(() => { sw.disabled = false; });
  });

  /* ---------- device switch (shared) ---------- */
  function fitIframe(f) { try { const h = f.contentDocument && f.contentDocument.documentElement ? Math.max(f.contentDocument.documentElement.scrollHeight, f.contentDocument.body.scrollHeight) : 0; if (h) f.style.height = (h + 4) + 'px'; } catch (e) {} }
  $(document).on('click', '.device-switch button', function () {
    const $b = $(this), frame = $b.closest('.modal-content').find('.device-frame');
    $b.addClass('active').siblings().removeClass('active'); frame.removeClass('tablet mobile');
    if ($b.data('device') !== 'desktop') frame.addClass($b.data('device'));
    setTimeout(() => frame.find('iframe').each((_, f) => fitIframe(f)), 480);
  });
  document.getElementById('pvIframe').addEventListener('load', function () { fitIframe(this); });
  document.getElementById('edIframe').addEventListener('load', function () { fitIframe(this); });

  /* ---------- preview modal ---------- */
  let pvType = null, pvMode = 'html';
  function fillHead($m, p) {
    $m.find('[data-f=name]').text(p.name); $m.find('[data-f=subject]').text(p.subject); $m.find('[data-f=from_name]').text(p.from_name); $m.find('[data-f=from_email]').text(p.from_email); $m.find('[data-f=reply_to]').text(p.reply_to);
    $m.find('[data-f=preheader]').text(p.preheader || ''); $m.find('[data-f=kind]').text(p.kind); $m.find('[data-f=avatar]').text((p.from_name || D.company).charAt(0).toUpperCase());
  }
  function showPreview(type) {
    pvType = type; pvMode = 'html'; const $m = $('#previewModal'); $m.find('[data-f=name]').text(T[type].name); $('#pvText').removeClass('active');
    document.getElementById('pvIframe').srcdoc = '<div style="font:13px Inter,system-ui;color:#6b7280;padding:24px;text-align:center">Rendering…</div>';
    modal('previewModal').show();
    fetchPreview(type).then(p => { fillHead($m, p); document.getElementById('pvIframe').srcdoc = p.html; }).catch(err => CRM.toast(err.message || 'Preview failed', 'error'));
  }
  $(document).on('click', '.btn-tpl-preview, .tpl-thumb', function () { showPreview(this.closest('.tpl-card').dataset.type); });
  $(document).on('keydown', '.tpl-thumb', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); showPreview(this.closest('.tpl-card').dataset.type); } });
  $('#pvText').on('click', function () {
    const p = cache[pvType]; if (!p) return; pvMode = pvMode === 'html' ? 'text' : 'html'; $(this).toggleClass('active', pvMode === 'text');
    document.getElementById('pvIframe').srcdoc = pvMode === 'text' ? '<pre style="margin:0;padding:22px;font:13px/1.6 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;color:#111;background:#fff">' + esc(p.text) + '</pre>' : p.html;
  });
  $('#pvEdit').on('click', () => { modal('previewModal').hide(); openEditor(pvType); });
  $('#pvTest').on('click', () => { modal('previewModal').hide(); openTest(pvType, null); });

  /* ---------- editor ---------- */
  const F = document.getElementById('editorForm'), fields = ['subject', 'body', 'preheader', 'from_name', 'from_email', 'reply_to', 'cta_label', 'cta_url', 'header_image', 'footer_text'];
  let edType = null, edTimer = null, edReq = null, lastFocus = null, dirty = false;
  function formValues() { const o = {}; fields.forEach(f => { o[f] = F.elements[f].value; }); o.status = F.elements.status_active.checked ? 'active' : 'disabled'; return o; }
  function openEditor(type) {
    const t = T[type]; edType = type; dirty = false; F.elements.type.value = type;
    fields.forEach(f => { F.elements[f].value = t[f] || ''; });
    F.elements.status_active.checked = t.status !== 'disabled'; F.elements.status.value = t.status;
    const $m = $('#editorModal'); $m.find('[data-f=name]').text(t.name); $m.find('[data-f=kind]').text(t.kind); $m.find('[data-f=about]').text(t.about);
    $m.find('[data-f=history]').html(t.customized ? '<i class="bi bi-pencil me-1"></i>Customised · last saved ' + esc(t.updated_label) : '<i class="bi bi-file-earmark me-1"></i>Using the built-in default text.');
    $('#edSaved').text(t.customized ? 'Last saved ' + t.updated_label : 'Built-in default'); $('#edReset').toggle(!!t.customized);
    $('#varChips').html(t.vars.map(v => '<span class="var-chip" data-v="{{' + v + '}}">{{' + v + '}}</span>').join('') + D.common.map(v => '<span class="var-chip common" data-v="{{' + v + '}}" title="always available">{{' + v + '}}</span>').join(''));
    $m.find('.invalid-feedback.server').remove(); $m.find('.is-invalid').removeClass('is-invalid');
    bootstrap.Tab.getOrCreateInstance($m.find('[data-bs-target="#edContent"]')[0]).show();
    $('#edFrame').removeClass('mobile'); $m.find('.device-switch button').removeClass('active').first().addClass('active');
    modal('editorModal').show(); livePreview(true);
  }
  function livePreview(immediate) {
    clearTimeout(edTimer); $('#epStatus').addClass('busy').html('<span class="dot"></span>Updating…');
    edTimer = setTimeout(() => {
      if (edReq && edReq.abort) edReq.abort();
      const v = formValues(); edReq = CRM.post('api/templates.php', Object.assign({ action: 'preview', type: edType, use_form: 1 }, v));
      edReq.done(res => { if (!res.success) return; const p = res.preview; fillHead($('#editorModal'), p); document.getElementById('edIframe').srcdoc = p.html; $('#epStatus').removeClass('busy').html('<span class="dot"></span>Live preview' + (dirty ? ' · unsaved changes' : '')); });
    }, immediate ? 0 : 450);
  }
  $(F).on('input change', '.ed-live', () => { dirty = true; livePreview(false); });
  $(F).on('focusin', 'input[type=text], input[type=url], textarea', function () { lastFocus = this; });
  F.elements.status_active.addEventListener('change', function () { F.elements.status.value = this.checked ? 'active' : 'disabled'; dirty = true; });
  $('#varChips').on('click', '.var-chip', function () {
    const el = (lastFocus && F.contains(lastFocus)) ? lastFocus : F.elements.body, v = this.dataset.v;
    const s = el.selectionStart ?? el.value.length, e = el.selectionEnd ?? el.value.length; el.value = el.value.slice(0, s) + v + el.value.slice(e); el.focus(); el.selectionStart = el.selectionEnd = s + v.length;
    dirty = true; livePreview(false);
  });
  function showErrors(errors) {
    $(F).find('.invalid-feedback.server').remove(); $(F).find('.is-invalid').removeClass('is-invalid'); let first = null;
    Object.entries(errors || {}).forEach(([k, msg]) => { const el = F.elements[k]; if (!el) return; el.classList.add('is-invalid'); $(el).closest('.mb-3, .mb-2, .col-12, .col-md-6').append('<div class="invalid-feedback server d-block">' + esc(msg) + '</div>'); if (!first) first = el; });
    if (first) { const pane = first.closest('.tab-pane'); if (pane && !pane.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#' + pane.id + '"]')).show(); first.focus(); }
  }
  $('#edSave').on('click', function () {
    const $b = $(this), orig = $b.html(); $b.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Saving…');
    CRM.post('api/templates.php', Object.assign({ action: 'save', type: edType }, formValues())).done(res => {
      if (!res.success) { CRM.toast(res.message || 'Could not save', 'error'); showErrors(res.errors); return; }
      const v = formValues(); Object.assign(T[edType], v, { customized: true, updated_label: res.template.updated_label, status: res.template.status, subject_preview: res.preview.subject });
      cache[edType] = res.preview; applyCard(Object.assign({}, res.template, { subject: res.preview.subject })); refreshThumb(edType); dirty = false;
      $('#edSaved').text('Saved just now'); $('#edReset').show(); $('#editorModal [data-f=history]').html('<i class="bi bi-pencil me-1"></i>Customised · saved just now'); $('#epStatus').removeClass('busy').html('<span class="dot"></span>Live preview');
      CRM.toast(res.message);
    }).always(() => $b.prop('disabled', false).html(orig));
  });
  $('#edReset').on('click', async function () {
    if (!await CRM.confirm({ title: 'Reset to default?', text: 'The built-in text, sender and button settings are restored. Your changes to this template are discarded.', icon: 'question', confirmButtonText: 'Reset' })) return;
    CRM.post('api/templates.php', { action: 'reset', type: edType }).done(res => {
      if (!res.success) { CRM.toast(res.message, 'error'); return; }
      const t = T[edType]; fields.forEach(f => { t[f] = f === 'subject' ? t.default_subject : (f === 'body' ? t.default_body : ''); }); t.status = 'active'; t.customized = false; t.updated_label = null; t.subject_preview = res.preview.subject;
      cache[edType] = res.preview; applyCard(Object.assign({}, res.template, { subject: res.preview.subject })); refreshThumb(edType); openEditor(edType); CRM.toast(res.message);
    });
  });
  $('#edTest').on('click', () => openTest(edType, formValues()));
  document.getElementById('editorModal').addEventListener('hide.bs.modal', e => { if (dirty && !confirm('Discard unsaved changes to this template?')) e.preventDefault(); else dirty = false; });

  /* ---------- send test ---------- */
  let testType = null, testOverride = null, testTimer = null;
  function openTest(type, override) {
    testType = type; testOverride = override; const $m = $('#testModal'); $m.find('[data-f=name]').text(T[type].name); $('#testResult').empty();
    $('#testNote').html((override ? '<i class="bi bi-pencil me-1"></i>Sends the <b>unsaved editor values</b> of this template' : 'The exact saved template') + ' rendered with sample data through the configured SMTP server. Nothing is simulated – the real server response is shown below.');
    if (!$m.find('[name=to]').val()) $m.find('[name=to]').val(D.me);
    modal('testModal').show(); setTimeout(() => $m.find('[name=to]').trigger('focus'), 300);
  }
  $(document).on('click', '.btn-tpl-test', function () { openTest(this.closest('.tpl-card').dataset.type, null); });
  // the test dialog can sit on top of the editor: keep the page in "modal open" state when only the top one closes
  document.getElementById('testModal').addEventListener('hidden.bs.modal', () => { if (document.querySelector('#editorModal.show')) { document.body.classList.add('modal-open'); document.body.style.overflow = 'hidden'; } });
  const flow = ['Validate', 'Render template', 'Connect SMTP', 'Authenticate', 'Send', 'Server reply', 'Log'];
  function flowHtml(state) { return '<div class="test-flow">' + flow.map((s, i) => '<span class="' + (state === 'run' ? (i < 2 ? 'done' : (i === 2 ? 'run' : '')) : (state === 'ok' ? 'done' : (i < 2 ? 'done' : 'fail'))) + '">' + s + '</span>').join('<i class="bi bi-chevron-right small-xs"></i>') + '</div>'; }
  $('#testForm').on('submit', function () {
    const to = $(this).find('[name=to]').val().trim(); if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) { $(this).find('[name=to]').addClass('is-invalid').trigger('focus'); return; }
    $(this).find('[name=to]').removeClass('is-invalid');
    const $b = $('#testSend'), orig = $b.html(), t0 = Date.now(); $b.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin me-1"></i>Sending…');
    $('#testResult').html('<div class="test-result busy"><div class="tr-head"><i class="bi bi-arrow-repeat spin text-brand"></i>Connecting to the SMTP server… <span class="text-muted fw-normal small" id="testElapsed">0.0 s</span></div>' + flowHtml('run') + '<div class="tr-hint">Gives up after ' + D.max_wait + ' s – you can keep using the page meanwhile.</div></div>');
    testTimer = setInterval(() => $('#testElapsed').text(((Date.now() - t0) / 1000).toFixed(1) + ' s'), 100);
    const data = { action: 'send_test', type: testType, to: to }; if (testOverride) { data.use_form = 1; Object.assign(data, testOverride); }
    const finish = () => { clearInterval(testTimer); $b.prop('disabled', false).html(orig); };
    CRM.post('api/templates.php', data, { timeout: D.max_wait * 1000 }).done(res => {
      finish();
      let h = '<div class="test-result ' + (res.success ? 'ok' : 'err') + '"><div class="tr-head">' + (res.success ? '🟢' : '🔴') + ' ' + esc(res.headline || (res.success ? 'Test Email Sent Successfully' : 'Email Sending Failed')) + '</div>' + flowHtml(res.success ? 'ok' : 'fail') + '<dl class="tr-grid">';
      const rows = [['Recipient', res.to], ['Subject', res.subject], ['Status', res.success ? 'Sent' : 'Failed'], ['Sending time', res.seconds != null ? res.seconds + ' s' : null], ['SMTP response', res.smtp_response || (res.success ? 'Accepted' : null)], ['Error', res.success ? null : ((res.kind_label ? res.kind_label + ': ' : '') + (res.error || res.reason || ''))], ['Message-ID', res.message_id], ['Transport', res.transport]];
      rows.forEach(([k, v]) => { if (v) h += '<dt>' + k + '</dt><dd>' + esc(v) + '</dd>'; });
      h += '</dl>' + (res.suggestion ? '<div class="tr-hint"><i class="bi bi-lightbulb me-1"></i>' + esc(res.suggestion) + '</div>' : '') + '<div class="tr-hint">' + (res.success ? 'Delivery to the inbox now depends on the mail provider – normally under a minute. ' : '') + '<a href="' + esc(res.logs_url || D.logs_url) + '">View in email logs</a>' + (res.success ? '' : ' · <a href="' + esc(res.settings_url) + '">SMTP settings</a>') + '</div></div>';
      $('#testResult').html(h); CRM.toast(res.message, res.success ? 'success' : 'error');
      loadRecent(); if (res.success && T[testType]) { const c = card(testType); if (c) c.querySelector('[data-f=sent]').innerHTML = '<i class="bi bi-send me-1"></i>Last sent just now'; }
      if (res.success) $('#stripSent').text(parseInt($('#stripSent').text().replace(/,/g, ''), 10) + 1); else $('#stripFailed').text(parseInt($('#stripFailed').text().replace(/,/g, ''), 10) + 1);
    }).fail(xhr => {
      finish(); const secs = ((Date.now() - t0) / 1000).toFixed(1);
      const msg = xhr.statusText === 'timeout' ? 'No answer within ' + D.max_wait + ' s – the SMTP server (or this web server) is not responding. Check host, port, encryption and the server firewall.' : ((xhr.responseJSON && xhr.responseJSON.message) || ('The server returned HTTP ' + xhr.status + '.'));
      $('#testResult').html('<div class="test-result err"><div class="tr-head">🔴 Email Sending Failed</div>' + flowHtml('fail') + '<dl class="tr-grid"><dt>Error</dt><dd>' + esc(msg) + '</dd><dt>Elapsed</dt><dd>' + secs + ' s</dd></dl></div>');
    });
  });

  /* ---------- recent deliveries ---------- */
  function loadRecent() {
    CRM.post('api/templates.php', { action: 'recent', limit: 10 }).done(res => {
      if (!res.success) return; const rows = res.rows || [];
      if (!rows.length) { $('#recentList').html('<div class="empty-state py-3"><i class="bi bi-envelope-open"></i><div class="es-title">No template emails yet</div><div class="es-text">Send a test from any template – the real SMTP result appears here with its duration.</div></div>'); return; }
      $('#recentList').html(rows.map(r => '<div class="rl-row"><div class="rl-ico">' + (r.status === 'sent' ? '🟢' : '🔴') + '</div><div class="rl-main"><div><span class="fw-600 text-white">' + esc(r.template_name) + '</span>' + (r.is_test ? ' <span class="badge bg-secondary-subtle text-secondary">test</span>' : '') + ' <span class="text-muted">→ ' + esc(r.to_email) + '</span></div><div class="rl-sub">' + esc(r.subject) + (r.attempt > 1 ? ' · attempt ' + r.attempt : '') + '</div></div><div class="rl-resp ' + (r.status === 'sent' ? '' : 'err') + '" title="' + esc(r.status === 'sent' ? r.smtp_response : (r.kind_label + ': ' + r.error)) + '">' + esc(r.status === 'sent' ? (r.smtp_response || 'Accepted') : (r.kind_label + ': ' + (r.error || ''))) + '</div><div class="rl-ms">' + (r.seconds != null ? esc(r.seconds) + ' s' : '—') + '</div><div class="rl-time" title="' + esc(r.sent_label) + '">' + esc(r.sent_ago) + '</div></div>').join(''));
    });
  }
  loadRecent();
})();
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
