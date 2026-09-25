<?php
/**
 * Platform status card – one-click 🟢 Live / 🟠 Maintenance Mode switch (included by platform/index.php and platform/system.php).
 * Backed by api/platform.php action=maintenance_toggle (audited). Direct requests to this partial are refused.
 */
if (empty($platformArea) || !Auth::isPlatformAdmin()) http_error(404);
$maintOn = (bool) setting('maintenance_mode', 0);
$maintMsg = (string) setting('maintenance_message', '');
?>
<div class="card mb-3 border-<?= $maintOn ? 'warning' : 'success' ?>" id="maintenanceCard">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
    <div class="ss-light bg-<?= $maintOn ? 'warning' : 'success' ?>"></div>
    <div class="flex-grow-1 min-w-0">
      <div class="ss-headline text-<?= $maintOn ? 'warning' : 'success' ?>"><?= $maintOn ? '🟠 Maintenance Mode' : '🟢 Live' ?> <span class="small fw-normal text-muted">· platform status</span></div>
      <div class="small text-muted"><?= $maintOn
        ? 'Customers see the 503 maintenance page' . ($maintMsg !== '' ? ' (“' . e($maintMsg) . '”)' : '') . '; Super Admins keep the console. Beacon, tracker, engine and public status pages keep running.'
        : 'Customers can sign in and use their workspaces. Switching to maintenance shows customers a 503 page while Super Admins keep full access.' ?></div>
    </div>
    <?php if ($maintOn): ?>
      <button class="btn btn-success btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"maintenance_toggle","enable":0}' data-confirm="End maintenance mode? Customers regain access immediately." data-confirm-btn="Go live" data-icon="question" data-reload="1" data-loading="1"><i class="bi bi-play-circle me-1"></i>Go live</button>
    <?php else: ?>
      <button class="btn btn-outline-warning btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"maintenance_toggle","enable":1}' data-confirm="Enable maintenance mode? Every customer (and their API calls) will get a 503 maintenance page until you switch back. Super Admins keep access." data-confirm-btn="Enable maintenance" data-reload="1" data-loading="1"><i class="bi bi-tools me-1"></i>Enable maintenance</button>
    <?php endif; ?>
    <a href="<?= url('platform/settings.php') ?>" class="btn btn-light btn-sm" title="Maintenance message and other general settings"><i class="bi bi-gear"></i></a>
  </div>
</div>
