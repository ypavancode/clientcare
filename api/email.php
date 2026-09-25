<?php
/**
 * SMTP / Email API (Super Admin only).
 *   GET  action=status           live SMTP service status (polled by the dashboard card)
 *   POST action=save_smtp        save SMTP + sender + notification settings (password only when given)
 *   POST action=test_smtp        full diagnostic with a real test email; accepts unsaved form values (test before saving)
 *   POST action=check_smtp       connection + authentication only (no email)
 *   POST action=process_queue    deliver pending queue rows now (forces past the circuit breaker, bounded)
 *   POST action=retry_email      resend one queue row now
 *   POST action=retry_failed     put every failed queue row back to pending
 *   POST action=delete_queued / clear_failed / email_details / resend_log
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (get('action') !== 'status') json_error('Unknown action.', 404);
    $s = Mailer::status((bool) get('fresh'));
    $fmt = fn($v) => $v ? date('d M Y, H:i:s', strtotime($v)) : '—';
    $ago = fn($v) => $v ? time_ago($v) : 'never';
    json_response(['success' => true, 'status' => $s['status'], 'tone' => $s['tone'], 'headline' => $s['headline'], 'detail' => $s['detail'], 'configured' => $s['configured'], 'enabled' => $s['enabled'],
        'host' => $s['host'] ? $s['host'] . ':' . $s['port'] . ' · ' . $s['encryption'] : '—', 'from' => $s['from'],
        'last_check' => $fmt($s['last_check_at']) . ($s['last_check_at'] ? ' (' . $ago($s['last_check_at']) . ')' : ''), 'last_check_ok' => $s['last_check_ok'], 'last_check_by' => $s['last_check_by'],
        'last_success' => $fmt($s['last_success_at']), 'last_failure' => $fmt($s['last_failure_at']), 'last_error' => $s['last_error'] ? ($s['last_error_label'] . ': ' . $s['last_error']) : '', 'last_response' => $s['last_response'] ?: '',
        'last_email_sent' => $s['last_email_sent_at'] ? $ago($s['last_email_sent_at']) : 'never', 'last_email_sent_full' => $fmt($s['last_email_sent_at']), 'last_email_failed' => $s['last_email_failed_at'] ? $ago($s['last_email_failed_at']) : 'never', 'last_email_error' => $s['last_email_error'] ?: '',
        'sent_24h' => $s['sent_24h'], 'failed_24h' => $s['failed_24h'], 'queue' => $s['queue'], 'backoff_until' => $s['backoff_until'] ? $fmt($s['backoff_until']) : '', 'checked' => date('H:i:s')]);
}

require_post();
data_changed();
$action = post('action', '');

/** Unsaved form values → Mailer::config() overrides (password blank = keep the saved one). */
$overridesFromPost = function (): array {
    if (!post('use_form')) return [];
    $o = ['host' => trim((string) post('smtp_host', '')), 'port' => post_int('smtp_port', 587), 'username' => trim((string) post('smtp_username', '')), 'encryption' => post('smtp_encryption', 'tls'),
        'from_email' => trim((string) post('from_email', '')), 'from_name' => trim((string) post('from_name', '')), 'reply_to' => trim((string) post('reply_to_email', '')),
        'verify_peer' => post('smtp_verify_peer') ? 1 : 0, 'connect_timeout' => post_int('smtp_connect_timeout', 10), 'timeout' => post_int('smtp_timeout', 20), 'enabled' => 1];
    $pass = (string) ($_POST['smtp_password'] ?? '');
    if ($pass !== '') $o['password'] = $pass;
    if ($o['username'] === '') $o['password'] = '';
    return $o;
};

