<?php
/**
 * Manual "Check now" runner used by the dashboard quick actions.
 * Runs in batches so a large site list does not time out the browser request.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
Auth::requireAbility('monitor');
set_time_limit(300);
ignore_user_abort(true);

$action = post('action', '');
$batch = max(1, min(50, post_int('batch', 10)));
$offset = max(0, post_int('offset', 0));

switch ($action) {
    case 'check_websites':
    case 'check_ssl':
    case 'check_pages':
    case 'check_forms':
        // v3.4: bulk checks are queued with top priority and processed by the workers – the request returns at once.
        $tid = Tenant::id();
        $items = [];
        if ($action === 'check_forms') {
            $rows = DB::fetchAll("SELECT f.id FROM forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id WHERE f.status NOT IN ('disabled','removed') AND w.monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND c.tenant_id = ? LIMIT 5000", [$tid]);
            foreach ($rows as $r) $items[] = ['form', 'form.test', (int) $r['id'], $tid, [], 0, 0, 'ft:' . $r['id']];
            $what = 'form test';
        } else {
            $extra = $action === 'check_pages' ? ' AND w.page_monitoring_enabled = 1' : ($action === 'check_ssl' ? " AND w.url LIKE 'https://%'" : '');
            $rows = DB::fetchAll("SELECT w.id FROM websites w JOIN clients c ON c.id = w.client_id WHERE w.monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND c.tenant_id = ?$extra LIMIT 5000", [$tid]);
            [$queue, $type, $key, $what] = $action === 'check_pages' ? ['page', 'website.pages', 'wp', 'page scan'] : ($action === 'check_ssl' ? ['ssl', 'website.ssl', 'ws', 'SSL check'] : ['website', 'website.check', 'wc', 'website check']);
            foreach ($rows as $r) $items[] = [$queue, $type, (int) $r['id'], $tid, [], 0, 0, $key . ':' . $r['id']];
        }
        if (!$items) json_success('Nothing to check.', ['total' => 0, 'done' => 0, 'finished' => true]);
        Queue::pushMany($items);
        DB::query("UPDATE jobs SET priority = 0 WHERE tenant_id = ? AND status = 'pending' AND type = ?", [$tid, $items[0][1]]);
        // like the dispatcher: the queued run counts as the next scheduled one, so "next check = now + plan interval" (no double run a minute later)
        $ids = implode(',', array_map(fn($i) => (int) $i[2], $items));
        if ($action === 'check_forms') DB::query("UPDATE forms SET next_test_at = DATE_ADD(NOW(), INTERVAL GREATEST(?, test_interval) MINUTE) WHERE id IN ($ids)", [Tenant::interval('form', $tid)]);
        else DB::query("UPDATE websites SET " . ['check_websites' => 'next_check_at', 'check_ssl' => 'next_ssl_at', 'check_pages' => 'next_scan_at'][$action] . " = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id IN ($ids)", [Tenant::interval(['check_websites' => 'website', 'check_ssl' => 'ssl', 'check_pages' => 'page'][$action], $tid)]);
        $inline = Scheduler::workersAlive() === 0;
        if ($inline) { // no worker on this server: run a short inline pass so small workspaces still get instant results
            ignore_user_abort(true);
            Scheduler::work('inline@' . gethostname() . '#' . getmypid(), 45, [$items[0][0]], 200);
        }
        data_changed();
        $n = count($items);
        json_success($inline ? "Ran $n $what(s)." : "Queued $n $what(s) – results appear within a minute as the workers finish.", ['total' => $n, 'done' => $n, 'finished' => true, 'queued' => !$inline]);

    case 'check_expiry':
        $r = Monitor::checkExpiry();
        Mailer::processQueue(30);
        json_success('Expiry check complete: ' . $r['domain'] . ' domain and ' . $r['hosting'] . ' hosting alert(s).', ['finished' => true, 'total' => 1, 'done' => 1]);

    default:
        json_error('Unknown action.');
}
