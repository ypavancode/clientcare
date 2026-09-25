<?php
/** Super Admin → Audit Logs: every platform-level action (client / website / plan / pricing / SMTP / settings changes, logins, impersonation, security refusals) with user, target, result and IP. */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
$platformArea = true;
$actions = DB::fetchAll("SELECT action, COUNT(*) AS n FROM activity_logs WHERE is_platform = 1 GROUP BY action ORDER BY n DESC LIMIT 60");
$admins = DB::fetchAll("SELECT id, name FROM users WHERE is_platform_admin = 1 ORDER BY name");
$c = DB::fetch("SELECT COUNT(*) AS total, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS day, SUM(result IN ('failed','denied')) AS bad FROM activity_logs WHERE is_platform = 1") ?: [];
$pageTitle = 'Audit Logs';
$breadcrumbs = [['label' => 'Platform', 'url' => 'platform/index.php'], ['label' => 'Audit Logs']];
$pageSubtitle = number_format((int) $c['total']) . ' Super Admin actions recorded · ' . number_format((int) $c['day']) . ' in the last 24 h · ' . number_format((int) $c['bad']) . ' failed / denied · kept ' . (int) setting('retention_activity_days', 365) . ' days · passwords and keys are never stored';
include ROOT_PATH . '/includes/layout/header.php';
?>
<form class="filter-bar" id="filters" onsubmit="return false">
  <?php if ((int) get('tenant')): $tn = DB::value("SELECT name FROM tenants WHERE id = ?", [(int) get('tenant')]); ?><input type="hidden" name="tenant_id" value="<?= (int) get('tenant') ?>"><span class="badge bg-brand">Client: <?= e($tn ?: '#' . (int) get('tenant')) ?> <a href="<?= url('platform/audit.php') ?>" class="text-dark ms-1" title="Clear">&times;</a></span><?php endif; ?>
  <select name="scope" class="form-select form-select-sm"><option value="">Super Admin actions</option><option value="all">All activity (every workspace)</option></select>
  <select name="action" class="form-select form-select-sm"><option value="">Any action</option><?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>"><?= e(ActivityLog::label($a['action'])) ?> (<?= (int) $a['n'] ?>)</option><?php endforeach; ?></select>
  <select name="user_id" class="form-select form-select-sm"><option value="">Any user</option><option value="0">System / cron</option><?php foreach ($admins as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
  <select name="result" class="form-select form-select-sm"><option value="">Any result</option><option value="ok">OK</option><option value="failed">Failed</option><option value="denied">Denied</option></select>
  <input type="date" name="from" class="form-control form-control-sm" title="From"><input type="date" name="to" class="form-control form-control-sm" title="To">
</form>
<div class="card dt-card">
  <table class="table table-hover datatable w-100 table-compact" data-source="platform_audit" data-filters="#filters" data-order='[]' data-page-length="50" data-empty="No audit entries" data-empty-text="Super Admin actions (client, website, plan, SMTP and settings changes) are recorded here." data-empty-icon="bi-clipboard-data">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>Details</th><th>Result</th><th>IP</th></tr></thead>
    <tbody></tbody>
  </table>
</div>
<?php include ROOT_PATH . '/includes/layout/footer.php';