switch ($action) {
    case 'save_smtp':
        $vals = [
            'smtp_enabled'         => post('smtp_enabled') ? 1 : 0,
            'smtp_host'            => trim((string) post('smtp_host', '')),
            'smtp_port'            => post_int('smtp_port', 587) ?? 587,
            'smtp_username'        => trim((string) post('smtp_username', '')),
            'smtp_encryption'      => in_array(post('smtp_encryption'), ['tls', 'ssl', 'none'], true) ? post('smtp_encryption') : 'tls',
            'smtp_verify_peer'     => post('smtp_verify_peer') ? 1 : 0,
            'smtp_connect_timeout' => max(3, min(60, post_int('smtp_connect_timeout', 10) ?? 10)),
            'smtp_timeout'         => max(5, min(120, post_int('smtp_timeout', 20) ?? 20)),
            'smtp_backoff_minutes' => max(1, min(60, post_int('smtp_backoff_minutes', 5) ?? 5)),
            'from_email'           => trim((string) post('from_email', '')),
            'from_name'            => trim((string) post('from_name', '')),
            'reply_to_email'       => trim((string) post('reply_to_email', '')),
            'notification_email'   => trim((string) post('notification_email', '')),
            'notify_clients'       => post('notify_clients') ? 1 : 0,
            'notify_system_errors' => post('notify_system_errors') ? 1 : 0,
        ];
        $errors = [];
        if ($vals['smtp_enabled'] && $vals['smtp_host'] === '') $errors['smtp_host'] = 'SMTP host is required.';
        elseif ($vals['smtp_host'] !== '' && !PHPMailer\PHPMailer\PHPMailer::isValidHost($vals['smtp_host'])) $errors['smtp_host'] = 'Enter a valid hostname or IP address (no http://, no spaces).';
        if ($vals['smtp_port'] < 1 || $vals['smtp_port'] > 65535) $errors['smtp_port'] = 'Port must be between 1 and 65535.';
        if (!valid_email($vals['from_email'])) $errors['from_email'] = 'Enter a valid from email.';
        if ($vals['reply_to_email'] !== '' && !valid_email($vals['reply_to_email'])) $errors['reply_to_email'] = 'Enter a valid reply-to email.';
        if ($vals['from_name'] === '') $vals['from_name'] = Mailer::DEFAULT_FROM_NAME;
        foreach (preg_split('~[,;\s]+~', $vals['notification_email'], -1, PREG_SPLIT_NO_EMPTY) as $em) if (!valid_email($em)) $errors['notification_email'] = 'One or more notification emails are invalid.';
        $pass = (string) ($_POST['smtp_password'] ?? '');
        if ($vals['smtp_username'] !== '' && $pass === '' && !setting('smtp_password') && !post('clear_smtp_password')) $errors['smtp_password'] = 'Enter the SMTP password for this username.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        $before = ['host' => setting('smtp_host'), 'port' => setting('smtp_port'), 'user' => setting('smtp_username'), 'enc' => setting('smtp_encryption'), 'from' => setting('from_email'), 'enabled' => setting('smtp_enabled', 1)];
        foreach ($vals as $k => $v) set_setting($k, (string) $v);
        if ($pass !== '') set_setting('smtp_password', encrypt_value($pass));
        if (post('clear_smtp_password')) set_setting('smtp_password', '');
        // the transport changed – forget the old back-off / status so the next test reflects the new settings
        DB::update('email_service_state', ['backoff_until' => null, 'consecutive_failures' => 0, 'status' => Mailer::configured() ? 'unknown' : 'incomplete', 'updated_at' => date('Y-m-d H:i:s')], 'id = 1');
        Cache::forget('email:status'); Cache::forget('email:dns:' . Mailer::senderDomain());
        $changed = [];
        foreach (['host' => 'smtp_host', 'port' => 'smtp_port', 'user' => 'smtp_username', 'enc' => 'smtp_encryption', 'from' => 'from_email', 'enabled' => 'smtp_enabled'] as $k => $sk) if ((string) $before[$k] !== (string) $vals[$sk]) $changed[] = $k;
        if ($pass !== '') $changed[] = 'password';
        ActivityLog::platform('smtp_changed', 'SMTP settings saved (' . ($changed ? 'changed: ' . implode(', ', $changed) : 'no transport change') . ')', $vals['smtp_host'] . ':' . $vals['smtp_port']);
        json_success('SMTP settings saved. Run Test SMTP to verify the connection.', ['changed' => $changed]);

    case 'test_smtp':
    case 'check_smtp':
        $to = trim((string) post('to', Auth::user()['email'] ?? ''));
        if ($action === 'test_smtp' && !valid_email($to)) json_error('Enter a valid email address to send the test to.', 422, ['errors' => ['to' => 'Invalid email']]);
        $o = $overridesFromPost();
        $c = Mailer::config($o);
        @set_time_limit($c['connect_timeout'] * 3 + $c['timeout'] * 5 + 20);
        $d = Mailer::diagnose($to ?: 'nobody@example.com', $o, $action === 'test_smtp');
        ActivityLog::platform('smtp_test', ($action === 'test_smtp' ? 'SMTP test to ' . $to : 'SMTP connection check') . ': ' . ($d['ok'] ? 'success' : 'failed – ' . $d['reason']) . ' (' . $d['ms'] . ' ms' . ($o ? ', unsaved settings' : '') . ')', $c['host'] . ':' . $c['port'], $d['ok'] ? 'ok' : 'failed');
        $steps = array_values($d['steps']);
        json_response(['success' => $d['ok'], 'message' => $d['ok'] ? $d['headline'] . ' – ' . $d['reason'] : $d['headline'] . ' – ' . $d['reason'], 'headline' => $d['headline'], 'reason' => $d['reason'], 'suggestion' => $d['suggestion'], 'kind' => $d['kind'],
            'kind_label' => $d['kind'] ? (Mailer::KINDS[$d['kind']] ?? 'Failed') : null, 'steps' => $steps, 'ms' => $d['ms'], 'message_id' => $d['message_id'], 'smtp_response' => $d['smtp_response'], 'environment' => $d['environment'], 'config' => $d['config'], 'unsaved' => (bool) $o, 'status' => Mailer::status(true)]);

    case 'process_queue':
        @set_time_limit(90);
        $r = Mailer::processQueue(100, 45, true);
        $msg = $r['skipped'] === 'not_configured' ? 'SMTP is not configured – nothing sent.' : 'Queue processed: ' . $r['sent'] . ' sent, ' . $r['failed'] . ' failed' . ($r['deferred'] ? ', ' . $r['deferred'] . ' deferred (' . ($r['skipped'] === 'transport_failure' ? 'SMTP server unreachable – retried automatically' : 'time budget') . ')' : '') . '.';
        ActivityLog::platform('email_retry', 'Processed the email queue manually: ' . $msg, 'queue', $r['failed'] || $r['skipped'] === 'not_configured' ? 'failed' : 'ok');
        json_response(['success' => !$r['skipped'] || $r['skipped'] === 'transport_failure' && $r['sent'] > 0 || $r['skipped'] === null, 'message' => $msg] + $r);

    case 'retry_email':
        $row = DB::fetch("SELECT * FROM email_queue WHERE id = ?", [post_int('id')]);
        if (!$row) json_error('Queue item not found.', 404);
        @set_time_limit(90);
        Mailer::$tenantId = $row['tenant_id'] ? (int) $row['tenant_id'] : null;
        $attempt = (int) $row['attempts'] + 1;
        $r = Mailer::send($row['to_email'], $row['subject'], $row['body'], $row['to_name'], $row['category'], $row['cc'], $row['bcc'], $row['ref_id'] ? (int) $row['ref_id'] : null, null, ['queue_id' => (int) $row['id'], 'attempt' => $attempt]);
        Mailer::$tenantId = null;
        DB::update('email_queue', ['status' => $r['ok'] ? 'sent' : 'failed', 'attempts' => $attempt, 'next_attempt_at' => null, 'last_error' => $r['error'], 'sent_at' => $r['ok'] ? date('Y-m-d H:i:s') : null], 'id = ?', [$row['id']]);
        ActivityLog::platform('email_retry', 'Retried queued email to ' . $row['to_email'] . ': ' . ($r['ok'] ? 'sent' : 'failed – ' . $r['error']), $row['to_email'], $r['ok'] ? 'ok' : 'failed');
        if (!$r['ok']) json_error((Mailer::KINDS[$r['kind']] ?? 'Failed') . ': ' . $r['error'], 200, ['kind' => $r['kind'], 'ms' => $r['ms']]);
        json_success('Email sent to ' . $row['to_email'] . ' (' . $r['ms'] . ' ms).', ['ms' => $r['ms']]);

    case 'resend_log':
        // re-send a message from the delivery log (only when the queue row still holds the body)
        $log = DB::fetch("SELECT * FROM email_logs WHERE id = ?", [post_int('id')]);
        if (!$log) json_error('Log entry not found.', 404);
        $row = $log['queue_id'] ? DB::fetch("SELECT * FROM email_queue WHERE id = ?", [$log['queue_id']]) : null;
        if (!$row) json_error('The message body is no longer available for this entry (only queued alerts can be re-sent).');
        @set_time_limit(90);
        Mailer::$tenantId = $row['tenant_id'] ? (int) $row['tenant_id'] : null;
        $r = Mailer::send($row['to_email'], $row['subject'], $row['body'], $row['to_name'], $row['category'], $row['cc'], $row['bcc'], $row['ref_id'] ? (int) $row['ref_id'] : null, null, ['queue_id' => (int) $row['id'], 'attempt' => (int) $row['attempts'] + 1]);
        Mailer::$tenantId = null;
        if ($r['ok']) DB::update('email_queue', ['status' => 'sent', 'attempts' => (int) $row['attempts'] + 1, 'next_attempt_at' => null, 'last_error' => null, 'sent_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        ActivityLog::platform('email_retry', 'Re-sent logged email to ' . $row['to_email'] . ': ' . ($r['ok'] ? 'sent' : 'failed – ' . $r['error']), $row['to_email'], $r['ok'] ? 'ok' : 'failed');
        if (!$r['ok']) json_error((Mailer::KINDS[$r['kind']] ?? 'Failed') . ': ' . $r['error']);
        json_success('Email re-sent to ' . $row['to_email'] . ' (' . $r['ms'] . ' ms).');

    case 'retry_failed':
        $n = DB::query("UPDATE email_queue SET status = 'pending', attempts = 0, next_attempt_at = NULL WHERE status = 'failed'")->rowCount();
        DB::update('email_service_state', ['backoff_until' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = 1'); Cache::forget('email:status');
        ActivityLog::platform('email_retry', 'Re-queued ' . $n . ' failed email(s)', 'queue');
        json_success($n . ' failed email(s) queued again – they are delivered on the next run (or use Process queue now).');

    case 'delete_queued':
        $n = DB::delete('email_queue', 'id = ?', [post_int('id')]);
        json_success($n ? 'Removed from queue.' : 'Queue item not found.');

    case 'clear_failed':
        $n = DB::delete('email_queue', "status = 'failed'");
        ActivityLog::platform('email_retry', 'Cleared ' . $n . ' failed email(s) from the queue', 'queue');
        json_success($n . ' failed email(s) removed from the queue.');

    case 'email_details':
        $id = post_int('id');
        $src = post('src') === 'queue' ? 'queue' : 'log';
        if ($src === 'queue') $row = DB::fetch("SELECT q.*, t.name AS tenant_name FROM email_queue q LEFT JOIN tenants t ON t.id = q.tenant_id WHERE q.id = ?", [$id]);
        else {
            $row = DB::fetch("SELECT l.*, t.name AS tenant_name FROM email_logs l LEFT JOIN tenants t ON t.id = l.tenant_id WHERE l.id = ?", [$id]);
            if ($row && $row['queue_id']) { $q = DB::fetch("SELECT body, attempts, status AS queue_status, created_at AS queued_at FROM email_queue WHERE id = ?", [$row['queue_id']]); if ($q) $row += $q; }
            if ($row) { $row['error_kind_label'] = $row['error_kind'] ? (Mailer::KINDS[$row['error_kind']] ?? 'Failed') : null; $row['history'] = DB::fetchAll("SELECT id, status, error_kind, error, smtp_response, duration_ms, attempt, sent_at FROM email_logs WHERE queue_id = ? AND queue_id IS NOT NULL ORDER BY id", [$row['queue_id'] ?: 0]); }
        }
        if (!$row) json_error('Not found.', 404);
        json_success('OK', ['email' => $row]);

    default:
        json_error('Unknown action.');
}
