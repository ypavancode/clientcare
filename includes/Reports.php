<?php
/**
 * Report definitions shared by reports/index.php (screen) and reports/export.php (CSV / Excel / print).
 */
class Reports
{
    public static function list(): array
    {
        return [
            'clients'  => ['title' => 'Client Report', 'icon' => 'bi-people', 'desc' => 'All clients with website, page and form counts.'],
            'health'   => ['title' => 'Website Health Report', 'icon' => 'bi-heart-pulse', 'desc' => 'Current status, page health, SSL, domain and hosting expiry per website.'],
            'pages'    => ['title' => 'Page Monitoring Report', 'icon' => 'bi-file-earmark-text', 'desc' => 'Every monitored page with its current status, HTTP code and last check.'],
            'failedpages' => ['title' => 'Failed Pages Report', 'icon' => 'bi-file-earmark-x', 'desc' => 'Pages currently failing with the reason and error.'],
            'pageincidents' => ['title' => 'Page Downtime Report', 'icon' => 'bi-file-earmark-break', 'desc' => 'Every page failure incident with duration and cause.'],
            'uptime'   => ['title' => 'Uptime Report', 'icon' => 'bi-graph-up', 'desc' => 'Uptime percentage, checks and average response time per website.'],
            'downtime' => ['title' => 'Website Downtime Report', 'icon' => 'bi-wifi-off', 'desc' => 'Every downtime incident with duration and cause.'],
            'formtests'=> ['title' => 'Form Testing Report', 'icon' => 'bi-clipboard-check', 'desc' => 'All form tests in the selected period.'],
            'failedforms' => ['title' => 'Failed Forms Report', 'icon' => 'bi-x-octagon', 'desc' => 'Forms currently failing with the last error.'],
            'ssl'      => ['title' => 'SSL Expiry Report', 'icon' => 'bi-shield-lock', 'desc' => 'SSL certificate status and expiry dates.'],
            'domains'  => ['title' => 'Domain Expiry Report', 'icon' => 'bi-globe', 'desc' => 'Domain registrations sorted by expiry.'],
            'hosting'  => ['title' => 'Hosting Expiry Report', 'icon' => 'bi-hdd-network', 'desc' => 'Hosting accounts sorted by expiry.'],
            'emails'   => ['title' => 'Email Log', 'icon' => 'bi-envelope', 'desc' => 'Notification emails sent by the system.'],
        ];
    }

    /** Maximum rows a report returns on screen / export – keeps memory and page size bounded for huge datasets. */
    const MAX_ROWS = 1000;
    const MAX_EXPORT_ROWS = 50000;

    /**
     * @return array{columns: array<string,string>, rows: array, limited: bool, limit: int}
     */
    public static function run(string $key, array $f, int $limit = self::MAX_ROWS): array
    {
        $from = ($f['from'] ?? '') && strtotime($f['from']) ? date('Y-m-d 00:00:00', strtotime($f['from'])) : date('Y-m-d 00:00:00', strtotime('-30 days'));
        $to = ($f['to'] ?? '') && strtotime($f['to']) ? date('Y-m-d 23:59:59', strtotime($f['to'])) : date('Y-m-d 23:59:59');
        $clientId = (int) ($f['client_id'] ?? 0);
        $cw = ' AND c.tenant_id = ' . Tenant::id() . ($clientId ? " AND c.id = $clientId" : '');
        $lim = ' LIMIT ' . (int) $limit;
        $result = self::build($key, $from, $to, $cw, $lim);
        $result['limited'] = count($result['rows']) >= $limit;
        $result['limit'] = $limit;
        return $result;
    }

