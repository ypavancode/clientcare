<?php
/**
 * Dashboard statistics. Computed with a handful of grouped queries, cached for 2 minutes and pre-warmed by the
 * cron (process-notifications.php) so users never wait for the aggregation on very large databases.
 */
class Stats
{
    /** Website project statistics for the dashboard and the projects page (cached, invalidated on writes). */
    public static function projects(bool $refresh = false): array
    {
        $key = Cache::vkey('data', 'projects:stats:' . Tenant::id());
        if ($refresh) Cache::forget($key);
        return Cache::remember($key, 120, function () {
            $byStatus = [];
            $total = 0;
            foreach (DB::fetchAll("SELECT status, COUNT(*) AS n FROM website_projects WHERE tenant_id = " . Tenant::id() . " GROUP BY status") as $r) { $byStatus[$r['status']] = (int) $r['n']; $total += (int) $r['n']; }
            $inProgress = 0;
            foreach (['design', 'development', 'testing', 'client_review', 'changes_required', 'ready_for_launch'] as $s) $inProgress += $byStatus[$s] ?? 0;
            return [
                'total' => $total,
                'in_progress' => $inProgress,
                'by_status' => $byStatus,
                'new' => (int) DB::value("SELECT COUNT(*) FROM website_projects WHERE tenant_id = " . Tenant::id() . " AND project_kind = 'new'"),
                'existing' => (int) DB::value("SELECT COUNT(*) FROM website_projects WHERE tenant_id = " . Tenant::id() . " AND project_kind = 'existing'"),
                'monitored' => (int) DB::value("SELECT COUNT(*) FROM website_projects p JOIN websites w ON w.id = p.website_id WHERE p.tenant_id = " . Tenant::id() . " AND w.monitoring_enabled = 1"),
                'by_department' => DB::fetchAll("SELECT d.id, d.name, COUNT(*) AS n FROM website_projects p LEFT JOIN departments d ON d.id = p.department_id WHERE p.tenant_id = " . Tenant::id() . " GROUP BY d.id, d.name ORDER BY n DESC, d.name LIMIT 30"),
                'by_type' => DB::fetchAll("SELECT t.id, t.name, d.name AS department_name, COUNT(*) AS n FROM website_projects p LEFT JOIN website_types t ON t.id = p.website_type_id LEFT JOIN departments d ON d.id = t.department_id WHERE p.tenant_id = " . Tenant::id() . " GROUP BY t.id, t.name, d.name ORDER BY n DESC, t.name LIMIT 30"),
            ];
        });
    }

