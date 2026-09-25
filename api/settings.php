<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();
$action = post('action', '');
$platformActions = ['save_template', 'reset_template', 'preview_template', 'save_general', 'save_monitoring', 'save_alerts', 'process_queue'];
if (in_array($action, $platformActions, true)) Auth::requirePlatformAdmin(); else Auth::requireRole('admin');
$tenantScope = Auth::isPlatformAdmin() && empty($_SESSION['act_as_tenant']) ? '' : ' AND tenant_id = ' . Tenant::id();

switch ($action) {
    // Legacy template actions – the Email Templates studio uses api/templates.php; these stay for older callers.
    case 'save_template':
        $type = post('type', '');
        if (!isset(Mailer::templateDefinitions()[$type])) json_error('Unknown template.');
        $r = Mailer::saveTemplate($type, ['subject' => post('subject', ''), 'body' => (string) ($_POST['body'] ?? '')], (int) (Auth::user()['id'] ?? 0) ?: null);
        if (!empty($r['errors'])) json_error('Please correct the highlighted fields.', 422, ['errors' => $r['errors']]);
        ActivityLog::add('settings_changed', 'Email template updated: ' . $type);
        json_success('Template saved.');

    case 'reset_template':
        $type = post('type', '');
        Mailer::resetTemplate($type);
        ActivityLog::add('settings_changed', 'Email template reset to default: ' . $type);
        json_success('Template restored to default.');

    case 'preview_template':
        $type = post('type', '');
        if (!isset(Mailer::templateDefinitions()[$type])) json_error('Unknown template.');
        $p = Mailer::previewTemplate($type, ['subject' => post('subject'), 'body' => isset($_POST['body']) ? (string) $_POST['body'] : null]);
        json_success('OK', ['subject' => $p['subject'], 'html' => $p['html']]);

    case 'retry_email':
        $id = post_int('id');
        $row = DB::fetch("SELECT * FROM email_queue WHERE id = ?" . $tenantScope, [$id]);
        if (!$row) json_error('Queue item not found.', 404);
        @set_time_limit(90);
        $r = Mailer::send($row['to_email'], $row['subject'], $row['body'], $row['to_name'], $row['category'], $row['cc'], $row['bcc'], $row['ref_id'] ? (int) $row['ref_id'] : null, null, ['queue_id' => (int) $row['id'], 'attempt' => (int) $row['attempts'] + 1]);
        DB::update('email_queue', ['status' => $r['ok'] ? 'sent' : 'failed', 'attempts' => (int) $row['attempts'] + 1, 'next_attempt_at' => null, 'last_error' => $r['error'], 'sent_at' => $r['ok'] ? date('Y-m-d H:i:s') : null], 'id = ?', [$id]);
        if (!$r['ok']) json_error('Sending failed: ' . $r['error']);
        json_success('Email sent to ' . $row['to_email'] . '.');

    case 'delete_queued':
        DB::delete('email_queue', 'id = ?' . $tenantScope, [post_int('id')]);
        json_success('Removed from queue.');

    case 'email_details':
        $id = post_int('id');
        $src = post('src') === 'queue' ? 'queue' : 'log';
        $row = $src === 'queue' ? DB::fetch("SELECT * FROM email_queue WHERE id = ?" . $tenantScope, [$id]) : DB::fetch("SELECT * FROM email_logs WHERE id = ?" . $tenantScope, [$id]);
        if (!$row) json_error('Not found.', 404);
        json_success('OK', ['email' => $row]);

    case 'save_general':
        Auth::requirePlatformOwner();
        $company = post('company_name', '');
        $tz = post('timezone', 'Asia/Kolkata');
        $df = post('date_format', 'd-M-Y');
        if ($company === '') json_error('Company name is required.', 422, ['errors' => ['company_name' => 'Required']]);
        if (!in_array($tz, timezone_identifiers_list(), true)) json_error('Invalid timezone.', 422, ['errors' => ['timezone' => 'Invalid']]);
        if (!in_array($df, ['d-M-Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd M Y', 'M d, Y'], true)) $df = 'd-M-Y';
        set_setting('company_name', $company);
        set_setting('timezone', $tz);
        set_setting('date_format', $df);
        $pn = trim((string) post('platform_name', '')); if ($pn !== '') set_setting('platform_name', mb_substr($pn, 0, 60));
        $tp = post('trial_plan', ''); if ($tp && Tenant::planByCode($tp)) set_setting('trial_plan', $tp);
        set_setting('trial_days', (string) max(1, min(90, post_int('trial_days', 14) ?? 14)));
        set_setting('registration_enabled', post('registration_enabled') ? '1' : '0');
        set_setting('web_heartbeat_enabled', post('web_heartbeat_enabled') ? '1' : '0');
        set_setting('maintenance_mode', post('maintenance_mode') ? '1' : '0');
        set_setting('maintenance_message', mb_substr(trim((string) post('maintenance_message', '')), 0, 300));
        set_setting('analytics_enabled', post('analytics_enabled') ? '1' : '0');
        set_setting('analytics_geo_lookup', post('analytics_geo_lookup') ? '1' : '0');
        set_setting('analytics_trust_proxy', post('analytics_trust_proxy') ? '1' : '0');
        set_setting('analytics_raw_retention_days', (string) max(7, min(365, post_int('analytics_raw_retention_days', 90) ?? 90)));
        Cache::forget('plans:all'); Cache::forget('plans:public');

        // Logo upload (optional)
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['logo'];
            if ($f['size'] > 1024 * 1024) json_error('Logo must be under 1 MB.');
            $info = @getimagesize($f['tmp_name']);
            $mime = $info['mime'] ?? mime_content_type($f['tmp_name']);
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) json_error('Logo must be PNG, JPG, WEBP or SVG.');
            if ($mime === 'image/svg+xml') {
                $svg = file_get_contents($f['tmp_name']);
                // no scripts, no event handlers, no animation/foreign objects, no external or data: references
                if (preg_match('~<\s*(script|foreignObject|set|animate|animateTransform|animateMotion|iframe|embed|object|use)\b|\son[a-z]+\s*=|javascript:|(xlink:)?href\s*=\s*["\']?\s*(data:|https?:|//)|<!ENTITY|<\?xml-stylesheet~i', $svg)) json_error('SVG contains disallowed content (scripts, event handlers, animation, external or data references).');
            }
            $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
            if (!move_uploaded_file($f['tmp_name'], UPLOAD_PATH . '/' . $name)) json_error('Could not save the uploaded logo.');
            $old = setting('logo_file');
            if ($old && is_file(UPLOAD_PATH . '/' . $old)) @unlink(UPLOAD_PATH . '/' . $old);
            set_setting('logo_file', $name);
        }
        if (post('remove_logo')) {
            $old = setting('logo_file');
            if ($old && is_file(UPLOAD_PATH . '/' . $old)) @unlink(UPLOAD_PATH . '/' . $old);
            set_setting('logo_file', '');
        }
        ActivityLog::add('settings_changed', 'General settings updated');
        json_success('General settings saved.');

    case 'save_monitoring':
        $vals = [
            'website_check_interval' => max(1, min(1440, post_int('website_check_interval', 5))),
            'form_check_interval'    => max(1, min(10080, post_int('form_check_interval', 5))),
            'cron_stale_minutes'     => max(2, min(1440, post_int('cron_stale_minutes', 12))),
            'check_timeout'          => max(3, min(60, post_int('check_timeout', 15))),
            'retry_attempts'         => max(0, min(5, post_int('retry_attempts', 2))),
            'form_stale_hours'       => max(1, min(720, post_int('form_stale_hours', 48))),
            'form_discovery_enabled' => post('form_discovery_enabled') ? 1 : 0,
            'form_scan_interval_hours' => max(1, min(720, post_int('form_scan_interval_hours', 24))),
            'form_scan_max_pages'    => max(1, min(500, post_int('form_scan_max_pages', 60))),
            'form_scan_browser_pages' => max(0, min(100, post_int('form_scan_browser_pages', 12))),
            'form_scan_per_run'      => max(1, min(50, post_int('form_scan_per_run', 3))),
            'form_test_email'        => post('form_test_email', ''),
            'form_email_required'    => post('form_email_required') ? 1 : 0,
            'imap_host'              => post_nullable('imap_host') ?? '',
            'imap_port'              => post_int('imap_port', 993),
            'imap_encryption'        => in_array(post('imap_encryption'), ['ssl', 'tls', 'none'], true) ? post('imap_encryption') : 'ssl',
            'imap_username'          => post_nullable('imap_username') ?? '',
            'imap_wait_seconds'      => max(0, min(60, post_int('imap_wait_seconds', 20))),
            'placeholder_keywords'   => post_nullable('placeholder_keywords') ?? '',
            'check_concurrency'      => max(1, min(200, post_int('check_concurrency', 25))),
            'page_monitoring_enabled' => post('page_monitoring_enabled') ? 1 : 0,
            'page_max_pages'         => max(1, min(500, post_int('page_max_pages', 50))),
            'page_discovery_hours'   => max(1, min(720, post_int('page_discovery_hours', 24))),
            'page_check_concurrency' => max(1, min(100, post_int('page_check_concurrency', 15))),
            'page_alert_threshold'   => max(1, min(50, post_int('page_alert_threshold', 3))),
            'page_ignore_patterns'   => post_nullable('page_ignore_patterns') ?? '',
            'retention_page_history_days' => max(1, min(365, post_int('retention_page_history_days', 30))),
            'retention_monitoring_days'    => max(1, min(90, post_int('retention_monitoring_days', 7))),
            'retention_form_tests_days'    => max(7, min(730, post_int('retention_form_tests_days', 90))),
            'retention_activity_days'      => max(30, min(3650, post_int('retention_activity_days', 365))),
            'retention_email_logs_days'    => max(30, min(3650, post_int('retention_email_logs_days', 180))),
            'retention_notifications_days' => max(7, min(3650, post_int('retention_notifications_days', 90))),
            'dispatch_batch_limit'    => max(100, min(20000, post_int('dispatch_batch_limit', 2000))),
            'queue_claim_batch'       => max(1, min(200, post_int('queue_claim_batch', 50))),
            'job_max_attempts'        => max(1, min(10, post_int('job_max_attempts', 3))),
            'worker_stale_seconds'    => max(30, min(3600, post_int('worker_stale_seconds', 120))),
            'browser_max_concurrent'  => max(1, min(50, post_int('browser_max_concurrent', 2))),
            'analytics_async'         => post('analytics_async') ? 1 : 0,
            'inline_fallback_enabled' => post('inline_fallback_enabled') ? 1 : 0,
        ];
        if (!valid_email($vals['form_test_email'])) json_error('Enter a valid test email address.', 422, ['errors' => ['form_test_email' => 'Invalid email']]);
        foreach ($vals as $k => $v) set_setting($k, (string) $v);
        $imapPass = (string) ($_POST['imap_password'] ?? '');
        if ($imapPass !== '') set_setting('imap_password', encrypt_value($imapPass));
        if (post('clear_imap_password')) set_setting('imap_password', '');
        ActivityLog::add('settings_changed', 'Monitoring settings updated');
        json_success('Monitoring settings saved.');

    case 'save_alerts':
        $clean = function (string $csv): string {
            $t = array_values(array_unique(array_filter(array_map('intval', preg_split('~[,\s]+~', $csv)))));
            rsort($t);
            return implode(',', $t ?: [30, 15, 7]);
        };
        set_setting('ssl_alert_days', $clean(post('ssl_alert_days', '30,15,7')));
        set_setting('ssl_warning_days', (string) max(1, post_int('ssl_warning_days', 30)));
        set_setting('domain_alert_days', $clean(post('domain_alert_days', '60,30,15,7')));
        set_setting('hosting_alert_days', $clean(post('hosting_alert_days', '60,30,15,7')));
        ActivityLog::add('settings_changed', 'Alert thresholds updated');
        json_success('Alert settings saved.');

    case 'process_queue':
        @set_time_limit(90);
        $r = Mailer::processQueue(100, 45, true);
        json_success($r['skipped'] === 'not_configured' ? 'SMTP is not configured – nothing sent.' : 'Queue processed: ' . $r['sent'] . ' sent, ' . $r['failed'] . ' failed' . ($r['deferred'] ? ', ' . $r['deferred'] . ' deferred' : '') . '.', $r);

    /* ---------------- tenant (workspace) actions ---------------- */
    case 'save_workspace':
        $tid = Tenant::id();
        $name = trim((string) post('name', ''));
        if (mb_strlen($name) < 2) json_error('Enter the workspace name.', 422, ['errors' => ['name' => 'Required']]);
        $billing = trim((string) post('billing_email', ''));
        if ($billing !== '' && !valid_email($billing)) json_error('Enter a valid billing email.', 422, ['errors' => ['billing_email' => 'Invalid email']]);
        $alerts = [];
        foreach (preg_split('~[,;\s]+~', (string) post('alert_emails', ''), -1, PREG_SPLIT_NO_EMPTY) as $em) { if (!valid_email($em)) json_error('Alert recipient "' . $em . '" is not a valid email.', 422, ['errors' => ['alert_emails' => 'Invalid email']]); $alerts[] = strtolower($em); }
        $tz = in_array(post('timezone'), timezone_identifiers_list(), true) ? post('timezone') : null;
        $data = ['name' => mb_substr($name, 0, 150), 'billing_email' => $billing ?: null, 'phone' => post_nullable('phone'), 'timezone' => $tz, 'alert_emails' => $alerts ? implode(',', array_unique($alerts)) : null];
        if (Tenant::feature('white_label')) { $data['white_label_from_name'] = post_nullable('white_label_from_name'); $data['white_label_name'] = post_nullable('white_label_name'); }
        DB::update('tenants', $data, 'id = ?', [$tid]);
        Tenant::setSetting('notify_clients', post('notify_clients') ? 1 : 0);
        Tenant::forget($tid);
        ActivityLog::add('settings_changed', 'Workspace settings updated');
        json_success('Workspace settings saved.');

    case 'save_workspace_alerts':
        $clean = function (string $csv): string { $t = array_values(array_unique(array_filter(array_map('intval', preg_split('~[,\s]+~', $csv))))); rsort($t); return implode(',', $t ?: [30, 15, 7]); };
        Tenant::setSetting('ssl_alert_days', $clean(post('ssl_alert_days', '30,15,7')));
        Tenant::setSetting('ssl_warning_days', (string) max(1, post_int('ssl_warning_days', 30) ?? 30));
        Tenant::setSetting('domain_alert_days', $clean(post('domain_alert_days', '90,30,14,7,1')));
        Tenant::setSetting('hosting_alert_days', $clean(post('hosting_alert_days', '90,30,14,7,1')));
        ActivityLog::add('settings_changed', 'Workspace alert thresholds updated');
        json_success('Alert settings saved.');

    case 'api_key_create':
        if (!Tenant::feature('api') || Tenant::feature('api') === 'none') json_error('API access is not included in your plan. Upgrade to Professional or above.', 403);
        $name = trim((string) post('name', ''));
        if ($name === '') json_error('Give the key a name.', 422, ['errors' => ['name' => 'Required']]);
        if ((int) DB::value("SELECT COUNT(*) FROM api_keys WHERE tenant_id = ? AND status = 'active'", [Tenant::id()]) >= 10) json_error('Maximum 10 active API keys per workspace.');
        $scopes = post('scopes') === 'read,write' && in_array(Tenant::feature('api'), ['full', 'advanced'], true) ? 'read,write' : 'read'; // Business / Agency plans (api = advanced) may create write keys
        $prefix = 'om_' . substr(bin2hex(random_bytes(6)), 0, 7);
        $key = $prefix . '_' . bin2hex(random_bytes(20));
        DB::insert('api_keys', ['tenant_id' => Tenant::id(), 'user_id' => Auth::id(), 'name' => mb_substr($name, 0, 80), 'key_prefix' => $prefix, 'key_hash' => hash('sha256', $key), 'scopes' => $scopes, 'rate_limit_per_min' => (int) setting('api_rate_limit_per_min', 60), 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['new_api_key'] = $key;
        ActivityLog::add('settings_changed', 'API key created: ' . $name);
        json_success('API key created. Copy it now – it is shown only once.', ['redirect' => url('settings/index.php?tab=api')]);

    case 'api_key_revoke':
        $k = DB::fetch("SELECT * FROM api_keys WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$k) json_error('Key not found.', 404);
        DB::update('api_keys', ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$k['id']]);
        ActivityLog::add('settings_changed', 'API key revoked: ' . $k['name']);
        json_success('API key revoked.');

    case 'webhook_save':
        if (!Tenant::feature('webhooks')) json_error('Webhooks are available on the Business plan and above.', 403);
        $name = trim((string) post('name', '')); $url = trim((string) post('url', ''));
        if ($name === '') json_error('Give the webhook a name.', 422, ['errors' => ['name' => 'Required']]);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) json_error('Enter a valid http(s) URL.', 422, ['errors' => ['url' => 'Invalid URL']]);
        $h = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($h === '' || $h === 'localhost' || (filter_var($h, FILTER_VALIDATE_IP) && !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) json_error('Webhook URL must point to a public host.', 422, ['errors' => ['url' => 'Public host required']]);
        if ((int) DB::value("SELECT COUNT(*) FROM webhooks WHERE tenant_id = ?", [Tenant::id()]) >= 10) json_error('Maximum 10 webhooks per workspace.');
        $events = array_values(array_intersect((array) ($_POST['events'] ?? []), Webhooks::EVENTS));
        $id = DB::insert('webhooks', ['tenant_id' => Tenant::id(), 'name' => mb_substr($name, 0, 80), 'url' => mb_substr($url, 0, 500), 'secret' => post_nullable('secret') ? mb_substr(post('secret'), 0, 64) : null, 'events' => $events ? json_encode($events) : null, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        ActivityLog::add('settings_changed', 'Webhook added: ' . $name);
        json_success('Webhook added.', ['id' => $id]);

    case 'webhook_test':
    case 'webhook_toggle':
    case 'webhook_delete':
        $hook = DB::fetch("SELECT * FROM webhooks WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$hook) json_error('Webhook not found.', 404);
        if ($action === 'webhook_test') {
            $r = Webhooks::test($hook);
            $ok = $r['code'] >= 200 && $r['code'] < 300;
            DB::update('webhooks', ['last_status_code' => $r['code'], 'last_delivered_at' => date('Y-m-d H:i:s')], 'id = ?', [$hook['id']]);
            json_response(['success' => $ok, 'message' => $ok ? 'Test delivered (HTTP ' . $r['code'] . ').' : 'Delivery failed: HTTP ' . $r['code'] . ' ' . truncate((string) $r['body'], 160)]);
        }
        if ($action === 'webhook_toggle') {
            $new = $hook['status'] === 'active' ? 'paused' : 'active';
            DB::update('webhooks', ['status' => $new, 'failures' => 0], 'id = ?', [$hook['id']]);
            json_success('Webhook ' . $new . '.');
        }
        DB::delete('webhooks', 'id = ?', [$hook['id']]);
        ActivityLog::add('settings_changed', 'Webhook deleted: ' . $hook['name']);
        json_success('Webhook deleted.');

    default:
        json_error('Unknown action.');
}
