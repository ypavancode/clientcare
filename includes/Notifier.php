<?php
/**
 * Central notification system: in-app notifications + email alerts (queued for cron delivery through Mailer).
 * Every alert email is de-duplicated through the alert_log table so a continuing problem never
 * produces repeated emails – one email when it starts, one when it recovers.
 *
 * Priority mapping (notification "type"):
 *   critical – website down, page error, form failed, SSL expired/error, domain/hosting expired, system error
 *   warning  – domain / hosting / SSL expiring soon
 *   recovery – website recovered, page recovered, form recovered
 *   info     – everything else
 *
 * Recipients of every important alert: admin notification address(es) + assigned staff + the client (each as To).
 * The From address is always the configured Client Care sender – client addresses are only recipients.
 */
class Notifier
{
    /* ---------- In-app notifications ---------- */

    public static function create(string $type, string $category, string $title, string $message, array $opts = []): int
    {
        $tenantId = array_key_exists('tenant_id', $opts) ? $opts['tenant_id'] : (Tenant::id() ?: null);
        if (!$tenantId && !empty($opts['website_id'])) $tenantId = DB::value("SELECT tenant_id FROM websites WHERE id = ?", [$opts['website_id']]) ?: null;
        if (!$tenantId && !empty($opts['client_id'])) $tenantId = DB::value("SELECT tenant_id FROM clients WHERE id = ?", [$opts['client_id']]) ?: null;
        if ($tenantId && class_exists('Webhooks')) Webhooks::onNotification((int) $tenantId, $category, $type, $title, $message, $opts);
        return DB::insert('notifications', [
            'tenant_id'  => $tenantId,
            'type'       => $type,
            'category'   => $category,
            'title'      => mb_substr($title, 0, 250),
            'message'    => mb_substr($message, 0, 2000),
            'client_id'  => $opts['client_id'] ?? null,
            'website_id' => $opts['website_id'] ?? null,
            'form_id'    => $opts['form_id'] ?? null,
            'link'       => $opts['link'] ?? null,
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------- Recipients ---------- */

    public static ?int $tenantId = null;

    public static function adminRecipients(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? self::$tenantId ?? (Tenant::id() ?: null);
        if ($tenantId) {
            // Workspace recipients: owner + admins + notify-only members + extra alert emails configured for the workspace
            $list = [];
            foreach (DB::fetchAll("SELECT name, email FROM users WHERE tenant_id = ? AND status = 'active' AND role IN ('owner','admin','notify')", [$tenantId]) as $u) if (valid_email($u['email'])) $list[strtolower($u['email'])] = ['email' => $u['email'], 'name' => $u['name']];
            $extra = (string) (Tenant::current($tenantId)['alert_emails'] ?? '') . ',' . (string) Tenant::ownSetting('notification_email', $tenantId, '');
            foreach (preg_split('~[,;\\s]+~', $extra, -1, PREG_SPLIT_NO_EMPTY) as $em) if (valid_email($em)) $list[strtolower($em)] = ['email' => $em, 'name' => Tenant::name($tenantId)];
            return array_values($list);
        }
        $list = [];
        $configured = setting('notification_email');
        if ($configured) {
            foreach (preg_split('~[,;\s]+~', $configured) as $em) {
                if (valid_email($em)) $list[strtolower($em)] = ['email' => $em, 'name' => company_name()];
            }
        }
        if (!$list) {
            foreach (DB::fetchAll("SELECT name, email FROM users WHERE role = 'admin' AND status = 'active'") as $u) {
                if (valid_email($u['email'])) $list[strtolower($u['email'])] = ['email' => $u['email'], 'name' => $u['name']];
            }
        }
        return array_values($list);
    }

    /** Platform administrators (system errors, cron warnings, sales enquiries) – never tenant users. */
    public static function platformAdminRecipients(): array
    {
        $list = [];
        $configured = setting('notification_email');
        if ($configured) foreach (preg_split('~[,;\s]+~', $configured) as $em) if (valid_email($em)) $list[strtolower($em)] = ['email' => $em, 'name' => setting('platform_name', 'Outline Monitor')];
        foreach (DB::fetchAll("SELECT name, email FROM users WHERE is_platform_admin = 1 AND status = 'active'") as $u) if (valid_email($u['email'])) $list[strtolower($u['email'])] = ['email' => $u['email'], 'name' => $u['name']];
        return array_values($list);
    }

    public static function staffRecipient(?int $userId): ?array
    {
        if (!$userId) return null;
        $u = DB::fetch("SELECT name, email FROM users WHERE id = ? AND status = 'active'", [$userId]);
        return ($u && valid_email($u['email'])) ? ['email' => $u['email'], 'name' => $u['name']] : null;
    }

    /** Client contact for alerts – enabled globally (Settings → Email) and per client (default on). */
    public static function clientRecipientById(?int $clientId): ?array
    {
        if (!$clientId || !setting('notify_clients', 1)) return null;
        $c = DB::fetch("SELECT name, email, notify_client FROM clients WHERE id = ?", [$clientId]);
        if ($c && $c['notify_client'] && valid_email($c['email'])) return ['email' => $c['email'], 'name' => $c['name']];
        return null;
    }

    private static function clientRecipient(array $website): ?array
    {
        return self::clientRecipientById((int) $website['client_id']);
    }

    /**
     * Queue an alert email to admins + extra recipients (assigned staff, client). De-duplicated by $alertKey.
     */
    public static function email(string $alertKey, string $subject, string $html, array $extraRecipients = [], string $category = 'alert', ?int $refId = null): bool
    {
        if (!self::shouldSend($alertKey)) return false;
        $recipients = self::adminRecipients();
        foreach ($extraRecipients as $r) {
            if ($r && valid_email($r['email'] ?? '')) $recipients[] = $r;
        }
        $seen = [];
        // One SMTP session for every recipient of this alert; each message is delivered immediately (queue row = retry record)
        Mailer::collect(function () use ($recipients, &$seen, $subject, $html, $category, $refId) {
            foreach ($recipients as $r) {
                $key = strtolower($r['email']);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                Mailer::queue($r['email'], $subject, $html, $r['name'] ?? null, $category, $refId);
            }
        });
        self::markSent($alertKey);
        return true;
    }

    private static function shouldSend(string $key): bool
    {
        return DB::value("SELECT COUNT(*) FROM alert_log WHERE alert_key = ?", [$key]) == 0;
    }

    private static function markSent(string $key): void
    {
        DB::query("INSERT IGNORE INTO alert_log (alert_key, tenant_id, sent_at) VALUES (?, ?, ?)", [$key, self::$tenantId ?: (Tenant::id() ?: null), date('Y-m-d H:i:s')]);
        // alert history: record that the failure / recovery notification went out
        try {
            DB::query("UPDATE alerts SET notified_at = IFNULL(notified_at, NOW()), notification_count = notification_count + 1 WHERE alert_key = ?", [$key]);
            DB::query("UPDATE alerts SET recovery_notified_at = IFNULL(recovery_notified_at, NOW()) WHERE recovery_key = ?", [$key]);
        } catch (Throwable $ignored) {}
    }

    /* ---------- Alert state machine (v3.9): working → failed (one notification) → still failing (silent) → recovered (one notification) ---------- */

    /**
     * Open the alert for a problem, or refresh it when it is already open (repeated failing checks never create a new
     * row or a new notification). $a: kind, tenant_id, client_id, website_id, target_id, incident_id, title, target_label,
     * error_type, error_message, previous_status, current_status, detected_at, severity, link, status (open | info).
     */
    public static function alertOpen(string $key, array $a): int
    {
        try {
            $row = DB::fetch("SELECT id, status FROM alerts WHERE alert_key = ?", [$key]);
            if ($row) {
                DB::query("UPDATE alerts SET last_seen_at = NOW(), checks_while_failing = checks_while_failing + 1, error_type = IFNULL(?, error_type), error_message = IFNULL(?, error_message), current_status = IFNULL(?, current_status) WHERE id = ?", [$a['error_type'] ?? null, isset($a['error_message']) ? mb_substr((string) $a['error_message'], 0, 1000) : null, $a['current_status'] ?? null, $row['id']]);
                return (int) $row['id'];
            }
            $tenantId = $a['tenant_id'] ?? (self::$tenantId ?: (Tenant::id() ?: null));
            if (!$tenantId && !empty($a['website_id'])) $tenantId = DB::value("SELECT tenant_id FROM websites WHERE id = ?", [$a['website_id']]) ?: null;
            return DB::insert('alerts', [
                'tenant_id' => $tenantId, 'client_id' => $a['client_id'] ?? null, 'website_id' => $a['website_id'] ?? null, 'kind' => $a['kind'], 'target_id' => $a['target_id'] ?? null, 'incident_id' => $a['incident_id'] ?? null,
                'alert_key' => $key, 'status' => $a['status'] ?? 'open', 'severity' => $a['severity'] ?? 'critical', 'title' => mb_substr((string) ($a['title'] ?? 'Alert'), 0, 250), 'target_label' => isset($a['target_label']) ? mb_substr((string) $a['target_label'], 0, 250) : null,
                'error_type' => isset($a['error_type']) ? mb_substr((string) $a['error_type'], 0, 120) : null, 'error_message' => isset($a['error_message']) ? mb_substr((string) $a['error_message'], 0, 1000) : null,
                'previous_status' => $a['previous_status'] ?? null, 'current_status' => $a['current_status'] ?? null, 'detected_at' => $a['detected_at'] ?? date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s'),
                'link' => $a['link'] ?? null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            app_log('warning', 'alertOpen failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Close the open alert(s) $keys (failure keys) as recovered. $recoveryKey = the key of the recovery notification
     * (so its delivery is recorded on the same row). $a: recovered_at, downtime_seconds, current_status, checks.
     */
    public static function alertRecover($keys, ?string $recoveryKey, array $a = []): void
    {
        foreach ((array) $keys as $key) {
            try {
                DB::query("UPDATE alerts SET status = 'recovered', recovered_at = ?, recovery_key = ?, downtime_seconds = ?, current_status = IFNULL(?, current_status), checks_while_failing = GREATEST(checks_while_failing, ?), last_seen_at = NOW() WHERE alert_key = ? AND status <> 'recovered'",
                    [$a['recovered_at'] ?? date('Y-m-d H:i:s'), $recoveryKey, $a['downtime_seconds'] ?? null, $a['current_status'] ?? null, (int) ($a['checks'] ?? 0), $key]);
            } catch (Throwable $e) { app_log('warning', 'alertRecover failed: ' . $e->getMessage()); }
        }
    }

    /** The problem was seen again by a check (incident continues): count it, stay silent. */
    public static function alertSeen(string $key, ?string $errorType = null, ?string $errorMessage = null): void
    {
        try { DB::query("UPDATE alerts SET checks_while_failing = checks_while_failing + 1, last_seen_at = NOW(), error_type = IFNULL(?, error_type), error_message = IFNULL(?, error_message) WHERE alert_key = ? AND status = 'open'", [$errorType, $errorMessage !== null ? mb_substr($errorMessage, 0, 1000) : null, $key]); } catch (Throwable $ignored) {}
    }

    /** Close every open alert of a kind (optionally one target) – used for SMTP / system alerts without incident ids. */
    public static function alertRecoverKind(string $kind, ?int $targetId, ?string $recoveryKey, array $a = []): int
    {
        try {
            $rows = DB::fetchAll("SELECT alert_key FROM alerts WHERE kind = ? AND status = 'open'" . ($targetId !== null ? ' AND target_id = ?' : ''), $targetId !== null ? [$kind, $targetId] : [$kind]);
            self::alertRecover(array_column($rows, 'alert_key'), $recoveryKey, $a);
            return count($rows);
        } catch (Throwable $e) { return 0; }
    }

    public static function alertSentAt(string $key): ?string
    {
        $v = DB::value("SELECT sent_at FROM alert_log WHERE alert_key = ?", [$key]);
        return $v ?: null;
    }

    /** All sent alerts whose key starts with the prefix (used to show "10-day notification: SENT"). */
    public static function sentAlerts(string $keyPrefix): array
    {
        return DB::fetchAll("SELECT alert_key, sent_at FROM alert_log WHERE alert_key LIKE ? ORDER BY sent_at", [$keyPrefix . '%']);
    }

    private static function websiteContext(array $website): array
    {
        self::$tenantId = !empty($website['tenant_id']) ? (int) $website['tenant_id'] : null;
        Mailer::$tenantId = self::$tenantId;
        $client = DB::fetch("SELECT id, name, company, assigned_user_id FROM clients WHERE id = ?", [$website['client_id']]);
        return [$client, $client ? self::staffRecipient($client['assigned_user_id'] ? (int) $client['assigned_user_id'] : null) : null];
    }

    /* ---------- Website down / recovered ---------- */

    private static function downLabels(string $status): array
    {
        $labels = [
            'parked'        => ['Website Not Served (Parking Page)', 'PARKED / PLACEHOLDER', 'The domain is showing a parking or placeholder page instead of the real website. Check DNS / nameservers, hosting status and domain renewal.'],
            'content_error' => ['Website Content Missing', 'CONTENT MISSING', 'The page loaded but the expected content was not found. The site may be showing an error, a default page or the wrong website.'],
            'ssl_error'     => ['Website SSL Error', 'DOWN (SSL ERROR)', 'The website could not be loaded securely. Check the SSL certificate installation and expiry, and the hosting configuration.'],
            'timeout'       => ['Website Timeout', 'DOWN (TIMEOUT)', 'The website did not respond in time. Check the hosting server load, database and application performance.'],
            'server_error'  => ['Website Server Error', 'DOWN (SERVER ERROR)', 'The server returned an error response. Check the application and server error logs (PHP errors, database connection, .htaccess).'],
        ];
        return $labels[$status] ?? ['Website Down', 'DOWN', 'Please check the hosting/server immediately (web server running, DNS records, firewall, domain and hosting renewal).'];
    }

    private static function reasonAdvice(?string $reason): ?string
    {
        $map = [
            'DNS Resolution Failed' => 'Check that the domain is registered and not expired, and that the nameservers / A records point to the hosting server.',
            'Connection Refused'    => 'The hosting server rejected the connection: the web server may be stopped, the port blocked by a firewall, or the account suspended.',
            'Connection Timeout'    => 'The server is not answering. Check server load, hosting status page, and whether the IP is blocked.',
            'Service Unavailable'   => 'HTTP 503 usually means the application is in maintenance mode, overloaded, or the backend (PHP/DB) is down.',
            'Page Not Found'        => 'HTTP 404 on the homepage usually means files were removed, the document root changed, or a broken redirect/.htaccess.',
        ];
        return $map[$reason] ?? null;
    }

    public static function websiteDown(array $website, array $incident, array $probe = []): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id']);
        $status = $incident['status'] ?? $website['status'] ?? 'down';
        $reason = $incident['failure_reason'] ?? $website['failure_reason'] ?? $probe['reason'] ?? 'Connection Failed';
        $details = $incident['error_message'] ?: ('HTTP ' . ($incident['status_code'] ?: 'no response'));
        [$title, $statusLabel, $action] = self::downLabels($status);
        if ($advice = self::reasonAdvice($reason)) $action = $advice;

        self::create('critical', 'website_down', $title . ': ' . $website['name'],
            $website['url'] . ' – ' . $statusLabel . '. Reason: ' . $reason . '. ' . $details,
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id']]);
        ActivityLog::add('website_down', $title . ': ' . $website['name'] . ' (' . $reason . ' – ' . $details . ')', ['client_id' => $website['client_id'], 'website_id' => $website['id']]);

        [$subject, $html] = Mailer::render('website_down', [
            'client_name'           => $clientName,
            'website_name'          => $website['name'],
            'website_url'           => $website['url'],
            'status'                => $statusLabel,
            'error_reason'          => $reason,
            'error_details'         => $details,
            'http_status'           => $incident['status_code'] ? $incident['status_code'] . ' ' . Monitor::httpText((int) $incident['status_code']) : 'No Response',
            'response_time'         => isset($probe['response_ms']) && $probe['response_ms'] !== null ? $probe['response_ms'] . ' ms' : 'n/a',
            'detected_at'           => format_datetime($incident['started_at']),
            'last_successful_check' => $website['last_success_at'] ? format_datetime($website['last_success_at']) : 'Never',
            'failed_checks'         => (string) (int) ($incident['failed_checks'] ?? 1),
            'recommended_action'    => $action,
        ], $link, '#dc3545');
        self::alertOpen('website_down:' . $incident['id'], ['kind' => 'website', 'tenant_id' => $website['tenant_id'] ?? null, 'client_id' => $website['client_id'], 'website_id' => $website['id'], 'incident_id' => $incident['id'], 'title' => $title . ': ' . $website['name'], 'target_label' => $website['url'],
            'error_type' => $reason, 'error_message' => $details, 'previous_status' => $website['previous_status'] ?? 'online', 'current_status' => $website['status'] ?? 'down', 'detected_at' => $incident['started_at'], 'link' => 'websites/view.php?id=' . $website['id']]);
        self::email('website_down:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'website_down', (int) $website['id']);
    }

    public static function websiteRecovered(array $website, array $incident): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id']);
        $duration = duration_human((int) $incident['duration_seconds']);

        self::create('recovery', 'website_recovered', 'Website Recovered: ' . $website['name'],
            $website['url'] . ' is back online after ' . $duration . ' of downtime.', ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id']]);
        ActivityLog::add('website_recovered', 'Website recovered: ' . $website['name'] . ' (downtime ' . $duration . ')', ['client_id' => $website['client_id'], 'website_id' => $website['id']]);

        [$subject, $html] = Mailer::render('website_recovered', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'status'         => 'RECOVERED',
            'down_since'     => format_datetime($incident['started_at']),
            'recovered_at'   => format_datetime($incident['resolved_at']),
            'total_downtime' => $duration,
            'failed_checks'  => (string) (int) ($incident['failed_checks'] ?? 1),
            'error_reason'   => trim(($incident['failure_reason'] ?? '') . ($incident['error_message'] ? ' – ' . $incident['error_message'] : '')) ?: 'n/a',
        ], $link, '#198754');
        self::alertRecover('website_down:' . $incident['id'], 'website_recovered:' . $incident['id'], ['recovered_at' => $incident['resolved_at'], 'downtime_seconds' => (int) $incident['duration_seconds'], 'current_status' => 'online', 'checks' => (int) ($incident['failed_checks'] ?? 1)]);
        self::email('website_recovered:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'website_recovered', (int) $website['id']);
    }

    /* ---------- SSL ---------- */

    /** Expiry warning – once per configured threshold (30 / 10 days) per certificate. */
    public static function sslAlert(array $website, string $status, ?int $days, ?string $expiry, ?string $error = null): void
    {
        if ($status !== 'expiring_soon' || $days === null) return;
        $thresholds = Monitor::thresholds((string) (!empty($website['tenant_id']) ? Tenant::setting('ssl_alert_days', '30,10', (int) $website['tenant_id']) : setting('ssl_alert_days', '30,10')));
        $threshold = Monitor::crossedThreshold($days, $thresholds);
        if ($threshold === null) return;
        $key = 'ssl_expiring:' . $website['id'] . ':' . $threshold . ':' . $expiry;
        if (!self::shouldSend($key)) return;
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id']);
        $title = 'SSL Expiring in ' . $days . ' days: ' . $website['name'];
        $msg = 'The SSL certificate for ' . $website['url'] . ' expires on ' . format_date($expiry) . ' (' . $days . ' days remaining).';
        self::create('warning', 'ssl', $title, $msg, ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id']]);
        ActivityLog::add('ssl_warning', $title, ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        [$subject, $html] = Mailer::render('ssl_expiry', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'status'         => 'Expiring in ' . $days . ' days',
            'expiry_date'    => $expiry ? format_date($expiry) : 'n/a',
            'days_remaining' => $days . ' Days',
            'ssl_issuer'     => $website['ssl_issuer'] ?? 'n/a',
            'error_details'  => $error ?: 'n/a',
        ], $link, '#fd7e14');
        self::email($key, $subject, $html, [$staff, self::clientRecipient($website)], 'ssl', (int) $website['id']);
    }

    private static function sslChecksText(array $result): string
    {
        $labels = ['connection' => 'TLS connection', 'chain' => 'Certificate chain trusted', 'hostname' => 'Hostname matches', 'validity' => 'Certificate valid', 'expiry' => 'Not expiring soon'];
        $out = [];
        foreach ($labels as $k => $l) {
            $v = $result['checks'][$k] ?? null;
            $out[] = $l . ': ' . ($v === null ? 'not checked' : ($v ? 'OK' : 'FAILED'));
        }
        return implode("\n", $out);
    }

    /** VALID → FAILED: one email per SSL incident (expired, untrusted chain, hostname mismatch, TLS connection failure). */
    public static function sslFailed(array $website, array $incident, array $result): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id']);
        $statusText = $result['status'] === 'expired' ? 'EXPIRED' : 'FAILED';
        $title = ($result['status'] === 'expired' ? 'SSL Certificate Expired: ' : 'SSL Certificate Failed: ') . $website['name'];
        $error = $result['error'] ?: 'SSL check failed';
        self::create('critical', 'ssl', $title, $website['url'] . ' – ' . $error, ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id']]);
        ActivityLog::add($result['status'] === 'expired' ? 'ssl_expired' : 'ssl_failed', $title . ' (' . $error . ')', ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        [$subject, $html] = Mailer::render('ssl_failed', [
            'client_name'           => $clientName,
            'website_name'          => $website['name'],
            'website_url'           => $website['url'],
            'domain'                => host_from_url($website['url']),
            'status'                => $statusText,
            'error_reason'          => $error,
            'checks'                => self::sslChecksText($result),
            'expiry_date'           => $result['expiry'] ? format_datetime($result['expiry']) : 'n/a',
            'days_remaining'        => $result['days'] !== null ? ($result['days'] < 0 ? 'Expired ' . abs($result['days']) . ' days ago' : $result['days'] . ' Days') : 'n/a',
            'ssl_issuer'            => $result['issuer'] ?: 'n/a',
            'detected_at'           => format_datetime($incident['started_at']),
            'last_successful_check' => !empty($website['ssl_last_valid_at']) ? format_datetime($website['ssl_last_valid_at']) : 'Never',
        ], $link, '#dc3545');
        self::alertOpen('ssl_failed:' . $incident['id'], ['kind' => 'ssl', 'tenant_id' => $website['tenant_id'] ?? null, 'client_id' => $website['client_id'], 'website_id' => $website['id'], 'incident_id' => $incident['id'], 'title' => $title, 'target_label' => host_from_url($website['url']),
            'error_type' => $result['status'] === 'expired' ? 'Certificate expired' : 'Certificate invalid', 'error_message' => $error, 'previous_status' => 'valid', 'current_status' => $result['status'], 'detected_at' => $incident['started_at'], 'link' => 'websites/view.php?id=' . $website['id']]);
        self::email('ssl_failed:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'ssl_failed', (int) $website['id']);
    }

    /** FAILED → VALID: one recovery email per SSL incident with the downtime. */
    public static function sslRecovered(array $website, array $incident, array $result): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id']);
        $duration = duration_human((int) $incident['duration_seconds']);
        self::create('recovery', 'ssl', 'SSL Certificate Recovered: ' . $website['name'], $website['url'] . ' – the SSL certificate is valid again after ' . $duration . '.', ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id']]);
        ActivityLog::add('ssl_recovered', 'SSL recovered: ' . $website['name'] . ' (failed for ' . $duration . ')', ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        [$subject, $html] = Mailer::render('ssl_recovered', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'domain'         => host_from_url($website['url']),
            'status'         => 'RECOVERED',
            'failed_since'   => format_datetime($incident['started_at']),
            'recovered_at'   => format_datetime($incident['resolved_at']),
            'total_downtime' => $duration,
            'error_reason'   => $incident['error_message'] ?: 'n/a',
            'expiry_date'    => $result['expiry'] ? format_datetime($result['expiry']) : 'n/a',
            'days_remaining' => $result['days'] !== null ? $result['days'] . ' Days' : 'n/a',
            'ssl_issuer'     => $result['issuer'] ?: 'n/a',
        ], $link, '#198754');
        self::alertRecover('ssl_failed:' . $incident['id'], 'ssl_recovered:' . $incident['id'], ['recovered_at' => $incident['resolved_at'], 'downtime_seconds' => (int) $incident['duration_seconds'], 'current_status' => 'valid', 'checks' => (int) ($incident['failed_checks'] ?? 1)]);
        self::email('ssl_recovered:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'ssl_recovered', (int) $website['id']);
    }

    /* ---------- Domain / hosting expiry ---------- */

    /**
     * $kind: domain | hosting. Returns true when a new alert was raised (once per threshold per expiry date).
     */
    public static function expiryAlert(string $kind, array $row, int $days): bool
    {
        self::$tenantId = !empty($row['tenant_id']) ? (int) $row['tenant_id'] : null;
        Mailer::$tenantId = self::$tenantId;
        $label = $kind === 'domain' ? 'Domain' : 'Hosting';
        $expired = $days < 0;
        $key = $kind . '_expiry:' . $row['id'] . ':' . $row['expiry_date'] . ':' . ($expired ? 'expired' : (string) $row['threshold']);
        if (!self::shouldSend($key)) return false;

        $clientName = $row['client_name'] ?? '—';
        $title = $expired ? $label . ' Expired: ' . $row['label'] : $label . ' Expiry Warning: ' . $row['label'];
        $msg = $label . ' "' . $row['label'] . '" ' . ($expired ? 'expired on ' . format_date($row['expiry_date']) . ' (' . abs($days) . ' days ago)' : 'expires on ' . format_date($row['expiry_date']) . ' – ' . $days . ' days remaining') . '.';
        $page = $kind === 'domain' ? 'domains/index.php' : 'hosting/index.php';
        self::create($expired ? 'critical' : 'warning', $kind . '_expiry', $title, $msg, ['client_id' => $row['client_id'], 'website_id' => $row['website_id'], 'link' => $page . '?highlight=' . $row['id']]);
        ActivityLog::add($kind . '_expiring', $title, ['client_id' => $row['client_id'], 'website_id' => $row['website_id']]);

        $staff = self::staffRecipient(!empty($row['assigned_user_id']) ? (int) $row['assigned_user_id'] : null);
        $clientRcpt = null;
        if (setting('notify_clients', 1) && !empty($row['notify_client']) && valid_email($row['client_email'] ?? '')) {
            $clientRcpt = ['email' => $row['client_email'], 'name' => $clientName];
        }
        $daysText = $expired ? 'EXPIRED ' . abs($days) . ' days ago' : $days . ' Days';
        [$subject, $html] = Mailer::render($kind . '_expiry', [
            'client_name'    => $clientName,
            'service_type'   => $label,
            'service_name'   => $row['label'],
            'domain_name'    => $kind === 'domain' ? $row['label'] : ($row['website_name'] ?? $row['label']),
            'provider'       => $row['provider'] ?? 'n/a',
            'plan'           => $row['plan'] ?? 'n/a',
            'website_name'   => $row['website_name'] ?? 'n/a',
            'expiry_date'    => format_date($row['expiry_date']),
            'days_remaining' => $daysText,
            'auto_renew'     => isset($row['auto_renew']) ? ($row['auto_renew'] ? 'Enabled' : 'Not enabled') : 'n/a',
        ], url($page . '?highlight=' . $row['id']), $expired ? '#dc3545' : '#fd7e14');
        if ($expired) $subject = $label . ' EXPIRED – ' . ($kind === 'domain' ? $row['label'] : $clientName);
        // alert history: a renewed expiry date closes the earlier alerts of this domain / hosting
        try {
            $renewed = DB::fetchAll("SELECT alert_key FROM alerts WHERE kind = ? AND target_id = ? AND status <> 'recovered' AND alert_key NOT LIKE ?", [$kind, (int) $row['id'], $kind . '_expiry:' . $row['id'] . ':' . $row['expiry_date'] . ':%']);
            if ($renewed) self::alertRecover(array_column($renewed, 'alert_key'), null, ['current_status' => 'renewed']);
        } catch (Throwable $ignored) {}
        self::alertOpen($key, ['kind' => $kind, 'tenant_id' => $row['tenant_id'] ?? null, 'client_id' => $row['client_id'] ?? null, 'website_id' => $row['website_id'] ?? null, 'target_id' => (int) $row['id'], 'title' => $title, 'target_label' => $row['label'],
            'error_type' => $expired ? 'Expired' : 'Expires in ' . $days . ' days', 'error_message' => $msg, 'previous_status' => 'valid', 'current_status' => $expired ? 'expired' : 'expiring', 'severity' => $expired ? 'critical' : 'warning', 'status' => $expired ? 'open' : 'info', 'link' => $page . '?highlight=' . $row['id']]);
        self::email($key, $subject, $html, [$staff, $clientRcpt], $kind . '_expiry', (int) $row['id']);
        return true;
    }

    /* ---------- Forms ---------- */

    public static function formKindLabel(array $form): string
    {
        $kind = ['normal' => 'Normal Form', 'popup' => 'Popup Form', 'ajax' => 'AJAX Form', 'wordpress' => 'WordPress Form'][$form['form_kind'] ?? 'normal'] ?? 'Form';
        if (!empty($form['wp_plugin'])) $kind .= ' (' . FormTester::pluginLabel($form['wp_plugin']) . ')';
        return $kind;
    }

    private static function popupInfo(array $form): string
    {
        if (($form['form_kind'] ?? '') !== 'popup') return 'n/a';
        $parts = [];
        if (!empty($form['popup_trigger'])) $parts[] = 'Trigger: ' . $form['popup_trigger'];
        if (!empty($form['popup_selector'])) $parts[] = 'Popup: ' . $form['popup_selector'];
        if (!empty($form['form_selector'])) $parts[] = 'Form: ' . $form['form_selector'];
        return $parts ? implode("\n", $parts) : 'Popup form';
    }

    private static function ajaxResponseText(array $test): string
    {
        $parts = [];
        if (!empty($test['ajax_status'])) $parts[] = 'AJAX response: HTTP ' . $test['ajax_status'];
        elseif (!empty($test['http_code'])) $parts[] = 'HTTP ' . $test['http_code'];
        else $parts[] = 'No HTTP response';
        if (!empty($test['response_text'])) $parts[] = truncate($test['response_text'], 220);
        return implode("\n", $parts);
    }

    /** WORKING → FAILED: one email per form incident (admin + client). */
    public static function formFailed(array $form, array $website, array $test, array $incident): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']);
        $reason = $test['failure_reason'] ?: 'Test failed';
        $title = 'Form Failed: ' . $form['name'] . ' – ' . $website['name'];
        self::create('critical', 'form_failed', $title, 'Reason: ' . $reason . '. ' . ($test['error'] ?: '') . ' (' . $form['page_url'] . ')',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id'], 'link' => 'forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']]);
        ActivityLog::add('form_failed', 'Form failed: ' . $form['name'] . ' on ' . $website['name'] . ' – ' . $reason, ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);
        [$subject, $html] = Mailer::render('form_failed', [
            'client_name'           => $clientName,
            'website_name'          => $website['name'],
            'website_url'           => $website['url'],
            'form_name'             => $form['name'],
            'form_type'             => self::formKindLabel($form) . ' · ' . ($form['form_type'] ?? ''),
            'form_technology'       => $form['technology'] ?: ($form['wp_plugin'] ? FormTester::pluginLabel($form['wp_plugin']) : 'Not detected'),
            'page_url'              => $form['page_url'],
            'form_url'              => $form['form_url'] ?: $form['page_url'],
            'popup_info'            => self::popupInfo($form),
            'status'                => strtoupper(form_outcome_label($test['outcome'] ?? 'failed')),
            'error_reason'          => $reason,
            'error_details'         => $test['error'] ?: 'n/a',
            'ajax_response'         => self::ajaxResponseText($test),
            'http_status'           => $test['http_code'] ? (string) $test['http_code'] . ' ' . Monitor::httpText((int) $test['http_code']) : 'No Response',
            'response_time'         => $test['response_time'] !== null ? $test['response_time'] . ' ms' : 'n/a',
            'engine'                => ($test['engine'] ?? 'http') === 'browser' ? 'Headless browser (real click + submit)' : 'HTTP submission',
            'detected_at'           => format_datetime($incident['started_at']),
            'last_successful_check' => $form['last_success_at'] ? format_datetime($form['last_success_at']) : 'Never',
        ], $link, '#dc3545');
        self::alertOpen('form_failed:' . $incident['id'], ['kind' => 'form', 'tenant_id' => $website['tenant_id'] ?? null, 'client_id' => $website['client_id'], 'website_id' => $website['id'], 'target_id' => $form['id'], 'incident_id' => $incident['id'], 'title' => $title, 'target_label' => $form['name'] . ' · ' . $form['page_url'],
            'error_type' => $reason, 'error_message' => $test['error'] ?: null, 'previous_status' => $test['previous_status'] ?? 'working', 'current_status' => 'failed', 'detected_at' => $incident['started_at'], 'link' => 'forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']]);
        self::email('form_failed:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'form_failed', (int) $form['id']);
    }

    /** FAILED → WORKING: one recovery email per form incident with failed-since / recovered-at / total downtime. */
    public static function formRecovered(array $form, array $website, array $test, array $incident): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $duration = duration_human((int) $incident['duration_seconds']);
        $title = 'Form Recovered: ' . $form['name'] . ' – ' . $website['name'];
        self::create('recovery', 'form_recovered', $title, 'The form is working again after ' . $duration . '.',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id'], 'link' => 'forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']]);
        ActivityLog::add('form_recovered', 'Form recovered: ' . $form['name'] . ' on ' . $website['name'] . ' (failed for ' . $duration . ')', ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);
        [$subject, $html] = Mailer::render('form_recovered', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'form_name'      => $form['name'],
            'form_type'      => self::formKindLabel($form) . ' · ' . ($form['form_type'] ?? ''),
            'page_url'       => $form['page_url'],
            'form_url'       => $form['form_url'] ?: $form['page_url'],
            'status'         => 'RECOVERED',
            'failed_since'   => format_datetime($incident['started_at']),
            'recovered_at'   => format_datetime($incident['resolved_at']),
            'total_downtime' => $duration,
            'failed_tests'   => (string) (int) ($incident['failed_tests'] ?? 1),
            'error_reason'   => trim(($incident['failure_reason'] ?? '') . ($incident['error_message'] ? ' – ' . $incident['error_message'] : '')) ?: 'n/a',
            'detected_at'    => format_datetime($test['tested_at']),
            'http_status'    => (string) ($test['http_code'] ?: 'n/a'),
        ], url('forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']), '#198754');
        self::alertRecover('form_failed:' . $incident['id'], 'form_recovered:' . $incident['id'], ['recovered_at' => $incident['resolved_at'], 'downtime_seconds' => (int) $incident['duration_seconds'], 'current_status' => 'working', 'checks' => (int) ($incident['failed_tests'] ?? 1)]);
        self::email('form_recovered:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'form_recovered', (int) $form['id']);
    }

    /**
     * Form test blocked by CAPTCHA / anti-bot / third-party widget: a separate monitoring status. In-app notice for the
     * team only – NO failure alert email, because the form itself is not known to be broken.
     */
    public static function formBlocked(array $form, array $website, array $test): void
    {
        $outcome = $test['outcome'] ?? 'captcha_blocked';
        $what = $outcome === 'interference' ? 'a third-party widget' : 'CAPTCHA / anti-bot verification';
        $title = ($outcome === 'interference' ? 'Form Test Blocked by Widget: ' : 'Form Requires CAPTCHA: ') . $form['name'] . ' – ' . $website['name'];
        self::create('info', 'form_blocked', $title,
            'The automated test could not submit the form because ' . $what . ' requires human interaction. This is not a form failure and no client alert was sent. '
            . 'Action: verify the form manually, or configure an owner-approved test method (test page / test parameter) under Form → Automated Testing → CAPTCHA / Anti-Bot Protection. ' . ($test['error'] ?: ''),
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id'], 'link' => 'forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']]);
        ActivityLog::add('form_blocked', 'Form test blocked (' . form_outcome_label($outcome) . '): ' . $form['name'] . ' on ' . $website['name'] . ' – ' . ($test['failure_reason'] ?: ''), ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);
    }

    /** Monitoring configuration problem (selector not found, browser engine missing…): in-app notice for the team, no client email. */
    public static function formConfigError(array $form, array $website, array $test): void
    {
        $title = 'Form Test Configuration Error: ' . $form['name'] . ' – ' . $website['name'];
        self::create('warning', 'form_config', $title, 'The automated test could not run because the form monitoring configuration is incorrect: ' . ($test['failure_reason'] ?: 'configuration error') . '. ' . ($test['error'] ?: '') . ' No client alert was sent.',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id'], 'link' => 'forms/index.php?website_id=' . $website['id'] . '&highlight=' . $form['id']]);
        ActivityLog::add('form_config_error', 'Form test configuration error: ' . $form['name'] . ' on ' . $website['name'] . ' – ' . ($test['failure_reason'] ?: ''), ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);
    }

    /* ---------- Page-level monitoring (individual pages of a website) ---------- */

    private static function pageLabel(array $page): string
    {
        return $page['title'] ?: Monitor::pathLabel($page['path'] ?? '/');
    }

    private static function pagesSummary(array $website): string
    {
        $w = DB::fetch("SELECT pages_total, pages_ok, pages_failed FROM websites WHERE id = ?", [$website['id']]) ?: $website;
        $total = (int) ($w['pages_total'] ?? 0);
        $failed = (int) ($w['pages_failed'] ?? 0);
        if (!$total) return 'n/a';
        return $failed ? ($total - $failed) . ' / ' . $total . ' pages working – ' . $failed . ' page' . ($failed > 1 ? 's' : '') . ' failed' : $total . ' / ' . $total . ' pages working';
    }

    /** One page stopped working: in-app alert + email to admin, assigned staff and the client (once per incident). */
    public static function pageFailed(array $website, array $page, array $incident): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $label = self::pageLabel($page);
        $link = url('websites/view.php?id=' . $website['id'] . '&tab=pages');
        $reason = $incident['failure_reason'] ?: ($page['failure_reason'] ?? 'Page Error');
        $details = $incident['error_message'] ?: ('HTTP ' . ($incident['status_code'] ?: 'no response'));
        $http = $incident['status_code'] ? $incident['status_code'] . ' ' . Monitor::httpText((int) $incident['status_code']) : 'No Response';

        self::create('critical', 'page_down', 'Page Error: ' . $label . ' – ' . $website['name'],
            $page['url'] . ' – FAILED (HTTP ' . ($incident['status_code'] ?: 'none') . '). Reason: ' . $reason . '. ' . $details,
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        ActivityLog::add('page_down', 'Page error: ' . $label . ' (' . $page['url'] . ') on ' . $website['name'] . ' – ' . $reason . ' – ' . $details, ['client_id' => $website['client_id'], 'website_id' => $website['id']]);

        [$subject, $html] = Mailer::render('page_failed', [
            'client_name'           => $clientName,
            'website_name'          => $website['name'],
            'website_url'           => $website['url'],
            'page_name'             => $label,
            'page_url'              => $page['url'],
            'status'                => 'FAILED',
            'http_status'           => $http,
            'error_reason'          => $reason,
            'error_details'         => $details,
            'response_time'         => isset($page['response_time']) && $page['response_time'] !== null ? $page['response_time'] . ' ms' : 'n/a',
            'detected_at'           => format_datetime($incident['started_at']),
            'last_successful_check' => !empty($page['last_success_at']) ? format_datetime($page['last_success_at']) : 'Never',
            'failed_checks'         => (string) (int) ($incident['failed_checks'] ?? 1),
            'pages_summary'         => self::pagesSummary($website),
        ], $link, '#dc3545');
        self::alertOpen('page_failed:' . $incident['id'], ['kind' => 'page', 'tenant_id' => $website['tenant_id'] ?? null, 'client_id' => $website['client_id'], 'website_id' => $website['id'], 'target_id' => $page['id'], 'incident_id' => $incident['id'], 'title' => 'Page Error: ' . $label . ' – ' . $website['name'], 'target_label' => $page['url'],
            'error_type' => $reason, 'error_message' => $details, 'previous_status' => 'working', 'current_status' => 'failed', 'detected_at' => $incident['started_at'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        self::email('page_failed:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'page_failed', (int) $page['id']);
    }

    /** Several pages failed in the same scan: one combined email listing every failed page (avoids an inbox flood). */
    public static function pagesFailed(array $website, array $items): void
    {
        if (!$items) return;
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id'] . '&tab=pages');
        $names = []; $urls = []; $reasons = []; $codes = []; $ids = [];
        foreach ($items as $it) {
            $p = $it['page']; $inc = $it['incident'];
            $names[] = self::pageLabel($p);
            $urls[] = self::pageLabel($p) . ' – ' . $p['url'] . ' (HTTP ' . ($inc['status_code'] ?: 'none') . ' – ' . ($inc['failure_reason'] ?: 'error') . ')';
            $reasons[] = ($inc['failure_reason'] ?: 'Page Error');
            $codes[] = (string) ($inc['status_code'] ?: 'none');
            $ids[] = (int) $inc['id'];
        }
        $n = count($items);
        self::create('critical', 'page_down', 'Page Errors: ' . $n . ' pages failed – ' . $website['name'], implode(', ', $names) . ' on ' . $website['url'],
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        ActivityLog::add('page_down', $n . ' pages failed on ' . $website['name'] . ': ' . implode(', ', $names), ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        $first = $items[0];
        [$subject, $html] = Mailer::render('page_failed', [
            'client_name'           => $clientName,
            'website_name'          => $website['name'],
            'website_url'           => $website['url'],
            'page_name'             => $n . ' pages: ' . implode(', ', $names),
            'page_url'              => implode("\n", $urls),
            'status'                => 'FAILED',
            'http_status'           => implode(', ', array_unique($codes)),
            'error_reason'          => implode(', ', array_unique($reasons)),
            'error_details'         => 'See the list of failed pages above. Each page is checked again every ' . (int) setting('website_check_interval', 5) . ' minutes.',
            'response_time'         => 'n/a',
            'detected_at'           => format_datetime($first['incident']['started_at']),
            'last_successful_check' => !empty($first['page']['last_success_at']) ? format_datetime($first['page']['last_success_at']) : 'Never',
            'failed_checks'         => '1',
            'pages_summary'         => self::pagesSummary($website),
        ], $link, '#dc3545');
        $subject = 'Website Page Errors – ' . $clientName . ' (' . $n . ' pages)';
        $combinedKey = 'pages_failed:' . $website['id'] . ':' . implode('-', $ids);
        foreach ($items as $it) {
            $p = $it['page']; $inc = $it['incident'];
            self::alertOpen('page_failed:' . $inc['id'], ['kind' => 'page', 'tenant_id' => $website['tenant_id'] ?? null, 'client_id' => $website['client_id'], 'website_id' => $website['id'], 'target_id' => $p['id'], 'incident_id' => $inc['id'], 'title' => 'Page Error: ' . self::pageLabel($p) . ' – ' . $website['name'], 'target_label' => $p['url'],
                'error_type' => $inc['failure_reason'] ?: 'Page Error', 'error_message' => $inc['error_message'] ?: ('HTTP ' . ($inc['status_code'] ?: 'no response')), 'previous_status' => 'working', 'current_status' => 'failed', 'detected_at' => $inc['started_at'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        }
        self::email($combinedKey, $subject, $html, [$staff, self::clientRecipient($website)], 'page_failed', (int) $website['id']);
        try { DB::query("UPDATE alerts SET notified_at = IFNULL(notified_at, NOW()), notification_count = notification_count + 1 WHERE alert_key IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", array_map(fn($i) => 'page_failed:' . $i, $ids)); } catch (Throwable $ignored) {}
    }

    public static function pageRecovered(array $website, array $page, array $incident): void
    {
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $label = self::pageLabel($page);
        $link = url('websites/view.php?id=' . $website['id'] . '&tab=pages');
        $duration = duration_human((int) $incident['duration_seconds']);
        self::create('recovery', 'page_recovered', 'Page Recovered: ' . $label . ' – ' . $website['name'], $page['url'] . ' is working again after ' . $duration . '.',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        ActivityLog::add('page_recovered', 'Page recovered: ' . $label . ' (' . $page['url'] . ') on ' . $website['name'] . ' – downtime ' . $duration, ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        [$subject, $html] = Mailer::render('page_recovered', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'page_name'      => $label,
            'page_url'       => $page['url'],
            'status'         => 'RECOVERED',
            'down_since'     => format_datetime($incident['started_at']),
            'recovered_at'   => format_datetime($incident['resolved_at']),
            'total_downtime' => $duration,
            'error_reason'   => trim(($incident['failure_reason'] ?? '') . ($incident['error_message'] ? ' – ' . $incident['error_message'] : '')) ?: 'n/a',
            'pages_summary'  => self::pagesSummary($website),
        ], $link, '#198754');
        self::alertRecover('page_failed:' . $incident['id'], 'page_recovered:' . $incident['id'], ['recovered_at' => $incident['resolved_at'], 'downtime_seconds' => (int) $incident['duration_seconds'], 'current_status' => 'working', 'checks' => (int) ($incident['failed_checks'] ?? 1)]);
        self::email('page_recovered:' . $incident['id'], $subject, $html, [$staff, self::clientRecipient($website)], 'page_recovered', (int) $page['id']);
    }

    public static function pagesRecovered(array $website, array $items): void
    {
        if (!$items) return;
        [$client, $staff] = self::websiteContext($website);
        $clientName = $client['name'] ?? '—';
        $link = url('websites/view.php?id=' . $website['id'] . '&tab=pages');
        $names = []; $urls = []; $ids = []; $earliest = null; $latest = null;
        foreach ($items as $it) {
            $p = $it['page']; $inc = $it['incident'];
            $names[] = self::pageLabel($p);
            $urls[] = self::pageLabel($p) . ' – ' . $p['url'] . ' (down ' . duration_human((int) $inc['duration_seconds']) . ')';
            $ids[] = (int) $inc['id'];
            if ($earliest === null || $inc['started_at'] < $earliest) $earliest = $inc['started_at'];
            if ($latest === null || $inc['resolved_at'] > $latest) $latest = $inc['resolved_at'];
        }
        $n = count($items);
        self::create('recovery', 'page_recovered', 'Pages Recovered: ' . $n . ' pages – ' . $website['name'], implode(', ', $names) . ' on ' . $website['url'] . ' are working again.',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'link' => 'websites/view.php?id=' . $website['id'] . '&tab=pages']);
        ActivityLog::add('page_recovered', $n . ' pages recovered on ' . $website['name'] . ': ' . implode(', ', $names), ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
        [$subject, $html] = Mailer::render('page_recovered', [
            'client_name'    => $clientName,
            'website_name'   => $website['name'],
            'website_url'    => $website['url'],
            'page_name'      => $n . ' pages: ' . implode(', ', $names),
            'page_url'       => implode("\n", $urls),
            'status'         => 'RECOVERED',
            'down_since'     => format_datetime($earliest),
            'recovered_at'   => format_datetime($latest),
            'total_downtime' => duration_human(max(0, strtotime($latest) - strtotime($earliest))),
            'error_reason'   => 'See the list above',
            'pages_summary'  => self::pagesSummary($website),
        ], $link, '#198754');
        $subject = 'Website Pages Recovered – ' . $clientName . ' (' . $n . ' pages)';
        $combinedKey = 'pages_recovered:' . $website['id'] . ':' . implode('-', $ids);
        foreach ($items as $it) self::alertRecover('page_failed:' . $it['incident']['id'], $combinedKey, ['recovered_at' => $it['incident']['resolved_at'], 'downtime_seconds' => (int) $it['incident']['duration_seconds'], 'current_status' => 'working', 'checks' => (int) ($it['incident']['failed_checks'] ?? 1)]);
        self::email($combinedKey, $subject, $html, [$staff, self::clientRecipient($website)], 'page_recovered', (int) $website['id']);
    }

    /* ---------- Cron health ---------- */

    public static function cronStale(array $status): void
    {
        self::$tenantId = null; Mailer::$tenantId = null;
        $key = 'cron_stale:' . date('Y-m-d-H');
        if (!self::shouldSend($key)) return;
        self::create('critical', 'cron_stale', 'Monitoring System Warning', $status['message'], ['link' => 'platform/system.php']);
        self::alertOpen($key, ['kind' => 'system', 'tenant_id' => null, 'title' => 'Monitoring scheduler not running', 'target_label' => 'Scheduler', 'error_type' => 'Scheduler stale', 'error_message' => $status['message'], 'previous_status' => 'running', 'current_status' => 'stopped', 'severity' => 'critical', 'link' => 'platform/system.php']);
        $html = Mailer::template('Monitoring System Warning', $status['message'], ['Checked at' => date('d-M-Y h:i A')], 'Open the Scheduler Health page: it shows the execution engine, workers, queues and the environment facts.', url('platform/system.php'), '#dc3545');
        self::email($key, 'Monitoring System Warning – scheduler not running', $html, [], 'system_error');
    }

    /** Scheduler running again after a stale alert: close the system alert(s) (no email – the in-app entry is enough). */
    public static function schedulerRecovered(): void
    {
        self::alertRecoverKind('system', null, null, ['current_status' => 'running']);
    }

    /* ---------- SMTP transport state (called by Mailer on real transitions only) ---------- */

    public static function smtpFailed(string $kind, string $error): void
    {
        self::$tenantId = null; Mailer::$tenantId = null;
        $key = 'smtp_failed:' . date('YmdHis');
        self::create('critical', 'smtp_failed', 'SMTP Connection Failed', (Mailer::KINDS[$kind] ?? 'Failed') . ': ' . $error . ' – outgoing alert emails are queued until the mail server answers again.', ['link' => 'platform/emails.php', 'tenant_id' => null]);
        self::alertOpen($key, ['kind' => 'smtp', 'tenant_id' => null, 'title' => 'SMTP Connection Failed', 'target_label' => setting('smtp_host', '') . ':' . setting('smtp_port', ''), 'error_type' => Mailer::KINDS[$kind] ?? 'Failed', 'error_message' => $error, 'previous_status' => 'connected', 'current_status' => 'failed', 'severity' => 'critical', 'link' => 'platform/emails.php']);
        try { DB::query("INSERT IGNORE INTO alert_log (alert_key, tenant_id, sent_at) VALUES (?, NULL, NOW())", [$key]); DB::query("UPDATE alerts SET notified_at = NOW(), notification_count = 1 WHERE alert_key = ?", [$key]); } catch (Throwable $ignored) {} // in-app only: email cannot be sent while SMTP is down
    }

    /** SMTP works again: one recovery email to the platform admins (SMTP is up, so it can be delivered now). */
    public static function smtpRecovered(?string $lastError, ?string $failedAt): void
    {
        self::$tenantId = null; Mailer::$tenantId = null;
        $since = $failedAt ? format_datetime($failedAt) : 'unknown';
        $key = 'smtp_recovered:' . date('YmdHis');
        self::create('recovery', 'smtp_recovered', 'SMTP Connection Recovered', 'The mail server answers again (failed since ' . $since . '). Queued alerts are being delivered.', ['link' => 'platform/emails.php', 'tenant_id' => null]);
        $n = self::alertRecoverKind('smtp', null, $key, ['current_status' => 'connected', 'downtime_seconds' => $failedAt ? max(0, time() - strtotime($failedAt)) : null]);
        if ($n === 0) return; // nothing was open – no recovery email for a failure nobody was told about
        $html = Mailer::template('SMTP Connection Recovered', 'The SMTP server accepts connections again. Alert emails that were queued while it was down are being delivered now.', ['Failed since' => $since, 'Recovered at' => date('d-M-Y h:i A'), 'Last error' => $lastError ?: '—', 'Server' => setting('smtp_host', '') . ':' . setting('smtp_port', '')], 'Open SMTP / Email', url('platform/emails.php'), '#198754');
        foreach (self::platformAdminRecipients() as $r) Mailer::send($r['email'], 'SMTP Connection Recovered – ' . setting('platform_name', company_name()), $html, $r['name'], 'smtp_recovered');
        try { DB::query("INSERT IGNORE INTO alert_log (alert_key, tenant_id, sent_at) VALUES (?, NULL, NOW())", [$key]); DB::query("UPDATE alerts SET recovery_notified_at = NOW() WHERE recovery_key = ?", [$key]); } catch (Throwable $ignored) {}
    }

    /* ---------- System errors ---------- */

    public static function systemError(Throwable $e): void
    {
        self::$tenantId = null; Mailer::$tenantId = null;
        if (!setting('notify_system_errors', 1)) return;
        $hash = substr(md5($e->getMessage() . $e->getFile()), 0, 12);
        $key = 'system_error:' . $hash . ':' . date('Y-m-d-H');
        if (!self::shouldSend($key)) return;
        self::create('critical', 'system_error', 'System error', truncate($e->getMessage(), 200), ['link' => 'activity/index.php']);
        $html = Mailer::template('System Error', 'The CRM encountered an application error. Details have been written to the log.', [
            'Message' => $e->getMessage(),
            'File'    => basename($e->getFile()) . ':' . $e->getLine(),
            'Time'    => date('d-M-Y h:i A'),
            'URL'     => $_SERVER['REQUEST_URI'] ?? 'cli',
        ], 'Check logs/ for the full stack trace.', null, '#dc3545');
        self::email($key, 'CRM System Error – ' . truncate($e->getMessage(), 60), $html, [], 'system_error');
    }
}
