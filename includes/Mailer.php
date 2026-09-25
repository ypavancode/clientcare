<?php
/**
 * CENTRAL EMAIL SERVICE – the only place in the CRM that sends email (v3.6).
 *
 *   Monitoring / modules  →  Notifier  →  Mailer::queue()  →  email_queue  →  Mailer::processQueue() (worker / cron / heartbeat)
 *                                                                          →  Mailer::send()  →  PHPMailer  →  authenticated SMTP
 *
 * - Transport: PHPMailer over authenticated SMTP (SMTPS / STARTTLS / plain). PHP mail() is never used.
 * - Configuration: Super Admin → SMTP / Email (settings table; the SMTP password is stored encrypted, never shown again).
 * - Timeouts are CONTROLLED at every stage: TCP connect (smtp_connect_timeout, default 10 s) and every SMTP command /
 *   reply (smtp_timeout, default 20 s – PHPMailer's own per-command limit of 300 s is overridden). A dead or wrongly
 *   configured server therefore fails within seconds instead of hanging the request until PHP's time limit kills it.
 * - Circuit breaker: after a connection / authentication / timeout failure the queue backs off for
 *   smtp_backoff_minutes (default 5) so a dead SMTP server never stalls web requests or workers; a batch stops at the
 *   first transport failure and the remaining messages are retried later with exponential back-off.
 * - Every attempt is written to email_logs (recipient, subject, type, status, error, error_kind, SMTP response,
 *   Message-ID, duration). The real service state lives in email_service_state (last check / success / failure /
 *   email) and drives the Super Admin status card – nothing is ever reported as "connected" without a real connection.
 * - Mailer::diagnose() = the "Test SMTP" button: configuration → DNS → TCP → TLS → EHLO → AUTH → test email → QUIT,
 *   each step timed with its own error / server response.
 * - Sender identity is always the configured From with a matching Reply-To and Return-Path. Client addresses are only
 *   ever used as To / CC / BCC. Optional DKIM signing. Templates: Super Admin → Email Templates, rendered by render().
 */
