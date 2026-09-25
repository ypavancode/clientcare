<?php /** JS for ".run-check" buttons: runs api/monitor.php in batches with a progress dialog. */ ?>
<script>
$(document).on('click', '.run-check', async function (e) {
  if (e && e.preventDefault) e.preventDefault();
  const action = $(this).data('check');
  const labels = { check_websites: 'Checking websites', check_pages: 'Scanning all website pages', check_forms: 'Testing forms', check_ssl: 'Checking SSL certificates', check_expiry: 'Checking expiry dates' };
  const notes = { check_forms: 'Forms with a test payload will receive a test submission using the configured test email address.', check_pages: 'Every monitored page of every active website is requested now (pages are re-discovered where the list is stale). This can take a few minutes.' };
  const ok = await CRM.confirm({ title: labels[action] + '?', text: notes[action] || 'This runs the check immediately for all active websites.', icon: 'question', confirmButtonText: 'Run now' });
  if (!ok) return;
  Swal.fire({ title: labels[action] + '…', html: '<div class="small text-muted" id="chkProgress">Starting…</div>', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
  let offset = 0, batch = action === 'check_websites' ? 50 : (action === 'check_pages' ? 10 : 5), last = null;
  try {
    while (true) {
      const res = await $.ajax({ url: CRM.url('api/monitor.php'), method: 'POST', data: { action: action, offset: offset, batch: batch }, dataType: 'json', timeout: 300000 });
      last = res;
      $('#chkProgress').text(res.message);
      if (!res.success || res.finished) break;
      offset = res.done;
    }
    Swal.close();
    if (last && last.success) {
      let txt = last.message;
      if (last.summary) txt += ' ' + Object.entries(last.summary).map(([k, v]) => v + ' ' + k).join(', ') + '.';
      await Swal.fire({ icon: 'success', title: 'Check complete', text: txt, confirmButtonText: 'Refresh' });
      window.location.reload();
    } else {
      CRM.toast((last && last.message) || 'Check failed', 'error');
    }
  } catch (xhr) {
    Swal.close();
    CRM.toast((xhr.responseJSON && xhr.responseJSON.message) || 'The check could not be completed (timeout or server error). Partial results were saved.', 'error');
  }
});
</script>
