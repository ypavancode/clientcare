<?php
/**
 * Scheduler / Background Processing status card (Super Admin). Green only when the scheduler is REALLY executing
 * (worker heartbeats / recent dispatch, no missed executions); red when stopped, stale or failed. Polls every 30 s.
 * Usage: $schedulerHealth = Scheduler::health(); include this partial.
 */
$sh = $schedulerHealth ?? Scheduler::health();
$q = $sh['queue'];
$green = $sh['healthy'] && $sh['status'] !== 'delayed';
$tone = $green ? 'success' : ($sh['status'] === 'delayed' ? 'warning' : 'danger');
$headline = $green ? 'Connected & Running' : ($sh['status'] === 'delayed' ? 'Running but delayed' : ($sh['status'] === 'never' ? 'Not Connected' : 'Not Connected / Not Running'));
$lastBeat = null; foreach ($sh['workers'] as $w) if ($w['alive']) { $lastBeat = $lastBeat && strtotime($lastBeat) > strtotime($w['heartbeat_at']) ? $lastBeat : $w['heartbeat_at']; }
$lastBeat = $lastBeat ?: ($sh['last_tick'] ?? null);
$errors = [];
if (($sh['dispatch']['status'] ?? '') === 'failed') $errors[] = 'Dispatcher: ' . ($sh['dispatch']['last_message'] ?? 'failed');
foreach ($sh['queue_state'] as $qn => $st) if (!empty($st['last_error']) && !empty($st['last_failed_at']) && (empty($st['last_done_at']) || strtotime($st['last_failed_at']) > strtotime($st['last_done_at']))) $errors[] = ucfirst($qn) . ': ' . $st['last_error'];
$dt = fn($v) => $v ? date('d M Y, H:i:s', strtotime($v)) : '—';
?>
<div class="card scheduler-status mb-3 border-<?= $tone ?>" id="schedulerStatus" data-poll="<?= e(url('api/platform.php?action=scheduler_health')) ?>">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div class="ss-light bg-<?= $tone ?>" data-f="light"></div>
      <div class="flex-grow-1 min-w-0">
        <div class="small-xs text-muted text-uppercase" style="letter-spacing:.1em">Scheduler / Background processing</div>
        <div class="ss-headline text-<?= $tone ?>" data-f="headline"><?= $green ? '🟢' : ($tone === 'warning' ? '🟠' : '🔴') ?> <?= e($headline) ?></div>
        <div class="small text-muted" data-f="message"><?= e($sh['message']) ?></div>
      </div>
      <div class="text-end small text-muted">Runner: <span class="fw-600 text-white" data-f="mode"><?= e($sh['mode'] === 'none' ? 'none' : ($sh['mode'] === 'web' ? 'web heartbeat' : $sh['mode'])) ?></span><br>Checked <span data-f="checked"><?= date('H:i:s') ?></span> · refreshes every 30 s</div>
    </div>
    <div class="ss-grid mt-3">
      <div><span class="l">Last successful execution</span><span class="v" data-f="last"><?= e($dt($sh['last_dispatch'] ?? null)) ?></span></div>
      <div><span class="l">Next expected execution</span><span class="v" data-f="next"><?= e($dt($sh['next_dispatch'] ?? null)) ?></span></div>
      <div><span class="l">Last heartbeat</span><span class="v" data-f="beat"><?= e($dt($lastBeat)) ?></span></div>
      <div><span class="l">Jobs processed (1 h / 24 h)</span><span class="v" data-f="processed"><?= number_format($q['hour']['processed']) ?> / <?= number_format($q['day']['processed']) ?></span></div>
      <div><span class="l">Jobs failed (1 h)</span><span class="v <?= $q['hour']['failed'] ? 'text-danger' : '' ?>" data-f="failed"><?= number_format($q['hour']['failed']) ?></span></div>
      <div><span class="l">Jobs pending / due now</span><span class="v" data-f="pending"><?= number_format($q['totals']['pending']) ?> / <?= number_format($q['totals']['due']) ?></span></div>
      <div><span class="l">Workers</span><span class="v" data-f="workers"><?= $sh['workers_alive'] ?> online (<?= $sh['workers_busy'] ?> busy)<?= $sh['inline'] ? ' · inline fallback' : '' ?></span></div>
      <div><span class="l">Average execution time</span><span class="v" data-f="avg"><?= number_format($q['hour']['avg_ms']) ?> ms / job · dispatch <?= $sh['dispatch_duration_ms'] !== null ? number_format($sh['dispatch_duration_ms'] / 1000, 2) . ' s' : '—' ?></span></div>
    </div>
    <div class="mt-2 small <?= $errors ? 'text-danger' : 'text-muted' ?>" data-f="errors"><?= $errors ? '<i class="bi bi-exclamation-triangle me-1"></i>' . e(implode(' · ', array_slice($errors, 0, 3))) : 'No scheduler errors.' ?></div>
  </div>
</div>
<script>
(function () {
  const $c = $('#schedulerStatus'); if (!$c.length) return;
  const poll = () => { if (document.visibilityState !== 'visible') return; $.getJSON($c.data('poll')).done(function (h) {
    const tone = h.tone; ['success', 'warning', 'danger'].forEach(t => { $c.removeClass('border-' + t); $c.find('[data-f=light]').removeClass('bg-' + t); $c.find('[data-f=headline]').removeClass('text-' + t); });
    $c.addClass('border-' + tone); $c.find('[data-f=light]').addClass('bg-' + tone); $c.find('[data-f=headline]').addClass('text-' + tone).text((tone === 'success' ? '🟢 ' : (tone === 'warning' ? '🟠 ' : '🔴 ')) + h.headline);
    $c.find('[data-f=message]').text(h.message); $c.find('[data-f=mode]').text(h.mode); $c.find('[data-f=checked]').text(h.checked);
    $c.find('[data-f=last]').text(h.last); $c.find('[data-f=next]').text(h.next); $c.find('[data-f=beat]').text(h.beat); $c.find('[data-f=processed]').text(h.processed); $c.find('[data-f=failed]').text(h.failed).toggleClass('text-danger', h.failed !== '0');
    $c.find('[data-f=pending]').text(h.pending); $c.find('[data-f=workers]').text(h.workers); $c.find('[data-f=avg]').text(h.avg);
    $c.find('[data-f=errors]').toggleClass('text-danger', !!h.errors).toggleClass('text-muted', !h.errors).html(h.errors ? '<i class="bi bi-exclamation-triangle me-1"></i>' + CRM.esc(h.errors) : 'No scheduler errors.');
    $('[data-scheduler-state]').attr('data-scheduler-state', h.mode);
  }); };
  setInterval(poll, 30000);
})();
</script>
