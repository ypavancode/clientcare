<?php
/**
 * Email Templates API (Super Admin only) – backs platform/templates.php.
 *   POST action=list                       every template with status / customisation / last delivery
 *   POST action=preview    type [+ fields]  rendered subject + HTML with realistic sample data (unsaved editor values when use_form=1)
 *   POST action=save       type + fields    validate + store (subject, body, sender, CTA, images, footer, status)
 *   POST action=status     type + status    active | disabled
 *   POST action=reset      type             back to the built-in default
 *   POST action=send_test  type + to        send the exact template through the real SMTP transport – real result only
 *   POST action=recent     [type]           latest deliveries of template emails (email_logs)
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
require_post();
$action = post('action', '');
$type = (string) post('type', '');
if (in_array($action, ['preview', 'save', 'status', 'reset', 'send_test'], true) && !isset(Mailer::templateDefinitions()[$type])) json_error('Unknown template.', 404);

/** Unsaved editor values (only the fields that were actually posted). */
$fieldsFromPost = function (): array {
    if (!post('use_form')) return [];
    $o = [];
    foreach (Mailer::TEMPLATE_FIELDS as $f) if (array_key_exists($f, $_POST)) $o[$f] = (string) $_POST[$f];
    if (array_key_exists('status', $_POST)) $o['status'] = $_POST['status'] === 'disabled' ? 'disabled' : 'active';
    return $o;
};
$fmtTemplate = function (array $t): array {
    return ['type' => $t['type'], 'name' => $t['name'], 'kind' => $t['kind'], 'tone' => $t['tone'], 'about' => $t['about'], 'status' => $t['status'], 'customized' => $t['customized'],
        'updated_at' => $t['updated_at'], 'updated_label' => $t['updated_at'] ? format_datetime($t['updated_at']) : 'Default', 'updated_ago' => $t['updated_at'] ? time_ago($t['updated_at']) : null,
        'subject' => $t['subject'], 'last_sent_at' => $t['last_sent_at'] ?? null, 'last_sent_ago' => !empty($t['last_sent_at']) ? time_ago($t['last_sent_at']) : null, 'sent_30d' => $t['sent_30d'] ?? 0, 'failed_30d' => $t['failed_30d'] ?? 0];
};

