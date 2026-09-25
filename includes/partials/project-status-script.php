<?php /** JS shared by the projects list and detail page: change status (with note), offer monitoring when Live. */ ?>
<script>
window.changeProjectStatus = async function (id, status, label) {
  const { value: note, isDismissed } = await Swal.fire({
    title: 'Change status to "' + label + '"?', input: 'text', inputPlaceholder: 'Optional note for the status history', showCancelButton: true,
    confirmButtonText: 'Change status', cancelButtonText: 'Cancel', reverseButtons: true, inputAttributes: { maxlength: 500 }
  });
  if (isDismissed) return;
  CRM.post('api/projects.php', { action: 'status', id: id, status: status, note: note || '' }).done(async function (res) {
    if (!res.success) { CRM.toast(res.message || 'Failed', 'error'); return; }
    CRM.toast(res.message);
    if (res.offer_monitoring) {
      const r = await Swal.fire({ icon: 'question', title: 'Website is Live 🎉', text: res.has_url ? 'Enable automatic monitoring for this website now? (uptime, pages, SSL and forms will be checked every few minutes)' : 'Add the website URL to the project, then enable monitoring from the project page.', showCancelButton: res.has_url, confirmButtonText: res.has_url ? 'Enable Monitoring' : 'OK', cancelButtonText: 'Not now', reverseButtons: true });
      if (res.has_url && r.isConfirmed) {
        CRM.loading('Enabling monitoring and running the first check…');
        CRM.post('api/projects.php', { action: 'enable_monitoring', id: id }).done(r2 => { Swal.close(); CRM.toast(r2.message, r2.success ? 'success' : 'error'); window.location.reload(); }).fail(() => Swal.close());
        return;
      }
    }
    if (Object.keys(CRM.tables || {}).length) CRM.reloadTable(); else window.location.reload();
  });
};
$(document).on('click', '.btn-status', function (e) { e.preventDefault(); changeProjectStatus($(this).data('id'), $(this).data('status'), $(this).text().trim()); });
$(document).on('click', '.btn-edit-project', function () { openProjectEdit($(this).data('id')); });
</script>
