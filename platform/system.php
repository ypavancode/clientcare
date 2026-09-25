<?php
/**
 * Scheduler / Cron Health (Super Admin) – v3.4.
 * Shows the real state of background processing: runner mode, last / next dispatch, duration, jobs processed / failed /
 * pending per queue, worker heartbeats, per-customer plan schedules, dead-lettered jobs, system jobs, engine and logs.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$h = Scheduler::health(true);
$q = $h['queue'];
$dead = Queue::dead(25);
$sjobs = DB::fetchAll("SELECT * FROM scheduler_jobs ORDER BY priority");
$locks = DB::fetchAll("SELECT * FROM monitor_locks WHERE locked_until > NOW() ORDER BY locked_until DESC LIMIT 30");
$browser = Browser::status();
$logFile = LOG_PATH . '/app-' . date('Y-m-d') . '.log';
$logTail = is_file($logFile) ? implode("\n", array_slice(file($logFile), -40)) : '';
$phpErr = is_file(LOG_PATH . '/php-errors.log') ? implode("\n", array_slice(file(LOG_PATH . '/php-errors.log'), -20)) : '';
$root = str_replace('\\', '/', ROOT_PATH);
$statusTone = ['running' => 'success', 'delayed' => 'warning', 'stopped' => 'danger', 'failed' => 'danger', 'never' => 'secondary'][$h['status']] ?? 'secondary';
$pageTitle = 'Scheduler & Workers';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Scheduler health']];
$pageSubtitle = 'Self-running, queue-based monitoring: the execution engine keeps the scheduler alive without any cron job; the dispatcher queues only the targets that are due (per plan interval) and workers process them. Locks and leases prevent double work.';
$pageActions = '<button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params=\'{"action":"dispatch_now"}\' data-reload="1"><i class="bi bi-lightning-charge me-1"></i>Dispatch now</button>'
  . ($h['inline'] ? '<button class="btn btn-brand btn-sm btn-action" data-url="api/platform.php" data-params=\'{"action":"process_now"}\' data-reload="1"><i class="bi bi-play-circle me-1"></i>Process queue now</button>' : '');
$useCharts = false;
include ROOT_PATH . '/includes/layout/header.php';
$fmt = fn($v) => $v ? format_datetime($v) : '—';
?>
<?php $schedulerHealth = $h; include ROOT_PATH . '/includes/partials/scheduler-status.php'; ?>
<?php include __DIR__ . '/maintenance-card.php'; ?>
<?php include ROOT_PATH . '/includes/partials/launch-checklist.php'; ?>
<?php $eng = Engine::status(); $env = $eng['env']; ?>
<div class="card mb-3 border-<?= e($eng['tone']) ?>" id="engineCard">
  <div class="card-header"><span><i class="bi bi-lightning-charge me-2"></i>Execution engine – how monitoring keeps running without cron</span>
    <span class="d-flex gap-2 flex-wrap">
      <button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"engine_test"}' data-loading="1" title="Fire a hop and wait for it to be recorded"><i class="bi bi-arrow-repeat me-1"></i>Test loopback</button>
      <?php if ($env['can_exec']): ?><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"worker_start"}' data-reload="1" data-loading="1"><i class="bi bi-cpu me-1"></i>Start worker</button><?php endif; ?>
      <button class="btn btn-brand btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"engine_start"}' data-reload="1" data-loading="1"><i class="bi bi-play-circle me-1"></i>Start engine</button>
      <button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"engine_toggle"}' data-confirm="<?= $eng['enabled'] ? 'Disable the execution engine? Monitoring then depends on an external trigger.' : 'Enable the execution engine?' ?>" data-reload="1"><i class="bi <?= $eng['enabled'] ? 'bi-pause-circle' : 'bi-play-circle' ?> me-1"></i><?= $eng['enabled'] ? 'Disable' : 'Enable' ?></button>
    </span></div>
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
      <div class="flex-grow-1 min-w-0">
        <div class="ss-headline text-<?= e($eng['tone']) ?>"><?= $eng['tone'] === 'success' ? '🟢' : ($eng['tone'] === 'warning' ? '🟠' : '🔴') ?> <?= e($eng['label']) ?></div>
        <div class="small text-muted"><?= e($eng['text']) ?></div>
      </div>
      <div class="small text-muted text-end">Hops: <strong class="text-white"><?= number_format($eng['hops_total']) ?></strong> · last hop <?= $eng['last_hop_at'] ? e(time_ago($eng['last_hop_at'])) . ' (' . (int) $eng['last_hop_ms'] . ' ms)' : 'never' ?><br>Last kick: <?= $eng['last_kick_at'] ? e(time_ago($eng['last_kick_at'])) . ' · ' . e((string) $eng['last_kick_source']) : '—' ?></div>
    </div>
    <div class="row g-3 small">
      <div class="col-lg-7">
        <div class="section-title">Mechanisms (all managed by the application)</div>
        <ul class="diag-steps">
          <li class="<?= $eng['workers_alive'] ? 'ok' : ($env['can_exec'] ? '' : 'skipped') ?>"><span class="ds-ico"><?= $eng['workers_alive'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : ($env['can_exec'] ? '<i class="bi bi-circle text-muted"></i>' : '<i class="bi bi-dash-circle text-muted"></i>') ?></span><span class="ds-main"><span class="ds-title">1 · Auto-spawned worker process</span><span class="ds-detail"><?= $env['can_exec'] ? 'PHP CLI ' . e((string) $env['php_cli']) . ' via ' . e((string) $env['exec']) . '. The engine starts <code>cron/worker.php</code> detached and restarts it when it is gone.' . ($eng['last_spawn_at'] ? ' Last start ' . e(time_ago($eng['last_spawn_at'])) . ($eng['last_spawn_error'] ? ' – failed: ' . e($eng['last_spawn_error']) : '') . '.' : '') : 'Not available here: ' . (!$env['php_cli'] ? 'no PHP CLI binary found (set php_cli_path in System Settings if the host provides one)' : 'process spawning (exec / proc_open / popen) is disabled') . '. The request chain does the work instead.' ?></span></span></li>
          <li class="<?= $eng['chain_alive'] ? 'ok' : '' ?>"><span class="ds-ico"><?= $eng['chain_alive'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></span><span class="ds-main"><span class="ds-title">2 · Self-perpetuating request chain (loopback HTTP)</span><span class="ds-detail">Every minute a hop runs one scheduler tick inside the web server, then calls <code><?= e(preg_replace('~t=[a-f0-9]+~', 't=…', $eng['hop_url'])) ?></code> before it exits. Nobody has to be logged in; no browser tab is involved. A DB lock keeps it single; a hop fires its successor only while it owns that lock.<?= $eng['last_fire_error'] ? ' <span class="text-danger">Last fire error: ' . e($eng['last_fire_error']) . '</span>' : '' ?><?= $eng['last_hop_error'] ? ' <span class="text-danger">Last hop error: ' . e($eng['last_hop_error']) . '</span>' : '' ?></span><?= $eng['last_hop_summary'] ? '<span class="ds-resp mono">last hop: ' . e($eng['last_hop_summary']) . '</span>' : '' ?></span></li>
          <li class="ok"><span class="ds-ico"><i class="bi bi-check-circle-fill text-success"></i></span><span class="ds-main"><span class="ds-title">3 · Inbound-request watchdog</span><span class="ds-detail">Every request the server receives – public pages, analytics beacons from client websites, API calls, uptime pingers, logged-in users – runs a ~1 ms check at the end of its response and restarts the chain (and the worker) when it has stopped. Beacon requests, which nobody waits for, also process due work inline as the last resort<?= $env['inline_fallback'] ? '' : ' (currently disabled)' ?>.</span></span></li>
        </ul>
        <div class="text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Honest limit: PHP cannot run while the web server receives no request at all AND the chain was killed (reboot, process limits). The next inbound request of any kind restarts everything within one round trip. No cPanel cron job, no supervisor and no open dashboard are required.</div>
      </div>
      <div class="col-lg-5">
        <div class="section-title">Environment facts</div>
        <dl class="dl-grid mb-0">
          <dt>PHP SAPI</dt><dd><?= e($env['sapi']) ?> · <?= e($env['os']) ?></dd>
          <dt>Process spawn</dt><dd><?= $env['can_exec'] ? '<span class="text-success">available</span> (' . e((string) $env['exec']) . ')' : '<span class="text-warning">not available</span>' ?></dd>
          <dt>PHP CLI</dt><dd><?= $env['php_cli'] ? '<span class="mono">' . e($env['php_cli']) . '</span>' : '<span class="text-warning">not found</span>' ?></dd>
          <dt>Loopback</dt><dd><?= $env['loopback'] ? '<span class="text-success">stream_socket_client available</span>' : '<span class="text-danger">sockets disabled</span>' ?> · base <span class="mono"><?= e($eng['base_url']) ?></span></dd>
          <dt>Time limit</dt><dd>max_execution_time <?= (int) $env['max_execution_time'] ?: '0 (unlimited)' ?> · set_time_limit <?= $env['time_limit_adjustable'] ? '<span class="text-success">honoured</span>' : '<span class="text-warning">ignored (hops stay short)</span>' ?></dd>
          <dt>Hop length</dt><dd><?= (int) $env['hop_seconds'] ?> s budget · worker autostart <?= $env['worker_autostart'] ? 'on' : 'off' ?> · engine <?= $eng['enabled'] ? 'enabled' : 'disabled' ?></dd>
          <dt>Last fire</dt><dd><?= $eng['last_fire_at'] ? e(time_ago($eng['last_fire_at'])) : 'never' ?><?= $eng['last_fire_error'] ? ' <span class="text-danger">' . e($eng['last_fire_error']) . '</span>' : '' ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>


<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card h-100"><div class="card-header"><span><i class="bi bi-diagram-3 me-2"></i>Queues</span><span class="small text-muted">avg job <?= number_format($q['hour']['avg_ms']) ?> ms · <?= $h['inline'] ? 'processed inline by cron / heartbeat (no worker online)' : $h['workers_alive'] . ' worker(s) online' ?></span></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0">
        <thead><tr><th>Queue</th><th>Pending</th><th>Due now</th><th>Running</th><th>Done (1 h)</th><th>Dead</th><th>Oldest waiting</th><th>Last completed</th></tr></thead>
        <tbody><?php foreach (Scheduler::QUEUE_ORDER as $name): $s = $q['queues'][$name]; $st = $h['queue_state'][$name] ?? []; ?>
          <tr><td class="fw-600"><?= e(ucfirst($name)) ?></td><td><?= number_format($s['pending']) ?></td><td class="<?= $s['due'] ? 'text-warning fw-600' : '' ?>"><?= number_format($s['due']) ?></td><td><?= number_format($s['running']) ?></td><td><?= number_format($s['done']) ?></td><td class="<?= $s['dead'] ? 'text-danger fw-600' : '' ?>"><?= number_format($s['dead']) ?></td>
            <td class="<?= $s['oldest_due_seconds'] > 600 ? 'text-danger' : '' ?>"><?= $s['oldest_due_seconds'] ? e(human_seconds($s['oldest_due_seconds'])) : '—' ?></td>
            <td class="text-muted"><?= !empty($st['last_done_at']) ? e(time_ago($st['last_done_at'])) : '—' ?><?= !empty($st['last_error']) && !empty($st['last_failed_at']) && (empty($st['last_done_at']) || strtotime($st['last_failed_at']) > strtotime($st['last_done_at'])) ? '<div class="small-xs text-danger">' . e(truncate($st['last_error'], 70)) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card h-100"><div class="card-header"><span><i class="bi bi-cpu me-2"></i>Workers</span><span class="small text-muted"><?= $h['workers_alive'] ?> online · <?= $h['workers_busy'] ?> busy · <?= $h['workers_idle'] ?> idle</span></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0">
        <thead><tr><th>Worker</th><th>Status</th><th>Queues</th><th>Heartbeat</th><th>Done / failed</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($h['workers'] as $w): $tone = $w['alive'] ? ($w['status'] === 'busy' ? 'info' : 'success') : ($w['status'] === 'stopped' ? 'secondary' : 'danger'); ?>
          <tr><td class="mono small"><?= e($w['id']) ?><div class="small-xs text-muted"><?= e((string) $w['host']) ?> · pid <?= (int) $w['pid'] ?> · <?= (int) $w['memory_mb'] ?> MB</div></td>
            <td><?= status_pill($tone, $w['alive'] ? ucfirst($w['status']) : ($w['status'] === 'stopped' ? 'Stopped' : 'Dead'), false) ?></td>
            <td class="small text-muted"><?= e(str_replace(',', ', ', (string) $w['queues'])) ?></td><td><?= e(time_ago($w['heartbeat_at'])) ?></td>
            <td><?= number_format((int) $w['jobs_done']) ?> / <span class="<?= $w['jobs_failed'] ? 'text-danger' : '' ?>"><?= number_format((int) $w['jobs_failed']) ?></span><?= $w['last_error'] ? '<div class="small-xs text-danger">' . e(truncate($w['last_error'], 60)) . '</div>' : '' ?></td>
            <td class="text-end row-actions"><?php if (!$w['alive']): ?><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"worker_forget","id":"<?= e($w['id']) ?>"}' data-reload="1" title="Remove from list"><i class="bi bi-x-lg"></i></button><?php endif; ?></td></tr>
        <?php endforeach; if (!$h['workers']): ?><tr><td colspan="6" class="text-center text-muted py-3">No worker process has registered yet – the request chain processes the queue inline every minute. The engine starts a worker automatically when this server allows process spawning (see the Execution engine card), or press "Start worker" there.</td></tr><?php endif; ?></tbody></table></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-header"><span><i class="bi bi-people me-2"></i>Customer schedules (plan intervals)</span><span class="small text-muted">most recently active workspaces</span></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0">
        <thead><tr><th>Customer</th><th>Plan</th><th>Interval</th><th>Websites</th><th>Last run</th><th>Next run</th><th>Queued</th></tr></thead>
        <tbody><?php foreach ($h['tenants'] as $t): ?>
          <tr><td class="fw-600"><a href="<?= url('platform/customer.php?id=' . $t['id']) ?>"><?= e($t['name']) ?></a></td><td><?= e((string) $t['plan']) ?></td><td><?= (int) $t['interval'] ?> min</td><td><?= number_format($t['sites']) ?></td><td class="text-muted"><?= $t['last_run'] ? e(time_ago($t['last_run'])) : '—' ?></td><td><?= $t['next_run'] ? e(date('H:i:s', strtotime($t['next_run']))) . ($t['sites'] && strtotime($t['next_run']) < time() - 120 ? ' <span class="badge bg-warning-subtle">overdue</span>' : '') : '—' ?></td><td><?= number_format($t['jobs_pending']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
    </div>
    <div class="card"><div class="card-header"><span><i class="bi bi-exclamation-triangle me-2"></i>Dead-lettered jobs (<?= count($dead) ?>)</span><?php if ($dead): ?><span><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"jobs_retry_all"}' data-reload="1"><i class="bi bi-arrow-repeat me-1"></i>Retry all</button></span><?php endif; ?></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0">
        <thead><tr><th>Job</th><th>Target</th><th>Attempts</th><th>Error</th><th>When</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($dead as $j): ?>
          <tr><td class="mono small">#<?= (int) $j['id'] ?> <?= e($j['type']) ?></td><td class="small"><?= $j['target_id'] ? '#' . (int) $j['target_id'] : '—' ?><?= $j['tenant_id'] ? ' <span class="text-muted">· tenant ' . (int) $j['tenant_id'] . '</span>' : '' ?></td><td><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td><td class="small text-danger"><?= e(truncate((string) $j['last_error'], 90)) ?></td><td class="text-muted"><?= e(time_ago($j['finished_at'])) ?></td>
            <td class="text-end row-actions"><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"job_retry","id":<?= (int) $j['id'] ?>}' data-reload="1" title="Retry"><i class="bi bi-arrow-repeat"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/platform.php" data-params='{"action":"job_discard","id":<?= (int) $j['id'] ?>}' data-reload="1" title="Discard"><i class="bi bi-x-lg"></i></button></td></tr>
        <?php endforeach; if (!$dead): ?><tr><td colspan="6" class="text-center text-muted py-3">No dead jobs – every failure was retried successfully.</td></tr><?php endif; ?></tbody></table></div></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header"><span><i class="bi bi-gear me-2"></i>System jobs</span></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0">
        <thead><tr><th>Job</th><th>Every</th><th>Status</th><th>Last</th><th>Took</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($sjobs as $j): ?>
          <tr><td class="fw-600"><?= e($j['label']) ?><div class="small-xs text-muted"><?= e(truncate((string) $j['last_message'], 60)) ?></div></td>
            <td><?= $j['interval_minutes'] >= 1440 ? intdiv((int) $j['interval_minutes'], 1440) . ' d' : ($j['interval_minutes'] >= 60 ? intdiv((int) $j['interval_minutes'], 60) . ' h' : $j['interval_minutes'] . ' min') ?></td>
            <td><?= !$j['enabled'] ? status_pill('secondary', 'Disabled', false) : ($j['locked_until'] && strtotime($j['locked_until']) > time() ? status_pill('info', 'Running', false) : status_pill($j['status'] === 'failed' ? 'danger' : ($j['status'] === 'ok' ? 'success' : 'secondary'), ucfirst($j['status']), false)) ?></td>
            <td class="text-muted"><?= $j['last_finished_at'] ? e(time_ago($j['last_finished_at'])) : '—' ?></td><td><?= $j['last_duration_ms'] !== null ? round($j['last_duration_ms'] / 1000, 1) . 's' : '—' ?></td>
            <td class="text-end text-nowrap row-actions"><button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"job_reset","name":"<?= e($j['name']) ?>"}' data-reload="1" title="Unlock / reset"><i class="bi bi-arrow-counterclockwise"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params='{"action":"job_toggle","name":"<?= e($j['name']) ?>"}' data-reload="1" title="<?= $j['enabled'] ? 'Disable' : 'Enable' ?>"><i class="bi <?= $j['enabled'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
    </div>
    <div class="card mb-3"><div class="card-header"><span><i class="bi bi-terminal me-2"></i>Nothing to configure on the server</span></div><div class="card-body small">
      <div class="fw-600 mb-1">Deploy → open the application once → done</div>
      <div class="text-muted mb-2">The execution engine above starts itself from the first request and keeps monitoring running per plan interval. There is no cron job to add in cPanel and no command to run by hand.</div>
      <div class="fw-600 mb-1">Optional – extra safety on hosts you control</div>
      <div class="text-muted mb-2">A supervisor-managed worker (<code>php <?= e($root) ?>/cron/worker.php</code>, autorestart) or any external pinger hitting <code><?= e(url('cron/health-check.php?key=YOUR_CRON_KEY')) ?></code> every few minutes are welcome but never required. Locks and leases make every runner cooperate.</div>
      <div class="fw-600 mb-1">Health endpoint for an external uptime monitor</div>
      <code class="d-block"><?= e(url('cron/health-check.php?key=YOUR_CRON_KEY')) ?></code>
      <div class="text-muted">Returns HTTP 200 while healthy, 503 when the scheduler is stopped or delayed (each request to it also restarts the engine).</div>
    </div></div>
    <div class="card mb-3"><div class="card-header">Engine</div><div class="card-body small">
      <dl class="dl-grid mb-0"><dt>Runners seen</dt><dd><?php foreach (['worker' => 'worker', 'engine' => 'engine chain', 'web' => 'inbound requests', 'cron' => 'external trigger'] as $m => $ml): ?><span class="me-2"><?= $ml ?>:<?= !empty($h['runners'][$m]) ? e(time_ago($h['runners'][$m])) : 'never' ?></span><?php endforeach; ?></dd>
      <dt>Inline fallback</dt><dd><?= setting('inline_fallback_enabled', 1) ? 'enabled' : 'disabled' ?> · web heartbeat <?= $h['web_enabled'] ? 'enabled' : 'disabled' ?></dd>
      <dt>Headless browser</dt><dd><?= $browser['available'] ? '<span class="text-success">available</span>' : '<span class="text-warning">unavailable</span>' ?> · max <?= (int) setting('browser_max_concurrent', 2) ?> concurrent platform-wide<div class="small-xs text-muted"><?= e($browser['message']) ?></div></dd>
      <dt>SMTP</dt><dd><?php $ms = Mailer::status(); ?><span class="text-<?= $ms['tone'] ?>"><?= e($ms['headline']) ?></span> · <a href="<?= url('platform/emails.php') ?>">SMTP / Email</a></dd>
      <dt>Sessions / cache</dt><dd><?= defined('SESSION_DRIVER') ? e(SESSION_DRIVER) : 'files' ?> / <?= defined('CACHE_DRIVER') && CACHE_DRIVER === 'database' ? 'database (shared)' : (function_exists('apcu_enabled') && apcu_enabled() ? 'APCu (per server)' : 'files (per server)') ?></dd>
      <dt>PHP / DB</dt><dd><?= PHP_VERSION ?> · memory <?= ini_get('memory_limit') ?> · <?= e((string) DB::value("SELECT VERSION()")) ?></dd></dl>
    </div></div>
    <div class="card"><div class="card-header"><span>Active locks (<?= count($locks) ?>)</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-compact mb-0 small"><thead><tr><th>Lock</th><th>Owner</th><th>Until</th></tr></thead><tbody><?php foreach ($locks as $l): ?><tr><td class="mono"><?= e($l['lock_key']) ?></td><td><?= e($l['owner']) ?></td><td><?= format_datetime($l['locked_until']) ?></td></tr><?php endforeach; ?><?php if (!$locks): ?><tr><td colspan="3" class="text-center text-muted py-2">No active locks</td></tr><?php endif; ?></tbody></table></div></div></div>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><span><i class="bi bi-file-text me-1"></i>Application log (today, last 40 lines)</span></div><div class="card-body"><pre class="small mb-0" style="max-height:300px;overflow:auto"><?= e($logTail ?: 'No log entries today') ?></pre></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><span><i class="bi bi-bug me-1"></i>PHP errors (last 20 lines)</span></div><div class="card-body"><pre class="small mb-0" style="max-height:300px;overflow:auto"><?= e($phpErr ?: 'No PHP errors logged') ?></pre></div></div></div>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';