switch ($action) {
    case 'list':
        json_success('OK', ['templates' => array_values(array_map($fmtTemplate, Mailer::templateList()))]);

    case 'preview':
        $p = Mailer::previewTemplate($type, $fieldsFromPost());
        json_success('OK', ['preview' => $p]);

    case 'save':
        data_changed();
        $data = [];
        foreach (Mailer::TEMPLATE_FIELDS as $f) if (array_key_exists($f, $_POST)) $data[$f] = (string) $_POST[$f];
        $data['status'] = post('status') === 'disabled' ? 'disabled' : 'active';
        $r = Mailer::saveTemplate($type, $data, (int) (Auth::user()['id'] ?? 0) ?: null);
        if (!empty($r['errors'])) json_error('Please correct the highlighted fields.', 422, ['errors' => $r['errors']]);
        ActivityLog::platform('template_saved', 'Email template "' . $r['template']['name'] . '" saved' . ($data['status'] === 'disabled' ? ' (disabled)' : ''), 'template ' . $type);
        $list = Mailer::templateList();
        json_success('Template saved.', ['template' => $fmtTemplate($list[$type]), 'preview' => Mailer::previewTemplate($type)]);

    case 'status':
        data_changed();
        $status = post('status') === 'disabled' ? 'disabled' : 'active';
        if (!Mailer::setTemplateStatus($type, $status)) json_error('Could not update the template status.');
        ActivityLog::platform('template_status', 'Email template "' . Mailer::templateDefinitions()[$type]['name'] . '" ' . ($status === 'disabled' ? 'disabled – no emails of this type are sent' : 'enabled'), 'template ' . $type);
        $list = Mailer::templateList();
        json_success($status === 'disabled' ? 'Template disabled – this alert type will not send email until it is enabled again.' : 'Template enabled.', ['template' => $fmtTemplate($list[$type])]);

    case 'reset':
        data_changed();
        Mailer::resetTemplate($type);
        ActivityLog::platform('template_reset', 'Email template "' . Mailer::templateDefinitions()[$type]['name'] . '" reset to default', 'template ' . $type);
        $list = Mailer::templateList();
        json_success('Template restored to the built-in default.', ['template' => $fmtTemplate($list[$type]), 'preview' => Mailer::previewTemplate($type)]);

    case 'send_test':
        $to = trim((string) post('to', ''));
        if (!valid_email($to)) json_error('Enter a valid email address for the test.', 422, ['errors' => ['to' => 'Invalid email address']]);
        $c = Mailer::config();
        $problems = Mailer::configProblems($c);
        if ($problems) json_response(['success' => false, 'message' => '🔴 Email Sending Failed – SMTP is not configured', 'headline' => 'Email Sending Failed', 'reason' => implode(' ', $problems), 'kind' => 'config', 'kind_label' => 'Configuration', 'suggestion' => Mailer::suggestion('config', $c), 'ms' => 0, 'settings_url' => url('platform/emails.php?tab=settings')]);
        @set_time_limit($c['connect_timeout'] * 2 + $c['timeout'] * 4 + 20);
        $t0 = microtime(true);
        $r = Mailer::sendTemplateTest($type, $to, $fieldsFromPost());
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $name = Mailer::templateDefinitions()[$type]['name'];
        ActivityLog::platform('template_test', 'Test email "' . $name . '" to ' . $to . ': ' . ($r['ok'] ? 'sent in ' . $r['ms'] . ' ms' : 'failed – ' . $r['error']), $to, $r['ok'] ? 'ok' : 'failed');
        $st = Mailer::status(true);
        json_response([
            'success'    => (bool) $r['ok'],
            'message'    => $r['ok'] ? '🟢 Test Email Sent Successfully to ' . $to . ' (' . number_format($r['ms'] / 1000, 2) . ' s)' : '🔴 Email Sending Failed – ' . (Mailer::KINDS[$r['kind']] ?? 'Failed') . ': ' . $r['error'],
            'headline'   => $r['ok'] ? 'Test Email Sent Successfully' : 'Email Sending Failed',
            'to'         => $to, 'subject' => $r['subject'], 'template' => $name,
            'ms'         => (int) $r['ms'], 'total_ms' => $ms, 'seconds' => number_format($r['ms'] / 1000, 2),
            'smtp_response' => $r['smtp_response'], 'message_id' => $r['message_id'],
            'kind'       => $r['kind'], 'kind_label' => $r['kind'] ? (Mailer::KINDS[$r['kind']] ?? 'Failed') : null, 'error' => $r['error'],
            'suggestion' => $r['ok'] ? null : Mailer::suggestion($r['kind'] ?? 'other', $c, (string) $r['error']),
            'log_id'     => $r['log_id'], 'logs_url' => url('platform/emails.php?tab=logs'), 'settings_url' => url('platform/emails.php?tab=settings'),
            'transport'  => $c['host'] . ':' . $c['port'] . ' · ' . strtoupper($c['encryption'] === 'tls' ? 'STARTTLS' : $c['encryption']),
            'smtp_status' => ['status' => $st['status'], 'tone' => $st['tone'], 'headline' => $st['headline']],
        ]);

    case 'recent':
        $where = "l.template IS NOT NULL"; $params = [];
        if ($type !== '' && isset(Mailer::templateDefinitions()[$type])) { $where .= " AND l.template = ?"; $params[] = $type; }
        $rows = DB::fetchAll("SELECT l.id, l.sent_at, l.to_email, l.subject, l.category, l.template, l.status, l.error_kind, l.error, l.smtp_response, l.duration_ms, l.attempt, l.message_id, t.name AS tenant_name FROM email_logs l LEFT JOIN tenants t ON t.id = l.tenant_id WHERE $where ORDER BY l.id DESC LIMIT " . max(1, min(50, post_int('limit', 12) ?? 12)), $params);
        $defs = Mailer::templateDefinitions();
        foreach ($rows as &$r) {
            $r['template_name'] = $defs[$r['template']]['name'] ?? $r['template'];
            $r['sent_label'] = format_datetime($r['sent_at']); $r['sent_ago'] = time_ago($r['sent_at']);
            $r['kind_label'] = $r['status'] === 'sent' ? 'Sent' : (Mailer::KINDS[$r['error_kind'] ?? 'other'] ?? 'Failed');
            $r['seconds'] = $r['duration_ms'] !== null ? number_format($r['duration_ms'] / 1000, 2) : null;
            $r['is_test'] = $r['category'] === 'test';
        }
        unset($r);
        json_success('OK', ['rows' => $rows]);

    default:
        json_error('Unknown action.');
}
