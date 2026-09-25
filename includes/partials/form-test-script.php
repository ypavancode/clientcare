<?php /** Shared JS for form rows: Test now (result dialog), Test history modal, Edit (fills #formModal). Include after the form modal. */ ?>
<script>
$(document).on('click', '.btn-edit-form', function () {
  CRM.post('api/forms.php', { action: 'get', id: $(this).data('id') }).done(res => { if (res.success) fillFormModal(res.form); });
});
$(document).on('click', '.btn-test-form', function () {
  const $b = $(this); const id = $b.data('id');
  $b.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin"></i>');
  CRM.post('api/forms.php', { action: 'test', id: id }).done(function (res) {
    const r = res.result || {}, esc = s => $('<div>').text(s == null ? '' : String(s)).html();
    const ok = !!r.success, blocked = !!res.blocked, cfg = r.outcome === 'config_error';
    const cls = ok ? 'text-success' : (blocked ? 'text-info' : (cfg ? 'text-warning' : 'text-danger'));
    const row = (k, v, c) => v ? '<div class="mb-1"><div class="text-muted small-xs text-uppercase">' + k + '</div><div class="' + (c || '') + '">' + v + '</div></div>' : '';
    const modeLabel = { popup: 'Popup', submission: 'Normal', availability: 'Availability' }[r.mode] || r.mode;
    const steps = (res.steps || []).map(s => '<li class="' + (s.ok === false ? 'text-danger' : (s.ok === null ? 'text-muted' : '')) + '"><span class="mono">' + esc(s.step) + '</span> – ' + esc(s.detail) + '</li>').join('');
    Swal.fire({ icon: res.icon || (ok ? 'success' : 'error'), title: ok ? 'Form test passed' : (blocked ? 'Form test blocked' : (cfg ? 'Form test could not run' : 'Form test failed')), width: 640,
      html: '<div class="text-start small">' +
        row('Mode', esc(modeLabel) + (r.engine ? ' <span class="text-muted">· ' + esc(r.engine) + ' engine</span>' : '')) +
        row('HTTP', (r.http_code || '—') + (r.ajax_status ? ' <span class="text-muted">· AJAX ' + r.ajax_status + '</span>' : '')) +
        row('Response Time', (r.response_ms || 0) + ' ms') +
        row('Result', res.outcome_html || esc(res.outcome_label || (ok ? 'WORKING' : 'FAILED')), cls) +
        (res.captcha_label ? row('CAPTCHA / Anti-bot detected', esc(res.captcha_label)) : '') +
        (res.interference ? row('Third-party widget', esc(res.interference)) : '') +
        (r.reason ? row(ok ? 'Note' : 'Reason', esc(r.reason), cls) : '') +
        (ok && r.excerpt ? row('Success Message', esc(r.excerpt), 'text-success') : '') +
        (!ok && r.error ? row('Details', esc(r.error), blocked || cfg ? 'text-muted' : 'text-danger') : '') +
        (!ok && !blocked && r.excerpt ? row('Response', esc(r.excerpt), 'text-muted') : '') +
        (res.action ? row('Action', esc(res.action), 'text-info') : '') +
        row('Email Received', esc(String(r.email_received || 'unknown').toUpperCase()), r.email_received === 'yes' ? 'text-success' : (r.email_received === 'no' ? 'text-danger' : 'text-muted')) +
        row('Last Successful Test', esc(res.last_success_at || 'Never')) +
        (steps ? '<details class="mt-2"><summary class="text-muted">Test steps</summary><ul class="small-xs ps-3 mt-1 mb-0">' + steps + '</ul></details>' : '') +
        '</div>', confirmButtonText: 'OK' }).then(() => { if (Object.keys(CRM.tables || {}).some(k => k)) CRM.reloadTable(); else window.location.reload(); });
  }).always(() => $b.prop('disabled', false).html('<i class="bi bi-play-circle"></i>'));
});
$(document).on('click', '.btn-history', function () {
  const id = $(this).data('id');
  $('#historyModal .modal-title').text('Test History · ' + $(this).data('name'));
  $('#historyBody').html('<div class="p-4 text-center text-muted">Loading…</div>');
  bootstrap.Modal.getOrCreateInstance($('#historyModal')[0]).show();
  $.get(CRM.url('forms/history.php'), { id: id }, html => $('#historyBody').html(html));
});
</script>
