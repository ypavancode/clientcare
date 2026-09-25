<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();

$action = post('action', '');

function load_form(int $id): array
{
    $f = DB::fetch("SELECT * FROM forms WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
    if (!$f) json_error('Form not found.', 404);
    return $f;
}

/** Strip the binary screenshot and keep the API payload small. */
function test_payload(array $r): array
{
    unset($r['screenshot']);
    if (!empty($r['screenshot_path'])) $r['screenshot_url'] = url('uploads/' . $r['screenshot_path']);
    return $r;
}

switch ($action) {
    case 'save':
        Auth::requireAbility('forms');
        $id = post_int('id');
        $kind = in_array(post('form_kind'), form_kinds(), true) ? post('form_kind') : 'normal';
        $interval = post_int('test_interval', 0) ?? 0;
        $data = [
            'website_id'      => post_int('website_id') ?: 0,
            'name'            => trim((string) post('name', '')),
            'page_url'        => normalize_url(post('page_url', '')),
            'form_url'        => post_nullable('form_url') ? normalize_url(post('form_url')) : null,
            'form_type'       => in_array(post('form_type'), form_types(), true) ? post('form_type') : 'Other',
            'form_kind'       => $kind,
            'engine'          => in_array(post('engine'), ['auto', 'http', 'browser'], true) ? post('engine') : 'auto',
            'method'          => post('method') === 'GET' ? 'GET' : 'POST',
            'ajax'            => in_array($kind, ['ajax', 'popup', 'wordpress'], true) || post('ajax') ? 1 : 0,
            'popup_trigger'   => post_nullable('popup_trigger'),
            'popup_selector'  => post_nullable('popup_selector'),
            'form_selector'   => post_nullable('form_selector'),
            'submit_selector' => post_nullable('submit_selector'),
            'recipient_email' => post_nullable('recipient_email'),
            'cc_email'        => post_nullable('cc_email'),
            'bcc_email'       => post_nullable('bcc_email'),
            'smtp_provider'   => post_nullable('smtp_provider'),
            'auto_test'       => post('auto_test') ? 1 : 0,
            'test_interval'   => in_array($interval, [0, 10, 15, 20, 30, 60], true) ? $interval : 0,
            'test_payload'    => post_nullable('test_payload'),
            'test_name'       => post_nullable('test_name'),
            'test_email'      => post_nullable('test_email'),
            'test_phone'      => post_nullable('test_phone'),
            'test_message'    => post_nullable('test_message'),
            'test_email_recipient' => post_nullable('test_email_recipient'),
            'verify_email'    => post('verify_email') ? 1 : 0,
            // CAPTCHA / anti-bot protection: the CRM never bypasses it – it either reports "Blocked by CAPTCHA" or uses an owner-approved test method
            'captcha_type'    => array_key_exists((string) post('captcha_type', ''), form_captcha_types()) ? (string) post('captcha_type') : 'none',
            'captcha_mode'    => in_array(post('captcha_mode'), ['detect', 'test_url', 'test_field'], true) ? post('captcha_mode') : 'detect',
            'captcha_test_url'   => post_nullable('captcha_test_url') ? normalize_url(post('captcha_test_url')) : null,
            'captcha_test_field' => post_nullable('captcha_test_field'),
            'success_match'   => post_nullable('success_match'),
            'expect_redirect' => post_nullable('expect_redirect'),
            'expect_response' => post_nullable('expect_response'),
            'expect_http_code'=> post_int('expect_http_code') ?: null,
            'notes'           => post_nullable('notes'),
        ];
        if ($data['expect_http_code'] !== null && ($data['expect_http_code'] < 100 || $data['expect_http_code'] > 599)) $data['expect_http_code'] = null;
        $errors = [];
        if ($data['name'] === '') $errors['name'] = 'Form name is required.';
        if (!valid_url($data['page_url'])) $errors['page_url'] = 'Enter a valid page URL.';
        if ($data['form_url'] !== null && !valid_url($data['form_url'])) $errors['form_url'] = 'Enter a valid URL.';
        if (!$data['website_id'] || !DB::value("SELECT id FROM websites WHERE id = ? AND tenant_id = ?", [$data['website_id'], Tenant::id()])) $errors['website_id'] = 'Please select a website.';
        if ($kind === 'popup' && $data['popup_trigger'] === null && $data['popup_selector'] === null) $errors['popup_trigger'] = 'Enter the popup trigger (button/link that opens the popup) or the popup selector.';
        if ($data['test_email'] !== null && !valid_email($data['test_email'])) $errors['test_email'] = 'Enter a valid test email address.';
        if ($data['captcha_mode'] === 'test_url' && ($data['captcha_test_url'] === null || !valid_url($data['captcha_test_url']))) $errors['captcha_test_url'] = 'Enter the owner-approved test / staging page URL (without CAPTCHA).';
        if ($data['captcha_mode'] === 'test_field' && ($data['captcha_test_field'] === null || !preg_match('~^[^=\s]+=~m', $data['captcha_test_field']))) $errors['captcha_test_field'] = 'Enter the owner-approved test parameter as name=value (one per line).';
        if ($data['captcha_mode'] === 'detect') { $data['captcha_test_url'] = $data['captcha_test_url']; } // kept for reference, unused until a test method is chosen
        if ($data['test_email_recipient'] !== null && !valid_email($data['test_email_recipient'])) $errors['test_email_recipient'] = 'Enter a valid email address.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);

        $status = post('status');
        $website = DB::fetch("SELECT id, name, client_id FROM websites WHERE id = ?", [$data['website_id']]);
        if ($id) {
            $existing = load_form($id);
            if ($status === 'disabled') $data['status'] = 'disabled';
            elseif ($existing['status'] === 'disabled') $data['status'] = 'not_tested';
            // configuration changed → detected plugin is re-evaluated on the next test
            if ($existing['page_url'] !== $data['page_url'] || ($existing['form_selector'] ?? null) !== $data['form_selector']) $data['wp_plugin'] = null;
            DB::update('forms', $data, 'id = ?', [$id]);
            ActivityLog::add('form_updated', 'Form updated: ' . $data['name'] . ' on ' . $website['name'], ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $id]);
            $msg = 'Form updated.';
        } else {
            $can = Tenant::canAdd('forms');
            if (!$can['ok']) json_error($can['message'], 403, ['upgrade' => true, 'usage' => $can]);
            $data['tenant_id'] = Tenant::id();
            $data['status'] = $status === 'disabled' ? 'disabled' : 'not_tested';
            $id = DB::insert('forms', $data);
            Tenant::usage(null, true);
            ActivityLog::add('form_added', 'Form added: ' . $data['name'] . ' (' . form_kind_label($kind) . ') on ' . $website['name'], ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $id]);
            $msg = 'Form added.';
        }
        $test = null;
        if (post('test_now') && $data['status'] !== 'disabled') {
            set_time_limit(180);
            $test = test_payload(Monitor::testForm(load_form($id), true));
            Browser::shutdownShared();
            Mailer::processQueue(20);
            $msg .= ' Test ' . ($test['success'] ? 'PASSED' : 'FAILED: ' . $test['reason']) . '.';
        }
        json_success($msg, ['id' => $id, 'test' => $test]);

    case 'get':
        $f = load_form((int) post_int('id'));
        json_success('OK', ['form' => $f]);

    case 'delete':
        Auth::requireRole('admin');
        $f = load_form((int) post_int('id'));
        $website = DB::fetch("SELECT id, name, client_id FROM websites WHERE id = ?", [$f['website_id']]);
        DB::delete('forms', 'id = ?', [$f['id']]);
        ActivityLog::add('form_deleted', 'Form deleted: ' . $f['name'] . ' on ' . ($website['name'] ?? ''), ['client_id' => $website['client_id'] ?? null, 'website_id' => $f['website_id']]);
        json_success('Form deleted.');

    case 'toggle':
        Auth::requireAbility('forms');
        $f = load_form((int) post_int('id'));
        $new = $f['status'] === 'disabled' ? 'not_tested' : 'disabled';
        DB::update('forms', ['status' => $new], 'id = ?', [$f['id']]);
        json_success($new === 'disabled' ? 'Form disabled.' : 'Form enabled.');

    case 'test':
        Auth::requireAbility('monitor');
        set_time_limit(180);
        $f = load_form((int) post_int('id'));
        if ($f['status'] === 'disabled') json_error('This form is disabled. Enable it first.');
        $r = test_payload(Monitor::testForm($f, true));
        Browser::shutdownShared();
        Mailer::processQueue(20);
        $f = load_form($f['id']);
        $blocked = !empty($r['blocked']);
        $lastTest = DB::fetch("SELECT * FROM form_tests WHERE id = ?", [$r['test_id']]) ?: [];
        json_success(($r['success'] ? 'Form test PASSED' : ($blocked ? 'Form test BLOCKED' : ($r['outcome'] === 'config_error' ? 'Form test could not run – CONFIGURATION ERROR' : 'Form test FAILED'))) . ($r['reason'] ? ' – ' . $r['reason'] : '') . ' (' . $r['engine'] . ' engine)', [
            'result' => $r, 'form' => $f, 'status_html' => form_status_badge($f['status']), 'icon' => $r['success'] ? 'success' : ($blocked ? 'info' : ($r['outcome'] === 'config_error' ? 'warning' : 'error')),
            'outcome' => $r['outcome'], 'outcome_label' => form_outcome_label($r['outcome']), 'outcome_html' => form_outcome_badge($r['outcome']), 'blocked' => $blocked,
            'captcha_label' => $r['captcha'] ? form_captcha_label($r['captcha']) : null, 'interference' => $r['interference'] ?? null,
            'last_success_at' => $f['last_success_at'] ? format_datetime($f['last_success_at']) : 'Never', 'steps' => $lastTest ? json_decode((string) $lastTest['steps'], true) : [],
            'action' => $blocked ? 'Manual verification is required, or configure an owner-approved test method (test page / test parameter) for this website under Form → Automated Testing → CAPTCHA / Anti-Bot Protection.' : null,
        ]);

    case 'test_website':
        Auth::requireAbility('monitor');
        set_time_limit(300);
        $wid = (int) post_int('website_id');
        $forms = DB::fetchAll("SELECT * FROM forms WHERE website_id = ? AND tenant_id = ? AND status NOT IN ('disabled','removed')", [$wid, Tenant::id()]);
        $ok = 0; $fail = 0;
        foreach ($forms as $f) {
            $r = Monitor::testForm($f, true);
            $r['success'] ? $ok++ : $fail++;
        }
        Browser::shutdownShared();
        Mailer::processQueue(20);
        json_success('Tested ' . count($forms) . ' form(s): ' . $ok . ' passed, ' . $fail . ' failed.', ['passed' => $ok, 'failed' => $fail]);

    case 'incidents':
        $f = load_form((int) post_int('id'));
        $rows = DB::fetchAll("SELECT * FROM form_incidents WHERE form_id = ? ORDER BY id DESC LIMIT 50", [$f['id']]);
        json_success('OK', ['incidents' => $rows]);

    default:
        json_error('Unknown action.');
}
