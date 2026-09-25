<?php
/**
 * Server-side data source for every large list in the CRM (DataTables serverSide mode).
 * The browser receives only the visible page; searching, sorting and paging run in MySQL on indexed columns.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$table = get('table', '');
$admin = Auth::isAdmin();
$canDelete = in_array(Auth::role(), ['admin', 'manager'], true);
$g = fn(string $k, $d = '') => get($k, $d);
$int = fn(string $k) => (int) get($k, 0);

switch ($table) {

    /* ------------------------------------------------------------------ clients */
    case 'clients':
        $status = $g('status');
        $where = ['c.tenant_id = ' . Tenant::id()];
        $params = [];
        if (in_array($status, ['active', 'inactive', 'archived'], true)) { $where[] = 'c.status = ?'; $params[] = $status; }
        elseif ($status !== 'all') $where[] = "c.status <> 'archived'";
        DataTable::serve([
            'from'   => 'clients c LEFT JOIN users u ON u.id = c.assigned_user_id',
            'page_from' => 'clients c LEFT JOIN users u ON u.id = c.assigned_user_id', 'key' => 'c.id',
            'count_from' => 'clients c',
            'where'  => $where, 'params' => $params,
            'select' => 'c.id, c.name, c.company, c.email, c.phone, c.status, c.monitoring_enabled, c.created_at, u.name AS assigned_name,
                (SELECT COUNT(*) FROM websites w WHERE w.client_id = c.id) AS site_count,
                (SELECT COUNT(*) FROM websites w WHERE w.client_id = c.id AND w.status IN (' . down_statuses_sql() . ')) AS down_count,
                (SELECT COUNT(*) FROM forms f JOIN websites w ON w.id = f.website_id WHERE w.client_id = c.id AND f.status = \'failed\') AS failed_forms,
                (SELECT COALESCE(SUM(w.pages_total),0) FROM websites w WHERE w.client_id = c.id) AS pages_total,
                (SELECT COALESCE(SUM(w.pages_failed),0) FROM websites w WHERE w.client_id = c.id) AS pages_failed',
            'columns' => [
                ['db' => 'c.name', 'search' => true], ['db' => 'c.email', 'search' => true], ['db' => null], ['db' => null], ['db' => null],
                ['db' => 'u.name'], ['db' => 'c.status'], ['db' => 'c.created_at'], ['db' => null],
            ],
            'default_order' => $g('sort') === 'new' ? 'c.created_at DESC' : 'c.name ASC',
            'row_attr' => fn($c) => ['data-row-id' => $c['id']],
            'row' => function ($c) use ($admin) {
                return [
                    '<a href="' . url('clients/view.php?id=' . $c['id']) . '" class="fw-500">' . e($c['name']) . '</a>' . ($c['company'] ? '<div class="text-muted small-xs">' . e($c['company']) . '</div>' : ''),
                    ($c['email'] ? '<div class="small"><i class="bi bi-envelope me-1 text-muted"></i>' . e($c['email']) . '</div>' : '') . ($c['phone'] ? '<div class="small"><i class="bi bi-telephone me-1 text-muted"></i>' . e($c['phone']) . '</div>' : ''),
                    (int) $c['site_count'],
                    ($c['down_count'] ? '<span class="badge bg-danger-subtle text-danger me-1">' . $c['down_count'] . ' down</span>' : '') . ($c['pages_failed'] ? '<span class="badge bg-danger-subtle text-danger me-1">' . $c['pages_failed'] . ' page' . ($c['pages_failed'] > 1 ? 's' : '') . ' failed</span>' : '') . ($c['failed_forms'] ? '<span class="badge bg-danger-subtle text-danger">' . $c['failed_forms'] . ' form' . ($c['failed_forms'] > 1 ? 's' : '') . ' failed</span>' : '') . (!$c['down_count'] && !$c['failed_forms'] && !$c['pages_failed'] ? '<span class="badge bg-success-subtle text-success">' . ($c['site_count'] ? 'Healthy' : '—') . '</span>' : '') . (!$c['monitoring_enabled'] ? '<span class="badge bg-secondary-subtle text-secondary ms-1">Monitoring off</span>' : ''),
                    $c['pages_total'] ? '<span class="' . ($c['pages_failed'] ? 'text-danger fw-500' : 'text-success') . '">' . ((int) $c['pages_total'] - (int) $c['pages_failed']) . ' / ' . (int) $c['pages_total'] . '</span>' : '<span class="text-muted">—</span>',
                    '<span class="text-muted">' . e($c['assigned_name'] ?? '—') . '</span>',
                    client_status_badge($c['status']),
                    format_date($c['created_at']),
                    '<div class="row-actions text-end"><a href="' . url('clients/view.php?id=' . $c['id']) . '" class="btn btn-light btn-sm" title="View"><i class="bi bi-eye"></i></a> <button class="btn btn-light btn-sm btn-edit-client" data-id="' . $c['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button>'
                        . ($admin ? ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/clients.php" data-params=\'{"action":"delete","id":' . $c['id'] . '}\' data-confirm="This permanently deletes the client AND all their websites, pages, forms, login details and monitoring history. Consider archiving instead." data-confirm-btn="Delete permanently" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ websites list */
    case 'websites':
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($int('client_id')) { $where[] = 'w.client_id = ?'; $params[] = $int('client_id'); }
        DataTable::serve([
            'from'   => 'websites w JOIN clients c ON c.id = w.client_id LEFT JOIN domains d ON d.website_id = w.id LEFT JOIN hosting h ON h.website_id = w.id',
            'page_from' => fn($sql) => 'websites w STRAIGHT_JOIN clients c ON c.id = w.client_id' . (str_contains($sql, 'd.') ? ' LEFT JOIN domains d ON d.website_id = w.id' : '') . (str_contains($sql, 'h.') ? ' LEFT JOIN hosting h ON h.website_id = w.id' : ''), 'key' => 'w.id',
            'count_from' => 'websites w JOIN clients c ON c.id = w.client_id',
            'where'  => $where, 'params' => $params,
            'select' => 'w.id, w.client_id, w.tenant_id, w.name, w.url, w.technology, w.status, w.response_time, w.ssl_status, w.ssl_days_left, w.last_checked_at, w.next_check_at, w.monitoring_enabled, w.page_health, w.pages_total, w.pages_failed, w.last_scan_at, c.name AS client_name, d.expiry_date AS domain_expiry, h.expiry_date AS hosting_expiry,
                (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id) AS form_count,
                (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status = \'failed\') AS failed_forms',
            'columns' => [
                ['db' => 'w.name', 'search' => true, 'order' => 'w.name'], ['db' => 'c.name', 'search' => true], ['db' => 'w.technology'], ['db' => 'w.status'], ['db' => 'w.page_health'], ['db' => 'w.ssl_days_left'],
                ['db' => null], ['db' => 'd.expiry_date'], ['db' => 'h.expiry_date'], ['db' => 'w.last_checked_at'], ['db' => null],
            ],
            'default_order' => 'w.name ASC',
            'row_attr' => fn($w) => ['data-row-id' => $w['id']],
            'row' => function ($w) use ($admin) {
                return [
                    '<a href="' . url('websites/view.php?id=' . $w['id']) . '" class="fw-500">' . e($w['name']) . '</a><div class="small-xs"><a href="' . e($w['url']) . '" target="_blank" rel="noopener" class="text-muted">' . e(host_from_url($w['url'])) . ' <i class="bi bi-box-arrow-up-right"></i></a></div>',
                    '<a href="' . url('clients/view.php?id=' . $w['client_id']) . '">' . e($w['client_name']) . '</a>',
                    '<span class="badge bg-secondary-subtle text-secondary">' . e($w['technology']) . '</span>',
                    website_status_badge($w['status']) . ($w['response_time'] && in_array($w['status'], ['online', 'redirecting']) ? '<div class="text-muted small-xs">' . (int) $w['response_time'] . ' ms</div>' : ''),
                    '<a href="' . url('websites/view.php?id=' . $w['id'] . '&tab=pages') . '" class="text-reset">' . page_health_badge($w['page_health'], $w['pages_total'], $w['pages_failed']) . '</a>' . ($w['pages_failed'] ? '<div class="small-xs text-danger">' . (int) $w['pages_failed'] . ' page' . ($w['pages_failed'] > 1 ? 's' : '') . ' failed</div>' : ''),
                    ssl_status_badge($w['ssl_status'], $w['ssl_days_left']),
                    ($w['failed_forms'] ? '<span class="text-danger fw-500">' . $w['failed_forms'] . ' failed</span> / ' : '') . (int) $w['form_count'],
                    expiry_badge($w['domain_expiry']),
                    expiry_badge($w['hosting_expiry']),
                    '<span class="text-muted">' . e(time_ago($w['last_checked_at'])) . '</span>' . ($w['monitoring_enabled'] && $w['next_check_at'] ? '<div class="small-xs text-muted" title="Every ' . Tenant::interval('website', $w['tenant_id'] ? (int) $w['tenant_id'] : null) . ' min (plan interval)">next ' . (strtotime($w['next_check_at']) <= time() ? 'now' : 'in ' . e(human_seconds(strtotime($w['next_check_at']) - time()))) . '</div>' : ($w['monitoring_enabled'] ? '' : '<div class="small-xs text-warning">paused</div>')),
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params=\'{"action":"check","id":' . $w['id'] . '}\' data-loading="1" data-reload="1" title="Check now"><i class="bi bi-arrow-repeat"></i></button> <a href="' . url('websites/view.php?id=' . $w['id']) . '" class="btn btn-light btn-sm" title="View"><i class="bi bi-eye"></i></a> <button class="btn btn-light btn-sm btn-edit-website" data-id="' . $w['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button>'
                        . ($admin ? ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/websites.php" data-params=\'{"action":"delete","id":' . $w['id'] . '}\' data-confirm="Delete this website with its pages, forms and monitoring history?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ website health / monitoring */
    case 'health':
        $status = $g('status'); $ssl = $g('ssl');
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($status === 'down') $where[] = "w.status IN (" . down_statuses_sql() . ")";
        elseif ($status === 'online') $where[] = "w.status IN ('online','redirecting')";
        elseif ($status === 'unknown') $where[] = "w.status IN ('unknown','paused')";
        if ($ssl === 'issues') $where[] = "w.ssl_status IN ('expiring_soon','expired','error')";
        if ($int('client_id')) { $where[] = 'w.client_id = ?'; $params[] = $int('client_id'); }
        DataTable::serve([
            'from'   => 'websites w JOIN clients c ON c.id = w.client_id LEFT JOIN domains d ON d.website_id = w.id LEFT JOIN hosting h ON h.website_id = w.id',
            'page_from' => fn($sql) => 'websites w STRAIGHT_JOIN clients c ON c.id = w.client_id' . (str_contains($sql, 'd.') ? ' LEFT JOIN domains d ON d.website_id = w.id' : '') . (str_contains($sql, 'h.') ? ' LEFT JOIN hosting h ON h.website_id = w.id' : ''), 'key' => 'w.id',
            'count_from' => 'websites w JOIN clients c ON c.id = w.client_id',
            'where'  => $where, 'params' => $params,
            'select' => 'w.id, w.name, w.url, w.status, w.failure_reason, w.error_message, w.response_time, w.last_checked_at, w.ssl_status, w.ssl_days_left, w.page_health, w.pages_total, w.pages_failed, w.last_scan_at, c.name AS client_name, d.expiry_date AS domain_expiry, h.expiry_date AS hosting_expiry,
                (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status <> \'disabled\') AS form_count,
                (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status = \'failed\') AS failed_forms,
                (SELECT COUNT(*) FROM forms f WHERE f.website_id = w.id AND f.status = \'working\') AS working_forms,
                (SELECT started_at FROM website_incidents i WHERE i.website_id = w.id AND i.resolved_at IS NULL ORDER BY id DESC LIMIT 1) AS down_since',
            'columns' => [
                ['db' => 'w.name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'w.status', 'order' => "w.status"], ['db' => 'w.failure_reason', 'search' => true],
                ['db' => 'w.page_health', 'order' => 'w.page_health'], ['db' => 'w.last_checked_at'], ['db' => 'w.response_time'], ['db' => 'w.ssl_days_left'], ['db' => null], ['db' => 'd.expiry_date'], ['db' => 'h.expiry_date'],
            ],
            'default_order' => "w.status, w.name",
            'row_attr' => fn($w) => ['data-row-id' => $w['id'], 'class' => 'cursor-pointer', 'data-href' => url('websites/view.php?id=' . $w['id'])],
            'row' => function ($w) {
                $isDown = !in_array($w['status'], ['online', 'redirecting', 'unknown', 'paused'], true);
                return [
                    '<a href="' . url('websites/view.php?id=' . $w['id']) . '" class="fw-500">' . e($w['name']) . '</a><div class="text-muted small-xs">' . e(host_from_url($w['url'])) . '</div>',
                    e($w['client_name']),
                    website_status_badge($w['status']) . ($isDown && $w['down_since'] ? '<div class="text-muted small-xs">since ' . e(time_ago($w['down_since'])) . '</div>' : ''),
                    $isDown ? '<span class="text-danger fw-500 small">' . e($w['failure_reason'] ?: 'Unknown') . '</span><div class="text-muted small-xs" title="' . e($w['error_message']) . '">' . e(truncate($w['error_message'] ?? '', 48)) . '</div>' : ($w['status'] === 'redirecting' ? '<div class="text-muted small-xs">' . e(truncate($w['error_message'] ?? '', 48)) . '</div>' : '<span class="text-muted">—</span>'),
                    '<a href="' . url('websites/view.php?id=' . $w['id'] . '&tab=pages') . '" class="text-reset">' . page_health_badge($w['page_health'], $w['pages_total'], $w['pages_failed']) . '</a>' . ($w['last_scan_at'] ? '<div class="text-muted small-xs">scan ' . e(time_ago($w['last_scan_at'])) . '</div>' : ''),
                    '<span class="text-muted">' . e(time_ago($w['last_checked_at'])) . '</span>',
                    !$isDown && $w['response_time'] !== null ? (int) $w['response_time'] . ' ms' : '<span class="text-muted">—</span>',
                    ssl_status_badge($w['ssl_status'], $w['ssl_days_left']),
                    !$w['form_count'] ? '<span class="text-muted">—</span>' : ($w['failed_forms'] ? status_pill('danger', $w['failed_forms'] . ' failed / ' . $w['form_count']) : ($w['working_forms'] == $w['form_count'] ? status_pill('success', $w['form_count'] . ' working') : status_pill('warning', $w['working_forms'] . ' / ' . $w['form_count'] . ' tested'))),
                    expiry_badge($w['domain_expiry']),
                    expiry_badge($w['hosting_expiry']),
                ];
            },
        ]);

    /* ------------------------------------------------------------------ uptime overview (from daily rollups + today's raw checks) */
    case 'uptime':
        $days = in_array((int) $g('days'), [1, 7, 30, 90], true) ? (int) $g('days') : 7;
        $since = date('Y-m-d', strtotime("-$days days"));
        $sinceDt = date('Y-m-d H:i:s', time() - $days * 86400);
        DataTable::serve([
            'from'   => 'websites w JOIN clients c ON c.id = w.client_id',
            'page_from' => 'websites w STRAIGHT_JOIN clients c ON c.id = w.client_id', 'key' => 'w.id',
            'where'  => ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()], 'params' => [],
            'select' => "w.id, w.name, w.url, w.status, w.response_time, w.last_checked_at, c.name AS client_name,
                (SELECT COALESCE(SUM(checks),0) FROM website_uptime_daily u WHERE u.website_id = w.id AND u.day >= '$since') + (SELECT COUNT(*) FROM website_monitoring m WHERE m.website_id = w.id AND m.checked_at >= GREATEST('$sinceDt', CURDATE())) AS checks,
                (SELECT COALESCE(SUM(up_checks),0) FROM website_uptime_daily u WHERE u.website_id = w.id AND u.day >= '$since') + (SELECT COALESCE(SUM(m.status IN ('online','redirecting')),0) FROM website_monitoring m WHERE m.website_id = w.id AND m.checked_at >= GREATEST('$sinceDt', CURDATE())) AS up_checks,
                (SELECT AVG(m.response_time) FROM website_monitoring m WHERE m.website_id = w.id AND m.status IN ('online','redirecting') AND m.checked_at >= '$sinceDt') AS avg_rt,
                (SELECT COUNT(*) FROM website_incidents i WHERE i.website_id = w.id AND i.started_at >= '$sinceDt') AS incidents,
                (SELECT COALESCE(SUM(COALESCE(i.duration_seconds, TIMESTAMPDIFF(SECOND, i.started_at, NOW()))),0) FROM website_incidents i WHERE i.website_id = w.id AND i.started_at >= '$sinceDt') AS downtime",
            'columns' => [
                ['db' => 'w.name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'w.status'], ['db' => null], ['db' => null], ['db' => null], ['db' => null], ['db' => null], ['db' => 'w.last_checked_at'],
            ],
            'default_order' => 'w.name ASC',
            'row' => function ($r) {
                $pct = $r['checks'] ? $r['up_checks'] / $r['checks'] * 100 : null;
                $bar = array_reverse(DB::fetchAll("SELECT status FROM website_monitoring WHERE website_id = ? ORDER BY checked_at DESC LIMIT 40", [$r['id']]));
                $barHtml = '<div class="uptime-bar" style="height:14px">' . ($bar ? '' : '<span></span>');
                foreach ($bar as $b) $barHtml .= '<span class="' . ($b['status'] === 'online' ? 'up' : ($b['status'] === 'redirecting' ? 'warn' : 'down')) . '"></span>';
                $barHtml .= '</div>';
                return [
                    '<a href="' . url('websites/view.php?id=' . $r['id']) . '" class="fw-500">' . e($r['name']) . '</a><div class="text-muted small-xs">' . e(host_from_url($r['url'])) . '</div>',
                    e($r['client_name']),
                    website_status_badge($r['status']),
                    $pct === null ? '<span class="text-muted">—</span>' : '<span class="fw-500 ' . ($pct >= 99.5 ? 'text-success' : ($pct >= 97 ? 'text-warning' : 'text-danger')) . '">' . number_format($pct, 2) . '%</span><div class="text-muted small-xs">' . (int) $r['checks'] . ' checks</div>',
                    $barHtml,
                    $r['avg_rt'] !== null ? (int) $r['avg_rt'] . ' ms' : '—',
                    (int) $r['incidents'] ?: '<span class="text-muted">0</span>',
                    $r['downtime'] ? '<span class="text-danger">' . duration_human((int) $r['downtime']) . '</span>' : '<span class="text-muted">none</span>',
                    '<span class="text-muted">' . e(time_ago($r['last_checked_at'])) . '</span>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ forms list */
    case 'forms':
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($int('website_id')) { $where[] = 'f.website_id = ?'; $params[] = $int('website_id'); }
        if ($int('client_id')) { $where[] = 'w.client_id = ?'; $params[] = $int('client_id'); }
        DataTable::serve([
            'from'   => 'forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id',
            'page_from' => 'forms f STRAIGHT_JOIN websites w ON w.id = f.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id', 'key' => 'f.id',
            'where'  => $where, 'params' => $params,
            'select' => 'f.id, f.website_id, f.name, f.page_url, f.page_title, f.source, f.technology, f.form_kind, f.form_type, f.test_payload, f.recipient_email, f.status, f.auto_test, f.last_tested_at, f.last_result, f.last_outcome, f.captcha_type, f.captcha_detected, f.last_response_code, f.last_error, f.last_failure_reason, w.name AS website_name, w.client_id, c.name AS client_name',
            'columns' => [
                ['db' => 'f.name', 'search' => true], ['db' => 'w.name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'f.form_type'], ['db' => 'f.recipient_email', 'search' => true],
                ['db' => 'f.status'], ['db' => 'f.last_tested_at'], ['db' => 'f.last_result'], ['db' => null],
            ],
            'default_order' => 'f.id DESC',
            'row_attr' => fn($f) => ['data-row-id' => $f['id']],
            'row' => function ($f) use ($admin) {
                return [
                    '<span class="fw-500">' . e($f['name']) . '</span> ' . form_source_badge($f['source']) . '<div class="small-xs"><a href="' . e($f['page_url']) . '" target="_blank" rel="noopener" class="text-muted">' . e(truncate($f['page_title'] ?: $f['page_url'], 45)) . '</a></div>',
                    '<a href="' . url('websites/view.php?id=' . $f['website_id']) . '">' . e($f['website_name']) . '</a><div class="small-xs"><a href="' . url('websites/forms.php?id=' . $f['website_id']) . '" class="text-muted">form inventory</a></div>',
                    '<a href="' . url('clients/view.php?id=' . $f['client_id']) . '">' . e($f['client_name']) . '</a>',
                    e($f['form_type']) . '<div class="small-xs text-muted">' . e(form_kind_label($f['form_kind'])) . ($f['technology'] ? ' · ' . e($f['technology']) : '') . '</div>',
                    '<span class="small">' . e($f['recipient_email'] ?: '—') . '</span>',
                    form_status_badge($f['status']) . (!$f['auto_test'] && $f['status'] !== 'disabled' ? '<div class="small-xs text-muted">manual only</div>' : ''),
                    '<span class="text-muted">' . e(time_ago($f['last_tested_at'])) . '</span>',
                    ($f['last_result'] ? form_outcome_badge($f['last_outcome'] ?: ($f['last_result'] === 'success' ? 'working' : ($f['last_result'] === 'blocked' ? 'captcha_blocked' : 'failed')), false) . '<div class="small-xs ' . ($f['last_result'] === 'failed' ? 'text-danger' : 'text-muted') . '">'
                        . e(truncate($f['last_result'] === 'success' ? 'HTTP ' . $f['last_response_code'] : ($f['last_failure_reason'] ?: $f['last_error']), 50)) . '</div>' : '<span class="text-muted">—</span>')
                        . (($f['captcha_type'] ?? 'none') !== 'none' || $f['captcha_detected'] ? '<div class="small-xs text-muted"><i class="bi bi-shield-lock"></i> ' . e(form_captcha_label($f['captcha_detected'] ?: $f['captcha_type'])) . '</div>' : ''),
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-test-form" data-id="' . $f['id'] . '" title="Test now"' . ($f['status'] === 'disabled' ? ' disabled' : '') . '><i class="bi bi-play-circle"></i></button> <button class="btn btn-light btn-sm btn-history" data-id="' . $f['id'] . '" data-name="' . e($f['name']) . '" title="Test history"><i class="bi bi-clock-history"></i></button> <button class="btn btn-light btn-sm btn-edit-form" data-id="' . $f['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button>'
                        . ($admin ? ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/forms.php" data-params=\'{"action":"delete","id":' . $f['id'] . '}\' data-confirm="Delete this form and its test history?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ form monitoring */
    case 'forms_monitoring':
        $status = $g('status');
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if (in_array($status, ['working', 'failed', 'captcha_blocked', 'not_tested', 'disabled', 'removed'], true)) { $where[] = 'f.status = ?'; $params[] = $status; }
        elseif ($status === '') { $where[] = "f.status <> 'removed'"; }
        if ($int('client_id')) { $where[] = 'w.client_id = ?'; $params[] = $int('client_id'); }
        if ($int('website_id')) { $where[] = 'f.website_id = ?'; $params[] = $int('website_id'); }
        if ($g('stale') === '1') { $where[] = "f.status <> 'disabled' AND (f.last_tested_at IS NULL OR f.last_tested_at < DATE_SUB(NOW(), INTERVAL ? HOUR))"; $params[] = (int) setting('form_stale_hours', 48); }
        if ($g('from') && strtotime($g('from'))) { $where[] = 'f.last_tested_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
        if ($g('to') && strtotime($g('to'))) { $where[] = 'f.last_tested_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
        DataTable::serve([
            'from'   => 'forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id',
            'page_from' => 'forms f STRAIGHT_JOIN websites w ON w.id = f.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id', 'key' => 'f.id',
            'where'  => $where, 'params' => $params,
            'select' => 'f.id, f.website_id, f.name, f.form_type, f.status, f.last_tested_at, f.last_result, f.last_outcome, f.last_page_ok, f.last_form_found, f.last_email_received, f.captcha_type, f.captcha_detected, f.last_response_code, f.last_error, f.last_failure_reason, f.failed_tests, w.name AS website_name, w.client_id, c.name AS client_name',
            'columns' => [
                ['db' => 'c.name', 'search' => true], ['db' => 'w.name', 'search' => true], ['db' => 'f.name', 'search' => true], ['db' => 'f.status', 'order' => "f.status"],
                ['db' => 'f.last_tested_at'], ['db' => 'f.last_result'], ['db' => 'f.last_failure_reason', 'search' => true], ['db' => null],
            ],
            'default_order' => "f.status, f.last_tested_at DESC",
            'row_attr' => fn($f) => ['data-row-id' => $f['id']],
            'row' => function ($f) {
                return [
                    '<a href="' . url('clients/view.php?id=' . $f['client_id']) . '">' . e($f['client_name']) . '</a>',
                    '<a href="' . url('websites/view.php?id=' . $f['website_id']) . '">' . e($f['website_name']) . '</a>',
                    '<a href="' . url('forms/index.php?website_id=' . $f['website_id'] . '&highlight=' . $f['id']) . '" class="fw-500">' . e($f['name']) . '</a><div class="text-muted small-xs">' . e($f['form_type']) . '</div>',
                    form_status_badge($f['status']),
                    format_datetime($f['last_tested_at']) . '<div class="text-muted small-xs">' . e(time_ago($f['last_tested_at'])) . '</div>',
                    $f['last_result'] ? form_outcome_badge($f['last_outcome'] ?: ($f['last_result'] === 'success' ? 'working' : ($f['last_result'] === 'blocked' ? 'captcha_blocked' : 'failed')), false) . ($f['last_response_code'] ? ' <span class="text-muted small">(' . (int) $f['last_response_code'] . ')</span>' : '')
                        . ($f['last_email_received'] ? '<div class="small-xs text-muted">Email: ' . e(strtoupper($f['last_email_received'])) . '</div>' : '') : '—',
                    $f['status'] === 'failed' ? '<span class="text-danger fw-500 small">' . e($f['last_failure_reason'] ?: 'Failed') . '</span><div class="text-muted small-xs" title="' . e($f['last_error']) . '">Page ' . ($f['last_page_ok'] === null ? '?' : ($f['last_page_ok'] ? 'available' : '<span class="text-danger">unreachable</span>')) . ' · form ' . ($f['last_form_found'] === null ? '?' : ($f['last_form_found'] ? 'present' : '<span class="text-danger">missing</span>')) . ' · ' . e(truncate($f['last_error'] ?? '', 60)) . ($f['failed_tests'] > 1 ? ' · ' . (int) $f['failed_tests'] . ' consecutive failures' : '') . '</div>'
                        : ($f['status'] === 'captcha_blocked' ? '<span class="text-warning fw-500 small"><i class="bi bi-shield-lock"></i> CAPTCHA Protected · ' . e(form_captcha_label($f['captcha_detected'] ?: $f['captcha_type'])) . '</span><div class="text-muted small-xs" title="' . e($f['last_error']) . '">Page ' . ($f['last_page_ok'] === null ? '?' : ($f['last_page_ok'] ? '<span class="text-success">available</span>' : '<span class="text-danger">unreachable</span>')) . ' · form ' . ($f['last_form_found'] === null ? '?' : ($f['last_form_found'] ? '<span class="text-success">present</span>' : '<span class="text-danger">missing</span>')) . ' · submission requires a human – not a failure, no alert sent</div>' : ($f['status'] === 'working' && $f['last_outcome'] === 'email_unknown' ? '<span class="text-muted small-xs">Submission accepted · email delivery not verified (no IMAP mailbox)</span>' : '<span class="text-muted">—</span>')),
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-action" data-url="api/forms.php" data-params=\'{"action":"test","id":' . $f['id'] . '}\' data-loading="1" data-reload="1" title="Test now"' . ($f['status'] === 'disabled' ? ' disabled' : '') . '><i class="bi bi-play-circle"></i></button> <a href="' . url('forms/index.php?website_id=' . $f['website_id'] . '&highlight=' . $f['id']) . '" class="btn btn-light btn-sm" title="Manage"><i class="bi bi-gear"></i></a></div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ domains */
    case 'domains':
        $warn = (int) explode(',', (string) setting('domain_alert_days', '30,10'))[0] ?: 30;
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($g('filter') === 'expiring') { $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'; $params[] = $warn; }
        if ($g('filter') === 'expired') $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date < CURDATE()';
        if ($int('client_id')) { $where[] = 'd.client_id = ?'; $params[] = $int('client_id'); }
        DataTable::serve([
            'from'   => 'domains d JOIN clients c ON c.id = d.client_id LEFT JOIN websites w ON w.id = d.website_id',
            'where'  => $where, 'params' => $params,
            'select' => 'd.*, c.name AS client_name, w.name AS website_name',
            'columns' => [
                ['db' => 'd.domain_name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'w.name'], ['db' => 'd.registrar', 'search' => true], ['db' => 'd.registration_date'],
                ['db' => 'd.expiry_date'], ['db' => 'd.expiry_date'], ['db' => 'd.auto_renew'], ['db' => null], ['db' => null],
            ],
            'default_order' => 'd.expiry_date IS NULL, d.expiry_date ASC',
            'row_attr' => fn($d) => ['data-row-id' => $d['id']],
            'row' => function ($d) use ($warn, $canDelete) {
                $sent = $d['expiry_date'] ? Notifier::sentAlerts('domain_expiry:' . $d['id'] . ':' . $d['expiry_date'] . ':') : [];
                $alerts = '';
                foreach ($sent as $s) { $k = substr(strrchr($s['alert_key'], ':'), 1); $alerts .= '<div><i class="bi bi-envelope-check text-success"></i> ' . ($k === 'expired' ? 'Expired notice' : $k . '-day notice') . ' <span class="text-muted">· ' . format_date($s['sent_at']) . '</span></div>'; }
                return [
                    '<span class="fw-500">' . e($d['domain_name']) . '</span>' . ($d['login_ref'] ? '<div class="text-muted small-xs">' . e($d['login_ref']) . '</div>' : ''),
                    '<a href="' . url('clients/view.php?id=' . $d['client_id']) . '">' . e($d['client_name']) . '</a>',
                    $d['website_id'] ? '<a href="' . url('websites/view.php?id=' . $d['website_id']) . '">' . e($d['website_name']) . '</a>' : '—',
                    e($d['registrar'] ?: '—'),
                    format_date($d['registration_date']),
                    format_date($d['expiry_date']),
                    expiry_badge($d['expiry_date'], $warn),
                    $d['auto_renew'] ? '<span class="text-success"><i class="bi bi-arrow-repeat"></i> Yes</span>' : '<span class="text-muted">No</span>',
                    '<span class="small">' . ($alerts ?: '<span class="text-muted">None</span>') . '</span>',
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-edit-domain" data-id="' . $d['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button>' . ($canDelete ? ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/domains.php" data-params=\'{"action":"delete","id":' . $d['id'] . '}\' data-confirm="Delete this domain record?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ hosting */
    case 'hosting':
        $warn = (int) explode(',', (string) setting('hosting_alert_days', '30,10'))[0] ?: 30;
        $where = ["c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($g('filter') === 'expiring') { $where[] = 'h.expiry_date IS NOT NULL AND h.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'; $params[] = $warn; }
        if ($g('filter') === 'expired') $where[] = 'h.expiry_date IS NOT NULL AND h.expiry_date < CURDATE()';
        if ($int('client_id')) { $where[] = 'h.client_id = ?'; $params[] = $int('client_id'); }
        DataTable::serve([
            'from'   => 'hosting h JOIN clients c ON c.id = h.client_id LEFT JOIN websites w ON w.id = h.website_id',
            'where'  => $where, 'params' => $params,
            'select' => 'h.*, c.name AS client_name, w.name AS website_name',
            'columns' => [
                ['db' => 'h.provider', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'w.name'], ['db' => 'h.server_ip', 'search' => true], ['db' => 'h.plan', 'search' => true],
                ['db' => 'h.start_date'], ['db' => 'h.expiry_date'], ['db' => 'h.expiry_date'], ['db' => 'h.renewal_status'], ['db' => null], ['db' => null],
            ],
            'default_order' => 'h.expiry_date IS NULL, h.expiry_date ASC',
            'row_attr' => fn($h) => ['data-row-id' => $h['id']],
            'row' => function ($h) use ($warn, $canDelete) {
                $sent = $h['expiry_date'] ? Notifier::sentAlerts('hosting_expiry:' . $h['id'] . ':' . $h['expiry_date'] . ':') : [];
                $alerts = '';
                foreach ($sent as $s) { $k = substr(strrchr($s['alert_key'], ':'), 1); $alerts .= '<div><i class="bi bi-envelope-check text-success"></i> ' . ($k === 'expired' ? 'Expired notice' : $k . '-day notice') . ' <span class="text-muted">· ' . format_date($s['sent_at']) . '</span></div>'; }
                return [
                    '<span class="fw-500">' . e($h['provider']) . '</span>' . ($h['login_ref'] ? '<div class="text-muted small-xs">' . e($h['login_ref']) . '</div>' : ''),
                    '<a href="' . url('clients/view.php?id=' . $h['client_id']) . '">' . e($h['client_name']) . '</a>',
                    $h['website_id'] ? '<a href="' . url('websites/view.php?id=' . $h['website_id']) . '">' . e($h['website_name']) . '</a>' : '—',
                    '<span class="mono">' . e($h['server_ip'] ?: '—') . '</span>',
                    e($h['plan'] ?: '—'),
                    format_date($h['start_date']),
                    format_date($h['expiry_date']),
                    expiry_badge($h['expiry_date'], $warn),
                    status_pill($h['renewal_status'] === 'auto' ? 'success' : ($h['renewal_status'] === 'cancelled' ? 'danger' : 'secondary'), ucfirst($h['renewal_status']), false),
                    '<span class="small">' . ($alerts ?: '<span class="text-muted">None</span>') . '</span>',
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-edit-hosting" data-id="' . $h['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button>' . ($canDelete ? ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/hosting.php" data-params=\'{"action":"delete","id":' . $h['id'] . '}\' data-confirm="Delete this hosting record?" data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ monitored pages (all websites) */
    case 'pages':
        $status = $g('status');
        $where = ["p.is_active = 1", "c.status <> 'archived'", 'c.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($status === 'failed') $where[] = "p.status IN (" . down_statuses_sql() . ")";
        elseif ($status === 'working') $where[] = "p.status IN ('online','redirecting')";
        elseif ($status === 'unknown') $where[] = "p.status IN ('unknown','paused')";
        if ($int('client_id')) { $where[] = 'w.client_id = ?'; $params[] = $int('client_id'); }
        if ($int('website_id')) { $where[] = 'p.website_id = ?'; $params[] = $int('website_id'); }
        DataTable::serve([
            'from'   => 'website_pages p JOIN websites w ON w.id = p.website_id JOIN clients c ON c.id = w.client_id',
            'page_from' => 'website_pages p STRAIGHT_JOIN websites w ON w.id = p.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id', 'key' => 'p.id',
            'where'  => $where, 'params' => $params,
            'select' => 'p.id, p.website_id, p.title, p.path, p.url, p.clean_url, p.source, p.status, p.http_code, p.response_time, p.failure_reason, p.error_message, p.failed_checks, p.last_checked_at, p.last_success_at, p.last_failed_at, w.name AS website_name, w.client_id, c.name AS client_name',
            'columns' => [
                ['db' => 'p.title', 'search' => true, 'order' => 'p.priority'], ['db' => 'w.name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'p.url', 'search' => true],
                ['db' => 'p.status', 'order' => 'p.status'], ['db' => 'p.http_code'], ['db' => 'p.response_time'], ['db' => 'p.failure_reason', 'search' => true], ['db' => 'p.last_checked_at'], ['db' => null],
            ],
            'default_order' => "p.status, p.last_failed_at DESC, w.name, p.priority",
            'row_attr' => fn($p) => ['data-row-id' => $p['id']],
            'row' => function ($p) {
                $failed = !in_array($p['status'], ['online', 'redirecting', 'unknown', 'paused'], true);
                return [
                    '<span class="fw-500">' . e($p['title'] ?: Monitor::pathLabel($p['path'])) . '</span><div class="text-muted small-xs">' . e($p['path']) . '</div>',
                    '<a href="' . url('websites/view.php?id=' . $p['website_id'] . '&tab=pages') . '">' . e($p['website_name']) . '</a>',
                    '<a href="' . url('clients/view.php?id=' . $p['client_id']) . '">' . e($p['client_name']) . '</a>',
                    '<a href="' . e(page_display_url($p['clean_url'], $p['url'])) . '" target="_blank" rel="noopener" class="small" title="' . e($p['url']) . '">' . e(truncate(page_display_url($p['clean_url'], $p['url']), 48)) . ' <i class="bi bi-box-arrow-up-right"></i></a>',
                    page_status_badge($p['status']) . ($failed && $p['failed_checks'] > 1 ? '<div class="small-xs text-danger">' . (int) $p['failed_checks'] . ' consecutive failures</div>' : ''),
                    '<span class="' . ($failed ? 'text-danger fw-500' : '') . '">' . e($p['http_code'] ?: '—') . '</span>',
                    $p['response_time'] !== null ? (int) $p['response_time'] . ' ms' : '<span class="text-muted">—</span>',
                    $failed ? '<span class="text-danger fw-500 small">' . e($p['failure_reason'] ?: 'Failed') . '</span><div class="text-muted small-xs" title="' . e($p['error_message']) . '">' . e(truncate($p['error_message'] ?? '', 55)) . '</div>' : '<span class="text-muted">—</span>',
                    '<span class="text-muted">' . e(time_ago($p['last_checked_at'])) . '</span>' . ($failed && $p['last_success_at'] ? '<div class="small-xs text-muted">last OK ' . e(time_ago($p['last_success_at'])) . '</div>' : ''),
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-action" data-url="api/websites.php" data-params=\'{"action":"page_check","id":' . $p['id'] . '}\' data-loading="1" data-reload="1" title="Check now"><i class="bi bi-arrow-repeat"></i></button> <a href="' . url('websites/view.php?id=' . $p['website_id'] . '&tab=pages&highlight=' . $p['id']) . '" class="btn btn-light btn-sm" title="Open website"><i class="bi bi-eye"></i></a></div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ activity log */
    case 'activity':
        Auth::requireAbility('activity');
        $where = ['a.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($g('user_id') !== '' && $g('user_id') !== null) { if ((int) $g('user_id') === 0) $where[] = 'a.user_id IS NULL'; else { $where[] = 'a.user_id = ?'; $params[] = (int) $g('user_id'); } }
        if ($g('action')) { $where[] = 'a.action = ?'; $params[] = $g('action'); }
        if ($int('client_id')) { $where[] = 'a.client_id = ?'; $params[] = $int('client_id'); }
        if ($g('from') && strtotime($g('from'))) { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
        if ($g('to') && strtotime($g('to'))) { $where[] = 'a.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
        DataTable::serve([
            'from'   => 'activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN clients c ON c.id = a.client_id LEFT JOIN websites w ON w.id = a.website_id',
            'count_from' => 'activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN clients c ON c.id = a.client_id LEFT JOIN websites w ON w.id = a.website_id',
            'where'  => $where, 'params' => $params,
            'select' => 'a.*, u.name AS user_name, c.name AS client_name, w.name AS website_name',
            'columns' => [
                ['db' => 'a.created_at'], ['db' => 'u.name'], ['db' => 'a.action'], ['db' => 'a.description', 'search' => true], ['db' => 'c.name'], ['db' => 'w.name'], ['db' => 'a.ip_address'],
            ],
            'default_order' => 'a.id DESC',
            'row' => function ($a) {
                return [
                    '<span class="text-nowrap">' . format_datetime($a['created_at']) . '</span>',
                    e($a['user_name'] ?? 'System'),
                    '<span class="badge bg-secondary-subtle text-secondary"><i class="bi ' . ActivityLog::icon($a['action']) . ' me-1"></i>' . e(ucwords(str_replace('_', ' ', $a['action']))) . '</span>',
                    e($a['description']),
                    $a['client_id'] ? '<a href="' . url('clients/view.php?id=' . $a['client_id']) . '">' . e($a['client_name'] ?? '#' . $a['client_id']) . '</a>' : '—',
                    $a['website_id'] ? '<a href="' . url('websites/view.php?id=' . $a['website_id']) . '">' . e($a['website_name'] ?? '#' . $a['website_id']) . '</a>' : '—',
                    '<span class="mono text-muted">' . e($a['ip_address']) . '</span>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ email delivery log */
    case 'emails':
        Auth::requireRole('admin', 'manager');
        $where = ['l.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($g('status') === 'sent' || $g('status') === 'failed') { $where[] = 'l.status = ?'; $params[] = $g('status'); }
        if ($g('category')) { $where[] = 'l.category = ?'; $params[] = $g('category'); }
        if ($g('from') && strtotime($g('from'))) { $where[] = 'l.sent_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
        if ($g('to') && strtotime($g('to'))) { $where[] = 'l.sent_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
        DataTable::serve([
            'from'   => 'email_logs l',
            'where'  => $where, 'params' => $params,
            'select' => 'l.id, l.sent_at, l.to_email, l.cc, l.bcc, l.category, l.template, l.subject, l.status, l.error_kind, l.smtp_response, l.message_id, l.error, l.duration_ms, l.attempt',
            'columns' => [
                ['db' => 'l.sent_at'], ['db' => 'l.to_email', 'search' => true], ['db' => 'l.category'], ['db' => 'l.subject', 'search' => true], ['db' => 'l.status'], ['db' => 'l.duration_ms'], ['db' => null], ['db' => null],
            ],
            'default_order' => 'l.id DESC',
            'row' => function ($m) {
                $defs = Mailer::templateDefinitions();
                return [
                    '<span class="text-nowrap">' . format_datetime($m['sent_at']) . '</span>',
                    e($m['to_email']) . ($m['cc'] ? '<div class="small-xs text-muted">CC: ' . e($m['cc']) . '</div>' : '') . ($m['bcc'] ? '<div class="small-xs text-muted">BCC: ' . e($m['bcc']) . '</div>' : ''),
                    '<span class="badge bg-secondary-subtle text-secondary">' . e(Mailer::categoryLabel($m['category'])) . '</span>' . ($m['template'] ? '<div class="small-xs text-muted">' . e($defs[$m['template']]['name'] ?? $m['template']) . '</div>' : ''),
                    '<span class="small">' . e(truncate($m['subject'], 70)) . '</span>',
                    ($m['status'] === 'sent' ? status_pill('success', 'Sent') : status_pill($m['error_kind'] === 'rejected' ? 'warning' : 'danger', Mailer::KINDS[$m['error_kind'] ?? 'other'] ?? 'Failed')) . ((int) $m['attempt'] > 1 ? '<div class="small-xs text-muted">attempt ' . (int) $m['attempt'] . '</div>' : ''),
                    '<span class="small text-muted text-nowrap">' . ($m['duration_ms'] !== null ? number_format($m['duration_ms'] / 1000, 2) . ' s' : '—') . '</span>',
                    $m['status'] === 'sent' ? '<span class="small text-muted">' . e(truncate($m['smtp_response'] ?? 'Accepted', 40)) . '</span>' . ($m['message_id'] ? '<div class="small-xs text-muted mono">' . e(truncate($m['message_id'], 40)) . '</div>' : '') : '<span class="small text-danger" title="' . e($m['error']) . '">' . e(truncate($m['error'] ?? '', 90)) . '</span>',
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-view-email" data-id="' . $m['id'] . '" data-src="log" title="Details"><i class="bi bi-eye"></i></button></div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ website projects */
    case 'projects':
        Auth::requireAbility('projects.view');
        require_once ROOT_PATH . '/includes/ProjectFilters.php';
        [$where, $params] = project_filters($g);
        $canEdit = Auth::can('projects');
        $canWeb = Auth::can('websites');
        DataTable::serve([
            'from'   => 'website_projects p JOIN clients c ON c.id = p.client_id LEFT JOIN websites w ON w.id = p.website_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id LEFT JOIN users u ON u.id = p.assigned_user_id',
            'page_from' => 'website_projects p JOIN clients c ON c.id = p.client_id LEFT JOIN websites w ON w.id = p.website_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id',
            'key'    => 'p.id',
            'where'  => $where, 'params' => $params,
            'select' => 'p.*, c.name AS client_name, d.name AS department_name, t.name AS type_name, u.name AS assigned_name, w.monitoring_enabled, w.status AS site_status, w.id AS wid',
            'columns' => [
                ['db' => 'p.project_name', 'search' => true], ['db' => 'c.name', 'search' => true], ['db' => 'p.website_url', 'search' => true], ['db' => 'd.name', 'search' => true], ['db' => 't.name', 'search' => true],
                ['db' => 'p.technology'], ['db' => 'p.status'], ['db' => 'p.start_date'], ['db' => 'COALESCE(p.actual_launch_date, p.expected_launch_date)'], ['db' => null], ['db' => null],
            ],
            'default_order' => 'p.status, p.updated_at DESC, p.id DESC',
            'row_attr' => fn($p) => ['data-row-id' => $p['id']],
            'row' => function ($p) use ($canEdit, $canWeb) {
                $mon = $p['wid'] && $p['monitoring_enabled'];
                $statusMenu = '';
                foreach (project_statuses() as $k => [$lbl, $cls]) {
                    $statusMenu .= '<li><a class="dropdown-item small btn-status' . ($k === $p['status'] ? ' active' : '') . '" href="#" data-id="' . $p['id'] . '" data-status="' . $k . '"><span class="badge badge-status ' . $cls . ' me-1"><span class="dot"></span></span>' . e($lbl) . '</a></li>';
                }
                return [
                    '<a href="' . url('projects/view.php?id=' . $p['id']) . '" class="fw-500">' . e($p['project_name']) . '</a><div class="small-xs">' . project_kind_badge($p['project_kind']) . '</div>',
                    '<a href="' . url('clients/view.php?id=' . $p['client_id']) . '">' . e($p['client_name']) . '</a>',
                    $p['website_url'] ? '<a href="' . e($p['website_url']) . '" target="_blank" rel="noopener">' . e(host_from_url($p['website_url'])) . ' <i class="bi bi-box-arrow-up-right small"></i></a>' . ($p['website_name'] ? '<div class="text-muted small-xs">' . e($p['website_name']) . '</div>' : '') : '<span class="text-muted">—</span>',
                    e($p['department_name'] ?: '—'),
                    e($p['type_name'] ?: '—'),
                    $p['technology'] ? '<span class="badge bg-secondary-subtle text-secondary">' . e($p['technology']) . '</span>' : '—',
                    project_status_badge($p['status']),
                    format_date($p['start_date']),
                    ($p['actual_launch_date'] ? '<span class="text-success">' . format_date($p['actual_launch_date']) . '</span>' : ($p['expected_launch_date'] ? format_date($p['expected_launch_date']) . '<div class="text-muted small-xs">expected</div>' : '<span class="text-muted">—</span>')),
                    $p['wid'] ? ($mon ? status_pill('success', 'Monitoring Active') : status_pill('secondary', 'Monitoring Disabled')) : '<span class="text-muted small">Not linked</span>',
                    '<div class="row-actions text-end text-nowrap"><a href="' . url('projects/view.php?id=' . $p['id']) . '" class="btn btn-light btn-sm" title="View"><i class="bi bi-eye"></i></a>'
                        . ($canEdit ? ' <button class="btn btn-light btn-sm btn-edit-project" data-id="' . $p['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button> <div class="dropdown d-inline-block"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" title="Change status"><i class="bi bi-arrow-repeat"></i></button><ul class="dropdown-menu dropdown-menu-end">' . $statusMenu . '</ul></div>' : '')
                        . ($p['website_url'] ? ' <a href="' . e($p['website_url']) . '" target="_blank" rel="noopener" class="btn btn-light btn-sm" title="Open website"><i class="bi bi-box-arrow-up-right"></i></a>' : '')
                        . ' <a href="' . url('clients/view.php?id=' . $p['client_id']) . '" class="btn btn-light btn-sm" title="View client"><i class="bi bi-person"></i></a>'
                        . ($canWeb && $p['website_url'] ? ($mon ? ' <button class="btn btn-light btn-sm text-secondary btn-action" data-url="api/projects.php" data-params=\'{"action":"disable_monitoring","id":' . $p['id'] . '}\' data-confirm="Disable monitoring for this website?" data-icon="question" data-reload="1" title="Disable monitoring"><i class="bi bi-toggle-on"></i></button>' : ' <button class="btn btn-light btn-sm text-success btn-action" data-url="api/projects.php" data-params=\'{"action":"enable_monitoring","id":' . $p['id'] . '}\' data-loading="1" data-reload="1" title="Enable monitoring"><i class="bi bi-toggle-off"></i></button>') : '')
                        . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ users */
    case 'users':
        Auth::requireRole('admin');
        $me = Auth::id();
        DataTable::serve([
            'from'   => 'users u',
            'where'  => ['u.tenant_id = ' . Tenant::id()], 'params' => [],
            'select' => 'u.id, u.name, u.email, u.username, u.role, u.status, u.phone, u.last_login_at, u.last_login_ip, u.email_verified_at, u.is_platform_admin',
            'columns' => [
                ['db' => 'u.name', 'search' => true], ['db' => 'u.email', 'search' => true], ['db' => 'u.username', 'search' => true], ['db' => 'u.role'], ['db' => 'u.status'], ['db' => 'u.last_login_at'], ['db' => null],
            ],
            'default_order' => 'u.name ASC',
            'row_attr' => fn($u) => ['data-row-id' => $u['id']],
            'row' => function ($u) use ($me) {
                return [
                    '<span class="fw-500">' . e($u['name']) . '</span>' . ($u['id'] == $me ? ' <span class="badge bg-brand">you</span>' : '') . ($u['phone'] ? '<div class="text-muted small-xs">' . e($u['phone']) . '</div>' : ''),
                    e($u['email']),
                    '<span class="mono">' . e($u['username']) . '</span>',
                    status_pill(in_array($u['role'], ['owner', 'admin'], true) ? 'dark' : ($u['role'] === 'manager' ? 'info' : 'secondary'), Registration::roleLabel($u['role']), false) . (empty($u['email_verified_at']) ? ' <span class="badge bg-warning text-dark">unverified</span>' : ''),
                    status_pill($u['status'] === 'active' ? 'success' : 'secondary', ucfirst($u['status']), false),
                    format_datetime($u['last_login_at']) . ($u['last_login_ip'] ? '<div class="text-muted small-xs">' . e($u['last_login_ip']) . '</div>' : ''),
                    '<div class="row-actions text-end"><button class="btn btn-light btn-sm btn-edit-user" data-id="' . $u['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button> <button class="btn btn-light btn-sm btn-pass-user" data-id="' . $u['id'] . '" data-name="' . e($u['name']) . '" title="Change password"><i class="bi bi-key"></i></button> <button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params=\'{"action":"reset_link","id":' . $u['id'] . '}\' data-confirm="Email a password reset link to ' . e($u['email']) . '?" data-icon="question" data-confirm-btn="Send" title="Send reset link"><i class="bi bi-envelope-arrow-up"></i></button>'
                        . ($u['id'] != $me ? ' <button class="btn btn-light btn-sm btn-action" data-url="api/users.php" data-params=\'{"action":"status","id":' . $u['id'] . '}\' data-reload="1" title="' . ($u['status'] === 'active' ? 'Deactivate' : 'Activate') . '"><i class="bi ' . ($u['status'] === 'active' ? 'bi-pause-circle text-warning' : 'bi-play-circle text-success') . '"></i></button> <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/users.php" data-params=\'{"action":"delete","id":' . $u['id'] . '}\' data-confirm="Delete this user? Their activity history is kept." data-confirm-btn="Delete" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    case 'platform_users':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        if ($g('status') && in_array($g('status'), ['active', 'inactive'], true)) { $where[] = 'u.status = ?'; $params[] = $g('status'); }
        if ($g('role')) { $where[] = 'u.role = ?'; $params[] = $g('role'); }
        if ($g('verified') === '1') $where[] = 'u.email_verified_at IS NOT NULL'; elseif ($g('verified') === '0') $where[] = 'u.email_verified_at IS NULL';
        if ($g('tenant_id')) { $where[] = 'u.tenant_id = ?'; $params[] = (int) $g('tenant_id'); }
        DataTable::serve([
            'from'   => 'users u LEFT JOIN tenants t ON t.id = u.tenant_id LEFT JOIN plans p ON p.id = t.plan_id',
            'where'  => $where, 'params' => $params,
            'select' => 'u.id, u.name, u.email, u.role, u.status, u.is_platform_admin, u.is_platform_owner, u.email_verified_at, u.last_login_at, u.last_login_ip, u.created_at, t.id AS tenant_id, t.name AS tenant_name, t.subscription_status, p.name AS plan_name',
            'columns' => [
                ['db' => 'u.name', 'search' => true], ['db' => 't.name', 'search' => true], ['db' => 'u.role'], ['db' => 'u.status'], ['db' => 'u.last_login_at'], ['db' => 'u.created_at'], ['db' => null],
            ],
            'default_order' => 'u.id DESC',
            'row_attr' => fn($u) => ['data-row-id' => $u['id']],
            'row' => function ($u) {
                $isOwner = Auth::isPlatformOwner(); $me = (int) $u['id'] === (int) Auth::id();
                $actions = '<div class="row-actions text-end text-nowrap">'
                    . ($me ? '' : '<button class="btn btn-light btn-sm ' . ($u['status'] === 'active' ? 'text-warning' : 'text-success') . ' btn-action" data-url="api/platform.php" data-params=\'{"action":"user_status","id":' . $u['id'] . '}\' data-confirm="' . ($u['status'] === 'active' ? 'Deactivate ' . e($u['email']) . '? Active sessions are ended.' : 'Activate ' . e($u['email']) . '?') . '" data-confirm-btn="' . ($u['status'] === 'active' ? 'Deactivate' : 'Activate') . '" data-reload="1" title="' . ($u['status'] === 'active' ? 'Deactivate' : 'Activate') . '"><i class="bi ' . ($u['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle') . '"></i></button> ')
                    . '<button class="btn btn-light btn-sm btn-reset-link" data-id="' . $u['id'] . '" data-email="' . e($u['email']) . '" title="Send password reset link"><i class="bi bi-key"></i></button>'
                    . ($isOwner && !$me ? ' <button class="btn btn-light btn-sm btn-platform-role" data-id="' . $u['id'] . '" data-email="' . e($u['email']) . '" data-role="' . ($u['is_platform_owner'] ? 'owner' : ($u['is_platform_admin'] ? 'admin' : 'none')) . '" title="Platform role"><i class="bi bi-shield-shaded"></i></button>' : '')
                    . '</div>';
                return [
                    '<div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm">' . e(strtoupper(mb_substr($u['name'], 0, 1))) . '</span><div class="min-w-0"><div class="fw-600 text-truncate">' . e($u['name']) . ($u['is_platform_owner'] ? ' <span class="badge bg-brand">Owner</span>' : ($u['is_platform_admin'] ? ' <span class="badge bg-brand">Super Admin</span>' : '')) . '</div><div class="text-muted small-xs text-truncate">' . e($u['email']) . '</div></div></div>',
                    $u['tenant_id'] ? '<a href="' . url('platform/customer.php?id=' . $u['tenant_id']) . '" class="fw-500">' . e($u['tenant_name']) . '</a><div class="text-muted small-xs">' . e($u['plan_name'] ?? '—') . ' · ' . e($u['subscription_status'] ?? '') . '</div>' : '<span class="text-muted">—</span>',
                    status_pill(in_array($u['role'], ['owner', 'admin'], true) ? 'dark' : ($u['role'] === 'manager' ? 'info' : 'secondary'), Registration::roleLabel($u['role']), false),
                    status_pill($u['status'] === 'active' ? 'success' : 'secondary', ucfirst($u['status']), false) . (empty($u['email_verified_at']) ? ' <span class="badge bg-warning text-dark">unverified</span>' : ''),
                    ($u['last_login_at'] ? e(time_ago($u['last_login_at'])) . '<div class="text-muted small-xs">' . format_datetime($u['last_login_at']) . ($u['last_login_ip'] ? ' · ' . e($u['last_login_ip']) : '') . '</div>' : '<span class="text-muted">never</span>'),
                    format_date($u['created_at']),
                    $actions,
                ];
            },
        ]);

    /* ================================================================== SUPER ADMIN (platform) tables */
    /* ------------------------------------------------------------------ clients = customer workspaces */
    case 'platform_clients':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        if ($g('status') && in_array($g('status'), ['active', 'pending', 'suspended', 'cancelled'], true)) { $where[] = 't.status = ?'; $params[] = $g('status'); }
        if ($g('sub') && in_array($g('sub'), ['free', 'trial', 'active', 'past_due', 'cancelled', 'expired'], true)) { $where[] = 't.subscription_status = ?'; $params[] = $g('sub'); }
        if ($int('plan_id')) { $where[] = 't.plan_id = ?'; $params[] = $int('plan_id'); }
        DataTable::serve([
            'from'   => 'tenants t LEFT JOIN plans p ON p.id = t.plan_id LEFT JOIN users u ON u.id = t.owner_user_id',
            'where'  => $where, 'params' => $params,
            'select' => 't.*, p.name AS plan_name, p.max_websites, p.max_forms, u.email AS owner_email, u.name AS owner_name,
                (SELECT COUNT(*) FROM websites w WHERE w.tenant_id = t.id) AS sites, (SELECT COUNT(*) FROM website_pages pg WHERE pg.tenant_id = t.id AND pg.is_active = 1) AS pages,
                (SELECT COUNT(*) FROM forms f WHERE f.tenant_id = t.id AND f.status <> \'removed\') AS forms, (SELECT COUNT(*) FROM users x WHERE x.tenant_id = t.id AND x.status = \'active\') AS members',
            'columns' => [
                ['db' => 't.name', 'search' => true], ['db' => 'u.email', 'search' => true], ['db' => 'p.name'], ['db' => 't.subscription_status'], ['db' => 't.status'], ['db' => null], ['db' => 't.last_active_at'], ['db' => 't.created_at'], ['db' => null],
            ],
            'default_order' => 't.id DESC',
            'row_attr' => fn($t) => ['data-row-id' => $t['id']],
            'row' => function ($t) {
                $trial = $t['subscription_status'] === 'trial' && $t['trial_ends_at'] ? (int) ceil((strtotime($t['trial_ends_at']) - time()) / 86400) : null;
                $subCls = ['trial' => 'warning', 'active' => 'success', 'free' => 'secondary', 'past_due' => 'danger', 'cancelled' => 'dark', 'expired' => 'danger'][$t['subscription_status']] ?? 'secondary';
                $stCls = ['active' => 'success', 'pending' => 'warning', 'suspended' => 'danger', 'cancelled' => 'dark'][$t['status']] ?? 'secondary';
                $isOwner = (int) $t['id'] === 1;
                return [
                    '<a href="' . url('platform/customer.php?id=' . $t['id']) . '" class="fw-500">' . e($t['name']) . '</a><div class="small-xs text-muted mono">' . e($t['slug']) . ' · #' . (int) $t['id'] . ($isOwner ? ' · platform owner' : '') . '</div>',
                    '<span class="small">' . e($t['owner_name'] ?? '—') . '</span><div class="small-xs text-muted">' . e($t['owner_email'] ?? '') . '</div>',
                    e($t['plan_name'] ?? '—'),
                    status_pill($subCls, strtoupper(str_replace('_', ' ', $t['subscription_status'])), false) . ($trial !== null ? '<div class="small-xs text-muted">' . max(0, $trial) . ' days left</div>' : ''),
                    status_pill($stCls, ucfirst($t['status']), false),
                    '<span class="small">' . (int) $t['sites'] . ($t['max_websites'] !== null ? '/' . (int) $t['max_websites'] : '') . ' sites · ' . number_format((int) $t['pages']) . ' pages · ' . (int) $t['forms'] . ($t['max_forms'] !== null ? '/' . (int) $t['max_forms'] : '') . ' forms · ' . (int) $t['members'] . ' users</span>',
                    '<span class="small text-muted">' . e(time_ago($t['last_active_at'])) . '</span>',
                    '<span class="small text-muted">' . format_date($t['created_at']) . '</span>',
                    '<div class="row-actions text-end text-nowrap"><a href="' . url('platform/customer.php?id=' . $t['id']) . '" class="btn btn-light btn-sm" title="View details"><i class="bi bi-eye"></i></a> <button class="btn btn-light btn-sm btn-edit-tenant" data-id="' . $t['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button> '
                        . ($t['status'] === 'active' ? ($isOwner ? '' : '<button class="btn btn-light btn-sm text-warning btn-action" data-url="api/platform.php" data-params=\'{"action":"set_status","tenant_id":' . $t['id'] . ',"status":"suspended"}\' data-confirm="Deactivate ' . e($t['name']) . '? Its users cannot sign in and monitoring pauses until it is activated again." data-confirm-btn="Deactivate" data-reload="1" title="Deactivate"><i class="bi bi-pause-circle"></i></button> ') : '<button class="btn btn-light btn-sm text-success btn-action" data-url="api/platform.php" data-params=\'{"action":"set_status","tenant_id":' . $t['id'] . ',"status":"active"}\' data-confirm="Activate ' . e($t['name']) . '?" data-confirm-btn="Activate" data-icon="question" data-reload="1" title="Activate"><i class="bi bi-play-circle"></i></button> ')
                        . '<button class="btn btn-light btn-sm btn-action" data-url="api/platform.php" data-params=\'{"action":"act_as","tenant_id":' . $t['id'] . '}\' title="View as client"><i class="bi bi-box-arrow-in-right"></i></button>'
                        . ($isOwner ? '' : ' <button class="btn btn-light btn-sm text-danger btn-delete-tenant" data-id="' . $t['id'] . '" data-name="' . e($t['name']) . '" data-slug="' . e($t['slug']) . '" title="Delete"><i class="bi bi-trash"></i></button>') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ websites of every workspace */
    case 'platform_websites':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        if ($int('tenant_id')) { $where[] = 'w.tenant_id = ?'; $params[] = $int('tenant_id'); }
        if ($g('status') === 'down') $where[] = 'w.status IN (' . down_statuses_sql() . ')';
        elseif ($g('status') === 'online') $where[] = "w.status IN ('online','redirecting')";
        elseif ($g('status') === 'paused') $where[] = "w.status = 'paused'";
        elseif ($g('status') === 'unknown') $where[] = "w.status = 'unknown'";
        if ($g('monitoring') === '1') $where[] = 'w.monitoring_enabled = 1'; elseif ($g('monitoring') === '0') $where[] = 'w.monitoring_enabled = 0';
        if ($g('tracking') === 'on') $where[] = 'w.analytics_enabled = 1'; elseif ($g('tracking') === 'off') $where[] = 'w.analytics_enabled = 0'; elseif ($g('tracking') === 'active') $where[] = 'w.analytics_enabled = 1 AND w.analytics_last_event_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'; elseif ($g('tracking') === 'silent') $where[] = 'w.analytics_enabled = 1 AND (w.analytics_last_event_at IS NULL OR w.analytics_last_event_at < DATE_SUB(NOW(), INTERVAL 7 DAY))';
        $month = date('Y-m-01');
        DataTable::serve([
            'from'   => 'websites w LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN clients c ON c.id = w.client_id LEFT JOIN plans p ON p.id = t.plan_id',
            'count_from' => 'websites w LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN clients c ON c.id = w.client_id',
            'where'  => $where, 'params' => $params,
            'select' => 'w.id, w.name, w.url, w.status, w.technology, w.monitoring_enabled, w.analytics_enabled, w.analytics_key, w.analytics_last_event_at, w.last_checked_at, w.next_check_at, w.last_failed_at, w.response_time, w.pages_total, w.forms_total, w.created_at,
                t.id AS tenant_id, t.name AS tenant_name, t.status AS tenant_status, t.subscription_status, c.id AS client_id, c.name AS client_name, p.max_pageviews_month, p.name AS plan_name,
                (SELECT COALESCE(SUM(d.pageviews),0) FROM analytics_daily d WHERE d.website_id = w.id AND d.day >= \'' . $month . '\') AS pv_month,
                (SELECT COUNT(*) FROM alerts a WHERE a.website_id = w.id AND a.status = \'open\') AS open_alerts',
            'columns' => [
                ['db' => 'w.name', 'search' => true], ['db' => 't.name', 'search' => true], ['db' => 'w.status'], ['db' => 'w.monitoring_enabled'], ['db' => 'w.analytics_enabled'], ['db' => null], ['db' => 'w.last_checked_at'], ['db' => 'w.next_check_at'], ['db' => null],
            ],
            'default_order' => 'w.id DESC',
            'row_attr' => fn($w) => ['data-row-id' => $w['id']],
            'row' => function ($w) {
                $tracking = !$w['analytics_key'] ? '<span class="badge bg-secondary-subtle">not set up</span>' : (!$w['analytics_enabled'] ? '<span class="badge bg-warning-subtle">paused</span>' : ($w['analytics_last_event_at'] && strtotime($w['analytics_last_event_at']) > time() - 7 * 86400 ? '<span class="badge bg-success-subtle">receiving</span>' : '<span class="badge bg-danger-subtle">no data' . ($w['analytics_last_event_at'] ? ' · last ' . e(time_ago($w['analytics_last_event_at'])) : ' yet') . '</span>'));
                $lim = $w['max_pageviews_month'] !== null ? (int) $w['max_pageviews_month'] : null;
                return [
                    '<span class="fw-500">' . e($w['name']) . '</span><div class="small-xs"><a href="' . e($w['url']) . '" target="_blank" rel="noopener" class="text-muted">' . e(host_from_url($w['url'])) . ' <i class="bi bi-box-arrow-up-right"></i></a> · ' . e($w['technology']) . '</div>',
                    '<a href="' . url('platform/customer.php?id=' . $w['tenant_id']) . '" class="fw-500">' . e($w['tenant_name'] ?? '—') . '</a><div class="small-xs text-muted">' . e($w['client_name'] ?? '—') . ($w['tenant_status'] !== 'active' ? ' · <span class="text-warning">' . e($w['tenant_status']) . '</span>' : '') . '</div>',
                    website_status_badge($w['status']) . ($w['response_time'] && in_array($w['status'], ['online', 'redirecting']) ? '<div class="text-muted small-xs">' . (int) $w['response_time'] . ' ms · ' . (int) $w['pages_total'] . ' pages · ' . (int) $w['forms_total'] . ' forms</div>' : ''),
                    ($w['monitoring_enabled'] ? (in_array($w['subscription_status'], ['expired', 'cancelled'], true) && !setting('monitor_expired_subscriptions', 0) ? status_pill('warning', 'Subscription ' . $w['subscription_status'], false) : status_pill('success', 'Enabled', false)) : status_pill('secondary', 'Disabled', false))
                        . '<div class="small-xs text-muted">' . e((string) ($w['plan_name'] ?? 'no plan')) . ' · every ' . Tenant::interval('website', $w['tenant_id'] ? (int) $w['tenant_id'] : null) . ' min' . ($w['open_alerts'] ? ' · <span class="text-danger">' . (int) $w['open_alerts'] . ' open alert' . ($w['open_alerts'] > 1 ? 's' : '') . '</span>' : '') . '</div>',
                    $tracking . ($w['analytics_key'] ? '<div class="small-xs text-muted mono">' . e($w['analytics_key']) . '</div>' : ''),
                    '<span class="small">' . number_format((int) $w['pv_month']) . ($lim ? ' <span class="text-muted">/ ' . number_format($lim) . '</span>' : '') . '</span>',
                    '<span class="small text-muted">' . e(time_ago($w['last_checked_at'])) . '</span>' . ($w['last_failed_at'] ? '<div class="small-xs text-muted">last failure ' . e(time_ago($w['last_failed_at'])) . '</div>' : ''),
                    '<span class="small ' . ($w['next_check_at'] && strtotime($w['next_check_at']) < time() - 120 ? 'text-warning' : 'text-muted') . '">' . ($w['monitoring_enabled'] && $w['next_check_at'] ? (strtotime($w['next_check_at']) <= time() ? 'due now' : 'in ' . e(human_seconds(strtotime($w['next_check_at']) - time()))) : '—') . '</span>' . ($w['next_check_at'] ? '<div class="small-xs text-muted">' . e(date('H:i', strtotime($w['next_check_at']))) . '</div>' : ''),
                    '<div class="row-actions text-end text-nowrap"><a href="' . url('websites/analytics.php?id=' . $w['id']) . '" class="btn btn-light btn-sm btn-site-analytics" data-tenant="' . $w['tenant_id'] . '" title="Analytics (opens the workspace)"><i class="bi bi-bar-chart-line"></i></a> <button class="btn btn-light btn-sm btn-edit-website" data-id="' . $w['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button> '
                        . ($w['monitoring_enabled'] ? '<button class="btn btn-light btn-sm text-warning btn-action" data-url="api/platform.php" data-params=\'{"action":"website_toggle","id":' . $w['id'] . ',"enable":0}\' data-confirm="Disable monitoring for ' . e($w['name']) . '?" data-confirm-btn="Disable" data-reload="1" title="Disable"><i class="bi bi-pause-circle"></i></button>' : '<button class="btn btn-light btn-sm text-success btn-action" data-url="api/platform.php" data-params=\'{"action":"website_toggle","id":' . $w['id'] . ',"enable":1}\' data-reload="1" title="Enable"><i class="bi bi-play-circle"></i></button>')
                        . ' <button class="btn btn-light btn-sm text-danger btn-action" data-url="api/platform.php" data-params=\'{"action":"website_delete","id":' . $w['id'] . '}\' data-confirm="Delete ' . e($w['name']) . ' (' . e(host_from_url($w['url'])) . ') of ' . e($w['tenant_name'] ?? '') . ' with all its pages, forms, incidents and analytics? This cannot be undone." data-confirm-btn="Delete website" data-reload="1" title="Delete"><i class="bi bi-trash"></i></button></div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ alert history (state machine rows) */
    case 'alerts':
    case 'platform_alerts':
        $platform = $table === 'platform_alerts';
        if ($platform) Auth::requirePlatformAdmin(); else Auth::requireAbility('incidents.view');
        $where = $platform ? ['1=1'] : ['a.tenant_id = ' . Tenant::id()]; $params = [];
        if (in_array($g('status'), ['open', 'recovered', 'info'], true)) { $where[] = 'a.status = ?'; $params[] = $g('status'); }
        if (in_array($g('kind'), ['website', 'page', 'form', 'ssl', 'domain', 'hosting', 'smtp', 'system'], true)) { $where[] = 'a.kind = ?'; $params[] = $g('kind'); }
        if ($int('website_id')) { $where[] = 'a.website_id = ?'; $params[] = $int('website_id'); }
        if ($platform && $int('tenant_id')) { $where[] = 'a.tenant_id = ?'; $params[] = $int('tenant_id'); }
        $days = max(1, min(365, (int) ($g('days') ?: 30)));
        $where[] = "(a.status = 'open' OR a.detected_at >= ?)"; $params[] = date('Y-m-d H:i:s', time() - $days * 86400);
        $kindLabel = ['website' => 'Website', 'page' => 'Page', 'form' => 'Form', 'ssl' => 'SSL', 'domain' => 'Domain', 'hosting' => 'Hosting', 'smtp' => 'SMTP', 'system' => 'System'];
        DataTable::serve([
            'from'   => 'alerts a LEFT JOIN websites w ON w.id = a.website_id LEFT JOIN clients c ON c.id = a.client_id' . ($platform ? ' LEFT JOIN tenants t ON t.id = a.tenant_id' : ''),
            'count_from' => 'alerts a LEFT JOIN websites w ON w.id = a.website_id LEFT JOIN clients c ON c.id = a.client_id' . ($platform ? ' LEFT JOIN tenants t ON t.id = a.tenant_id' : ''),
            'where'  => $where, 'params' => $params,
            'select' => 'a.*, w.name AS website_name, c.name AS client_name' . ($platform ? ', t.name AS tenant_name' : ''),
            'columns' => array_merge([['db' => 'a.detected_at'], ['db' => 'a.kind']], $platform ? [['db' => 't.name', 'search' => true]] : [], [['db' => 'a.title', 'search' => true], ['db' => 'a.error_message', 'search' => true], ['db' => 'a.status'], ['db' => 'a.notification_count'], ['db' => 'a.recovered_at']]),
            'default_order' => 'a.id DESC',
            'row' => function ($a) use ($platform, $kindLabel) {
                $open = $a['status'] === 'open';
                $downtime = $a['downtime_seconds'] !== null ? duration_human((int) $a['downtime_seconds']) : ($open ? duration_human(max(0, time() - strtotime($a['detected_at']))) . ' so far' : null);
                $st = $a['status'] === 'recovered' ? status_pill('success', 'Recovered') : ($a['status'] === 'info' ? status_pill('warning', ucfirst($a['severity']) . ' notice', false) : status_pill('danger', 'Failing'));
                $notif = ($a['notified_at'] ? '<span class="text-success"><i class="bi bi-envelope-check me-1"></i>alert ' . e(time_ago($a['notified_at'])) . '</span>' : '<span class="text-muted"><i class="bi bi-envelope me-1"></i>' . ($a['kind'] === 'smtp' ? 'in-app only' : 'not sent') . '</span>')
                    . ($a['recovery_notified_at'] ? '<div class="small-xs text-success"><i class="bi bi-envelope-check me-1"></i>recovery ' . e(time_ago($a['recovery_notified_at'])) . '</div>' : ($a['status'] === 'recovered' ? '<div class="small-xs text-muted">recovery: no email</div>' : ''))
                    . '<div class="small-xs text-muted">' . (int) $a['notification_count'] . ' notification' . ((int) $a['notification_count'] === 1 ? '' : 's') . ' · ' . (int) $a['checks_while_failing'] . ' check' . ((int) $a['checks_while_failing'] === 1 ? '' : 's') . ' while failing</div>';
                $target = '<span class="fw-500">' . e($a['title']) . '</span>' . ($a['target_label'] ? '<div class="small-xs text-muted">' . e(truncate($a['target_label'], 70)) . '</div>' : '') . ($a['website_name'] || $a['client_name'] ? '<div class="small-xs text-muted">' . e($a['website_name'] ?? '') . ($a['client_name'] ? ' · ' . e($a['client_name']) : '') . '</div>' : '');
                if ($a['link']) $target = '<a href="' . url($a['link']) . '" class="text-reset">' . $target . '</a>';
                $row = [
                    '<span class="text-nowrap small">' . format_datetime($a['detected_at']) . '</span><div class="small-xs text-muted">' . e(time_ago($a['detected_at'])) . '</div>',
                    '<span class="badge bg-secondary-subtle text-secondary">' . e($kindLabel[$a['kind']] ?? $a['kind']) . '</span>',
                ];
                if ($platform) $row[] = '<a href="' . url('platform/customer.php?id=' . (int) $a['tenant_id']) . '" class="fw-500">' . e($a['tenant_name'] ?? 'platform') . '</a>';
                return array_merge($row, [
                    $target,
                    '<span class="small text-danger fw-500">' . e($a['error_type'] ?: '—') . '</span>' . ($a['error_message'] ? '<div class="small-xs text-muted" title="' . e($a['error_message']) . '">' . e(truncate($a['error_message'], 90)) . '</div>' : '') . ($a['previous_status'] || $a['current_status'] ? '<div class="small-xs text-muted">' . e($a['previous_status'] ?: '?') . ' → ' . e($a['current_status'] ?: '?') . '</div>' : ''),
                    $st . ($downtime ? '<div class="small-xs text-muted">' . e($downtime) . '</div>' : ''),
                    $notif,
                    $a['recovered_at'] ? '<span class="text-nowrap small">' . format_datetime($a['recovered_at']) . '</span>' : '<span class="text-muted">—</span>',
                ]);
            },
        ]);

    /* ------------------------------------------------------------------ subscriptions */
    case 'platform_subscriptions':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        if ($g('sub') && in_array($g('sub'), ['free', 'trial', 'active', 'past_due', 'cancelled', 'expired'], true)) { $where[] = 't.subscription_status = ?'; $params[] = $g('sub'); }
        if ($int('plan_id')) { $where[] = 't.plan_id = ?'; $params[] = $int('plan_id'); }
        if ($g('ending') === '7') $where[] = "t.subscription_status = 'trial' AND t.trial_ends_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)";
        DataTable::serve([
            'from'   => 'tenants t LEFT JOIN plans p ON p.id = t.plan_id LEFT JOIN users u ON u.id = t.owner_user_id LEFT JOIN subscriptions s ON s.id = (SELECT MAX(id) FROM subscriptions x WHERE x.tenant_id = t.id)',
            'count_from' => 'tenants t',
            'where'  => $where, 'params' => $params,
            'select' => 't.id, t.name, t.slug, t.status, t.subscription_status, t.trial_ends_at, t.billing_email, t.plan_id, t.created_at, p.name AS plan_name, p.price_monthly, p.currency, u.email AS owner_email, s.started_at, s.current_period_end, s.renews_at, s.notes, s.amount',
            'columns' => [
                ['db' => 't.name', 'search' => true], ['db' => 'p.name'], ['db' => 'p.price_monthly'], ['db' => 't.subscription_status'], ['db' => 's.started_at'], ['db' => 't.trial_ends_at'], ['db' => 't.billing_email', 'search' => true], ['db' => null],
            ],
            'default_order' => 't.id DESC',
            'row_attr' => fn($t) => ['data-row-id' => $t['id']],
            'row' => function ($t) {
                $subCls = ['trial' => 'warning', 'active' => 'success', 'free' => 'secondary', 'past_due' => 'danger', 'cancelled' => 'dark', 'expired' => 'danger'][$t['subscription_status']] ?? 'secondary';
                $trial = $t['subscription_status'] === 'trial' && $t['trial_ends_at'] ? (int) ceil((strtotime($t['trial_ends_at']) - time()) / 86400) : null;
                return [
                    '<a href="' . url('platform/customer.php?id=' . $t['id']) . '" class="fw-500">' . e($t['name']) . '</a><div class="small-xs text-muted">' . e($t['owner_email'] ?? '') . ($t['status'] !== 'active' ? ' · <span class="text-warning">' . e($t['status']) . '</span>' : '') . '</div>',
                    e($t['plan_name'] ?? '—'),
                    '<span class="small">' . ($t['price_monthly'] === null ? 'Custom' : '₹' . number_format((int) $t['price_monthly']) . ' / mo') . '</span>',
                    status_pill($subCls, strtoupper(str_replace('_', ' ', $t['subscription_status'])), false),
                    '<span class="small text-muted">' . ($t['started_at'] ? format_date($t['started_at']) : format_date($t['created_at'])) . '</span>',
                    $t['subscription_status'] === 'trial' ? '<span class="small ' . ($trial !== null && $trial <= 3 ? 'text-danger' : '') . '">' . ($t['trial_ends_at'] ? format_date($t['trial_ends_at']) . ' (' . max(0, (int) $trial) . ' d)' : '—') . '</span>' : '<span class="small text-muted">' . ($t['renews_at'] ? format_date($t['renews_at']) : ($t['current_period_end'] ? format_date($t['current_period_end']) : '—')) . '</span>',
                    '<span class="small">' . e($t['billing_email'] ?: '—') . '</span>',
                    '<div class="row-actions text-end text-nowrap"><button class="btn btn-light btn-sm btn-change-plan" data-id="' . $t['id'] . '" data-name="' . e($t['name']) . '" data-plan="' . (int) $t['plan_id'] . '" title="Change plan"><i class="bi bi-tags"></i></button> <button class="btn btn-light btn-sm btn-sub-status" data-id="' . $t['id'] . '" data-name="' . e($t['name']) . '" data-status="' . e($t['subscription_status']) . '" title="Subscription status"><i class="bi bi-credit-card"></i></button> <button class="btn btn-light btn-sm btn-sub-history" data-id="' . $t['id'] . '" title="History"><i class="bi bi-clock-history"></i></button></div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ usage vs plan limits */
    case 'platform_usage':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        if ($int('plan_id')) { $where[] = 't.plan_id = ?'; $params[] = $int('plan_id'); }
        if ($g('over') === '1') $where[] = '((p.max_websites IS NOT NULL AND COALESCE(ws.n,0) >= p.max_websites) OR (p.max_pageviews_month IS NOT NULL AND COALESCE(ad.pv,0) >= p.max_pageviews_month * 0.8))';
        $month = date('Y-m-01');
        $from = "tenants t LEFT JOIN plans p ON p.id = t.plan_id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM websites GROUP BY tenant_id) ws ON ws.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM website_pages WHERE is_active = 1 GROUP BY tenant_id) pg ON pg.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM forms WHERE status <> 'removed' GROUP BY tenant_id) fm ON fm.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM users WHERE status = 'active' AND role <> 'notify' GROUP BY tenant_id) us ON us.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM status_pages GROUP BY tenant_id) sp ON sp.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, SUM(pageviews) AS pv FROM analytics_daily WHERE day >= '$month' GROUP BY tenant_id) ad ON ad.tenant_id = t.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) AS n FROM analytics_events GROUP BY tenant_id) ae ON ae.tenant_id = t.id";
        DataTable::serve([
            'from'   => $from,
            'where'  => $where, 'params' => $params,
            'select' => 't.id, t.name, t.status, t.subscription_status, p.name AS plan_name, p.max_websites, p.max_pages, p.max_forms, p.max_users, p.max_status_pages, p.max_pageviews_month, p.retention_days, p.features,
                COALESCE(ws.n,0) AS websites, COALESCE(pg.n,0) AS pages, COALESCE(fm.n,0) AS forms, COALESCE(us.n,0) AS users, COALESCE(sp.n,0) AS status_pages, COALESCE(ad.pv,0) AS pv, COALESCE(ae.n,0) AS events',
            'columns' => [
                ['db' => 't.name', 'search' => true], ['db' => 'p.name'], ['db' => 'ws.n', 'order' => 'COALESCE(ws.n,0)'], ['db' => 'pg.n', 'order' => 'COALESCE(pg.n,0)'], ['db' => 'fm.n', 'order' => 'COALESCE(fm.n,0)'], ['db' => 'us.n', 'order' => 'COALESCE(us.n,0)'], ['db' => 'ad.pv', 'order' => 'COALESCE(ad.pv,0)'], ['db' => 'ae.n', 'order' => 'COALESCE(ae.n,0)'], ['db' => 'p.retention_days'],
            ],
            'default_order' => 'COALESCE(ad.pv,0) DESC, t.id DESC',
            'row' => function ($t) {
                $cell = function ($cur, $lim) { $cur = (int) $cur; if ($lim === null) return '<div class="usage-cell"><span class="small">' . number_format($cur) . ' <span class="text-muted">/ ∞</span></span></div>'; $lim = (int) $lim; $pct = $lim ? min(100, (int) round($cur / max(1, $lim) * 100)) : ($cur ? 100 : 0); return '<div class="usage-cell"><span class="small ' . ($pct >= 100 ? 'text-danger fw-600' : ($pct >= 80 ? 'text-warning' : '')) . '">' . number_format($cur) . ' <span class="text-muted">/ ' . number_format($lim) . '</span></span><div class="progress"><div class="progress-bar ' . ($pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success')) . '" style="width:' . max(2, $pct) . '%"></div></div></div>'; };
                $feat = json_decode((string) $t['features'], true) ?: [];
                return [
                    '<a href="' . url('platform/customer.php?id=' . $t['id']) . '" class="fw-500">' . e($t['name']) . '</a><div class="small-xs text-muted">' . e($t['subscription_status']) . ($t['status'] !== 'active' ? ' · <span class="text-warning">' . e($t['status']) . '</span>' : '') . '</div>',
                    e($t['plan_name'] ?? '—'),
                    $cell($t['websites'], $t['max_websites']), $cell($t['pages'], $t['max_pages']), $cell($t['forms'], $t['max_forms']), $cell($t['users'], $t['max_users']),
                    $cell($t['pv'], $t['max_pageviews_month']),
                    '<span class="small">' . number_format((int) $t['events']) . '</span>',
                    '<span class="small text-muted">' . (int) $t['retention_days'] . ' d checks · ' . (int) ($feat['analytics_retention'] ?? 30) . ' d analytics</span>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ email delivery log (all workspaces) */
    case 'platform_emails':
        Auth::requirePlatformAdmin();
        $where = ['1=1']; $params = [];
        $st = $g('status');
        if ($st === 'sent' || $st === 'failed') { $where[] = 'l.status = ?'; $params[] = $st; }
        elseif (str_starts_with((string) $st, 'kind:')) { $where[] = "l.status = 'failed' AND l.error_kind = ?"; $params[] = substr($st, 5); }
        if ($g('category')) { $where[] = 'l.category = ?'; $params[] = $g('category'); }
        if ($g('tenant_id') !== '' && $g('tenant_id') !== null) { if ((int) $g('tenant_id') === 0) $where[] = 'l.tenant_id IS NULL'; else { $where[] = 'l.tenant_id = ?'; $params[] = (int) $g('tenant_id'); } }
        if ($g('from') && strtotime($g('from'))) { $where[] = 'l.sent_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
        if ($g('to') && strtotime($g('to'))) { $where[] = 'l.sent_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
        DataTable::serve([
            'from'   => 'email_logs l LEFT JOIN tenants t ON t.id = l.tenant_id',
            'count_from' => 'email_logs l',
            'where'  => $where, 'params' => $params,
            'select' => 'l.id, l.sent_at, l.to_email, l.cc, l.bcc, l.from_email, l.category, l.template, l.subject, l.status, l.error_kind, l.smtp_response, l.message_id, l.error, l.duration_ms, l.queue_id, l.attempt, t.name AS tenant_name',
            'columns' => [
                ['db' => 'l.sent_at'], ['db' => 'l.to_email', 'search' => true], ['db' => 'l.subject', 'search' => true], ['db' => 'l.category'], ['db' => 'l.status'], ['db' => 'l.duration_ms'], ['db' => null], ['db' => null],
            ],
            'default_order' => 'l.id DESC',
            'row' => function ($m) {
                $kind = $m['status'] === 'sent' ? status_pill('success', 'Sent') : status_pill($m['error_kind'] === 'rejected' ? 'warning' : 'danger', Mailer::KINDS[$m['error_kind'] ?? 'other'] ?? 'Failed');
                return [
                    '<span class="text-nowrap small">' . format_datetime($m['sent_at']) . '</span>',
                    '<span class="small">' . e($m['to_email']) . '</span>' . ($m['cc'] ? '<div class="small-xs text-muted">CC: ' . e($m['cc']) . '</div>' : '') . '<div class="small-xs text-muted">' . e($m['tenant_name'] ?? 'platform') . '</div>',
                    '<span class="small">' . e(truncate($m['subject'], 70)) . '</span>',
                    '<span class="badge bg-secondary-subtle text-secondary">' . e(Mailer::categoryLabel($m['category'])) . '</span>',
                    $kind . ($m['attempt'] > 1 ? '<div class="small-xs text-muted">attempt ' . (int) $m['attempt'] . '</div>' : ''),
                    '<span class="small text-muted">' . ($m['duration_ms'] !== null ? number_format((int) $m['duration_ms']) . ' ms' : '—') . '</span>',
                    $m['status'] === 'sent' ? '<span class="small text-muted" title="' . e($m['smtp_response']) . '">' . e(truncate($m['smtp_response'] ?? '', 60)) . '</span>' : '<span class="small text-danger" title="' . e($m['error']) . '">' . e(truncate($m['error'] ?? '', 90)) . '</span>' . ($m['smtp_response'] ? '<div class="small-xs text-muted mono">' . e(truncate($m['smtp_response'], 60)) . '</div>' : ''),
                    '<div class="row-actions text-end text-nowrap"><button class="btn btn-light btn-sm btn-view-email" data-id="' . $m['id'] . '" data-src="log" title="Details"><i class="bi bi-eye"></i></button>' . ($m['queue_id'] && $m['status'] === 'failed' ? ' <button class="btn btn-light btn-sm btn-resend-log" data-id="' . $m['id'] . '" title="Re-send"><i class="bi bi-arrow-repeat"></i></button>' : '') . '</div>',
                ];
            },
        ]);

    /* ------------------------------------------------------------------ Super Admin audit log */
    case 'platform_audit':
        Auth::requirePlatformAdmin();
        $where = [$g('scope') === 'all' ? '1=1' : 'a.is_platform = 1']; $params = [];
        if ($g('action')) { $where[] = 'a.action = ?'; $params[] = $g('action'); }
        if ($g('user_id') !== '' && $g('user_id') !== null) { if ((int) $g('user_id') === 0) $where[] = 'a.user_id IS NULL'; else { $where[] = 'a.user_id = ?'; $params[] = (int) $g('user_id'); } }
        if ($g('result')) { $where[] = 'a.result = ?'; $params[] = $g('result'); }
        if ($int('tenant_id')) { $where[] = 'a.tenant_id = ?'; $params[] = $int('tenant_id'); }
        if ($g('from') && strtotime($g('from'))) { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
        if ($g('to') && strtotime($g('to'))) { $where[] = 'a.created_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
        DataTable::serve([
            'from'   => 'activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id',
            'count_from' => 'activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id',
            'where'  => $where, 'params' => $params,
            'select' => 'a.id, a.created_at, a.action, a.description, a.target, a.result, a.ip_address, a.tenant_id, u.name AS user_name, u.email AS user_email, t.name AS tenant_name',
            'columns' => [
                ['db' => 'a.created_at'], ['db' => 'u.name', 'search' => true], ['db' => 'a.action'], ['db' => 'a.target', 'search' => true], ['db' => 'a.description', 'search' => true], ['db' => 'a.result'], ['db' => 'a.ip_address'],
            ],
            'default_order' => 'a.id DESC',
            'row' => function ($a) {
                $res = $a['result'] ?: 'ok';
                return [
                    '<span class="text-nowrap small">' . format_datetime($a['created_at']) . '</span>',
                    '<span class="small">' . e($a['user_name'] ?? 'System') . '</span>' . ($a['user_email'] ? '<div class="small-xs text-muted">' . e($a['user_email']) . '</div>' : ''),
                    '<span class="badge bg-secondary-subtle text-secondary"><i class="bi ' . ActivityLog::icon($a['action']) . ' me-1"></i>' . e(ActivityLog::label($a['action'])) . '</span>',
                    '<span class="small">' . e($a['target'] ?: ($a['tenant_name'] ?: '—')) . '</span>' . ($a['target'] && $a['tenant_name'] ? '<div class="small-xs text-muted">' . e($a['tenant_name']) . '</div>' : ''),
                    '<span class="small">' . e($a['description']) . '</span>',
                    status_pill($res === 'ok' ? 'success' : ($res === 'denied' ? 'warning' : 'danger'), ucfirst($res), false),
                    '<span class="mono small-xs text-muted">' . e($a['ip_address']) . '</span>',
                ];
            },
        ]);

    default:
        json_error('Unknown table.', 404);
}