    /** Dashboard "recent items" panels (down sites, failed pages, failed forms, SSL, expiring). Cached and warmed by cron like the counters. */
    public static function panels(bool $refresh = false): array
    {
        $key = Cache::vkey('data', 'dashboard:panels:' . Tenant::id());
        if ($refresh) Cache::forget($key);
        return Cache::remember($key, 120, function () {
            $domWarn = (int) explode(',', (string) setting('domain_alert_days', '30,10'))[0] ?: 30;
            $hostWarn = (int) explode(',', (string) setting('hosting_alert_days', '30,10'))[0] ?: 30;
            return [
                'down' => DB::fetchAll("SELECT w.id, w.name, w.url, w.status, w.failure_reason, w.error_message, w.last_failed_at, w.last_checked_at, c.name AS client_name, c.id AS client_id,
                    (SELECT started_at FROM website_incidents i WHERE i.website_id = w.id AND i.resolved_at IS NULL ORDER BY id DESC LIMIT 1) AS down_since
                    FROM websites w STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE w.status IN (" . down_statuses_sql() . ") AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY w.last_failed_at DESC LIMIT 8"),
                'failed_pages' => DB::fetchAll("SELECT p.id, p.title, p.path, p.url, p.status, p.http_code, p.failure_reason, p.error_message, p.last_failed_at, p.last_checked_at, w.id AS website_id, w.name AS website_name, w.status AS website_status, c.name AS client_name, c.id AS client_id
                    FROM website_pages p STRAIGHT_JOIN websites w ON w.id = p.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id
                    WHERE p.is_active = 1 AND p.status IN (" . down_statuses_sql() . ") AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY p.last_failed_at DESC LIMIT 8"),
                'failed_forms' => DB::fetchAll("SELECT f.id, f.name, f.page_url, f.last_error, f.last_tested_at, f.website_id, w.name AS website_name, c.name AS client_name
                    FROM forms f STRAIGHT_JOIN websites w ON w.id = f.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE f.status = 'failed' AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY f.last_tested_at DESC LIMIT 8"),
                'ssl' => DB::fetchAll("SELECT w.id, w.name, w.ssl_status, w.ssl_days_left, w.ssl_expires_at, w.ssl_error, c.name AS client_name
                    FROM websites w STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE w.ssl_status IN ('expiring_soon','expired','error') AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY w.ssl_days_left ASC LIMIT 8"),
                'expiring' => DB::fetchAll("(SELECT 'Domain' AS kind, d.id, d.domain_name AS label, d.expiry_date, c.name AS client_name, d.website_id FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY d.expiry_date LIMIT 10)
                    UNION ALL (SELECT 'Hosting', h.id, CONCAT(h.provider, IFNULL(CONCAT(' – ', w.name), '')), h.expiry_date, c.name, h.website_id FROM hosting h JOIN clients c ON c.id = h.client_id LEFT JOIN websites w ON w.id = h.website_id WHERE h.expiry_date IS NOT NULL AND h.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . " ORDER BY h.expiry_date LIMIT 10)
                    ORDER BY expiry_date ASC LIMIT 10", [$domWarn, $hostWarn]),
                'activity' => DB::fetchAll("SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = " . Tenant::id() . " ORDER BY a.id DESC LIMIT 10"),
            ];
        });
    }

    public static function dashboard(bool $refresh = false): array
    {
        $key = Cache::vkey('data', 'dashboard:stats:' . Tenant::id());
        if ($refresh) Cache::forget($key);
        return Cache::remember($key, 120, function () {
            $staleHours = (int) setting('form_stale_hours', 48);
            $domWarn = (int) explode(',', (string) setting('domain_alert_days', '30,10'))[0] ?: 30;
            $hostWarn = (int) explode(',', (string) setting('hosting_alert_days', '30,10'))[0] ?: 30;
            $c = DB::fetch("SELECT SUM(status <> 'archived') AS total, SUM(status = 'active') AS active, SUM(status = 'inactive') AS inactive, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent FROM clients WHERE tenant_id = " . Tenant::id() . "");
            $w = DB::fetch("SELECT COUNT(*) AS total, SUM(w.status IN ('online','redirecting')) AS online, SUM(w.status IN (" . down_statuses_sql() . ")) AS down, SUM(w.ssl_status IN ('expiring_soon','expired','error')) AS ssl_issues,
                COALESCE(SUM(w.pages_total),0) AS pages_total, COALESCE(SUM(w.pages_ok),0) AS pages_ok, COALESCE(SUM(w.pages_failed),0) AS pages_failed,
                SUM(w.page_health = 'good') AS sites_good, SUM(w.page_health IN ('warning','failed')) AS sites_page_issues
                FROM websites w STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . "");
            $f = DB::fetch("SELECT SUM(f.status <> 'removed') AS total, SUM(f.status = 'working') AS working, SUM(f.status = 'failed') AS failed, SUM(f.status = 'captcha_blocked') AS blocked, SUM(f.status NOT IN ('disabled','removed') AND f.auto_test = 1 AND (f.last_tested_at IS NULL OR f.last_tested_at < DATE_SUB(NOW(), INTERVAL ? HOUR))) AS stale
                FROM forms f STRAIGHT_JOIN websites w ON w.id = f.website_id STRAIGHT_JOIN clients c ON c.id = w.client_id WHERE c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . "", [$staleHours]);
            return [
                'clients_total' => (int) $c['total'], 'clients_active' => (int) $c['active'], 'clients_inactive' => (int) $c['inactive'], 'clients_new' => (int) $c['recent'],
                'sites_total' => (int) $w['total'], 'sites_online' => (int) $w['online'], 'sites_down' => (int) $w['down'], 'ssl_issues' => (int) $w['ssl_issues'],
                'pages_total' => (int) $w['pages_total'], 'pages_ok' => (int) $w['pages_ok'], 'pages_failed' => (int) $w['pages_failed'], 'sites_good' => (int) $w['sites_good'], 'sites_page_issues' => (int) $w['sites_page_issues'],
                'domain_expiring' => (int) DB::value("SELECT COUNT(*) FROM domains d JOIN clients c ON c.id = d.client_id WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . "", [$domWarn]),
                'hosting_expiring' => (int) DB::value("SELECT COUNT(*) FROM hosting h JOIN clients c ON c.id = h.client_id WHERE h.expiry_date IS NOT NULL AND h.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND c.status <> 'archived' AND c.tenant_id = " . Tenant::id() . "", [$hostWarn]),
                'forms_total' => (int) $f['total'], 'forms_working' => (int) $f['working'], 'forms_failed' => (int) $f['failed'], 'forms_blocked' => (int) $f['blocked'], 'forms_stale' => (int) $f['stale'],
                'computed_at' => date('Y-m-d H:i:s'),
            ];
        });
    }
    /** Day labels for the last N days (oldest first) and a zero-filled map. */
    private static function dayRange(int $days): array
    {
        $labels = []; $map = [];
        for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', time() - $i * 86400); $labels[] = $d; $map[$d] = 0; }
        return [$labels, $map];
    }

    /**
     * Workspace chart series (uptime %, response time, incidents by kind, form test outcomes, monitoring volume,
     * health donuts). Aggregated from the daily rollup and incident tables – never from raw monitoring rows – so it
     * stays fast with millions of checks. Cached 5 minutes per tenant.
     */
    public static function charts(int $days = 30): array
    {
        $tid = Tenant::id();
        return Cache::remember(Cache::vkey('data', "dashboard:charts:$tid:$days"), 300, function () use ($tid, $days) {
            [$labels, $zero] = self::dayRange($days);
            $since = $labels[0];
            $upPct = $zero; $resp = $zero; $checks = $zero; $hasUp = $zero;
            foreach (DB::fetchAll("SELECT d.day, SUM(d.checks) AS c, SUM(d.up_checks) AS u, AVG(NULLIF(d.avg_response,0)) AS rt FROM website_uptime_daily d STRAIGHT_JOIN websites w ON w.id = d.website_id WHERE w.tenant_id = ? AND d.day >= ? GROUP BY d.day", [$tid, $since]) as $r) {
                $d = $r['day']; if (!isset($zero[$d])) continue;
                $checks[$d] = (int) $r['c']; $upPct[$d] = $r['c'] > 0 ? round($r['u'] / $r['c'] * 100, 2) : 0; $resp[$d] = (int) round((float) $r['rt']); $hasUp[$d] = $r['c'] > 0 ? 1 : 0;
            }
            // today is not rolled up yet – add the live rows (small: only today's checks)
            $today = date('Y-m-d');
            $t = DB::fetch("SELECT COUNT(*) AS c, SUM(m.status IN ('online','redirecting')) AS u, AVG(NULLIF(m.response_time,0)) AS rt FROM website_monitoring m STRAIGHT_JOIN websites w ON w.id = m.website_id WHERE w.tenant_id = ? AND m.checked_at >= ?", [$tid, $today . ' 00:00:00']);
            if ($t && (int) $t['c'] > 0) { $checks[$today] += (int) $t['c']; $upPct[$today] = round((int) $t['u'] / (int) $t['c'] * 100, 2); $resp[$today] = (int) round((float) $t['rt']); $hasUp[$today] = 1; }
            foreach ($labels as $d) if (!$hasUp[$d]) $upPct[$d] = null; // no data → gap, not 0 %

            $inc = ['website' => $zero, 'page' => $zero, 'form' => $zero, 'ssl' => $zero];
            foreach (['website' => 'website_incidents', 'page' => 'page_incidents', 'form' => 'form_incidents', 'ssl' => 'ssl_incidents'] as $k => $tbl) {
                foreach (DB::fetchAll("SELECT DATE(started_at) AS d, COUNT(*) AS n FROM $tbl WHERE tenant_id = ? AND started_at >= ? GROUP BY DATE(started_at)", [$tid, $since . ' 00:00:00']) as $r) if (isset($zero[$r['d']])) $inc[$k][$r['d']] = (int) $r['n'];
            }
            $tests = ['ok' => $zero, 'failed' => $zero, 'blocked' => $zero];
            foreach (DB::fetchAll("SELECT DATE(t.tested_at) AS d, SUM(t.result = 'success') AS ok, SUM(t.result = 'failed') AS failed, SUM(t.result = 'blocked') AS blocked FROM form_tests t STRAIGHT_JOIN forms f ON f.id = t.form_id WHERE f.tenant_id = ? AND t.tested_at >= ? GROUP BY DATE(t.tested_at)", [$tid, $since . ' 00:00:00']) as $r) {
                if (!isset($zero[$r['d']])) continue; $tests['ok'][$r['d']] = (int) $r['ok']; $tests['failed'][$r['d']] = (int) $r['failed']; $tests['blocked'][$r['d']] = (int) $r['blocked'];
            }
            $s = self::dashboard();
            $ssl = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0, 'error' => 0, 'none' => 0, 'unknown' => 0];
            foreach (DB::fetchAll("SELECT ssl_status, COUNT(*) AS n FROM websites WHERE tenant_id = ? GROUP BY ssl_status", [$tid]) as $r) $ssl[$r['ssl_status'] ?? 'unknown'] = (int) $r['n'];
            $pretty = array_map(fn($d) => date('d M', strtotime($d)), $labels);
            $totalChecks = array_sum($checks);
            $avail = array_filter($upPct, fn($v) => $v !== null);
            return [
                'labels' => $pretty, 'days' => $days,
                'uptime' => array_values($upPct), 'response' => array_values($resp), 'checks' => array_values($checks),
                'uptime_avg' => $avail ? round(array_sum($avail) / count($avail), 2) : null, 'response_avg' => $totalChecks ? (int) round(array_sum(array_map(fn($d) => $resp[$d] * $checks[$d], $labels)) / max(1, $totalChecks)) : null, 'checks_total' => $totalChecks,
                'incidents' => ['website' => array_values($inc['website']), 'page' => array_values($inc['page']), 'form' => array_values($inc['form']), 'ssl' => array_values($inc['ssl']), 'total' => array_sum($inc['website']) + array_sum($inc['page']) + array_sum($inc['form']) + array_sum($inc['ssl'])],
                'tests' => ['ok' => array_values($tests['ok']), 'failed' => array_values($tests['failed']), 'blocked' => array_values($tests['blocked'])],
                'website_health' => ['online' => $s['sites_online'], 'down' => $s['sites_down'], 'other' => max(0, $s['sites_total'] - $s['sites_online'] - $s['sites_down'])],
                'form_health' => ['working' => $s['forms_working'], 'failed' => $s['forms_failed'], 'blocked' => $s['forms_blocked'], 'other' => max(0, $s['forms_total'] - $s['forms_working'] - $s['forms_failed'] - $s['forms_blocked'])],
                'page_health' => ['ok' => $s['pages_ok'], 'failed' => $s['pages_failed'], 'other' => max(0, $s['pages_total'] - $s['pages_ok'] - $s['pages_failed'])],
                'ssl' => $ssl,
            ];
        });
    }

    /** Platform-wide analytics for the Super Admin console (all tenants). Cached 2 minutes. */
    public static function platform(int $days = 30): array
    {
        return Cache::remember("platform:analytics:$days", 120, function () use ($days) {
            [$labels, $zero] = self::dayRange($days);
            $since = $labels[0] . ' 00:00:00';
            $reg = $zero; $userReg = $zero; $logins = $zero; $checks = $zero; $sent = $zero; $failed = $zero; $sites = $zero;
            foreach (DB::fetchAll("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM tenants WHERE created_at >= ? GROUP BY DATE(created_at)", [$since]) as $r) if (isset($zero[$r['d']])) $reg[$r['d']] = (int) $r['n'];
            foreach (DB::fetchAll("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM users WHERE created_at >= ? GROUP BY DATE(created_at)", [$since]) as $r) if (isset($zero[$r['d']])) $userReg[$r['d']] = (int) $r['n'];
            foreach (DB::fetchAll("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM activity_logs WHERE action = 'login' AND created_at >= ? GROUP BY DATE(created_at)", [$since]) as $r) if (isset($zero[$r['d']])) $logins[$r['d']] = (int) $r['n'];
            foreach (DB::fetchAll("SELECT day AS d, SUM(checks) AS n FROM website_uptime_daily WHERE day >= ? GROUP BY day", [$labels[0]]) as $r) if (isset($zero[$r['d']])) $checks[$r['d']] = (int) $r['n'];
            foreach (DB::fetchAll("SELECT DATE(sent_at) AS d, SUM(status = 'sent') AS s, SUM(status = 'failed') AS f FROM email_logs WHERE sent_at >= ? GROUP BY DATE(sent_at)", [$since]) as $r) if (isset($zero[$r['d']])) { $sent[$r['d']] = (int) $r['s']; $failed[$r['d']] = (int) $r['f']; }
            foreach (DB::fetchAll("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM websites WHERE created_at >= ? GROUP BY DATE(created_at)", [$since]) as $r) if (isset($zero[$r['d']])) $sites[$r['d']] = (int) $r['n'];
            $byPlan = DB::fetchAll("SELECT p.name, COUNT(t.id) AS n FROM plans p LEFT JOIN tenants t ON t.plan_id = p.id GROUP BY p.id ORDER BY p.sort_order");
            $bySub = DB::fetchAll("SELECT subscription_status AS k, COUNT(*) AS n FROM tenants GROUP BY subscription_status");
            $users = DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'active' AND email_verified_at IS NOT NULL) AS active, SUM(status <> 'active') AS inactive, SUM(email_verified_at IS NULL AND status = 'active') AS unverified, SUM(last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS active_7d, SUM(last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS active_30d, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_7d, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30d FROM users");
            $byRole = DB::fetchAll("SELECT role AS k, COUNT(*) AS n FROM users WHERE status = 'active' GROUP BY role");
            $tenants = DB::fetch("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'pending') AS pending, SUM(status IN ('suspended','cancelled')) AS suspended, SUM(subscription_status = 'trial') AS trial, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_7d, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30d, SUM(last_active_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS active_7d FROM tenants");
            $paid = (int) DB::value("SELECT COUNT(*) FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.subscription_status = 'active' AND p.price_monthly > 0");
            $mrr = (int) DB::value("SELECT COALESCE(SUM(p.price_monthly),0) FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.subscription_status = 'active' AND p.price_monthly > 0");
            $vol = DB::fetch("SELECT COUNT(*) AS websites, COALESCE(SUM(pages_total),0) AS pages, COALESCE(SUM(forms_total),0) AS forms, SUM(status IN (" . down_statuses_sql() . ")) AS down FROM websites");
            $openInc = (int) DB::value("SELECT (SELECT COUNT(*) FROM website_incidents WHERE resolved_at IS NULL) + (SELECT COUNT(*) FROM page_incidents WHERE resolved_at IS NULL) + (SELECT COUNT(*) FROM form_incidents WHERE resolved_at IS NULL) + (SELECT COUNT(*) FROM ssl_incidents WHERE resolved_at IS NULL)");
            $emails24 = DB::fetch("SELECT SUM(status = 'sent') AS s, SUM(status = 'failed') AS f FROM email_logs WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $jobs = DB::fetch("SELECT SUM(status = 'failed') AS failed, SUM(locked_until > NOW()) AS running FROM scheduler_jobs");
            return [
                'labels' => array_map(fn($d) => date('d M', strtotime($d)), $labels), 'days' => $days,
                'registrations' => array_values($reg), 'user_signups' => array_values($userReg), 'logins' => array_values($logins), 'checks' => array_values($checks), 'websites_added' => array_values($sites),
                'emails' => ['sent' => array_values($sent), 'failed' => array_values($failed)],
                'by_plan' => $byPlan, 'by_subscription' => $bySub, 'by_role' => $byRole,
                'users' => array_map('intval', $users ?: []), 'tenants' => array_map('intval', $tenants ?: []), 'paid' => $paid, 'mrr' => $mrr,
                'volume' => array_map('intval', $vol ?: []), 'open_incidents' => $openInc, 'checks_total' => array_sum($checks),
                'emails_24h' => ['sent' => (int) ($emails24['s'] ?? 0), 'failed' => (int) ($emails24['f'] ?? 0)],
                'queue' => (int) DB::value("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'"),
                'jobs' => ['failed' => (int) ($jobs['failed'] ?? 0), 'running' => (int) ($jobs['running'] ?? 0)],
                'computed_at' => date('Y-m-d H:i:s'),
            ];
        });
    }
}