require_once ROOT_PATH . '/vendor/phpmailer/src/Exception.php';
require_once ROOT_PATH . '/vendor/phpmailer/src/PHPMailer.php';
require_once ROOT_PATH . '/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Mailer
{
    public static ?int $tenantId = null;
    const DEFAULT_FROM_EMAIL = 'clientcare@outlinestudio.in';
    const DEFAULT_FROM_NAME  = 'Outline Media Client Care';
    const MAX_ATTEMPTS = 3;
    /** Error kinds recorded in email_logs.error_kind / shown in the UI. */
    const KINDS = ['config' => 'Configuration', 'dns' => 'DNS Failed', 'connection' => 'Connection Failed', 'timeout' => 'Timeout', 'tls' => 'TLS/SSL Failed', 'auth' => 'Authentication Failed', 'rejected' => 'Rejected by Server', 'other' => 'Failed'];
    /** Failures that mean "the server is unreachable / refuses us" – the whole queue backs off, not just this message. */
    const TRANSPORT_KINDS = ['dns', 'connection', 'timeout', 'tls', 'auth'];
    /** Ports that belong to other mail protocols – a classic mix-up when copying settings from a mail client. */
    const NON_SMTP_PORTS = [110 => 'POP3', 143 => 'IMAP', 993 => 'IMAP (SSL)', 995 => 'POP3 (SSL)'];
    private static ?array $state = null;

    /* =====================================================================
     * CONFIGURATION
     * ===================================================================== */

    /** SMTP settings as used for sending (password decrypted). $override = unsaved form values for a test. */
    public static function config(array $override = []): array
    {
        $c = [
            'enabled'         => (bool) setting('smtp_enabled', 1),
            'host'            => trim((string) setting('smtp_host', '')),
            'port'            => (int) setting('smtp_port', 587),
            'username'        => (string) setting('smtp_username', ''),
            'password'        => (string) (decrypt_value(setting('smtp_password', '')) ?? ''),
            'encryption'      => (string) setting('smtp_encryption', 'tls'),
            'from_email'      => (string) setting('from_email', self::DEFAULT_FROM_EMAIL),
            'from_name'       => (string) setting('from_name', self::DEFAULT_FROM_NAME),
            'reply_to'        => (string) setting('reply_to_email', ''),
            'verify_peer'     => (bool) setting('smtp_verify_peer', 1),
            'connect_timeout' => max(3, min(60, (int) setting('smtp_connect_timeout', 10))),
            'timeout'         => max(5, min(120, (int) setting('smtp_timeout', 20))),
        ];
        foreach ($override as $k => $v) if (array_key_exists($k, $c) && $v !== null) $c[$k] = is_bool($c[$k]) ? (bool) $v : (is_int($c[$k]) ? (int) $v : (string) $v);
        if (!in_array($c['encryption'], ['ssl', 'tls', 'starttls', 'none'], true)) $c['encryption'] = 'tls';
        if ($c['encryption'] === 'starttls') $c['encryption'] = 'tls';
        $c['connect_timeout'] = max(3, min(60, $c['connect_timeout']));
        $c['timeout'] = max(5, min(120, $c['timeout']));
        return $c;
    }

    /** Problems that make the configuration unusable (empty = complete). */
    public static function configProblems(array $c = null): array
    {
        $c = $c ?? self::config();
        $p = [];
        if (!$c['enabled']) $p[] = 'SMTP sending is disabled.';
        if ($c['host'] === '') $p[] = 'SMTP host is empty.';
        elseif (!PHPMailer::isValidHost($c['host'])) $p[] = 'SMTP host "' . $c['host'] . '" is not a valid hostname or IP address.';
        if ($c['port'] < 1 || $c['port'] > 65535) $p[] = 'SMTP port must be between 1 and 65535.';
        elseif (isset(self::NON_SMTP_PORTS[$c['port']])) $p[] = 'Port ' . $c['port'] . ' is the ' . self::NON_SMTP_PORTS[$c['port']] . ' port (used for receiving mail), not SMTP. Use 465 with SSL or 587 with STARTTLS.';
        if (!valid_email($c['from_email'])) $p[] = 'From email is missing or invalid.';
        if ($c['reply_to'] !== '' && !valid_email($c['reply_to'])) $p[] = 'Reply-To email is invalid.';
        if ($c['username'] !== '' && $c['password'] === '') $p[] = 'SMTP username is set but the password is empty.';
        if (in_array($c['encryption'], ['ssl', 'tls'], true) && !extension_loaded('openssl')) $p[] = 'The PHP OpenSSL extension is not loaded – SSL/TLS is impossible on this server.';
        return $p;
    }

    public static function configured(): bool
    {
        return !self::configProblems();
    }

    public static function fromEmail(): string
    {
        return setting('from_email', self::DEFAULT_FROM_EMAIL);
    }

    public static function fromName(): string
    {
        // White-label sender name for Business / Agency workspaces (the address stays the platform sender for SPF / DKIM)
        if (self::$tenantId) {
            $t = Tenant::current(self::$tenantId);
            if ($t && !empty($t['white_label_from_name']) && Tenant::feature('white_label', self::$tenantId)) return $t['white_label_from_name'];
        }
        return setting('from_name', self::DEFAULT_FROM_NAME);
    }

    public static function replyTo(): string
    {
        $r = setting('reply_to_email', '');
        return valid_email($r) ? $r : self::fromEmail();
    }

    public static function senderDomain(): string
    {
        return strtolower(substr(strrchr(self::fromEmail(), '@') ?: '', 1));
    }

    /* =====================================================================
     * SERVICE STATE (real, persisted – drives every "SMTP connected / failed" indicator)
     * ===================================================================== */

    public static function state(bool $fresh = false): array
    {
        if (self::$state === null || $fresh) {
            try { self::$state = DB::fetch("SELECT * FROM email_service_state WHERE id = 1") ?: []; } catch (Throwable $e) { self::$state = []; }
        }
        return self::$state;
    }

    private static function recordState(array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        try { DB::update('email_service_state', $fields, 'id = 1'); } catch (Throwable $e) { app_log('error', 'email_service_state update failed: ' . $e->getMessage()); }
        self::$state = null;
        Cache::forget('email:status');
    }

    /** Transport success: clears the back-off. */
    private static bool $transitioning = false;

    private static function recordSuccess(string $response = null, bool $check = false, ?int $ms = null, ?string $by = null): void
    {
        $now = date('Y-m-d H:i:s');
        $prev = self::state();
        $f = ['status' => 'connected', 'last_success_at' => $now, 'last_response' => $response ? mb_substr($response, 0, 500) : null, 'consecutive_failures' => 0, 'backoff_until' => null];
        if ($check) $f += ['last_check_at' => $now, 'last_check_ok' => 1, 'last_check_ms' => $ms, 'last_check_by' => $by, 'last_error' => null, 'last_error_kind' => null];
        else $f['last_email_sent_at'] = $now;
        self::recordState($f);
        // failed → connected: ONE recovery notification (never on every successful send)
        if (($prev['status'] ?? '') === 'failed' && !self::$transitioning && class_exists('Notifier')) {
            self::$transitioning = true;
            try { Notifier::smtpRecovered($prev['last_error'] ?? null, $prev['last_failure_at'] ?? null); } catch (Throwable $e) { app_log('warning', 'SMTP recovery notice failed: ' . $e->getMessage()); }
            self::$transitioning = false;
        }
    }

    /** Transport failure: opens the circuit for smtp_backoff_minutes. Rejections of a single message do not. */
    private static function recordFailure(string $kind, string $error, ?string $response = null, bool $check = false, ?int $ms = null, ?string $by = null): void
    {
        $now = date('Y-m-d H:i:s');
        $st = self::state();
        $transport = in_array($kind, self::TRANSPORT_KINDS, true);
        $fails = $transport ? (int) ($st['consecutive_failures'] ?? 0) + 1 : (int) ($st['consecutive_failures'] ?? 0);
        $f = ['last_failure_at' => $now, 'last_error' => mb_substr($error, 0, 1000), 'last_error_kind' => $kind, 'last_response' => $response ? mb_substr($response, 0, 500) : null, 'consecutive_failures' => $fails];
        if ($transport) {
            $f['status'] = 'failed';
            $minutes = max(1, min(60, (int) setting('smtp_backoff_minutes', 5)));
            $f['backoff_until'] = date('Y-m-d H:i:s', time() + min(60, $minutes * min(4, $fails)) * 60);
        }
        if ($check) $f += ['last_check_at' => $now, 'last_check_ok' => 0, 'last_check_ms' => $ms, 'last_check_by' => $by];
        else { $f['last_email_failed_at'] = $now; $f['last_email_error'] = mb_substr($error, 0, 1000); }
        self::recordState($f);
        // connected → failed: ONE in-app alert + alert-history row (repeated failures stay silent until it recovers)
        if ($transport && ($st['status'] ?? '') !== 'failed' && !self::$transitioning && class_exists('Notifier')) {
            self::$transitioning = true;
            try { Notifier::smtpFailed($kind, $error); } catch (Throwable $e) { app_log('warning', 'SMTP failure notice failed: ' . $e->getMessage()); }
            self::$transitioning = false;
        }
    }

    /** Is the transport currently in back-off (recent transport failure)? */
    public static function inBackoff(): bool
    {
        $st = self::state();
        return !empty($st['backoff_until']) && strtotime($st['backoff_until']) > time();
    }

    /**
     * Status summary for dashboards: status connected | failed | incomplete | disabled | unknown, tone, headline,
     * and every timestamp the Super Admin needs. Cached 15 s (cheap anyway – one row + two aggregates).
     */
    public static function status(bool $fresh = false): array
    {
        if ($fresh) Cache::forget('email:status');
        return Cache::remember('email:status', 15, function () {
            $st = self::state(true);
            $c = self::config();
            $problems = self::configProblems($c);
            $q = DB::fetch("SELECT SUM(status = 'pending') AS pending, SUM(status = 'pending' AND attempts > 0) AS retrying, SUM(status = 'failed') AS failed FROM email_queue") ?: [];
            $l = DB::fetch("SELECT SUM(status = 'failed') AS failed_24h, SUM(status = 'sent') AS sent_24h FROM email_logs WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?: [];
            $lastOk = $st['last_success_at'] ?? null; $lastFail = $st['last_failure_at'] ?? null;
            if (!$c['enabled']) { $status = 'disabled'; $tone = 'secondary'; $headline = 'SMTP Disabled'; $detail = 'Email sending is switched off. Alerts are queued but not delivered.'; }
            elseif ($problems) { $status = 'incomplete'; $tone = 'warning'; $headline = 'SMTP Configuration Incomplete'; $detail = implode(' ', $problems); }
            elseif ($lastFail && (!$lastOk || strtotime($lastFail) > strtotime($lastOk))) { $status = 'failed'; $tone = 'danger'; $headline = 'SMTP Connection Failed'; $detail = (self::KINDS[$st['last_error_kind'] ?? 'other'] ?? 'Failed') . ': ' . ($st['last_error'] ?? 'unknown error'); }
            elseif ($lastOk) { $status = 'connected'; $tone = 'success'; $headline = 'SMTP Connected'; $detail = 'Last verified ' . time_ago($lastOk) . ($st['last_response'] ? ' · server said: ' . $st['last_response'] : '') . '.'; }
            else { $status = 'unknown'; $tone = 'secondary'; $headline = 'SMTP Not Tested Yet'; $detail = 'The configuration looks complete but no connection has been made yet. Run Test SMTP to verify it.'; }
            return [
                'status' => $status, 'tone' => $tone, 'headline' => $headline, 'detail' => $detail, 'configured' => !$problems, 'enabled' => $c['enabled'], 'problems' => $problems,
                'host' => $c['host'], 'port' => $c['port'], 'encryption' => strtoupper($c['encryption'] === 'tls' ? 'STARTTLS' : $c['encryption']), 'from' => $c['from_name'] . ' <' . $c['from_email'] . '>',
                'last_check_at' => $st['last_check_at'] ?? null, 'last_check_ok' => isset($st['last_check_ok']) ? (bool) $st['last_check_ok'] : null, 'last_check_ms' => $st['last_check_ms'] ?? null, 'last_check_by' => $st['last_check_by'] ?? null,
                'last_success_at' => $lastOk, 'last_failure_at' => $lastFail, 'last_error' => $st['last_error'] ?? null, 'last_error_kind' => $st['last_error_kind'] ?? null, 'last_error_label' => isset($st['last_error_kind']) ? (self::KINDS[$st['last_error_kind']] ?? 'Failed') : null, 'last_response' => $st['last_response'] ?? null,
                'last_email_sent_at' => $st['last_email_sent_at'] ?? null, 'last_email_failed_at' => $st['last_email_failed_at'] ?? null, 'last_email_error' => $st['last_email_error'] ?? null,
                'backoff_until' => (!empty($st['backoff_until']) && strtotime($st['backoff_until']) > time()) ? $st['backoff_until'] : null, 'consecutive_failures' => (int) ($st['consecutive_failures'] ?? 0),
                'queue' => ['pending' => (int) ($q['pending'] ?? 0), 'retrying' => (int) ($q['retrying'] ?? 0), 'failed' => (int) ($q['failed'] ?? 0)],
                'sent_24h' => (int) ($l['sent_24h'] ?? 0), 'failed_24h' => (int) ($l['failed_24h'] ?? 0), 'checked_at' => date('Y-m-d H:i:s'),
            ];
        });
    }

    /* =====================================================================
     * QUEUE + IMMEDIATE DELIVERY
     *
     * v3.8: queue() writes the durable queue row AND delivers it right away in the same process (one bounded SMTP
     * session). Before, rows waited for the next scheduler tick (alert job every 2 min + cron every 5 min = the
     * "emails take 2+ minutes" complaint) although the SMTP round trip itself takes well under a second.
     * The scheduler's queue processing is now only the safety net for rows that could not be delivered at once
     * (SMTP in back-off, transport failure, "send immediately" switched off).
     * ===================================================================== */

    /** While collect() runs, queued ids are gathered here and delivered together in ONE SMTP session. */
    private static ?array $collecting = null;
    private static array $templateRows = [];

    /** Global switch (Super Admin → SMTP settings): deliver in-process or leave everything to the scheduler. */
    public static function sendImmediately(): bool
    {
        return (bool) setting('email_send_immediately', 1);
    }

    /**
     * Queue an email and deliver it immediately (see above). $opts: template (type – defaults from the category),
     * from_name / from_email / reply_to (explicit sender overrides), defer (true = leave it to the scheduler).
     * Returns the queue id, or 0 when the template is disabled (nothing is sent or stored).
     */
    public static function queue(string $to, string $subject, string $html, ?string $toName = null, string $category = 'general', ?int $refId = null, ?string $cc = null, ?string $bcc = null, array $opts = []): int
    {
        $template = $opts['template'] ?? self::templateForCategory($category);
        $sender = ['from_name' => null, 'from_email' => null, 'reply_to' => null];
        if ($template) {
            $row = self::templateRow($template);
            if ($row && ($row['status'] ?? 'active') === 'disabled') { app_log('info', 'Email to ' . $to . ' not sent – template "' . $template . '" is disabled'); return 0; }
            if ($row) foreach ($sender as $k => $_) if (!empty($row[$k])) $sender[$k] = $row[$k];
        }
        foreach ($sender as $k => $_) if (array_key_exists($k, $opts)) $sender[$k] = $opts[$k] !== '' ? $opts[$k] : null;
        if ($sender['from_email'] !== null && !valid_email($sender['from_email'])) $sender['from_email'] = null;
        if ($sender['reply_to'] !== null && !valid_email($sender['reply_to'])) $sender['reply_to'] = null;
        $id = DB::insert('email_queue', [
            'tenant_id'  => self::$tenantId ?: (Tenant::id() ?: null),
            'to_email'   => $to,
            'to_name'    => $toName,
            'cc'         => $cc ?: null,
            'bcc'        => $bcc ?: null,
            'from_name'  => $sender['from_name'],
            'from_email' => $sender['from_email'],
            'reply_to'   => $sender['reply_to'],
            'subject'    => mb_substr($subject, 0, 250),
            'body'       => $html,
            'category'   => $category,
            'template'   => $template,
            'ref_id'     => $refId,
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if (self::$collecting !== null) { self::$collecting[] = $id; return $id; }
        if (empty($opts['defer'])) self::deliverNow([$id]);
        return $id;
    }

    /**
     * Run $fn (which queues one or more messages) and deliver everything it queued in one SMTP session afterwards.
     * Used by Notifier: an alert to five recipients = one connection, one authentication, five messages.
     */
    public static function collect(callable $fn): array
    {
        if (self::$collecting !== null) { $fn(); return ['sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 'nested']; }
        self::$collecting = [];
        try { $fn(); } finally { $ids = self::$collecting; self::$collecting = null; }
        return $ids ? self::deliverNow($ids) : ['sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => null];
    }

    /**
     * Deliver specific queue rows now. Bounded (20 s in web requests), breaker-aware, never throws. Rows that cannot be
     * delivered stay pending with next_attempt_at set – the scheduler retries them.
     * @return array{sent:int, failed:int, deferred:int, skipped:?string}
     */
    public static function deliverNow(array $ids, bool $force = false): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $out = ['sent' => 0, 'failed' => 0, 'deferred' => count($ids), 'skipped' => null];
        if (!$ids) return $out;
        if (!$force && !self::sendImmediately()) { $out['skipped'] = 'deferred'; return $out; }
        if (!self::configured()) { $out['skipped'] = 'not_configured'; return $out; }
        if (!$force && self::inBackoff()) { $out['skipped'] = 'backoff'; return $out; }
        try {
            $rows = DB::fetchAll("SELECT * FROM email_queue WHERE id IN (" . implode(',', $ids) . ") AND status = 'pending' ORDER BY id ASC");
            return self::deliverRows($rows, IS_CLI ? 0 : 20);
        } catch (Throwable $e) {
            app_log('error', 'Immediate email delivery failed: ' . $e->getMessage());
            $out['skipped'] = 'error';
            return $out;
        }
    }

    /**
     * Send pending queue items that are due (safety net run by the scheduler / "Process queue now"). Bounded by a time
     * budget (web requests: 12 s by default, CLI: unlimited) and by the circuit breaker: while the transport is in
     * back-off nothing is attempted (unless $force), and a batch stops at the first transport failure – the remaining
     * rows are retried with exponential back-off.
     * @return array{sent:int, failed:int, deferred:int, skipped:?string}
     */
    public static function processQueue(int $limit = 50, int $budgetSeconds = 0, bool $force = false): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => null];
        if (!self::configured()) { $out['skipped'] = 'not_configured'; return $out; }
        if (!$force && self::inBackoff()) { $out['skipped'] = 'backoff'; return $out; }
        $budget = $budgetSeconds > 0 ? $budgetSeconds : (IS_CLI ? 0 : 12);
        $rows = DB::fetchAll("SELECT * FROM email_queue WHERE status = 'pending' AND attempts < " . self::MAX_ATTEMPTS . " AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id ASC LIMIT " . (int) $limit);
        if (!$rows) return $out;
        return self::deliverRows($rows, $budget);
    }

    /** Shared delivery loop: one SMTP session (SMTPKeepAlive) for the whole batch, per-row bookkeeping, breaker stop. */
    private static function deliverRows(array $rows, int $budget): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => null];
        if (!$rows) return $out;
        $t0 = microtime(true);
        $prevTenant = self::$tenantId;
        $shared = self::mailer();
        foreach ($rows as $i => $row) {
            if ($budget && $i > 0 && microtime(true) - $t0 > $budget) { $out['deferred'] += count($rows) - $i; $out['skipped'] = 'budget'; break; }
            self::$tenantId = !empty($row['tenant_id']) ? (int) $row['tenant_id'] : null;
            $attempt = (int) $row['attempts'] + 1;
            $r = self::send($row['to_email'], $row['subject'], $row['body'], $row['to_name'], $row['category'], $row['cc'], $row['bcc'], $row['ref_id'] ? (int) $row['ref_id'] : null, $shared,
                ['queue_id' => (int) $row['id'], 'attempt' => $attempt, 'template' => $row['template'] ?? null, 'from_name' => $row['from_name'] ?? null, 'from_email' => $row['from_email'] ?? null, 'reply_to' => $row['reply_to'] ?? null]);
            $final = !$r['ok'] && ($attempt >= self::MAX_ATTEMPTS || $r['kind'] === 'rejected' || $r['kind'] === 'config');
            DB::update('email_queue', [
                'status'          => $r['ok'] ? 'sent' : ($final ? 'failed' : 'pending'),
                'attempts'        => $attempt,
                // retry after 1 min, then 2, then 4 – the scheduler picks the row up on its next run
                'next_attempt_at' => $r['ok'] || $final ? null : date('Y-m-d H:i:s', time() + 60 * (2 ** ($attempt - 1))),
                'last_error'      => $r['error'] ? mb_substr($r['error'], 0, 1000) : null,
                'sent_at'         => $r['ok'] ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$row['id']]);
            $r['ok'] ? $out['sent']++ : $out['failed']++;
            if (!$r['ok'] && in_array($r['kind'], self::TRANSPORT_KINDS, true)) { $out['deferred'] += count($rows) - $i - 1; $out['skipped'] = 'transport_failure'; break; } // circuit open – stop hammering the server
        }
        self::$tenantId = $prevTenant;
        try { $shared->smtpClose(); } catch (Throwable $ignored) {}
        return $out;
    }

    /* =====================================================================
     * SEND (PHPMailer / SMTP)
     * ===================================================================== */

    /**
     * Send immediately through authenticated SMTP and log the result. Never throws; never hangs longer than the
     * configured timeouts. $meta: queue_id, attempt (for the log).
     * @return array{ok:bool, error:?string, kind:?string, message_id:?string, smtp_response:?string, ms:int}
     */
    public static function send(string $to, string $subject, string $html, ?string $toName = null, string $category = 'general', ?string $cc = null, ?string $bcc = null, ?int $refId = null, ?PHPMailer $shared = null, array $meta = []): array
    {
        $ok = false; $error = null; $kind = null; $messageId = null; $smtpResponse = null;
        $t0 = microtime(true);
        $problems = self::configProblems();
        if ($problems) {
            $error = 'SMTP is not configured: ' . implode(' ', $problems); $kind = 'config';
        } elseif (!valid_email($to)) {
            $error = 'Recipient address "' . $to . '" is invalid.'; $kind = 'rejected';
        } else {
            self::guardTimeLimit();
            $customSender = valid_email($meta['from_email'] ?? '') || (($meta['from_name'] ?? '') !== '') || valid_email($meta['reply_to'] ?? '');
            try {
                $mail = $shared ?: self::mailer();
                $mail->clearAllRecipients();
                $mail->clearCustomHeaders();
                $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
                $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
                if ($customSender) self::applySender($mail, $meta['from_email'] ?? null, $meta['from_name'] ?? null, $meta['reply_to'] ?? null);
                $mail->addAddress($to, $toName ?? '');
                foreach (self::splitAddresses($cc) as $a) $mail->addCC($a);
                foreach (self::splitAddresses($bcc) as $a) $mail->addBCC($a);
                $mail->Subject = $subject;
                $mail->isHTML(true);
                $mail->Body = $html;
                $mail->AltBody = self::toText($html);
                $ok = $mail->send();
                $messageId = $mail->getLastMessageID() ?: null;
                $smtpResponse = trim((string) $mail->getSMTPInstance()->getLastReply()) ?: null;
                if (!$ok) $error = $mail->ErrorInfo ?: 'Unknown PHPMailer error';
            } catch (Throwable $e) {
                $error = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
                if (isset($mail)) { try { $smtpResponse = trim((string) $mail->getSMTPInstance()->getLastReply()) ?: null; } catch (Throwable $ignored) {} }
            }
            if (isset($mail) && !$shared) { try { $mail->smtpClose(); } catch (Throwable $ignored) {} }
            elseif (isset($mail) && $customSender) { try { self::applySender($mail, null, null, null); } catch (Throwable $ignored) {} } // restore the platform sender for the next message of the shared session
            if (!$ok) { $error = self::cleanError((string) $error); $kind = self::classify($error, $smtpResponse); }
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $error = $error ? self::scrub($error) : null;
        try {
            DB::insert('email_logs', [
                'tenant_id'     => self::$tenantId ?: (Tenant::id() ?: null),
                'to_email'      => $to,
                'cc'            => $cc ?: null,
                'bcc'           => $bcc ?: null,
                'from_email'    => valid_email($meta['from_email'] ?? '') ? $meta['from_email'] : self::fromEmail(),
                'subject'       => mb_substr($subject, 0, 250),
                'category'      => $category,
                'template'      => $meta['template'] ?? self::templateForCategory($category),
                'status'        => $ok ? 'sent' : 'failed',
                'message_id'    => $messageId ? mb_substr($messageId, 0, 190) : null,
                'smtp_response' => $smtpResponse ? mb_substr($smtpResponse, 0, 500) : null,
                'ref_id'        => $refId,
                'error'         => $error ? mb_substr($error, 0, 1000) : null,
                'error_kind'    => $kind,
                'duration_ms'   => $ms,
                'queue_id'      => $meta['queue_id'] ?? null,
                'attempt'       => (int) ($meta['attempt'] ?? 1),
                'sent_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            app_log('error', 'email_logs insert failed: ' . $e->getMessage());
        }
        if ($ok) self::recordSuccess($smtpResponse);
        elseif ($kind !== 'config') self::recordFailure($kind ?? 'other', $error, $smtpResponse);
        if (!$ok) app_log('warning', 'Email failed to ' . $to . ' [' . $kind . ', ' . $ms . ' ms]: ' . $error);
        return ['ok' => $ok, 'error' => $error, 'kind' => $kind, 'message_id' => $messageId, 'smtp_response' => $smtpResponse, 'ms' => $ms];
    }

    /** Build a configured PHPMailer instance with hard connect / command timeouts. */
    private static function mailer(array $override = []): PHPMailer
    {
        $c = self::config($override);
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $c['host'];
        $mail->Port = $c['port'];
        $mail->SMTPAuth = $c['username'] !== '';
        $mail->Username = $c['username'];
        $mail->Password = $c['password'];
        $mail->SMTPSecure = $c['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($c['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->SMTPAutoTLS = $c['encryption'] !== 'none'; // "None" really means no TLS – never upgrade silently to a failing STARTTLS
        $mail->Timeout = $c['connect_timeout'];           // TCP connect
        $mail->SMTPOptions = self::streamOptions($c);
        // Per-command reply limit (PHPMailer default is 300 s – the reason "SMTP test takes forever" on a stalled server)
        $smtp = $mail->getSMTPInstance();
        $smtp->Timelimit = $c['timeout'];
        $smtp->Timeout = $c['connect_timeout'];
        $mail->SMTPKeepAlive = true; // keep the session open so the server's "250 ... queued" reply can be logged; closed explicitly after send
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = company_name() . ' CRM';
        // Consistent, authenticated sender identity – never the client's address (SPF/DKIM/DMARC alignment)
        $mail->setFrom($c['from_email'], self::fromName(), false);
        $mail->Sender = $c['from_email']; // envelope sender / Return-Path
        $mail->addReplyTo(valid_email($c['reply_to']) ? $c['reply_to'] : $c['from_email'], self::fromName());
        // Message-ID on the sender's domain (not the server hostname) – expected by spam filters
        $domain = strtolower(substr(strrchr($c['from_email'], '@') ?: '', 1));
        if ($domain) $mail->Hostname = $domain;
        // Optional DKIM signing from the CRM itself (when the SMTP provider does not sign)
        $dkimKey = decrypt_value(setting('dkim_private_key', ''));
        if ($dkimKey && setting('dkim_selector') && setting('dkim_domain')) {
            $mail->DKIM_domain = setting('dkim_domain');
            $mail->DKIM_selector = setting('dkim_selector');
            $mail->DKIM_private_string = $dkimKey;
            $mail->DKIM_identity = $c['from_email'];
            $mail->DKIM_passphrase = '';
        }
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        return $mail;
    }

    /**
     * Per-message sender (template "From name / From email / Reply-To"). Null = platform default. The envelope sender
     * (Return-Path) always stays the authenticated SMTP identity so SPF keeps passing; a From address on another domain
     * may still fail DMARC at the recipient – the template editor warns about that.
     */
    private static function applySender(PHPMailer $mail, ?string $from, ?string $name, ?string $reply): void
    {
        $c = self::config();
        $from = valid_email($from ?? '') ? $from : $c['from_email'];
        $name = ($name ?? '') !== '' ? $name : self::fromName();
        $reply = valid_email($reply ?? '') ? $reply : (valid_email($c['reply_to']) ? $c['reply_to'] : $from);
        $mail->setFrom($from, $name, false);
        $mail->Sender = $c['from_email'];
        $mail->clearReplyTos();
        $mail->addReplyTo($reply, $name);
    }

    private static function streamOptions(array $c): array
    {
        if ($c['verify_peer']) return [];
        return ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    }

    /** Make sure PHP's own time limit cannot kill us in the middle of an SMTP conversation (that produces no JSON, no log). */
    private static function guardTimeLimit(): void
    {
        $c = self::config();
        $need = $c['connect_timeout'] * 2 + $c['timeout'] * 4 + 15;
        $max = (int) ini_get('max_execution_time');
        if ($max !== 0 && $max < $need) @set_time_limit($need);
    }

    /* =====================================================================
     * DIAGNOSTICS – "Test SMTP"
     * ===================================================================== */

    /**
     * Full, timed, step-by-step SMTP check with a real test email. Never hangs: every stage is bounded by the
     * configured timeouts and the whole run is capped. $override = unsaved form values (password: blank = saved one).
     * @return array{ok:bool, headline:string, reason:?string, suggestion:?string, kind:?string, steps:array, ms:int, message_id:?string, smtp_response:?string, environment:array, config:array}
     */
    public static function diagnose(string $to, array $override = [], bool $sendMail = true): array
    {
        $t0 = microtime(true);
        $c = self::config($override);
        $by = (!IS_CLI && class_exists('Auth') && Auth::check()) ? (Auth::user()['email'] ?? 'admin') : 'cli';
        $steps = [];
        $step = function (string $key, string $label) use (&$steps) { $steps[$key] = ['key' => $key, 'label' => $label, 'status' => 'pending', 'ms' => 0, 'detail' => '', 'response' => null]; return microtime(true); };
        $done = function (string $key, float $t, string $status, string $detail = '', ?string $response = null) use (&$steps) { $steps[$key]['status'] = $status; $steps[$key]['ms'] = (int) round((microtime(true) - $t) * 1000); $steps[$key]['detail'] = $detail; $steps[$key]['response'] = $response !== null && $response !== '' ? mb_substr(trim($response), 0, 300) : null; };
        $result = ['ok' => false, 'headline' => 'SMTP Connection Failed', 'reason' => null, 'suggestion' => null, 'kind' => null, 'steps' => &$steps, 'ms' => 0, 'message_id' => null, 'smtp_response' => null, 'environment' => self::environment($c), 'config' => ['host' => $c['host'], 'port' => $c['port'], 'encryption' => $c['encryption'], 'username' => $c['username'], 'from' => $c['from_email'], 'connect_timeout' => $c['connect_timeout'], 'timeout' => $c['timeout'], 'verify_peer' => $c['verify_peer']]];
        $fail = function (string $kind, string $reason, ?string $response = null) use (&$result, $c, &$steps, $t0, $by, $to) {
            $reason = self::scrub($reason);
            $result['kind'] = $kind; $result['reason'] = $reason; $result['suggestion'] = self::suggestion($kind, $c, $reason); $result['smtp_response'] = $response;
            $result['headline'] = ['config' => 'SMTP Configuration Incomplete', 'auth' => 'SMTP Authentication Failed', 'timeout' => 'SMTP Connection Timed Out', 'dns' => 'SMTP Host Not Found', 'tls' => 'SMTP TLS/SSL Failed', 'rejected' => 'Test Email Rejected'][$kind] ?? 'SMTP Connection Failed';
            $result['ms'] = (int) round((microtime(true) - $t0) * 1000);
            foreach ($steps as &$s) if ($s['status'] === 'pending') $s['status'] = 'skipped';
            unset($s);
            if ($kind !== 'config') self::recordFailure($kind, $reason, $response, true, $result['ms'], $by);
            else self::recordState(['status' => 'incomplete', 'last_check_at' => date('Y-m-d H:i:s'), 'last_check_ok' => 0, 'last_check_ms' => $result['ms'], 'last_check_by' => $by, 'last_error' => mb_substr($reason, 0, 1000), 'last_error_kind' => 'config']);
            try { DB::insert('email_logs', ['to_email' => $to, 'from_email' => $c['from_email'], 'subject' => 'SMTP test', 'category' => 'test', 'status' => 'failed', 'error' => mb_substr($reason, 0, 1000), 'error_kind' => $kind, 'smtp_response' => $response ? mb_substr($response, 0, 500) : null, 'duration_ms' => $result['ms'], 'sent_at' => date('Y-m-d H:i:s')]); } catch (Throwable $ignored) {}
            return $result;
        };

        // 1. configuration
        $t = $step('config', 'Configuration');
        $problems = self::configProblems($c);
        if (!valid_email($to)) $problems[] = 'The test recipient "' . $to . '" is not a valid email address.';
        if ($problems) { $done('config', $t, 'failed', implode(' ', $problems)); return $fail('config', implode(' ', $problems)); }
        $done('config', $t, 'ok', $c['host'] . ':' . $c['port'] . ' · ' . strtoupper($c['encryption'] === 'tls' ? 'STARTTLS' : $c['encryption']) . ($c['username'] !== '' ? ' · auth as ' . $c['username'] : ' · no authentication') . ' · from ' . $c['from_email']);
        self::guardTimeLimit();

        // 2. DNS
        $t = $step('dns', 'DNS resolution');
        $ip = $c['host'];
        if (!filter_var($c['host'], FILTER_VALIDATE_IP)) {
            $ip = gethostbyname($c['host']);
            if ($ip === $c['host'] || !filter_var($ip, FILTER_VALIDATE_IP)) { $done('dns', $t, 'failed', 'The hostname "' . $c['host'] . '" could not be resolved from this server.'); return $fail('dns', 'The SMTP host "' . $c['host'] . '" could not be resolved (DNS lookup failed on this server).'); }
        }
        $done('dns', $t, 'ok', $c['host'] . ' → ' . $ip);

        // 3. TCP connect (plain socket, bounded by connect_timeout – tells "port blocked / refused" apart from TLS problems)
        $t = $step('tcp', 'TCP connection to port ' . $c['port']);
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client('tcp://' . $ip . ':' . $c['port'], $errno, $errstr, $c['connect_timeout'], STREAM_CLIENT_CONNECT);
        if (!$sock) {
            $msg = trim($errstr) ?: 'connection failed';
            $kind = ($errno === 110 || $errno === 10060 || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) ? 'timeout' : 'connection';
            $done('tcp', $t, 'failed', ($kind === 'timeout' ? 'No answer within ' . $c['connect_timeout'] . ' s' : 'Connection refused / unreachable') . ' (' . $msg . ', errno ' . $errno . ')');
            return $fail($kind, $kind === 'timeout' ? 'Connection to ' . $c['host'] . ':' . $c['port'] . ' timed out after ' . $c['connect_timeout'] . ' seconds.' : 'Could not connect to ' . $c['host'] . ':' . $c['port'] . ' – ' . $msg . ' (errno ' . $errno . ').');
        }
        fclose($sock);
        $done('tcp', $t, 'ok', 'Port ' . $c['port'] . ' on ' . $ip . ' accepts connections');

        // 4. SMTP session: connect (+ implicit TLS), banner, EHLO, STARTTLS, AUTH
        $smtp = new SMTP();
        $smtp->Timeout = $c['connect_timeout'];
        $smtp->Timelimit = min($c['timeout'], 15); // a stalled server must not keep the admin waiting
        $smtp->do_debug = SMTP::DEBUG_OFF;
        $prefix = $c['encryption'] === 'ssl' ? 'ssl://' : '';
        $t = $step('greeting', $c['encryption'] === 'ssl' ? 'SSL handshake & server greeting' : 'Server greeting (banner)');
        $connected = false;
        try { $connected = $smtp->connect($prefix . $c['host'], $c['port'], $c['connect_timeout'], self::streamOptions($c)); } catch (Throwable $e) { $connected = false; $smtpErr = $e->getMessage(); }
        if (!$connected) {
            $banner = trim((string) $smtp->getLastReply());
            if (preg_match('~^\* OK|IMAP4|^\+OK|POP3|^HTTP/~i', $banner)) { $proto = preg_match('~IMAP|^\* OK~i', $banner) ? 'IMAP' : (preg_match('~POP3|^\+OK~i', $banner) ? 'POP3' : 'HTTP'); $done('greeting', $t, 'failed', 'The server on port ' . $c['port'] . ' speaks ' . $proto . ', not SMTP', $banner); return $fail('connection', 'Port ' . $c['port'] . ' on ' . $c['host'] . ' is a ' . $proto . ' server (used for receiving mail), not an SMTP server. Use port 465 with SSL or 587 with STARTTLS.', $banner); }
            $err = $smtp->getError(); $msg = self::cleanError(trim(($err['error'] ?? '') . ' ' . ($err['detail'] ?? '') . ' ' . ($err['smtp_code'] ?? '') . ' ' . ($smtpErr ?? '')));
            $kind = $c['encryption'] === 'ssl' ? 'tls' : self::classify($msg, null);
            if ($kind === 'other' || $kind === 'connection') $kind = $c['encryption'] === 'ssl' ? 'tls' : 'timeout';
            $done('greeting', $t, 'failed', $msg ?: 'No SMTP greeting received within ' . $c['timeout'] . ' s', $smtp->getLastReply());
            return $fail($kind, $kind === 'tls' ? 'The SSL handshake with ' . $c['host'] . ':' . $c['port'] . ' failed: ' . ($msg ?: 'no TLS on this port') : 'The server accepted the connection but sent no SMTP greeting within ' . $c['timeout'] . ' seconds' . ($msg ? ' (' . $msg . ')' : '') . '.', $smtp->getLastReply());
        }
        $done('greeting', $t, 'ok', 'Server greeting received', $smtp->getLastReply());

        $hello = $c['from_email'] && str_contains($c['from_email'], '@') ? substr(strrchr($c['from_email'], '@'), 1) : (gethostname() ?: 'localhost');
        $t = $step('ehlo', 'EHLO handshake');
        if (!$smtp->hello($hello)) { $err = $smtp->getError(); $msg = self::cleanError(($err['error'] ?? 'EHLO rejected') . ' ' . ($err['detail'] ?? '')); $done('ehlo', $t, 'failed', $msg, $smtp->getLastReply()); $smtp->close(); return $fail('other', 'The server rejected the EHLO/HELO handshake: ' . $msg, $smtp->getLastReply()); }
        $caps = $smtp->getServerExtList() ?: [];
        $done('ehlo', $t, 'ok', 'Server capabilities: ' . (implode(', ', array_keys($caps)) ?: 'none advertised'), $smtp->getLastReply());

        if ($c['encryption'] === 'tls') {
            $t = $step('starttls', 'STARTTLS encryption');
            if (!isset($caps['STARTTLS'])) { $done('starttls', $t, 'failed', 'The server does not offer STARTTLS on port ' . $c['port']); $smtp->quit(); return $fail('tls', 'STARTTLS was requested but ' . $c['host'] . ':' . $c['port'] . ' does not offer it.', $smtp->getLastReply()); }
            $tlsOk = false;
            try { $tlsOk = $smtp->startTLS(); } catch (Throwable $e) { $tlsErr = $e->getMessage(); }
            if (!$tlsOk) { $err = $smtp->getError(); $msg = self::cleanError(trim(($err['error'] ?? '') . ' ' . ($err['detail'] ?? '') . ' ' . ($tlsErr ?? ''))); $done('starttls', $t, 'failed', $msg ?: 'TLS negotiation failed', $smtp->getLastReply()); $smtp->close(); return $fail('tls', 'STARTTLS negotiation with ' . $c['host'] . ' failed: ' . ($msg ?: 'handshake error'), $smtp->getLastReply()); }
            $smtp->hello($hello); $caps = $smtp->getServerExtList() ?: [];
            $done('starttls', $t, 'ok', 'Connection upgraded to TLS', $smtp->getLastReply());
        } elseif ($c['encryption'] === 'none') {
            $step('starttls', 'Encryption'); $done('starttls', microtime(true), 'skipped', isset($caps['STARTTLS']) ? 'Not used (server offers STARTTLS – consider enabling it)' : 'Not used (plain connection)');
        }

        $t = $step('auth', 'Authentication');
        if ($c['username'] === '') { $done('auth', $t, 'skipped', 'No username configured – sending without authentication'); }
        else {
            if (!isset($caps['AUTH'])) { $done('auth', $t, 'failed', 'The server does not offer AUTH here' . ($c['encryption'] === 'none' ? ' – most servers require TLS before AUTH' : '')); $smtp->quit(); return $fail('auth', 'The server does not accept authentication on this connection' . ($c['encryption'] === 'none' ? ' (enable STARTTLS or SSL)' : '') . '.', $smtp->getLastReply()); }
            $authOk = false;
            try { $authOk = $smtp->authenticate($c['username'], $c['password']); } catch (Throwable $e) { $authErr = $e->getMessage(); }
            if (!$authOk) { $err = $smtp->getError(); $msg = self::cleanError(trim(($err['error'] ?? '') . ' ' . ($err['detail'] ?? '') . ' ' . ($err['smtp_code'] ?? '') . ' ' . ($err['smtp_code_ex'] ?? '') . ' ' . ($authErr ?? ''))); $done('auth', $t, 'failed', $msg ?: 'Credentials rejected', $smtp->getLastReply()); $smtp->quit(); return $fail('auth', 'The SMTP server rejected the username / password: ' . ($msg ?: 'authentication failed'), $smtp->getLastReply()); }
            $done('auth', $t, 'ok', 'Authenticated as ' . $c['username'] . ' (' . implode('/', array_intersect(['CRAM-MD5', 'LOGIN', 'PLAIN', 'XOAUTH2'], (array) ($caps['AUTH'] ?? []))) . ')', $smtp->getLastReply());
        }

        // 5. real test email over the same session
        $t = $step('send', $sendMail ? 'Send test email to ' . $to : 'Send test email');
        if (!$sendMail) { $done('send', $t, 'skipped', 'Connection-only check'); }
        else {
            try {
                $mail = self::mailer($override);
                $mail->setSMTPInstance($smtp); // reuse the verified session
                $mail->addAddress($to);
                $mail->Subject = 'SMTP test – ' . setting('platform_name', company_name());
                $mail->isHTML(true);
                $mail->Body = self::template('SMTP test successful', 'This is a test email from ' . setting('platform_name', company_name()) . '. Connection, encryption, authentication and sending all worked from the server.', [
                    'SMTP host' => $c['host'] . ':' . $c['port'], 'Encryption' => strtoupper($c['encryption'] === 'tls' ? 'STARTTLS' : $c['encryption']), 'Authenticated as' => $c['username'] ?: '— (no auth)',
                    'From' => self::fromName() . ' <' . $c['from_email'] . '>', 'Reply-To' => valid_email($c['reply_to']) ? $c['reply_to'] : $c['from_email'], 'Sent by' => $by, 'Server' => gethostname() ?: php_uname('n'), 'Time' => date('d-M-Y h:i:s A'),
                ], '', url('platform/emails.php'), '#198754');
                $mail->AltBody = self::toText($mail->Body);
                $mail->send();
                $result['message_id'] = $mail->getLastMessageID() ?: null;
                $result['smtp_response'] = trim((string) $smtp->getLastReply()) ?: null;
                $done('send', $t, 'ok', 'Accepted for delivery' . ($result['message_id'] ? ' · Message-ID ' . $result['message_id'] : ''), $result['smtp_response']);
            } catch (Throwable $e) {
                $msg = self::cleanError(isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage());
                $done('send', $t, 'failed', $msg, $smtp->getLastReply());
                try { $smtp->close(); } catch (Throwable $ignored) {}
                return $fail(self::classify($msg, $smtp->getLastReply()) === 'auth' ? 'auth' : 'rejected', 'The server refused the test message: ' . $msg, $smtp->getLastReply());
            }
        }
        $t = $step('quit', 'Close session');
        try { $smtp->quit(); } catch (Throwable $ignored) {} try { $smtp->close(); } catch (Throwable $ignored) {}
        $done('quit', $t, 'ok', 'QUIT');

        $result['ok'] = true;
        $result['headline'] = 'SMTP Connected Successfully';
        $result['reason'] = $sendMail ? 'Test email sent to ' . $to . '.' : 'Connection and authentication verified.';
        $result['ms'] = (int) round((microtime(true) - $t0) * 1000);
        self::recordSuccess($result['smtp_response'], true, $result['ms'], $by);
        if ($sendMail) { try { DB::insert('email_logs', ['to_email' => $to, 'from_email' => $c['from_email'], 'subject' => 'SMTP test – ' . setting('platform_name', company_name()), 'category' => 'test', 'status' => 'sent', 'message_id' => $result['message_id'], 'smtp_response' => $result['smtp_response'] ? mb_substr($result['smtp_response'], 0, 500) : null, 'duration_ms' => $result['ms'], 'sent_at' => date('Y-m-d H:i:s')]); } catch (Throwable $ignored) {} }
        return $result;
    }

    /** Backward-compatible wrapper (older callers). */
    public static function testSmtp(string $to): array
    {
        $d = self::diagnose($to);
        return ['connection' => in_array(($d['steps']['greeting']['status'] ?? ''), ['ok'], true) ? 'SUCCESS' : 'FAILED', 'authentication' => ($d['steps']['auth']['status'] ?? '') === 'ok' ? 'SUCCESS' : (($d['steps']['auth']['status'] ?? '') === 'skipped' ? 'SKIPPED' : 'FAILED'), 'sent' => $d['ok'] ? 'SUCCESS' : 'FAILED', 'ok' => $d['ok'], 'reason' => $d['ok'] ? null : $d['reason'], 'message_id' => $d['message_id']];
    }

    /** Server-side facts that decide whether SMTP can work at all (shown in the diagnostics panel). */
    public static function environment(array $c = null): array
    {
        $c = $c ?? self::config();
        $max = (int) ini_get('max_execution_time');
        return [
            'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY,
            'openssl' => extension_loaded('openssl') ? (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'loaded') : 'MISSING',
            'stream_socket_client' => function_exists('stream_socket_client'), 'fsockopen' => function_exists('fsockopen'),
            'max_execution_time' => $max, 'time_limit_ok' => $max === 0 || $max >= $c['connect_timeout'] * 2 + $c['timeout'] * 4 + 15 || !in_array('set_time_limit', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
            'default_socket_timeout' => (int) ini_get('default_socket_timeout'), 'sender_domain' => self::senderDomain(), 'server' => gethostname() ?: php_uname('n'),
        ];
    }

    /** DNS records that affect deliverability of the sender domain (best effort, cached 10 min – DNS lookups can be slow on shared hosts). */
    public static function senderDns(): array
    {
        $domain = self::senderDomain();
        if ($domain === '') return ['domain' => '', 'mx' => false, 'spf' => null, 'dmarc' => null];
        return Cache::remember('email:dns:' . $domain, 600, function () use ($domain) {
            $spf = null; $dmarc = null; $mx = false;
            try { foreach ((array) @dns_get_record($domain, DNS_TXT) as $r) { $txt = $r['txt'] ?? ''; if (stripos($txt, 'v=spf1') === 0) $spf = $txt; } } catch (Throwable $e) {}
            try { foreach ((array) @dns_get_record('_dmarc.' . $domain, DNS_TXT) as $r) { $txt = $r['txt'] ?? ''; if (stripos($txt, 'v=DMARC1') === 0) $dmarc = $txt; } } catch (Throwable $e) {}
            try { $mx = (bool) @dns_get_record($domain, DNS_MX); } catch (Throwable $e) {}
            return ['domain' => $domain, 'mx' => $mx, 'spf' => $spf, 'dmarc' => $dmarc];
        });
    }

    /** Map an error message / server reply to an error kind. */
    public static function classify(string $msg, ?string $reply = null): string
    {
        $l = strtolower($msg . ' ' . (string) $reply);
        if (preg_match('~could not authenticate|authenticat|username and password|invalid login|invalid credentials|bad credentials|\b535\b|5\.7\.8|5\.7\.0|password not accepted|login failed~', $l)) return 'auth';
        if (preg_match('~timed? ?out|timeout|select timed|no answer|did not respond~', $l)) return 'timeout';
        if (preg_match('~getaddrinfo|could not resolve|name or service not known|no such host|nodename nor servname|dns|host not found~', $l)) return 'dns';
        if (preg_match('~ssl|tls|certificate|handshake|crypto|starttls~', $l)) return 'tls';
        if (preg_match('~refused|failed to connect|could not connect|unreachable|10061|10065|no route|connection reset|broken pipe|not connected|connection closed~', $l)) return 'connection';
        if (preg_match('~recipient|mailbox|relay|\b55[0-4]\b|\b45[0-2]\b|rejected|spam|blocked|blacklist|sender address|data not accepted|quota|rate limit|too many~', $l)) return 'rejected';
        return 'other';
    }

    /** Human advice per error kind – shown under the failure message. */
    public static function suggestion(string $kind, array $c, string $reason = ''): string
    {
        switch ($kind) {
            case 'config': return 'Complete the SMTP settings (host, port, encryption, username, password, from email) and save them before testing.';
            case 'dns': return 'Check the spelling of the SMTP host. If it is correct, the server\'s DNS resolver cannot reach it – contact the hosting provider.';
            case 'timeout': return 'Nothing answered on ' . $c['host'] . ':' . $c['port'] . '. On shared hosting outbound SMTP ports (25, 465, 587) are often blocked by a firewall – ask the host to open the port, use the provider\'s local mail server (usually "localhost" / mail.yourdomain.com) or a transactional email service. Also check that the port matches the encryption (465 = SSL, 587 = STARTTLS).';
            case 'connection': return 'The port is closed or the service refused the connection. Verify host and port, and make sure the hosting firewall allows outbound connections to ' . $c['port'] . '.';
            case 'tls': return $c['encryption'] === 'ssl' ? 'Port ' . $c['port'] . ' does not speak SSL. Use SSL with port 465, or STARTTLS with port 587. If the server uses a self-signed certificate, disable "Verify server certificate" in the SMTP settings (less secure).' : 'STARTTLS failed. Use STARTTLS with port 587 or SSL with port 465; on a self-signed certificate disable "Verify server certificate".';
            case 'auth': return 'The username / password were rejected. Re-enter the password (it is stored encrypted and cannot be displayed). Gmail / Google Workspace require an App Password; Office 365 requires SMTP AUTH to be enabled for the mailbox; some providers expect the full email address as the username.';
            case 'rejected': return 'The server accepted the connection but refused the message. Check that the From address ' . $c['from_email'] . ' belongs to the authenticated account / domain (most providers block other senders), that the recipient is valid, and that the account is not rate limited.';
            default: return 'Verify host, port, encryption, username, password and the server firewall. The exact server response is shown above.';
        }
    }

    /** Strip PHPMailer's verbose duplication ("SMTP Error: Could not connect... SMTP server error: Failed to connect...") down to the useful part. */
    private static function cleanError(string $msg): string
    {
        $msg = preg_replace('~\s+~', ' ', trim($msg));
        $msg = preg_replace('~SMTP server error: Failed to connect to server SMTP code: ~', 'errno ', $msg);
        $msg = str_replace('Failed to connect to serverSMTP', 'Failed to connect to server. SMTP', $msg);
        $msg = preg_replace('~ Additional SMTP info: ~', ' – ', $msg);
        if (preg_match('~^Failed to connect to server\s*0?$~', $msg)) $msg = 'the server did not complete the SSL handshake (it does not speak SSL on this port)';
        return $msg;
    }
    /* =====================================================================
     * TEMPLATES
     * ===================================================================== */

    /** Template definitions with defaults. Body is plain text with {{variables}}; line breaks are preserved. */
    public static function templateDefinitions(): array
    {
        $sig = "\n\nRegards,\n{{company_name}} Client Care\n{{sender_email}}";
        return [
            'website_down' => ['name' => 'Website Down', 'vars' => ['client_name', 'website_name', 'website_url', 'status', 'error_reason', 'error_details', 'http_status', 'response_time', 'detected_at', 'last_successful_check', 'failed_checks', 'recommended_action'],
                'subject' => 'Website Down Alert – {{website_name}}',
                'body' => "Hello {{client_name}},\n\nWe detected an issue with your website.\n\nWebsite:\n{{website_name}}\n{{website_url}}\n\nStatus:\n{{status}}\n\nReason:\n{{error_reason}}\n\nDetails:\n{{error_details}}\n\nHTTP Status:\n{{http_status}}\n\nDetected At:\n{{detected_at}}\n\nLast Successful Check:\n{{last_successful_check}}\n\nFailed Checks:\n{{failed_checks}}\n\nRecommended Action:\n{{recommended_action}}\n\nOur team has been notified and will check the issue." . $sig],
            'website_recovered' => ['name' => 'Website Recovered', 'vars' => ['client_name', 'website_name', 'website_url', 'status', 'down_since', 'recovered_at', 'total_downtime', 'failed_checks', 'error_reason'],
                'subject' => 'Website Recovered – {{website_name}}',
                'body' => "Hello {{client_name}},\n\nGood news – your website is back online.\n\nWebsite:\n{{website_name}}\n{{website_url}}\n\nStatus:\nRECOVERED\n\nDown Since:\n{{down_since}}\n\nRecovered At:\n{{recovered_at}}\n\nTotal Downtime:\n{{total_downtime}}\n\nProblem Was:\n{{error_reason}}" . $sig],
            'form_failed' => ['name' => 'Form Failure Alert', 'vars' => ['client_name', 'website_name', 'website_url', 'form_name', 'form_type', 'form_technology', 'page_url', 'form_url', 'popup_info', 'status', 'error_reason', 'error_details', 'ajax_response', 'http_status', 'response_time', 'engine', 'detected_at', 'last_successful_check'],
                'subject' => 'Form Failure Alert – {{form_name}}',
                'body' => "Hello {{client_name}},\n\nAn automated test of a form on your website has failed. Enquiries submitted through this form may not be reaching you.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nForm:\n{{form_name}}\n\nType:\n{{form_type}}\n\nTechnology:\n{{form_technology}}\n\nPage:\n{{page_url}}\n\nPopup:\n{{popup_info}}\n\nStatus:\n{{status}}\n\nReason:\n{{error_reason}}\n\nDetails:\n{{error_details}}\n\nHTTP / AJAX Response:\n{{ajax_response}}\n\nDetected:\n{{detected_at}}\n\nLast Successful Test:\n{{last_successful_check}}\n\nOur team has been notified and will check the form." . $sig],
            'form_recovered' => ['name' => 'Form Recovered', 'vars' => ['client_name', 'website_name', 'website_url', 'form_name', 'form_type', 'page_url', 'form_url', 'status', 'failed_since', 'recovered_at', 'total_downtime', 'failed_tests', 'error_reason', 'detected_at', 'http_status'],
                'subject' => 'Form Recovered – {{form_name}}',
                'body' => "Hello {{client_name}},\n\nGood news – the form below is working again and passing automated tests.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nForm:\n{{form_name}}\n\nType:\n{{form_type}}\n\nPage:\n{{page_url}}\n\nStatus:\nRECOVERED\n\nFailed Since:\n{{failed_since}}\n\nRecovered At:\n{{recovered_at}}\n\nTotal Downtime:\n{{total_downtime}}\n\nProblem Was:\n{{error_reason}}" . $sig],
            'ssl_expiry' => ['name' => 'SSL Expiry Warning', 'vars' => ['client_name', 'website_name', 'website_url', 'status', 'expiry_date', 'days_remaining', 'ssl_issuer', 'error_details'],
                'subject' => 'SSL Certificate {{status}} – {{website_name}}',
                'body' => "Hello {{client_name}},\n\nThis is a reminder that the SSL certificate of your website is about to expire.\n\nWebsite:\n{{website_url}}\n\nSSL Status:\n{{status}}\n\nExpiry Date:\n{{expiry_date}}\n\nDays Remaining:\n{{days_remaining}}\n\nIssuer:\n{{ssl_issuer}}\n\nPlease renew the certificate before it expires so visitors do not see a security warning." . $sig],
            'ssl_failed' => ['name' => 'SSL Certificate Failed', 'vars' => ['client_name', 'website_name', 'website_url', 'domain', 'status', 'error_reason', 'checks', 'expiry_date', 'days_remaining', 'ssl_issuer', 'detected_at', 'last_successful_check'],
                'subject' => 'SSL Certificate {{status}} – {{website_name}}',
                'body' => "Hello {{client_name}},\n\nThe SSL certificate of your website is no longer working. Visitors will see a security warning in their browser.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nDomain:\n{{domain}}\n\nStatus:\n{{status}}\n\nReason:\n{{error_reason}}\n\nChecks:\n{{checks}}\n\nExpiry Date:\n{{expiry_date}}\n\nDays Remaining:\n{{days_remaining}}\n\nIssuer:\n{{ssl_issuer}}\n\nDetected At:\n{{detected_at}}\n\nLast Successful Check:\n{{last_successful_check}}\n\nOur team has been notified and will check the certificate." . $sig],
            'ssl_recovered' => ['name' => 'SSL Certificate Recovered', 'vars' => ['client_name', 'website_name', 'website_url', 'domain', 'status', 'failed_since', 'recovered_at', 'total_downtime', 'error_reason', 'expiry_date', 'days_remaining', 'ssl_issuer'],
                'subject' => 'SSL Certificate Recovered – {{website_name}}',
                'body' => "Hello {{client_name}},\n\nGood news – the SSL certificate of your website is valid again.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nDomain:\n{{domain}}\n\nStatus:\nRECOVERED\n\nFailed Since:\n{{failed_since}}\n\nRecovered At:\n{{recovered_at}}\n\nTotal Downtime:\n{{total_downtime}}\n\nProblem Was:\n{{error_reason}}\n\nNew Expiry Date:\n{{expiry_date}}\n\nDays Remaining:\n{{days_remaining}}\n\nIssuer:\n{{ssl_issuer}}" . $sig],
            'domain_expiry' => ['name' => 'Domain Expiry', 'vars' => ['client_name', 'service_type', 'service_name', 'domain_name', 'provider', 'expiry_date', 'days_remaining', 'auto_renew'],
                'subject' => 'Important: {{service_type}} Expiry in {{days_remaining}} – {{domain_name}}',
                'body' => "Hello {{client_name}},\n\nThis is a reminder that your {{service_type}} is approaching its expiry date.\n\nService:\n{{service_type}}\n\nDomain / Hosting:\n{{service_name}}\n\nRegistrar:\n{{provider}}\n\nExpiry Date:\n{{expiry_date}}\n\nDays Remaining:\n{{days_remaining}}\n\nAuto-renewal:\n{{auto_renew}}\n\nPlease renew the service before the expiry date to avoid interruption of your website and email." . $sig],
            'hosting_expiry' => ['name' => 'Hosting Expiry', 'vars' => ['client_name', 'service_type', 'service_name', 'provider', 'plan', 'website_name', 'expiry_date', 'days_remaining'],
                'subject' => 'Important: {{service_type}} Expiry in {{days_remaining}} – {{client_name}}',
                'body' => "Hello {{client_name}},\n\nThis is a reminder that your {{service_type}} is approaching its expiry date.\n\nService:\n{{service_type}}\n\nHosting Provider:\n{{provider}}\n\nPlan:\n{{plan}}\n\nWebsite:\n{{website_name}}\n\nExpiry Date:\n{{expiry_date}}\n\nDays Remaining:\n{{days_remaining}}\n\nPlease renew the hosting service before expiry to avoid interruption." . $sig],
            'page_failed' => ['name' => 'Website Page Error', 'vars' => ['client_name', 'website_name', 'website_url', 'page_name', 'page_url', 'status', 'http_status', 'error_reason', 'error_details', 'response_time', 'detected_at', 'last_successful_check', 'failed_checks', 'pages_summary'],
                'subject' => 'Website Page Error – {{client_name}}',
                'body' => "Hello {{client_name}},\n\nOne of the pages on your website is not working. Visitors opening this page will see an error.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nFailed Page:\n{{page_name}}\n\nURL:\n{{page_url}}\n\nStatus:\n{{status}}\n\nHTTP Status:\n{{http_status}}\n\nReason:\n{{error_reason}}\n\nDetails:\n{{error_details}}\n\nDetected At:\n{{detected_at}}\n\nLast Successful Check:\n{{last_successful_check}}\n\nWebsite Pages:\n{{pages_summary}}\n\nOur team has been notified and will check the page." . $sig],
            'page_recovered' => ['name' => 'Website Page Recovered', 'vars' => ['client_name', 'website_name', 'website_url', 'page_name', 'page_url', 'status', 'down_since', 'recovered_at', 'total_downtime', 'error_reason', 'pages_summary'],
                'subject' => 'Website Page Recovered – {{client_name}}',
                'body' => "Hello {{client_name}},\n\nGood news – the page below is working again.\n\nClient:\n{{client_name}}\n\nWebsite:\n{{website_url}}\n\nPage:\n{{page_name}}\n\nURL:\n{{page_url}}\n\nStatus:\nRECOVERED\n\nDown Since:\n{{down_since}}\n\nRecovered At:\n{{recovered_at}}\n\nTotal Downtime:\n{{total_downtime}}\n\nProblem Was:\n{{error_reason}}\n\nWebsite Pages:\n{{pages_summary}}" . $sig],
        ];
    }

    /** Presentation meta per template type: tone (colour system), kind label, default CTA label, one-line purpose. */
    public static function templateMeta(string $type): array
    {
        $map = [
            'website_down'      => ['tone' => 'danger',  'kind' => 'Alert',    'cta' => 'View website status',  'about' => 'Sent when a monitored website stops responding.'],
            'website_recovered' => ['tone' => 'success', 'kind' => 'Recovery', 'cta' => 'View website status',  'about' => 'Sent when a website is reachable again.'],
            'form_failed'       => ['tone' => 'danger',  'kind' => 'Alert',    'cta' => 'View form test',       'about' => 'Sent when an automated form test fails.'],
            'form_recovered'    => ['tone' => 'success', 'kind' => 'Recovery', 'cta' => 'View form test',       'about' => 'Sent when a failing form passes again.'],
            'ssl_expiry'        => ['tone' => 'warning', 'kind' => 'Reminder', 'cta' => 'View SSL details',     'about' => 'Reminder before an SSL certificate expires.'],
            'ssl_failed'        => ['tone' => 'danger',  'kind' => 'Alert',    'cta' => 'View SSL details',     'about' => 'Sent when a certificate is invalid or expired.'],
            'ssl_recovered'     => ['tone' => 'success', 'kind' => 'Recovery', 'cta' => 'View SSL details',     'about' => 'Sent when the certificate is valid again.'],
            'domain_expiry'     => ['tone' => 'warning', 'kind' => 'Reminder', 'cta' => 'View domain',          'about' => 'Reminder before a domain registration expires.'],
            'hosting_expiry'    => ['tone' => 'warning', 'kind' => 'Reminder', 'cta' => 'View hosting',         'about' => 'Reminder before a hosting plan expires.'],
            'page_failed'       => ['tone' => 'danger',  'kind' => 'Alert',    'cta' => 'View page report',     'about' => 'Sent when a monitored page returns an error.'],
            'page_recovered'    => ['tone' => 'success', 'kind' => 'Recovery', 'cta' => 'View page report',     'about' => 'Sent when a failed page works again.'],
        ];
        return $map[$type] ?? ['tone' => 'info', 'kind' => 'Notification', 'cta' => 'Open in CRM', 'about' => ''];
    }

    /** Fields of email_templates the editor may change (everything except type / name). */
    const TEMPLATE_FIELDS = ['subject', 'body', 'from_name', 'from_email', 'reply_to', 'preheader', 'cta_label', 'cta_url', 'header_image', 'footer_text', 'status'];

    /** Raw customised row (or null) – cached per request. */
    private static function templateRow(string $type): ?array
    {
        if (!array_key_exists($type, self::$templateRows)) {
            try { self::$templateRows[$type] = DB::fetch("SELECT * FROM email_templates WHERE type = ?", [$type]) ?: null; } catch (Throwable $e) { self::$templateRows[$type] = null; }
        }
        return self::$templateRows[$type];
    }

    /** Returns the active template (custom from DB merged over the default) including sender / CTA / design fields. */
    public static function getTemplate(string $type): array
    {
        $defs = self::templateDefinitions();
        if (!isset($defs[$type])) throw new InvalidArgumentException('Unknown email template: ' . $type);
        $def = $defs[$type];
        $row = self::templateRow($type) ?: [];
        $meta = self::templateMeta($type);
        return [
            'type'         => $type,
            'name'         => $def['name'],
            'vars'         => $def['vars'],
            'tone'         => $meta['tone'],
            'kind'         => $meta['kind'],
            'about'        => $meta['about'],
            'subject'      => $row['subject'] ?? $def['subject'],
            'body'         => $row['body'] ?? $def['body'],
            'from_name'    => $row['from_name'] ?? null,
            'from_email'   => $row['from_email'] ?? null,
            'reply_to'     => $row['reply_to'] ?? null,
            'preheader'    => $row['preheader'] ?? null,
            'cta_label'    => $row['cta_label'] ?? null,
            'cta_url'      => $row['cta_url'] ?? null,
            'header_image' => $row['header_image'] ?? null,
            'footer_text'  => $row['footer_text'] ?? null,
            'status'       => $row['status'] ?? 'active',
            'default_cta'  => $meta['cta'],
            'customized'   => (bool) $row,
            'updated_at'   => $row['updated_at'] ?? null,
            'updated_by'   => $row['updated_by'] ?? null,
            'default_subject' => $def['subject'],
            'default_body'    => $def['body'],
        ];
    }

    /** Every template with status / customisation info + last delivery (for the templates page). */
    public static function templateList(): array
    {
        $out = [];
        $last = [];
        try { foreach (DB::fetchAll("SELECT template, MAX(sent_at) AS last_at, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM email_logs WHERE template IS NOT NULL AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY template") as $r) $last[$r['template']] = $r; } catch (Throwable $e) {}
        // one query for every customised row instead of one per template
        try { $rows = []; foreach (DB::fetchAll("SELECT * FROM email_templates") as $r) $rows[$r['type']] = $r; foreach (array_keys(self::templateDefinitions()) as $type) self::$templateRows[$type] = $rows[$type] ?? null; } catch (Throwable $e) {}
        foreach (array_keys(self::templateDefinitions()) as $type) {
            $t = self::getTemplate($type);
            $t['last_sent_at'] = $last[$type]['last_at'] ?? null;
            $t['sent_30d'] = (int) ($last[$type]['sent'] ?? 0);
            $t['failed_30d'] = (int) ($last[$type]['failed'] ?? 0);
            $out[$type] = $t;
        }
        return $out;
    }

    /** Realistic sample values for previews / test emails (never raw {{placeholders}}). */
    public static function sampleVars(string $type): array
    {
        $now = time();
        $s = [
            'client_name' => 'John Smith', 'website_name' => 'Acme Digital', 'website_url' => 'https://www.acmedigital.com', 'domain' => 'acmedigital.com',
            'form_name' => 'Contact Us', 'form_type' => 'Contact form', 'form_technology' => 'Elementor Forms', 'page_url' => 'https://www.acmedigital.com/contact/', 'form_url' => 'https://www.acmedigital.com/contact/#contact-form', 'popup_info' => 'No popup – inline form', 'engine' => 'Headless browser',
            'status' => 'DOWN', 'error_reason' => 'Connection timeout', 'error_details' => 'The server did not respond within 15 seconds (3 attempts from 2 locations).', 'ajax_response' => 'HTTP 500 – {"success":false,"message":"Internal error"}',
            'http_status' => 'No response', 'response_time' => '15,002 ms', 'detected_at' => date('d-M-Y h:i A', $now - 120), 'last_successful_check' => date('d-M-Y h:i A', $now - 420), 'failed_checks' => '3', 'recommended_action' => 'Check the hosting server status and DNS records, then contact the hosting provider if the outage continues.',
            'down_since' => date('d-M-Y h:i A', $now - 1560), 'failed_since' => date('d-M-Y h:i A', $now - 1560), 'recovered_at' => date('d-M-Y h:i A', $now), 'total_downtime' => '26 minutes', 'failed_tests' => '2',
            'expiry_date' => date('d-M-Y', $now + 864000), 'days_remaining' => '10 days', 'ssl_issuer' => "Let's Encrypt (R11)", 'checks' => 'Certificate chain, hostname match, expiry date',
            'service_type' => $type === 'hosting_expiry' ? 'Hosting' : 'Domain', 'service_name' => $type === 'hosting_expiry' ? 'Hostinger – Business Web Hosting' : 'acmedigital.com', 'domain_name' => 'acmedigital.com',
            'provider' => $type === 'hosting_expiry' ? 'Hostinger' : 'GoDaddy', 'plan' => 'Business Web Hosting', 'auto_renew' => 'Not enabled',
            'page_name' => 'Contact Us', 'pages_summary' => '24 of 25 pages working · 1 page failed',
        ];
        if (str_contains($type, 'recovered')) { $s['status'] = 'RECOVERED'; $s['error_reason'] = 'Connection timeout'; }
        if ($type === 'ssl_expiry') { $s['status'] = 'EXPIRING SOON'; }
        if ($type === 'ssl_failed') { $s['status'] = 'EXPIRED'; $s['error_reason'] = 'The certificate expired on ' . date('d-M-Y', $now - 86400); $s['expiry_date'] = date('d-M-Y', $now - 86400); $s['days_remaining'] = 'Expired 1 day ago'; }
        if ($type === 'page_failed') { $s['status'] = 'FAILED'; $s['http_status'] = '500 Internal Server Error'; $s['error_reason'] = 'Server error'; $s['error_details'] = 'HTTP 500 Internal Server Error returned by the web server.'; $s['response_time'] = '842 ms'; }
        if ($type === 'form_failed') { $s['status'] = 'FAILED'; $s['error_reason'] = 'Submission rejected'; $s['error_details'] = 'The form returned an error after submitting the test enquiry.'; $s['http_status'] = '500 Internal Server Error'; $s['response_time'] = '1,204 ms'; }
        return $s;
    }

    /**
     * Render a template with variables. Returns [subject, html, meta]. Adds the branded responsive layout, the CTA
     * button ($link, unless the template sets its own URL) and the tone colour of the template ($color is kept for
     * older callers – the template's own tone wins when it is known).
     */
    public static function render(string $type, array $vars, ?string $link = null, string $color = '#dc3545'): array
    {
        return self::renderWith(self::getTemplate($type), $vars, $link);
    }

    /** Render any template array (saved or unsaved editor values) with the given variables. */
    public static function renderWith(array $t, array $vars, ?string $link = null): array
    {
        $vars += ['company_name' => company_name(), 'sender_email' => $t['from_email'] && valid_email($t['from_email']) ? $t['from_email'] : self::fromEmail(), 'sender_name' => $t['from_name'] ?: self::fromName(), 'date' => date('d-M-Y'), 'time' => date('h:i A'), 'link' => $link ?? ''];
        $replace = [];
        foreach ($vars as $k => $v) $replace['{{' . $k . '}}'] = ($v === null || $v === '') ? '—' : (string) $v;
        $sub = fn(?string $s, string $missing = '—') => $s === null ? null : preg_replace('~\{\{[a-z0-9_]+\}\}~i', $missing, strtr($s, $replace));
        $subject = trim((string) $sub($t['subject'] ?? '', ''));
        $bodyText = (string) $sub($t['body'] ?? '');
        $ctaUrl = trim((string) $sub($t['cta_url'] ?? '', ''));
        if ($ctaUrl === '' || !preg_match('~^https?://~i', $ctaUrl)) $ctaUrl = $link;
        $ctaLabel = trim((string) ($t['cta_label'] ?? '')) ?: ($t['default_cta'] ?? 'Open in CRM');
        $preheader = trim((string) $sub($t['preheader'] ?? '', '')) ?: self::preheaderFrom($bodyText);
        $footer = trim((string) $sub($t['footer_text'] ?? '', ''));
        $html = self::layout($t['name'] ?? 'Notification', self::bodyToHtml($bodyText, $t['tone'] ?? 'info'), $ctaUrl, $t['tone'] ?? 'info', [
            'kind' => $t['kind'] ?? 'Notification', 'cta_label' => $ctaLabel, 'preheader' => $preheader, 'header_image' => trim((string) ($t['header_image'] ?? '')), 'footer_text' => $footer, 'subject' => $subject,
        ]);
        return [$subject, $html, ['preheader' => $preheader, 'cta_label' => $ctaLabel, 'cta_url' => $ctaUrl, 'from_name' => $t['from_name'] ?: self::fromName(), 'from_email' => valid_email($t['from_email'] ?? '') ? $t['from_email'] : self::fromEmail(), 'reply_to' => valid_email($t['reply_to'] ?? '') ? $t['reply_to'] : self::replyTo()]];
    }

    /** Preview a template with realistic sample data; $override = unsaved editor values. */
    public static function previewTemplate(string $type, array $override = []): array
    {
        $t = self::getTemplate($type);
        foreach (self::TEMPLATE_FIELDS as $f) if (array_key_exists($f, $override) && $override[$f] !== null) $t[$f] = (string) $override[$f];
        [$subject, $html, $meta] = self::renderWith($t, self::sampleVars($type), url('dashboard/index.php'));
        return ['type' => $type, 'name' => $t['name'], 'subject' => $subject, 'html' => $html, 'text' => self::toText($html), 'tone' => $t['tone'], 'kind' => $t['kind'], 'status' => $t['status'], 'to' => 'John Smith <john.smith@acmedigital.com>'] + $meta;
    }

    /** Validate + upsert editor values. Returns ['errors' => [...]] or ['ok' => true, 'template' => ...]. */
    public static function saveTemplate(string $type, array $data, ?int $userId = null): array
    {
        $defs = self::templateDefinitions();
        if (!isset($defs[$type])) return ['errors' => ['type' => 'Unknown template.']];
        $v = [];
        foreach (self::TEMPLATE_FIELDS as $f) $v[$f] = array_key_exists($f, $data) ? trim((string) $data[$f]) : null;
        $errors = [];
        if (($v['subject'] ?? '') === '') $errors['subject'] = 'Subject is required.';
        elseif (mb_strlen($v['subject']) > 250) $errors['subject'] = 'Subject must be 250 characters or less.';
        if (trim((string) ($v['body'] ?? '')) === '') $errors['body'] = 'Email content is required.';
        if ($v['from_email'] !== null && $v['from_email'] !== '' && !valid_email($v['from_email'])) $errors['from_email'] = 'Enter a valid email address.';
        if ($v['reply_to'] !== null && $v['reply_to'] !== '' && !valid_email($v['reply_to'])) $errors['reply_to'] = 'Enter a valid email address.';
        foreach (['cta_url' => 'Button link', 'header_image' => 'Header image'] as $f => $label) if ($v[$f] !== null && $v[$f] !== '' && !preg_match('~^(https?://|\{\{link\}\})~i', $v[$f])) $errors[$f] = $label . ' must be a full https:// URL (or {{link}} for the CRM page).';
        if ($v['status'] !== null && !in_array($v['status'], ['active', 'disabled'], true)) $v['status'] = 'active';
        if ($errors) return ['errors' => $errors];
        $row = self::templateRow($type) ?: [];
        $fields = ['type' => $type, 'name' => $defs[$type]['name'], 'subject' => mb_substr($v['subject'], 0, 250), 'body' => str_replace("\r\n", "\n", $v['body'])];
        foreach (['from_name', 'from_email', 'reply_to', 'preheader', 'cta_label', 'cta_url', 'header_image', 'footer_text'] as $f) $fields[$f] = $v[$f] === null ? ($row[$f] ?? null) : ($v[$f] === '' ? null : mb_substr($v[$f], 0, $f === 'cta_label' ? 80 : ($f === 'from_name' ? 150 : ($f === 'preheader' || $f === 'from_email' || $f === 'reply_to' ? 190 : 500))));
        $fields['status'] = $v['status'] ?? ($row['status'] ?? 'active');
        $fields['updated_by'] = $userId;
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $fields['created_at'] = $row['created_at'] ?? date('Y-m-d H:i:s');
        $cols = array_keys($fields);
        DB::query("INSERT INTO email_templates (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['type', 'created_at']))), array_values($fields));
        unset(self::$templateRows[$type]);
        return ['ok' => true, 'template' => self::getTemplate($type)];
    }

    /** Only the status switch (keeps every other customisation). */
    public static function setTemplateStatus(string $type, string $status): bool
    {
        if (!isset(self::templateDefinitions()[$type]) || !in_array($status, ['active', 'disabled'], true)) return false;
        $t = self::getTemplate($type);
        $r = self::saveTemplate($type, ['subject' => $t['subject'], 'body' => $t['body'], 'status' => $status]);
        return !empty($r['ok']);
    }

    public static function resetTemplate(string $type): void
    {
        DB::delete('email_templates', 'type = ?', [$type]);
        unset(self::$templateRows[$type]);
    }

    /**
     * Send the exact template (saved, or unsaved editor values) with sample data to a test address through the real
     * SMTP transport. Returns the send() result + the rendered subject – never a simulated success.
     */
    public static function sendTemplateTest(string $type, string $to, array $override = []): array
    {
        $p = self::previewTemplate($type, $override);
        $t = self::getTemplate($type);
        foreach (self::TEMPLATE_FIELDS as $f) if (array_key_exists($f, $override) && $override[$f] !== null) $t[$f] = (string) $override[$f];
        $by = (!IS_CLI && class_exists('Auth') && Auth::check()) ? (Auth::user()['name'] ?? 'admin') : 'cli';
        $r = self::send($to, '[TEST] ' . $p['subject'], $p['html'], $by, 'test', null, null, null, null, ['template' => $type, 'attempt' => 1, 'from_name' => $t['from_name'] ?: null, 'from_email' => $t['from_email'] ?: null, 'reply_to' => $t['reply_to'] ?: null]);
        $r['subject'] = '[TEST] ' . $p['subject'];
        $r['log_id'] = null;
        try { $r['log_id'] = (int) DB::value("SELECT MAX(id) FROM email_logs WHERE to_email = ? AND category = 'test'", [$to]); } catch (Throwable $ignored) {}
        return $r;
    }

    /* ---------- HTML email layout (responsive, email-client safe: tables + inline styles + a small media query) ---------- */

    /** Tone → colours used by the accent bar, pill, button and status values. */
    private static function tone(string $tone): array
    {
        $map = [
            'danger'  => ['main' => '#dc2626', 'soft' => '#fef2f2', 'line' => '#fecaca', 'text' => '#991b1b'],
            'success' => ['main' => '#16a34a', 'soft' => '#f0fdf4', 'line' => '#bbf7d0', 'text' => '#166534'],
            'warning' => ['main' => '#ea580c', 'soft' => '#fff7ed', 'line' => '#fed7aa', 'text' => '#9a3412'],
            'info'    => ['main' => '#2563eb', 'soft' => '#eff6ff', 'line' => '#bfdbfe', 'text' => '#1e40af'],
            'brand'   => ['main' => '#FCAF17', 'soft' => '#fff8e6', 'line' => '#fde68a', 'text' => '#92400e'],
        ];
        return $map[$tone] ?? $map['info'];
    }

    /** Escape text and turn URLs / email addresses into links. */
    private static function linkify(string $text, string $color): string
    {
        $parts = preg_split('~(https?://[^\s<>"\']+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) { $out .= nl2br(e($p)); continue; }
            $isMail = !preg_match('~^https?://~i', $p);
            $trail = ''; if (preg_match('~[.,;:)]+$~', $p, $m)) { $trail = $m[0]; $p = substr($p, 0, -strlen($trail)); }
            $out .= '<a href="' . e($isMail ? 'mailto:' . $p : $p) . '" style="color:' . $color . ';text-decoration:underline;' . ($isMail ? 'white-space:nowrap' : 'word-break:break-all') . '">' . e($p) . '</a>' . e($trail);
        }
        return $out;
    }

    /** Value pill for "Status" rows: DOWN/FAILED → red, RECOVERED/ONLINE → green, EXPIRING → orange. */
    private static function statusPill(string $value): string
    {
        $u = strtoupper($value);
        $tone = preg_match('~RECOVER|ONLINE|WORKING|VALID|OK|PASSED|ACTIVE|UP\b~', $u) ? 'success' : (preg_match('~EXPIR|WARN|SOON|SLOW|DEGRAD|PENDING~', $u) && !preg_match('~EXPIRED~', $u) ? 'warning' : (preg_match('~DOWN|FAIL|ERROR|EXPIRED|INVALID|BLOCKED|MISSING|PARKED|TIMEOUT|CRITICAL~', $u) ? 'danger' : 'info'));
        $c = self::tone($tone);
        return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . $c['soft'] . ';border:1px solid ' . $c['line'] . ';color:' . $c['text'] . ';font-size:12px;font-weight:700;letter-spacing:.3px">' . e($value) . '</span>';
    }

    /**
     * Turn the plain-text template body into designed blocks: greeting + paragraphs, "Label:\nValue" pairs grouped
     * into a details table, a trailing "Regards, …" block as signature. Returns [mainHtml, signatureHtml].
     */
    private static function bodyToHtml(string $text, string $tone): array
    {
        $c = self::tone($tone);
        $blocks = preg_split('~\n\s*\n~', trim(str_replace("\r\n", "\n", $text)));
        $html = ''; $rows = []; $signature = '';
        $flush = function () use (&$html, &$rows) {
            if (!$rows) return;
            $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e6e8ec;border-radius:10px;border-collapse:separate;overflow:hidden;margin:6px 0 18px">';
            foreach ($rows as $i => [$label, $value]) {
                $bg = $i % 2 ? '#ffffff' : '#f8f9fb';
                $h .= '<tr><td class="kv-label" width="36%" style="padding:10px 14px;background:' . $bg . ';font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;vertical-align:top;border-top:' . ($i ? '1px solid #eceef1' : '0') . '">' . e($label) . '</td>'
                    . '<td class="kv-value" style="padding:10px 14px;background:' . $bg . ';font-size:14px;color:#111827;line-height:1.5;vertical-align:top;border-top:' . ($i ? '1px solid #eceef1' : '0') . '">' . $value . '</td></tr>';
            }
            $html .= $h . '</table>';
            $rows = [];
        };
        $n = count($blocks);
        foreach ($blocks as $idx => $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn($l) => trim($l) !== ''));
            if (!$lines) continue;
            if ($idx === $n - 1 && preg_match('~^(regards|kind regards|best regards|thanks|thank you|many thanks|sincerely|cheers|warm regards)\b~i', $lines[0])) {
                $signature = '<p style="margin:0;font-size:13px;line-height:1.6;color:#4b5563">' . self::linkify(implode("\n", $lines), $c['main']) . '</p>';
                continue;
            }
            if (count($lines) >= 2 && preg_match('~^[A-Za-z][A-Za-z0-9 /&()\'’.-]{0,60}:$~', $lines[0])) {
                $label = rtrim($lines[0], ':');
                $valueText = implode("\n", array_slice($lines, 1));
                $value = preg_match('~status$~i', $label) && strlen($valueText) <= 40 && !str_contains($valueText, "\n") ? self::statusPill($valueText) : self::linkify($valueText, $c['main']);
                $rows[] = [$label, $value];
                continue;
            }
            $flush();
            $isGreeting = $idx === 0 && preg_match('~^(hello|hi|dear|good (morning|afternoon|evening))\b~i', $lines[0]) && count($lines) === 1;
            $html .= '<p style="margin:0 0 14px;font-size:' . ($isGreeting ? '16px;font-weight:600;color:#111827' : '14px;color:#374151') . ';line-height:1.65">' . self::linkify(implode("\n", $lines), $c['main']) . '</p>';
        }
        $flush();
        return [$html, $signature];
    }

    /** First meaningful sentence of the body (after the greeting) – shown as inbox preview text. */
    private static function preheaderFrom(string $text): string
    {
        foreach (preg_split('~\n\s*\n~', trim($text)) as $i => $block) {
            $line = trim(preg_replace('~\s+~', ' ', $block));
            if ($line === '' || ($i === 0 && preg_match('~^(hello|hi|dear)\b~i', $line)) || preg_match('~:$~', $line) || str_contains($block, ":\n")) continue;
            return mb_substr($line, 0, 140);
        }
        return '';
    }

    /**
     * Branded responsive layout. $content = [mainHtml, signatureHtml] from bodyToHtml() or a ready HTML string.
     * $opts: kind, cta_label, preheader, header_image, footer_text, subject.
     */
    private static function layout(string $title, $content, ?string $link, string $tone, array $opts = []): string
    {
        [$main, $signature] = is_array($content) ? $content : [$content, ''];
        $c = self::tone($tone);
        $company = e(company_name());
        $initial = e(mb_strtoupper(mb_substr(company_name(), 0, 1)));
        $kind = e($opts['kind'] ?? 'Notification');
        $ctaLabel = e($opts['cta_label'] ?? 'Open in CRM');
        $preheader = e($opts['preheader'] ?? '');
        $footerText = ($opts['footer_text'] ?? '') !== '' ? self::linkify($opts['footer_text'], '#6b7280') : 'Automated notification from ' . $company . ' Client Care · ' . self::linkify(self::fromEmail(), '#6b7280');
        $headerImage = ($opts['header_image'] ?? '') !== '' && preg_match('~^https?://~i', $opts['header_image']) ? '<tr><td style="padding:0;line-height:0"><img src="' . e($opts['header_image']) . '" width="600" alt="" style="width:100%;max-width:600px;height:auto;display:block;border:0"></td></tr>' : '';
        $button = $link ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-wrap" style="margin:4px 0 8px"><tr><td class="btn" align="center" bgcolor="#111111" style="border-radius:8px;background:#111111"><a href="' . e($link) . '" style="display:inline-block;padding:12px 22px;font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#FCAF17;text-decoration:none;border-radius:8px;letter-spacing:.1px">' . $ctaLabel . ' &rarr;</a></td></tr></table>' : '';
        $signatureRow = $signature ? '<tr><td class="px" style="padding:18px 32px 26px;border-top:1px solid #eef0f3;font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif">' . $signature . '</td></tr>' : '';
        $date = e(date('d M Y, h:i A'));
        return '<!DOCTYPE html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . e($opts['subject'] ?? $title) . '</title>'
            . '<style>body{margin:0;padding:0;background:#f3f4f6;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}'
            . '@media only screen and (max-width:620px){.container{width:100%!important;max-width:100%!important}.px{padding-left:20px!important;padding-right:20px!important}.hero{padding-top:22px!important}.kv-label,.kv-value{display:block!important;width:100%!important;box-sizing:border-box}.kv-label{padding-bottom:2px!important}.kv-value{padding-top:0!important;border-top:0!important}.btn-wrap{width:100%!important}.btn a{display:block!important;text-align:center!important}.brand-date{display:none!important}h1{font-size:20px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6">'
            . ($preheader ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f6">' . $preheader . str_repeat('&#8199;&#65279;', 40) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="background:#f3f4f6"><tr><td align="center" style="padding:28px 12px 32px">'
            . '<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">'
            // brand bar
            . '<tr><td style="padding:0 6px 14px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:15px;font-weight:700;color:#111111;letter-spacing:-.2px"><span style="display:inline-block;width:26px;height:26px;line-height:26px;text-align:center;border-radius:7px;background:#111111;color:#FCAF17;font-size:14px;font-weight:800;vertical-align:middle;margin-right:8px">' . $initial . '</span><span style="vertical-align:middle">' . $company . '</span> <span style="vertical-align:middle;color:#FCAF17;font-weight:600">Client Care</span></td>'
            . '<td class="brand-date" align="right" style="font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:12px;color:#6b7280;white-space:nowrap">' . $date . '</td></tr></table></td></tr>'
            // card
            . '<tr><td style="background:#ffffff;border:1px solid #e6e8ec;border-radius:14px;overflow:hidden"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="height:5px;line-height:5px;font-size:0;background:' . $c['main'] . '">&nbsp;</td></tr>'
            . $headerImage
            . '<tr><td class="px hero" style="padding:30px 32px 6px;font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif">'
            . '<span style="display:inline-block;padding:4px 11px;border-radius:999px;background:' . $c['soft'] . ';border:1px solid ' . $c['line'] . ';color:' . $c['text'] . ';font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">' . $kind . '</span>'
            . '<h1 style="margin:14px 0 14px;font-size:22px;line-height:1.3;font-weight:700;color:#111827;letter-spacing:-.3px">' . e($title) . '</h1>'
            . $main . $button
            . '</td></tr>'
            . $signatureRow
            . '<tr><td class="px" style="padding:14px 32px;background:#fafafa;border-top:1px solid #eef0f3;font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6b7280">' . $footerText . '</td></tr>'
            . '</table></td></tr>'
            // footer
            . '<tr><td align="center" style="padding:18px 8px 0;font-family:Inter,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:11px;line-height:1.6;color:#9ca3af">You receive this message because ' . $company . ' monitors this website for you. Sent ' . $date . ' · <a href="mailto:' . e(self::fromEmail()) . '" style="color:#9ca3af;text-decoration:underline">' . e(self::fromEmail()) . '</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Generic key/value email (system errors, password resets, verification, test emails). Uses the same layout.
     * @param array $rows label => value (plain text, escaped here)
     */
    public static function template(string $title, string $intro, array $rows, string $action = '', ?string $link = null, string $color = '#dc3545'): string
    {
        $tone = ['#dc3545' => 'danger', '#198754' => 'success', '#0d6efd' => 'info', '#FCAF17' => 'brand', '#ffc107' => 'warning'][$color] ?? 'info';
        $c = self::tone($tone);
        $text = '';
        foreach ($rows as $label => $value) { if ($value === null || $value === '') continue; $text .= "\n\n" . $label . ":\n" . $value; }
        $asButton = $link && $action !== '' && mb_strlen($action) <= 40;   // short action + link = the button label; long advice = a paragraph
        [$main, $sig] = self::bodyToHtml(trim($intro . $text . ($action !== '' && !$asButton ? "\n\nSuggested action:\n" . $action : '')), $tone);
        return self::layout($title, [$main, $sig], $link, $tone, ['kind' => $tone === 'danger' ? 'Alert' : ($tone === 'success' ? 'Confirmation' : 'Notification'), 'cta_label' => $asButton ? $action : 'Open in CRM', 'preheader' => mb_substr(preg_replace('~\s+~', ' ', $intro), 0, 140), 'subject' => $title]);
    }

    /* =====================================================================
     * Helpers
     * ===================================================================== */

    private static function splitAddresses(?string $list): array
    {
        if (!$list) return [];
        return array_values(array_filter(array_map('trim', preg_split('~[,;\s]+~', $list)), 'valid_email'));
    }

    private static function toText(string $html): string
    {
        $t = preg_replace('~<br\s*/?>|</p>|</tr>|</h[1-6]>~i', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~[ \t]+\n~', "\n", preg_replace('~\n{3,}~', "\n\n", $t)));
    }

    /** Make sure no credential ever ends up in a log or error message. */
    private static function scrub(string $msg): string
    {
        $pass = decrypt_value(setting('smtp_password', ''));
        if ($pass) $msg = str_replace($pass, '********', $msg);
        $imap = decrypt_value(setting('imap_password', ''));
        if ($imap) $msg = str_replace($imap, '********', $msg);
        $dkim = decrypt_value(setting('dkim_private_key', ''));
        if ($dkim) $msg = str_replace($dkim, '********', $msg);
        return $msg;
    }

    private static function templateForCategory(string $category): ?string
    {
        $map = ['website_down' => 'website_down', 'website_recovered' => 'website_recovered', 'form_failed' => 'form_failed', 'form_recovered' => 'form_recovered', 'ssl' => 'ssl_expiry',
            'ssl_failed' => 'ssl_failed', 'ssl_recovered' => 'ssl_recovered', 'domain_expiry' => 'domain_expiry', 'hosting_expiry' => 'hosting_expiry', 'page_failed' => 'page_failed', 'page_recovered' => 'page_recovered'];
        return $map[$category] ?? null;
    }

    public static function categoryLabel(string $category): string
    {
        $map = ['website_down' => 'Website Down', 'website_recovered' => 'Website Recovered', 'form_failed' => 'Form Failed', 'form_recovered' => 'Form Recovered', 'ssl' => 'SSL Expiry Warning',
            'ssl_failed' => 'SSL Failed', 'ssl_recovered' => 'SSL Recovered', 'domain_expiry' => 'Domain Expiry', 'hosting_expiry' => 'Hosting Expiry', 'page_failed' => 'Page Error', 'page_recovered' => 'Page Recovered', 'test' => 'Test Email',
            'password_reset' => 'Password Reset', 'system_error' => 'System Error', 'general' => 'General'];
        return $map[$category] ?? ucwords(str_replace('_', ' ', $category));
    }
}