    private static function build(string $key, string $from, string $to, string $cw, string $lim): array
    {
        $sinceDay = substr($from, 0, 10);
        switch ($key) {
            case 'clients':
                return ['columns' => ['name' => 'Client', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', 'status' => 'Status', 'assigned' => 'Assigned', 'sites' => 'Websites', 'pages' => 'Pages', 'failed_pages' => 'Failed Pages', 'forms' => 'Forms', 'failed_forms' => 'Failed Forms', 'created' => 'Client Since'],
                    'rows' => DB::fetchAll("SELECT c.name, c.company, c.email, c.phone, c.status, u.name AS assigned,
                        (SELECT COUNT(*) FROM websites w WHERE w.client_id = c.id) AS sites,
                        (SELECT COALESCE(SUM(w.pages_total),0) FROM websites w WHERE w.client_id = c.id) AS pages,
                        (SELECT COALESCE(SUM(w.pages_failed),0) FROM websites w WHERE w.client_id = c.id) AS failed_pages,
                        (SELECT COUNT(*) FROM forms fm JOIN websites w ON w.id = fm.website_id WHERE w.client_id = c.id) AS forms,
                        (SELECT COUNT(*) FROM forms fm JOIN websites w ON w.id = fm.website_id WHERE w.client_id = c.id AND fm.status='failed') AS failed_forms,
                        DATE(c.created_at) AS created FROM clients c LEFT JOIN users u ON u.id = c.assigned_user_id WHERE 1=1 $cw ORDER BY c.name" . $lim)];
            case 'health':
                return ['columns' => ['website' => 'Website', 'url' => 'URL', 'client' => 'Client', 'technology' => 'Technology', 'status' => 'Status', 'http_code' => 'HTTP', 'response_time' => 'Response (ms)', 'page_health' => 'Page Health', 'pages' => 'Pages Working', 'ssl_status' => 'SSL', 'ssl_expires' => 'SSL Expiry', 'domain_expiry' => 'Domain Expiry', 'hosting_expiry' => 'Hosting Expiry', 'last_checked' => 'Last Check', 'last_scan' => 'Last Page Scan'],
                    'rows' => array_map(function ($r) { $r['pages'] = $r['pages_total'] ? ($r['pages_total'] - $r['pages_failed']) . ' / ' . $r['pages_total'] : ''; unset($r['pages_total'], $r['pages_failed']); return $r; },
                    DB::fetchAll("SELECT w.name AS website, w.url, c.name AS client, w.technology, w.status, w.http_code, w.response_time, w.page_health, w.pages_total, w.pages_failed, w.ssl_status, DATE(w.ssl_expires_at) AS ssl_expires, d.expiry_date AS domain_expiry, h.expiry_date AS hosting_expiry, w.last_checked_at AS last_checked, w.last_scan_at AS last_scan
                        FROM websites w STRAIGHT_JOIN clients c ON c.id = w.client_id LEFT JOIN domains d ON d.website_id = w.id LEFT JOIN hosting h ON h.website_id = w.id WHERE c.status <> 'archived' $cw ORDER BY w.status, w.name" . $lim))];
            case 'pages':
                return ['columns' => ['client' => 'Client', 'website' => 'Website', 'page' => 'Page', 'url' => 'URL', 'page_status' => 'Status', 'http_code' => 'HTTP', 'response_time' => 'Response (ms)', 'failure_reason' => 'Reason', 'last_checked' => 'Last Check', 'last_success_at' => 'Last Success', 'source' => 'Found via'],
                    'rows' => array_map(function ($r) { $r['page'] = $r['title'] ?: Monitor::pathLabel($r['path']); unset($r['title'], $r['path']); return $r; },
                    DB::fetchAll("SELECT c.name AS client, w.name AS website, p.title, p.path, p.url, p.status AS page_status, p.http_code, p.response_time, p.failure_reason, p.last_checked_at AS last_checked, p.last_success_at, p.source
                        FROM website_pages p JOIN websites w ON w.id = p.website_id JOIN clients c ON c.id = w.client_id WHERE p.is_active = 1 AND c.status <> 'archived' $cw ORDER BY c.name, w.name, p.priority, p.path" . $lim))];
            case 'failedpages':
                return ['columns' => ['client' => 'Client', 'website' => 'Website', 'page' => 'Page', 'url' => 'URL', 'http_code' => 'HTTP', 'failure_reason' => 'Reason', 'error_message' => 'Error', 'failed_checks' => 'Failed Checks', 'last_failed_at' => 'Failing Since', 'last_success_at' => 'Last Success'],
                    'rows' => array_map(function ($r) { $r['page'] = $r['title'] ?: Monitor::pathLabel($r['path']); unset($r['title'], $r['path']); return $r; },
                    DB::fetchAll("SELECT c.name AS client, w.name AS website, p.title, p.path, p.url, p.http_code, p.failure_reason, p.error_message, p.failed_checks,
                        (SELECT i.started_at FROM page_incidents i WHERE i.page_id = p.id AND i.resolved_at IS NULL ORDER BY i.id DESC LIMIT 1) AS last_failed_at, p.last_success_at
                        FROM website_pages p JOIN websites w ON w.id = p.website_id JOIN clients c ON c.id = w.client_id WHERE p.is_active = 1 AND p.status IN (" . down_statuses_sql() . ") AND c.status <> 'archived' $cw ORDER BY p.last_failed_at DESC" . $lim))];
            case 'pageincidents':
                return ['columns' => ['client' => 'Client', 'website' => 'Website', 'page' => 'Page', 'url' => 'URL', 'started_at' => 'Started', 'resolved_at' => 'Resolved', 'duration' => 'Duration', 'status_code' => 'HTTP', 'failure_reason' => 'Reason', 'error_message' => 'Error', 'alerted' => 'Email Sent'],
                    'rows' => array_map(function ($r) { $r['page'] = $r['title'] ?: Monitor::pathLabel($r['path']); $r['duration'] = duration_human($r['resolved_at'] ? (int) $r['duration_seconds'] : time() - strtotime($r['started_at'])); $r['resolved_at'] = $r['resolved_at'] ?: 'Ongoing'; $r['alerted'] = $r['alert_sent'] ? 'Yes' : 'No'; unset($r['title'], $r['path'], $r['duration_seconds'], $r['alert_sent']); return $r; },
                    DB::fetchAll("SELECT c.name AS client, w.name AS website, p.title, p.path, p.url, i.started_at, i.resolved_at, i.duration_seconds, i.status_code, i.failure_reason, i.error_message, i.alert_sent
                        FROM page_incidents i JOIN website_pages p ON p.id = i.page_id JOIN websites w ON w.id = i.website_id JOIN clients c ON c.id = w.client_id WHERE i.started_at BETWEEN ? AND ? $cw ORDER BY i.started_at DESC" . $lim, [$from, $to]))];
            case 'uptime':
                return ['columns' => ['website' => 'Website', 'client' => 'Client', 'checks' => 'Checks', 'up_checks' => 'Successful', 'uptime' => 'Uptime %', 'avg_rt' => 'Avg Response (ms)', 'incidents' => 'Incidents', 'downtime' => 'Downtime'],
                    'rows' => array_map(function ($r) { $r['uptime'] = $r['checks'] ? number_format($r['up_checks'] / $r['checks'] * 100, 2) : ''; $r['avg_rt'] = $r['avg_rt'] !== null ? (int) $r['avg_rt'] : ''; $r['downtime'] = duration_human((int) $r['downtime']); return $r; },
                    DB::fetchAll("SELECT w.name AS website, c.name AS client,
                        (SELECT COALESCE(SUM(u.checks),0) FROM website_uptime_daily u WHERE u.website_id = w.id AND u.day >= ?) + (SELECT COUNT(*) FROM website_monitoring m WHERE m.website_id = w.id AND m.checked_at >= CURDATE() AND m.checked_at <= ?) AS checks,
                        (SELECT COALESCE(SUM(u.up_checks),0) FROM website_uptime_daily u WHERE u.website_id = w.id AND u.day >= ?) + (SELECT COALESCE(SUM(m.status IN ('online','redirecting')),0) FROM website_monitoring m WHERE m.website_id = w.id AND m.checked_at >= CURDATE() AND m.checked_at <= ?) AS up_checks,
                        (SELECT AVG(m.response_time) FROM website_monitoring m WHERE m.website_id = w.id AND m.status IN ('online','redirecting') AND m.checked_at BETWEEN ? AND ?) AS avg_rt,
                        (SELECT COUNT(*) FROM website_incidents i WHERE i.website_id = w.id AND i.started_at BETWEEN ? AND ?) AS incidents,
                        (SELECT COALESCE(SUM(COALESCE(i.duration_seconds, TIMESTAMPDIFF(SECOND, i.started_at, NOW()))),0) FROM website_incidents i WHERE i.website_id = w.id AND i.started_at BETWEEN ? AND ?) AS downtime
                        FROM websites w STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' $cw ORDER BY w.name" . $lim, [$sinceDay, $to, $sinceDay, $to, $from, $to, $from, $to, $from, $to]))];
            case 'downtime':
                return ['columns' => ['website' => 'Website', 'client' => 'Client', 'started_at' => 'Started', 'resolved_at' => 'Resolved', 'duration' => 'Duration', 'status' => 'Status', 'status_code' => 'HTTP', 'error_message' => 'Error'],
                    'rows' => array_map(function ($r) { $r['duration'] = duration_human($r['resolved_at'] ? (int) $r['duration_seconds'] : time() - strtotime($r['started_at'])); $r['resolved_at'] = $r['resolved_at'] ?: 'Ongoing'; unset($r['duration_seconds']); return $r; },
                    DB::fetchAll("SELECT w.name AS website, c.name AS client, i.started_at, i.resolved_at, i.duration_seconds, i.status, i.status_code, i.error_message FROM website_incidents i JOIN websites w ON w.id = i.website_id JOIN clients c ON c.id = w.client_id WHERE i.started_at BETWEEN ? AND ? $cw ORDER BY i.started_at DESC" . $lim, [$from, $to]))];
            case 'formtests':
                return ['columns' => ['tested_at' => 'Tested', 'client' => 'Client', 'website' => 'Website', 'form' => 'Form', 'test_mode' => 'Mode', 'result' => 'Result', 'http_code' => 'HTTP', 'response_time' => 'Time (ms)', 'email_received' => 'Email', 'error' => 'Error'],
                    'rows' => DB::fetchAll("SELECT t.tested_at, c.name AS client, w.name AS website, f.name AS form, t.test_mode, t.result, t.http_code, t.response_time, t.email_received, t.error FROM form_tests t JOIN forms f ON f.id = t.form_id JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id WHERE t.tested_at BETWEEN ? AND ? $cw ORDER BY t.tested_at DESC" . $lim, [$from, $to])];
            case 'failedforms':
                return ['columns' => ['client' => 'Client', 'website' => 'Website', 'form' => 'Form', 'page_url' => 'Page URL', 'recipient_email' => 'Recipient', 'last_tested_at' => 'Last Test', 'last_success_at' => 'Last Success', 'last_error' => 'Error'],
                    'rows' => DB::fetchAll("SELECT c.name AS client, w.name AS website, f.name AS form, f.page_url, f.recipient_email, f.last_tested_at, f.last_success_at, f.last_error FROM forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id WHERE f.status = 'failed' $cw ORDER BY f.last_tested_at DESC" . $lim)];
            case 'ssl':
                return ['columns' => ['website' => 'Website', 'client' => 'Client', 'url' => 'URL', 'ssl_status' => 'Status', 'ssl_expires' => 'Expiry', 'ssl_days_left' => 'Days Left', 'ssl_issuer' => 'Issuer', 'ssl_error' => 'Error', 'ssl_checked_at' => 'Checked'],
                    'rows' => DB::fetchAll("SELECT w.name AS website, c.name AS client, w.url, w.ssl_status, DATE(w.ssl_expires_at) AS ssl_expires, w.ssl_days_left, w.ssl_issuer, w.ssl_error, w.ssl_checked_at FROM websites w JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' $cw ORDER BY w.ssl_days_left IS NULL, w.ssl_days_left ASC" . $lim)];
            case 'domains':
                return ['columns' => ['domain_name' => 'Domain', 'client' => 'Client', 'website' => 'Website', 'registrar' => 'Registrar', 'registration_date' => 'Registered', 'expiry_date' => 'Expiry', 'days_left' => 'Days Left', 'auto_renew' => 'Auto-renew'],
                    'rows' => array_map(function ($r) { $r['days_left'] = days_until($r['expiry_date']); $r['auto_renew'] = $r['auto_renew'] ? 'Yes' : 'No'; return $r; },
                    DB::fetchAll("SELECT d.domain_name, c.name AS client, w.name AS website, d.registrar, d.registration_date, d.expiry_date, d.auto_renew FROM domains d JOIN clients c ON c.id = d.client_id LEFT JOIN websites w ON w.id = d.website_id WHERE c.status <> 'archived' $cw ORDER BY d.expiry_date IS NULL, d.expiry_date ASC" . $lim))];
            case 'hosting':
                return ['columns' => ['provider' => 'Provider', 'client' => 'Client', 'website' => 'Website', 'server_ip' => 'Server', 'plan' => 'Plan', 'start_date' => 'Start', 'expiry_date' => 'Expiry', 'days_left' => 'Days Left', 'renewal_status' => 'Renewal'],
                    'rows' => array_map(function ($r) { $r['days_left'] = days_until($r['expiry_date']); return $r; },
                    DB::fetchAll("SELECT h.provider, c.name AS client, w.name AS website, h.server_ip, h.plan, h.start_date, h.expiry_date, h.renewal_status FROM hosting h JOIN clients c ON c.id = h.client_id LEFT JOIN websites w ON w.id = h.website_id WHERE c.status <> 'archived' $cw ORDER BY h.expiry_date IS NULL, h.expiry_date ASC" . $lim))];
            case 'emails':
                return ['columns' => ['sent_at' => 'Sent', 'to_email' => 'To', 'subject' => 'Subject', 'category' => 'Category', 'status' => 'Status', 'error' => 'Error'],
                    'rows' => DB::fetchAll("SELECT sent_at, to_email, subject, category, status, error FROM email_logs WHERE tenant_id = ? AND sent_at BETWEEN ? AND ? ORDER BY sent_at DESC" . $lim, [Tenant::id(), $from, $to])];
        }
        return ['columns' => [], 'rows' => []];
    }

    public static function usesDateRange(string $key): bool
    {
        return in_array($key, ['uptime', 'downtime', 'pageincidents', 'formtests', 'emails'], true);
    }
}
