<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireRole('admin', 'manager');

$status = get('status', '');
$category = get('category', '');

// Pending / failed queue items (bounded)
$queue = DB::fetchAll("SELECT * FROM email_queue WHERE status <> 'sent' AND tenant_id = " . Tenant::id() . " ORDER BY id DESC LIMIT 100");
$counts = Cache::remember(Cache::vkey('data', 'emails:counts:' . Tenant::id()), 30, fn() => DB::fetch("SELECT
    (SELECT COUNT(*) FROM email_logs WHERE tenant_id = " . Tenant::id() . " AND status = 'sent') AS sent,
    (SELECT COUNT(*) FROM email_logs WHERE tenant_id = " . Tenant::id() . " AND status = 'failed') AS failed,
    (SELECT COUNT(*) FROM email_queue WHERE tenant_id = " . Tenant::id() . " AND status = 'pending') AS pending,
    (SELECT COUNT(*) FROM email_queue WHERE tenant_id = " . Tenant::id() . " AND status = 'failed') AS queue_failed,
    (SELECT COUNT(*) FROM email_logs WHERE tenant_id = " . Tenant::id() . " AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'sent') AS sent_24h"));
$categories = Cache::remember('emails:categories:' . Tenant::id(), 300, fn() => DB::fetchAll("SELECT DISTINCT category FROM email_logs WHERE tenant_id = " . Tenant::id() . " ORDER BY category"));

$pageTitle = 'Email Logs';
$breadcrumbs = [['label' => 'Email Logs']];
$pageSubtitle = 'Sender: <strong>' . e(Mailer::fromName()) . ' &lt;' . e(Mailer::fromEmail()) . '&gt;</strong> · Transport: PHPMailer / SMTP ' . (Mailer::configured() ? '<span class="text-success">configured</span>' : '<span class="text-danger">NOT configured</span>');
$pageActions = (Auth::isPlatformAdmin() ? '<button class="btn btn-outline-primary btn-sm btn-action" data-url="api/email.php" data-params=\'{"action":"process_queue"}\' data-reload="1"><i class="bi bi-send-check me-1"></i>Process Queue Now</button>' : '')
  . (Auth::isPlatformAdmin() ? '<a href="' . url('platform/emails.php?tab=settings') . '" class="btn btn-dark btn-sm"><i class="bi bi-gear me-1"></i>SMTP Settings</a><a href="' . url('platform/templates.php') . '" class="btn btn-light btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Templates</a>' : '');
include ROOT_PATH . '/includes/layout/header.php';
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><a href="?status=sent" class="stat-card"><div class="stat-icon tint-success"><i class="bi bi-envelope-check"></i></div><div><div class="stat-value"><?= number_format((int) $counts['sent']) ?></div><div class="stat-label">🟢 Sent <span class="text-muted">(<?= number_format((int) $counts['sent_24h']) ?> in 24h)</span></div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=failed" class="stat-card <?= $counts['failed'] || $counts['queue_failed'] ? 'alert-card border-danger' : '' ?>"><div class="stat-icon tint-danger"><i class="bi bi-envelope-x"></i></div><div><div class="stat-value"><?= number_format((int) $counts['failed'] + (int) $counts['queue_failed']) ?></div><div class="stat-label">🔴 Failed</div></div></a></div>
  <div class="col-6 col-md-3"><a href="?status=pending" class="stat-card"><div class="stat-icon tint-warning"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= number_format((int) $counts['pending']) ?></div><div class="stat-label">🟡 Pending in queue</div></div></a></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon tint-brand"><i class="bi bi-envelope-at"></i></div><div><div class="stat-value small text-truncate" title="<?= e(Mailer::fromEmail()) ?>"><?= e(Mailer::fromEmail()) ?></div><div class="stat-label">From / Reply-To: <?= e(Mailer::replyTo()) ?></div></div></div></div>
</div>
<form class="filter-bar" id="filters" onsubmit="return false">
  <select name="status" class="form-select form-select-sm"><option value="">All statuses</option><option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option><option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option></select>
  <select name="category" class="form-select form-select-sm"><option value="">All types</option><?php foreach ($categories as $c): ?><option value="<?= e($c['category']) ?>" <?= $category === $c['category'] ? 'selected' : '' ?>><?= e(Mailer::categoryLabel($c['category'])) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" class="form-control form-control-sm" value="<?= e(get('from', '')) ?>"><input type="date" name="to" class="form-control form-control-sm" value="<?= e(get('to', '')) ?>">
  <a href="<?= url('emails/index.php') ?>" class="btn btn-light btn-sm">Clear</a>
</form>

<?php if ($queue && $status !== 'sent'): ?>
<div class="card mb-3">
  <div class="card-header"><span><i class="bi bi-hourglass-split text-warning me-1"></i>Queue (pending / failed after <?= Mailer::MAX_ATTEMPTS ?> attempts)</span><span class="small fw-normal text-muted">latest <?= count($queue) ?></span></div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover table-compact mb-0">
    <thead><tr><th>Queued</th><th>Recipient</th><th>Type</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Last error</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($queue as $m): ?>
      <tr data-row-id="q<?= $m['id'] ?>"><td class="text-nowrap"><?= format_datetime($m['created_at']) ?></td><td><?= e($m['to_email']) ?><?php if ($m['cc']): ?><div class="small-xs text-muted">CC: <?= e($m['cc']) ?></div><?php endif; ?></td><td><span class="badge bg-secondary-subtle text-secondary"><?= e(Mailer::categoryLabel($m['category'])) ?></span></td><td class="small"><?= e(truncate($m['subject'], 60)) ?></td>
        <td><?= $m['status'] === 'pending' ? status_pill('warning', 'Pending') : status_pill('danger', 'Failed') ?></td><td><?= (int) $m['attempts'] ?>/<?= Mailer::MAX_ATTEMPTS ?></td><td class="small text-danger"><?= e(truncate($m['last_error'] ?? '', 80)) ?></td>
        <td class="row-actions text-end"><button class="btn btn-light btn-sm btn-view-email" data-id="<?= $m['id'] ?>" data-src="queue" title="View"><i class="bi bi-eye"></i></button><button class="btn btn-light btn-sm btn-action" data-url="api/settings.php" data-params='{"action":"retry_email","id":<?= $m['id'] ?>}' data-reload="1" title="Retry now"><i class="bi bi-arrow-repeat"></i></button><?php if (Auth::isAdmin()): ?><button class="btn btn-light btn-sm text-danger btn-action" data-url="api/settings.php" data-params='{"action":"delete_queued","id":<?= $m['id'] ?>}' data-reload="1" title="Remove"><i class="bi bi-x-lg"></i></button><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
</div>
<?php endif; ?>

<div class="card dt-card">
  <div class="card-header"><span><i class="bi bi-journal-text me-1"></i>Delivery Log</span><span class="small fw-normal text-muted">retained <?= (int) setting('retention_email_logs_days', 180) ?> days</span></div>
  <table class="table table-hover datatable w-100 table-compact" data-source="emails" data-filters="#filters" data-page-length="50" data-order='[[0,"desc"]]'>
    <thead><tr><th>Date</th><th>Recipient</th><th>Type</th><th>Subject</th><th>Status</th><th>Sending time</th><th class="no-sort">SMTP response</th><th class="no-sort"></th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<div class="modal fade" id="emailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Email details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="emailModalBody">Loading…</div>
</div></div></div>
<?php
$pageScripts = <<<'JS'
<script>
$(document).on('click', '.btn-view-email', function () {
  const $b = $(this);
  bootstrap.Modal.getOrCreateInstance($('#emailModal')[0]).show();
  $('#emailModalBody').html('<div class="text-muted">Loading…</div>');
  CRM.post('api/settings.php', { action: 'email_details', id: $b.data('id'), src: $b.data('src') }).done(function (res) {
    if (!res.success) { $('#emailModalBody').html('<div class="text-danger">' + res.message + '</div>'); return; }
    const d = res.email; const esc = s => $('<div>').text(s || '—').html();
    let h = '<dl class="dl-grid">';
    [['To', d.to_email], ['CC', d.cc], ['BCC', d.bcc], ['From', d.from_email], ['Subject', d.subject], ['Type', d.category], ['Template', d.template], ['Status', d.status === 'sent' ? '🟢 Sent' : (d.status === 'failed' ? '🔴 Failed' : d.status)], ['Attempt', d.attempt || d.attempts], ['Sending time', d.duration_ms != null ? (d.duration_ms / 1000).toFixed(2) + ' s' : null], ['Message-ID', d.message_id], ['SMTP response', d.smtp_response], ['Error type', d.error_kind], ['Error', d.error || d.last_error], ['Queued', d.created_at], ['Sent / attempted', d.sent_at]].forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') h += '<dt>' + k + '</dt><dd>' + esc(String(v)) + '</dd>'; });
    h += '</dl>';
    if (d.body) h += '<div class="section-title mt-3">Message</div><iframe style="width:100%;height:420px;border:1px solid var(--border);border-radius:8px" srcdoc="' + d.body.replace(/"/g, '&quot;') + '"></iframe>';
    $('#emailModalBody').html(h);
  });
});
</script>
JS;
include ROOT_PATH . '/includes/layout/footer.php';
