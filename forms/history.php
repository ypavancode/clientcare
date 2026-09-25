<?php
/** Returns an HTML fragment with the test history for a form (loaded into a modal). */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$id = (int) get('id', 0);
if (!DB::value("SELECT id FROM forms WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()])) { echo '<div class="empty-state">Not found</div>'; exit; }
$tests = DB::fetchAll("SELECT * FROM form_tests WHERE form_id = ? ORDER BY tested_at DESC LIMIT 50", [$id]);
if (!$tests) {
    echo '<div class="empty-state"><i class="bi bi-clipboard"></i>No tests recorded yet</div>';
    exit;
}
?>
<div class="table-responsive"><table class="table table-hover table-compact mb-0">
  <thead><tr><th>Tested</th><th>Mode</th><th>Result</th><th>HTTP</th><th>Time</th><th>Email</th><th>Details</th></tr></thead>
  <tbody>
  <?php foreach ($tests as $t): $outcome = $t['outcome'] ?: ($t['result'] === 'success' ? 'working' : ($t['result'] === 'blocked' ? 'captcha_blocked' : 'failed')); ?>
    <tr>
      <td class="text-nowrap"><?= format_datetime($t['tested_at']) ?></td>
      <td><?= e(ucfirst($t['test_mode'])) ?><div class="small-xs text-muted"><?= e($t['engine'] ?? 'http') ?></div></td>
      <td><?= form_outcome_badge($outcome) ?><?php if ($t['captcha_type']): ?><div class="small-xs text-muted"><i class="bi bi-shield-lock"></i> <?= e(form_captcha_label($t['captcha_type'])) ?></div><?php endif; ?><?php if ($t['interference']): ?><div class="small-xs text-muted"><i class="bi bi-chat-dots"></i> <?= e(truncate($t['interference'], 40)) ?></div><?php endif; ?></td>
      <td><?= e($t['http_code'] ?: '—') ?></td>
      <td><?= $t['response_time'] !== null ? (int) $t['response_time'] . ' ms' : '—' ?></td>
      <td><?= e(strtoupper($t['email_received'])) ?></td>
      <td class="small"><?php if ($t['failure_reason']): ?><div class="<?= $t['result'] === 'blocked' ? 'text-info' : ($t['result'] === 'failed' ? 'text-danger' : '') ?> fw-500"><?= e($t['failure_reason']) ?></div><?php endif; ?>
        <?php if ($t['error']): ?><span class="<?= $t['result'] === 'blocked' ? 'text-muted' : 'text-danger' ?>"><?= e($t['error']) ?></span><?php else: ?><span class="text-muted"><?= e($t['response_text']) ?></span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
