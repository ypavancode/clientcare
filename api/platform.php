<?php
/**
 * Platform (Super Admin) API: clients = customer workspaces (tenants), websites across all workspaces, plans & pricing,
 * subscriptions, impersonation, scheduler controls. Every state change is written to the Super Admin audit log
 * (ActivityLog::platform → activity_logs.is_platform) with target and result. All actions: platform admin + CSRF.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requirePlatformAdmin();
// GET action=scheduler_health – compact live status for the scheduler card (polled every 30 s)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get('action') === 'scheduler_health') {
    $h = Scheduler::health(true); $q = $h['queue'];
    $green = $h['healthy'] && $h['status'] !== 'delayed'; $tone = $green ? 'success' : ($h['status'] === 'delayed' ? 'warning' : 'danger');
    $beat = null; foreach ($h['workers'] as $w) if ($w['alive'] && (!$beat || strtotime($w['heartbeat_at']) > strtotime($beat))) $beat = $w['heartbeat_at']; $beat = $beat ?: ($h['last_tick'] ?? null);
    $errors = []; if (($h['dispatch']['status'] ?? '') === 'failed') $errors[] = 'Dispatcher: ' . ($h['dispatch']['last_message'] ?? 'failed');
    foreach ($h['queue_state'] as $qn => $st) if (!empty($st['last_error']) && !empty($st['last_failed_at']) && (empty($st['last_done_at']) || strtotime($st['last_failed_at']) > strtotime($st['last_done_at']))) $errors[] = ucfirst($qn) . ': ' . $st['last_error'];
    $dt = fn($v) => $v ? date('d M Y, H:i:s', strtotime($v)) : '—';
    json_response(['success' => true, 'tone' => $tone, 'status' => $h['status'], 'headline' => $green ? 'Connected & Running' : ($h['status'] === 'delayed' ? 'Running but delayed' : ($h['status'] === 'never' ? 'Not Connected' : 'Not Connected / Not Running')), 'message' => $h['message'], 'mode' => $h['mode'] === 'none' ? 'none' : ($h['mode'] === 'web' ? 'web heartbeat' : $h['mode']), 'checked' => date('H:i:s'),
        'last' => $dt($h['last_dispatch'] ?? null), 'next' => $dt($h['next_dispatch'] ?? null), 'beat' => $dt($beat), 'processed' => number_format($q['hour']['processed']) . ' / ' . number_format($q['day']['processed']), 'failed' => number_format($q['hour']['failed']), 'pending' => number_format($q['totals']['pending']) . ' / ' . number_format($q['totals']['due']),
        'workers' => $h['workers_alive'] . ' online (' . $h['workers_busy'] . ' busy)' . ($h['inline'] ? ' · inline fallback' : ''), 'avg' => number_format($q['hour']['avg_ms']) . ' ms / job · dispatch ' . ($h['dispatch_duration_ms'] !== null ? number_format($h['dispatch_duration_ms'] / 1000, 2) . ' s' : '—'), 'errors' => $errors ? implode(' · ', array_slice($errors, 0, 3)) : '']);
}
require_post();
data_changed();
$action = post('action', '');
$loadTenant = function (int $id): array { $t = DB::fetch("SELECT * FROM tenants WHERE id = ?", [$id]); if (!$t) json_error('Client not found.', 404); return $t; };
$loadWebsite = function (int $id): array { $w = DB::fetch("SELECT w.*, t.name AS tenant_name, c.name AS client_name FROM websites w LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN clients c ON c.id = w.client_id WHERE w.id = ?", [$id]); if (!$w) json_error('Website not found.', 404); return $w; };
$forgetTenant = function (int $id): void { Tenant::forget($id); Cache::bump('data'); Cache::forget('platform:users:counts'); Cache::forget('platform:overview'); };

switch ($action) {
    /* =============================== CLIENTS (customer workspaces) =============================== */
    case 'tenant_get':
        $t = $loadTenant((int) post_int('tenant_id'));
        json_success('OK', ['tenant' => $t]);

    case 'tenant_save':
        $t = $loadTenant((int) post_int('tenant_id'));
        $name = trim((string) post('name', ''));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) $errors['name'] = 'Enter the client / workspace name (2–150 characters).';
        $billing = trim((string) post('billing_email', ''));
        if ($billing !== '' && !valid_email($billing)) $errors['billing_email'] = 'Enter a valid billing email.';
        $alerts = [];
        foreach (preg_split('~[,;\s]+~', (string) post('alert_emails', ''), -1, PREG_SPLIT_NO_EMPTY) as $em) { if (!valid_email($em)) $errors['alert_emails'] = 'Alert recipient "' . $em . '" is not a valid email.'; $alerts[] = strtolower($em); }
        $tz = (string) post('timezone', '');
        if ($tz !== '' && !in_array($tz, timezone_identifiers_list(), true)) $errors['timezone'] = 'Unknown timezone.';
        $status = in_array(post('status'), ['active', 'pending', 'suspended', 'cancelled'], true) ? post('status') : $t['status'];
        if ((int) $t['id'] === 1 && $status !== 'active') $errors['status'] = 'The platform owner workspace must stay active.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        $data = ['name' => $name, 'billing_email' => $billing ?: null, 'phone' => post_nullable('phone') ? mb_substr(post('phone'), 0, 30) : null, 'country' => post_nullable('country') ? mb_substr(post('country'), 0, 60) : null, 'timezone' => $tz ?: null,
            'alert_emails' => $alerts ? implode(',', array_unique($alerts)) : null, 'notes' => post_nullable('notes'), 'status' => $status, 'white_label_name' => post_nullable('white_label_name') ? mb_substr(post('white_label_name'), 0, 120) : null];
        DB::update('tenants', $data, 'id = ?', [$t['id']]);
        $forgetTenant((int) $t['id']);
        if ($status !== $t['status']) Scheduler::reschedule((int) $t['id']);
        $changed = []; foreach ($data as $k => $v) if ((string) ($t[$k] ?? '') !== (string) ($v ?? '')) $changed[] = $k;
        ActivityLog::platform('client_updated', 'Client "' . $name . '" updated' . ($changed ? ' (' . implode(', ', $changed) . ')' : ' (no changes)'), 'tenant #' . $t['id'] . ' ' . $name, 'ok', ['tenant_id' => (int) $t['id']]);
        if ($status !== $t['status']) ActivityLog::platform($status === 'active' ? 'client_activated' : 'client_deactivated', 'Client "' . $name . '" set to ' . $status, 'tenant #' . $t['id'] . ' ' . $name, 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Client updated.');

    case 'set_status':
        $t = $loadTenant((int) post_int('tenant_id'));
        $status = in_array(post('status'), ['active', 'suspended', 'cancelled', 'pending'], true) ? post('status') : 'active';
        if ((int) $t['id'] === 1 && $status !== 'active') { ActivityLog::platform('client_deactivated', 'Refused to deactivate the platform owner workspace', 'tenant #1', 'denied'); json_error('The platform owner workspace cannot be suspended.'); }
        DB::update('tenants', ['status' => $status], 'id = ?', [$t['id']]);
        if ($status === 'active') DB::query("UPDATE users SET email_verified_at = IFNULL(email_verified_at, NOW()) WHERE tenant_id = ? AND role = 'owner'", [$t['id']]);
        $forgetTenant((int) $t['id']);
        if ($status !== $t['status']) Scheduler::reschedule((int) $t['id']);
        ActivityLog::platform($status === 'active' ? 'client_activated' : 'client_deactivated', 'Client "' . $t['name'] . '" set to ' . $status . ' (was ' . $t['status'] . ')', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Client "' . $t['name'] . '" is now ' . $status . '.');

    case 'verify_owner':
        $t = $loadTenant((int) post_int('tenant_id'));
        DB::query("UPDATE users SET email_verified_at = IFNULL(email_verified_at, NOW()) WHERE tenant_id = ? AND role = 'owner'", [$t['id']]);
        Tenant::activate((int) $t['id']);
        $forgetTenant((int) $t['id']);
        ActivityLog::platform('client_activated', 'Owner of "' . $t['name'] . '" marked verified, workspace activated', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Owner marked as verified and workspace activated.');

    case 'note':
        $t = $loadTenant((int) post_int('tenant_id'));
        DB::update('tenants', ['notes' => post_nullable('notes')], 'id = ?', [$t['id']]);
        ActivityLog::platform('client_note', 'Notes of "' . $t['name'] . '" updated', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Notes saved.');

    case 'act_as':
        $t = $loadTenant((int) post_int('tenant_id'));
        $_SESSION['act_as_tenant'] = (int) $t['id'];
        Tenant::forget();
        ActivityLog::platform('impersonate', 'Super Admin viewing workspace "' . $t['name'] . '"', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Now viewing ' . $t['name'] . ' as a platform admin.', ['redirect' => url('dashboard/index.php')]);

    case 'act_as_stop':
        $was = (int) ($_SESSION['act_as_tenant'] ?? 0);
        unset($_SESSION['act_as_tenant']);
        Tenant::forget();
        ActivityLog::platform('platform_impersonate', 'Stopped viewing as customer' . ($was ? ' #' . $was : ''), $was ? 'tenant #' . $was : 'platform', 'ok', ['tenant_id' => $was ?: null]);
        json_success('Back to your own workspace.', ['redirect' => url('platform/customers.php')]);

    case 'delete_tenant':
        $t = $loadTenant((int) post_int('tenant_id'));
        if ((int) $t['id'] === 1) { ActivityLog::platform('client_deleted', 'Refused to delete the platform owner workspace', 'tenant #1', 'denied'); json_error('The platform owner workspace cannot be deleted.'); }
        if (trim((string) post('confirm', '')) !== $t['slug']) json_error('Type the client slug (' . $t['slug'] . ') exactly to confirm the deletion.', 422);
        $counts = ['websites' => (int) DB::value("SELECT COUNT(*) FROM websites WHERE tenant_id = ?", [$t['id']]), 'users' => (int) DB::value("SELECT COUNT(*) FROM users WHERE tenant_id = ?", [$t['id']])];
        DB::begin();
        try {
            DB::delete('users', 'tenant_id = ?', [$t['id']]);
            DB::delete('clients', 'tenant_id = ?', [$t['id']]); // cascades websites, pages, forms, incidents…
            foreach (['subscriptions', 'tenant_settings', 'api_keys', 'webhooks', 'status_pages', 'team_invitations', 'notifications', 'jobs'] as $tbl) { try { DB::delete($tbl, 'tenant_id = ?', [$t['id']]); } catch (Throwable $ignored) {} }
            DB::delete('email_queue', "tenant_id = ? AND status = 'pending'", [$t['id']]);
            DB::delete('tenants', 'id = ?', [$t['id']]);
            DB::commit();
        } catch (Throwable $e) { DB::rollBack(); ActivityLog::platform('client_deleted', 'Deleting client "' . $t['name'] . '" failed: ' . $e->getMessage(), 'tenant #' . $t['id'] . ' ' . $t['name'], 'failed'); throw $e; }
        $forgetTenant((int) $t['id']);
        ActivityLog::platform('client_deleted', 'Client "' . $t['name'] . '" (' . $t['slug'] . ') permanently deleted with ' . $counts['websites'] . ' website(s) and ' . $counts['users'] . ' user(s)', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok');
        json_success('Client and all its data deleted.', ['redirect' => url('platform/customers.php')]);

    /* =============================== WEBSITES (all workspaces) =============================== */
    case 'website_get':
        $w = $loadWebsite((int) post_int('id'));
        unset($w['wp_password']);
        $w['clients'] = DB::fetchAll("SELECT id, name FROM clients WHERE tenant_id = ? AND status <> 'archived' ORDER BY name LIMIT 300", [$w['tenant_id']]);
        $w['pv_month'] = (int) DB::value("SELECT COALESCE(SUM(pageviews),0) FROM analytics_daily WHERE website_id = ? AND day >= ?", [$w['id'], date('Y-m-01')]);
        json_success('OK', ['website' => $w]);

    case 'website_save':
        $w = $loadWebsite((int) post_int('id'));
        $data = [
            'name' => trim((string) post('name', '')), 'url' => normalize_url(post('url', '')), 'technology' => in_array(post('technology'), technologies(), true) ? post('technology') : $w['technology'],
            'client_id' => post_int('client_id') ?: (int) $w['client_id'], 'monitoring_enabled' => post('monitoring_enabled') ? 1 : 0, 'page_monitoring_enabled' => post('page_monitoring_enabled') ? 1 : 0,
            'form_discovery_enabled' => post('form_discovery_enabled') ? 1 : 0, 'max_pages' => max(0, min(500, post_int('max_pages', 0) ?? 0)), 'expect_text' => post_nullable('expect_text'), 'notes' => post_nullable('notes'),
        ];
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 150) $errors['name'] = 'Website name is required.';
        if (!valid_url($data['url'])) $errors['url'] = 'Enter a valid URL, e.g. https://example.com';
        if (!DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$data['client_id'], $w['tenant_id']])) $errors['client_id'] = 'Choose a client of the same workspace.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($w['url'] !== $data['url']) { $data += ['status' => 'unknown', 'ssl_status' => 'unknown', 'pages_discovered_at' => null, 'page_health' => 'unknown', 'pages_total' => 0, 'pages_ok' => 0, 'pages_failed' => 0]; DB::delete('website_pages', 'website_id = ?', [$w['id']]); }
        if (!$data['monitoring_enabled'] && $w['monitoring_enabled']) $data['status'] = 'paused';
        if ($data['monitoring_enabled'] && $w['status'] === 'paused') $data['status'] = 'unknown';
        DB::update('websites', $data, 'id = ?', [$w['id']]);
        Cache::bump('data'); Cache::forget('platform:overview');
        $changed = []; foreach ($data as $k => $v) if ((string) ($w[$k] ?? '') !== (string) ($v ?? '')) $changed[] = $k;
        ActivityLog::platform('website_updated', 'Website "' . $data['name'] . '" (' . $w['tenant_name'] . ') updated' . ($changed ? ': ' . implode(', ', $changed) : ' (no changes)'), 'website #' . $w['id'] . ' ' . $data['url'], 'ok', ['tenant_id' => (int) $w['tenant_id'], 'website_id' => (int) $w['id'], 'client_id' => (int) $data['client_id']]);
        if (($data['monitoring_enabled'] ?? 1) !== (int) $w['monitoring_enabled']) ActivityLog::platform($data['monitoring_enabled'] ? 'website_enabled' : 'website_disabled', 'Monitoring ' . ($data['monitoring_enabled'] ? 'enabled' : 'disabled') . ' for "' . $data['name'] . '"', 'website #' . $w['id'] . ' ' . $data['url'], 'ok', ['tenant_id' => (int) $w['tenant_id'], 'website_id' => (int) $w['id']]);
        json_success('Website updated.');

    case 'website_toggle':
        $w = $loadWebsite((int) post_int('id'));
        $on = post('enable') !== null ? (post('enable') ? 1 : 0) : ($w['monitoring_enabled'] ? 0 : 1);
        DB::update('websites', ['monitoring_enabled' => $on, 'status' => $on ? 'unknown' : 'paused', 'next_check_at' => $on ? date('Y-m-d H:i:s') : $w['next_check_at']], 'id = ?', [$w['id']]);
        Cache::bump('data'); Cache::forget('platform:overview');
        ActivityLog::platform($on ? 'website_enabled' : 'website_disabled', 'Website "' . $w['name'] . '" (' . $w['tenant_name'] . ') ' . ($on ? 'enabled' : 'disabled'), 'website #' . $w['id'] . ' ' . $w['url'], 'ok', ['tenant_id' => (int) $w['tenant_id'], 'website_id' => (int) $w['id']]);
        json_success('Website "' . $w['name'] . '" ' . ($on ? 'enabled – monitoring resumes on the next dispatch.' : 'disabled – monitoring paused.'));

    case 'website_delete':
        $w = $loadWebsite((int) post_int('id'));
        DB::delete('websites', 'id = ?', [$w['id']]); // cascades pages, forms, incidents, analytics
        Cache::bump('data'); Cache::forget('platform:overview'); if ($w['analytics_key']) Cache::forget('analytics:site:' . $w['analytics_key']);
        ActivityLog::platform('website_deleted', 'Website "' . $w['name'] . '" (' . $w['url'] . ', ' . $w['tenant_name'] . ') deleted with its pages, forms and history', 'website #' . $w['id'] . ' ' . $w['url'], 'ok', ['tenant_id' => (int) $w['tenant_id']]);
        json_success('Website deleted.');

    case 'website_tracking':
        $w = $loadWebsite((int) post_int('id'));
        $on = post('enable') ? 1 : 0;
        if ($on) { $key = Analytics::enable((int) $w['id']); Cache::forget('analytics:site:' . $key); }
        else { DB::update('websites', ['analytics_enabled' => 0], 'id = ?', [$w['id']]); if ($w['analytics_key']) Cache::forget('analytics:site:' . $w['analytics_key']); }
        ActivityLog::platform($on ? 'website_enabled' : 'website_disabled', 'Visitor tracking ' . ($on ? 'enabled' : 'paused') . ' for "' . $w['name'] . '"', 'website #' . $w['id'] . ' ' . $w['url'], 'ok', ['tenant_id' => (int) $w['tenant_id'], 'website_id' => (int) $w['id']]);
        json_success('Tracking ' . ($on ? 'enabled.' : 'paused (data kept).'), ['key' => $on ? ($key ?? $w['analytics_key']) : $w['analytics_key']]);

    case 'website_regenerate_key':
        $w = $loadWebsite((int) post_int('id'));
        $key = Analytics::newKey();
        DB::update('websites', ['analytics_key' => $key, 'analytics_first_event_at' => null, 'analytics_last_event_at' => null], 'id = ?', [$w['id']]); // status returns to "not detected" until the new snippet delivers data
        if ($w['analytics_key']) Cache::forget('analytics:site:' . $w['analytics_key']);
        ActivityLog::platform('tracking_key', 'Tracking ID regenerated for "' . $w['name'] . '" (' . $w['tenant_name'] . ') – the old snippet stops collecting', 'website #' . $w['id'] . ' ' . $w['url'], 'ok', ['tenant_id' => (int) $w['tenant_id'], 'website_id' => (int) $w['id']]);
        json_success('New tracking ID generated: ' . $key . '. The website must be updated with the new snippet.', ['key' => $key, 'snippet' => Analytics::snippet($key)]);

    /* =============================== PLANS, PRICING, SUBSCRIPTIONS =============================== */
    case 'set_plan':
        $t = $loadTenant((int) post_int('tenant_id'));
        $plan = DB::fetch("SELECT * FROM plans WHERE id = ?", [post_int('plan_id')]);
        if (!$plan) json_error('Unknown plan.');
        $mode = post('mode') === 'trial' ? 'trial' : 'active';
        $days = max(1, min(365, post_int('trial_days', 14) ?? 14));
        Tenant::changePlan((int) $t['id'], (int) $plan['id'], $mode, $mode === 'trial' ? date('Y-m-d H:i:s', time() + $days * 86400) : null, 'Set by platform admin ' . Auth::user()['name'] . (post_nullable('note') ? ': ' . post('note') : ''));
        $forgetTenant((int) $t['id']);
        ActivityLog::platform('plan_changed', 'Plan of "' . $t['name'] . '" set to ' . $plan['name'] . ($mode === 'trial' ? ' (trial ' . $days . ' days)' : ' (active)') . (post_nullable('note') ? ' – ' . post('note') : ''), 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        Notifier::create('info', 'billing', 'Plan updated: ' . $plan['name'], 'Your workspace plan is now ' . $plan['name'] . ($mode === 'trial' ? ' (trial for ' . $days . ' days)' : '') . '.', ['tenant_id' => (int) $t['id']]);
        json_success('Plan of ' . $t['name'] . ' set to ' . $plan['name'] . '.');

    case 'extend_trial':
        $t = $loadTenant((int) post_int('tenant_id'));
        $days = max(1, min(365, post_int('days', 14) ?? 14));
        $base = $t['trial_ends_at'] && strtotime($t['trial_ends_at']) > time() ? strtotime($t['trial_ends_at']) : time();
        $until = date('Y-m-d H:i:s', $base + $days * 86400);
        DB::update('tenants', ['trial_ends_at' => $until, 'subscription_status' => 'trial'], 'id = ?', [$t['id']]);
        DB::query("UPDATE subscriptions SET trial_ends_at = ?, status = 'trial' WHERE tenant_id = ? AND status IN ('trial','expired') ORDER BY id DESC LIMIT 1", [$until, $t['id']]);
        $forgetTenant((int) $t['id']);
        ActivityLog::platform('trial_extended', 'Trial of "' . $t['name'] . '" extended by ' . $days . ' days (until ' . $until . ')', 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Trial extended until ' . format_datetime($until) . '.');

    case 'set_subscription_status':
        $t = $loadTenant((int) post_int('tenant_id'));
        $status = in_array(post('status'), ['free', 'trial', 'active', 'past_due', 'cancelled', 'expired'], true) ? post('status') : null;
        if (!$status) json_error('Unknown subscription status.');
        DB::update('tenants', ['subscription_status' => $status], 'id = ?', [$t['id']]);
        if (in_array($status, ['trial', 'active', 'past_due', 'cancelled', 'expired'], true)) DB::query("UPDATE subscriptions SET status = ?, cancelled_at = IF(? = 'cancelled', NOW(), cancelled_at), ended_at = IF(? IN ('cancelled','expired'), NOW(), ended_at) WHERE tenant_id = ? ORDER BY id DESC LIMIT 1", [$status, $status, $status, $t['id']]);
        $forgetTenant((int) $t['id']);
        Scheduler::reschedule((int) $t['id']); // expired / cancelled → the dispatcher skips the workspace; renewed → overdue targets are due at once
        ActivityLog::platform('subscription_status', 'Subscription of "' . $t['name'] . '" set to ' . $status . ' (was ' . $t['subscription_status'] . ')' . (post_nullable('note') ? ' – ' . post('note') : ''), 'tenant #' . $t['id'] . ' ' . $t['name'], 'ok', ['tenant_id' => (int) $t['id']]);
        json_success('Subscription of ' . $t['name'] . ' is now ' . str_replace('_', ' ', $status) . '.');

    case 'subscription_history':
        $t = $loadTenant((int) post_int('tenant_id'));
        $rows = DB::fetchAll("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 50", [$t['id']]);
        json_success('OK', ['tenant' => ['id' => $t['id'], 'name' => $t['name'], 'subscription_status' => $t['subscription_status'], 'trial_ends_at' => $t['trial_ends_at']], 'rows' => $rows]);

    case 'plan_save':
        Auth::requirePlatformOwner();
        $id = post_int('id');
        $data = [
            'name' => trim((string) post('name', '')), 'tagline' => post_nullable('tagline'), 'price_monthly' => post('price_monthly') === '' || post('price_monthly') === null ? null : max(0, post_int('price_monthly', 0)),
            'is_popular' => post('is_popular') ? 1 : 0, 'is_public' => post('is_public') ? 1 : 0, 'trial_days' => max(0, min(365, post_int('trial_days', 0) ?? 0)), 'sort_order' => post_int('sort_order', 100) ?? 100, 'status' => post('status') === 'inactive' ? 'inactive' : 'active',
            'website_interval' => max(1, post_int('website_interval', 60) ?? 60), 'page_interval' => max(1, post_int('page_interval', 60) ?? 60), 'form_interval' => max(1, post_int('form_interval', 60) ?? 60), 'ssl_interval' => max(1, post_int('ssl_interval', 60) ?? 60), 'retention_days' => max(1, post_int('retention_days', 7) ?? 7),
        ];
        foreach (['max_websites', 'max_pages', 'max_forms', 'max_users', 'max_status_pages', 'max_pageviews_month'] as $k) $data[$k] = post($k) === '' || post($k) === null ? null : max(0, post_int($k, 0));
        $features = json_decode((string) post('features', ''), true);
        if (!is_array($features)) json_error('Features must be valid JSON.', 422, ['errors' => ['features' => 'Invalid JSON']]);
        $highlights = array_values(array_filter(array_map('trim', preg_split('~\r?\n~', (string) post('highlights', '')))));
        if (post('f_analytics') !== null) $features['analytics'] = in_array(post('f_analytics'), ['none', 'basic', 'full'], true) ? post('f_analytics') : 'basic';
        if (post('f_analytics_retention') !== null) $features['analytics_retention'] = max(7, min(730, post_int('f_analytics_retention', 30) ?? 30));
        if (post('f_geo') !== null) $features['geo'] = in_array(post('f_geo'), ['country', 'region', 'city'], true) ? post('f_geo') : 'country';
        $features['realtime'] = post('f_realtime') ? 1 : 0;
        if (post('f_max_events_month') !== null) $features['max_events_month'] = max(0, post_int('f_max_events_month', 0) ?? 0);
        if (post('f_reports') !== null) $features['reports'] = in_array(post('f_reports'), ['none', 'basic', 'standard', 'advanced'], true) ? post('f_reports') : 'basic';
        $data['features'] = json_encode($features, JSON_UNESCAPED_SLASHES);
        $data['highlights'] = json_encode($highlights, JSON_UNESCAPED_UNICODE);
        if ($data['name'] === '') json_error('Name is required.', 422, ['errors' => ['name' => 'Required']]);
        $old = $id ? DB::fetch("SELECT * FROM plans WHERE id = ?", [$id]) : null;
        if ($id && !$old) json_error('Plan not found.', 404);
        if ($id) DB::update('plans', $data, 'id = ?', [$id]);
        else { $code = trim(preg_replace('~[^a-z0-9_-]~', '', strtolower((string) post('code', '')))); if ($code === '' || DB::value("SELECT id FROM plans WHERE code = ?", [$code])) json_error('Enter a unique plan code.', 422, ['errors' => ['code' => 'Unique code required']]); $id = DB::insert('plans', $data + ['code' => $code]); }
        Cache::forget('plans:all'); Cache::forget('plans:public'); Tenant::forget();
        // interval edits re-align every workspace on this plan at once (the plan is the source of truth for the cadence)
        $intervalsChanged = !$old || array_filter(['website_interval', 'page_interval', 'form_interval', 'ssl_interval'], fn($k) => (int) $old[$k] !== (int) $data[$k]);
        if ($intervalsChanged) Scheduler::reschedule(null, (int) $id);
        $priceChanged = $old && (string) $old['price_monthly'] !== (string) $data['price_monthly'];
        $limitsChanged = []; if ($old) foreach (['max_websites', 'max_pages', 'max_forms', 'max_users', 'max_status_pages', 'max_pageviews_month', 'retention_days', 'features'] as $k) if ((string) $old[$k] !== (string) $data[$k]) $limitsChanged[] = $k;
        ActivityLog::platform($priceChanged ? 'pricing_changed' : 'plan_saved', ($old ? 'Plan "' . $data['name'] . '" updated' : 'Plan "' . $data['name'] . '" created') . ($priceChanged ? ' – price ' . ($old['price_monthly'] ?? 'custom') . ' → ' . ($data['price_monthly'] ?? 'custom') : '') . ($limitsChanged ? ' – changed: ' . implode(', ', $limitsChanged) : ''), 'plan #' . $id . ' ' . ($old['code'] ?? ($code ?? '')), 'ok');
        json_success('Plan saved.', ['id' => $id]);

    case 'plan_delete':
        Auth::requirePlatformOwner();
        $p = DB::fetch("SELECT * FROM plans WHERE id = ?", [post_int('id')]);
        if (!$p) json_error('Plan not found.', 404);
        $n = (int) DB::value("SELECT COUNT(*) FROM tenants WHERE plan_id = ?", [$p['id']]);
        if ($n) json_error('This plan is used by ' . $n . ' client(s). Move them to another plan first, or set the plan to inactive.');
        if (setting('trial_plan') === $p['code'] || $p['code'] === 'free') json_error('The free plan and the trial plan cannot be deleted – set them to inactive instead.');
        DB::delete('plans', 'id = ?', [$p['id']]);
        Cache::forget('plans:all'); Cache::forget('plans:public');
        ActivityLog::platform('plan_saved', 'Plan "' . $p['name'] . '" deleted', 'plan #' . $p['id'] . ' ' . $p['code'], 'ok');
        json_success('Plan deleted.');

    /* =============================== USER ACCESS (any workspace) =============================== */
    case 'user_status':
        $u = DB::fetch("SELECT u.*, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ?", [post_int('id')]);
        if (!$u) json_error('User not found.', 404);
        if ((int) $u['id'] === (int) Auth::id()) json_error('You cannot deactivate your own account.');
        if ($u['is_platform_owner'] && !Auth::isPlatformOwner()) { ActivityLog::platform('security', 'Refused to change the Owner account ' . $u['email'], 'user #' . $u['id'], 'denied'); json_error('Only the platform Owner can change an Owner account.', 403); }
        $new = $u['status'] === 'active' ? 'inactive' : 'active';
        DB::update('users', ['status' => $new], 'id = ?', [$u['id']]);
        if ($new === 'inactive') { try { DB::delete('remember_tokens', 'user_id = ?', [$u['id']]); DB::delete('sessions', 'user_id = ?', [$u['id']]); } catch (Throwable $ignored) {} }
        Cache::forget('platform:users:counts'); Cache::forget('users:options:' . (int) $u['tenant_id']);
        ActivityLog::platform($new === 'active' ? 'client_activated' : 'client_deactivated', 'User ' . $u['email'] . ($u['tenant_name'] ? ' (' . $u['tenant_name'] . ')' : '') . ' set to ' . $new, 'user #' . $u['id'] . ' ' . $u['email'], 'ok', ['tenant_id' => $u['tenant_id'] ? (int) $u['tenant_id'] : null]);
        json_success('User ' . $u['email'] . ' is now ' . $new . '.');

    case 'user_reset_link':
        // secure credential workflow: a one-hour reset link is emailed to the user; the password itself is never seen
        $u = DB::fetch("SELECT u.*, t.name AS tenant_name FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.id = ?", [post_int('id')]);
        if (!$u) json_error('User not found.', 404);
        if ($u['status'] !== 'active') json_error('The account is inactive – activate it first.');
        $token = Auth::createResetToken((int) $u['id']);
        $link = url('auth/reset-password.php?token=' . $token);
        $html = Mailer::template('Reset your password', 'The platform administrator requested a password reset for your ' . setting('platform_name', 'Outline Monitor') . ' account. The link is valid for 1 hour.', ['Account' => $u['email'], 'Workspace' => $u['tenant_name'] ?: '—'], 'Reset password', $link, '#0d6efd');
        $r = Mailer::send($u['email'], 'Password reset – ' . setting('platform_name', 'Outline Monitor'), $html, $u['name'], 'password_reset');
        ActivityLog::platform('security', 'Password reset link ' . ($r['ok'] ? 'emailed to ' : 'generated for ') . $u['email'], 'user #' . $u['id'] . ' ' . $u['email'], $r['ok'] ? 'ok' : 'failed', ['tenant_id' => $u['tenant_id'] ? (int) $u['tenant_id'] : null]);
        json_success($r['ok'] ? 'Reset link emailed to ' . $u['email'] . '.' : 'Email could not be sent (' . $r['error'] . '). Share this link with the user manually:', ['link' => $link, 'emailed' => $r['ok']]);

    case 'user_platform_role':
        Auth::requirePlatformOwner();
        $u = DB::fetch("SELECT * FROM users WHERE id = ?", [post_int('id')]);
        if (!$u) json_error('User not found.', 404);
        $role = in_array(post('role'), ['none', 'admin', 'owner'], true) ? post('role') : 'none';
        if ((int) $u['id'] === (int) Auth::id() && $role !== 'owner') json_error('You cannot remove your own Owner access.');
        if ($u['is_platform_owner'] && $role !== 'owner' && (int) DB::value("SELECT COUNT(*) FROM users WHERE is_platform_owner = 1 AND status = 'active'") <= 1) json_error('At least one active Owner must remain.');
        DB::update('users', ['is_platform_admin' => $role === 'none' ? 0 : 1, 'is_platform_owner' => $role === 'owner' ? 1 : 0], 'id = ?', [$u['id']]);
        Cache::forget('platform:users:counts');
        ActivityLog::platform('security', 'Platform role of ' . $u['email'] . ' set to ' . ($role === 'none' ? 'no platform access' : ($role === 'owner' ? 'Owner' : 'Super Admin')), 'user #' . $u['id'] . ' ' . $u['email'], 'ok');
        json_success('Platform role updated: ' . $u['email'] . ' is now ' . ($role === 'none' ? 'a normal workspace user' : ($role === 'owner' ? 'Owner' : 'Super Admin')) . '.');

    /* =============================== SCHEDULER / SYSTEM =============================== */
    /* =============================== MAINTENANCE MODE (one-click Live / Maintenance switch) =============================== */
    case 'maintenance_toggle':
        // Customers (pages + AJAX) get the 503 maintenance page via Auth::requireLogin(); Super Admins keep the console.
        // Never affected: the analytics beacon (/collect), the tracker (/analytics/KEY.js), engine hops, health check, public status pages.
        $on = post_nullable('enable') !== null ? (int) (bool) post_int('enable', 0) : (int) !setting('maintenance_mode', 0);
        set_setting('maintenance_mode', (string) $on);
        if (post('message') !== null) set_setting('maintenance_message', mb_substr(trim((string) post('message', '')), 0, 300));
        Cache::forget('platform:overview');
        ActivityLog::platform('maintenance', 'Maintenance mode ' . ($on ? 'ENABLED – customers now see the 503 maintenance page, Super Admins keep access' : 'disabled – the platform is live again'), 'platform', 'ok');
        json_success($on ? 'Maintenance mode is ON – customers see the maintenance page, Super Admins keep full access.' : 'Platform is live again.', ['maintenance' => $on]);
    case 'dispatch_now':
        $r = Scheduler::dispatch('manual@' . Auth::id(), true);
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state');
        ActivityLog::platform('job', 'Ran the dispatcher manually', 'scheduler');
        json_success(empty($r['skipped']) ? 'Dispatched: ' . array_sum(array_intersect_key($r, array_flip(['website', 'ssl', 'page', 'form', 'discovery', 'analytics', 'system']))) . ' job(s) queued.' : 'Dispatcher skipped (' . $r['skipped'] . ').');

    case 'process_now':
        // inline processing for installs without a worker (bounded so the request always returns)
        set_time_limit(120); ignore_user_abort(true);
        [$d, $f] = Scheduler::work('manual@' . gethostname() . '#' . getmypid(), 60, Scheduler::QUEUE_ORDER, 200);
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state');
        ActivityLog::platform('job', "Processed the queue manually: $d done, $f failed", 'scheduler', $f ? 'failed' : 'ok');
        json_success("Processed $d job(s)" . ($f ? ", $f failed" : '') . '.');

    case 'job_retry':
        $ok = Queue::retry(post_int('id'));
        ActivityLog::platform('job', ($ok ? 'Retried' : 'Retry refused for') . ' dead job #' . post_int('id'), 'job #' . post_int('id'), $ok ? 'ok' : 'failed');
        json_success($ok ? 'Job queued again.' : 'Job not found.');

    case 'job_discard':
        $ok = Queue::discard(post_int('id'));
        ActivityLog::platform('job', ($ok ? 'Discarded' : 'Discard refused for') . ' dead job #' . post_int('id'), 'job #' . post_int('id'), $ok ? 'ok' : 'failed');
        json_success($ok ? 'Job discarded.' : 'Job not found.');

    case 'jobs_retry_all':
        $n = Queue::retryAllDead();
        ActivityLog::platform('job', "Retried all dead jobs ($n)", 'scheduler');
        json_success($n . ' dead job(s) queued again.');

    case 'worker_forget':
        DB::query("DELETE FROM workers WHERE id = ? AND status IN ('stopped','dead')", [(string) post('id', '')]);
        Cache::forget('scheduler:health');
        ActivityLog::platform('job', 'Removed stopped worker ' . (string) post('id', '') . ' from the list', 'worker ' . (string) post('id', ''));
        json_success('Worker removed from the list.');

    case 'engine_start':
        // fire a hop and (when possible) start a worker – the same thing the watchdog does automatically
        $err = null; $fired = Engine::fireHop($err);
        $e2 = null; $spawned = setting('worker_autostart', 1) && Engine::canExec() && Scheduler::workersAlive() === 0 ? Engine::spawnWorker($e2, true) : false;
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state'); Cache::forget('engine:checked');
        ActivityLog::platform('engine', 'Engine start requested: hop ' . ($fired ? 'fired' : 'failed – ' . $err) . ($spawned ? ', worker started' : ($e2 ? ', worker not started (' . $e2 . ')' : '')), 'engine', $fired ? 'ok' : 'failed');
        if (!$fired) json_error('The engine could not reach its own hop URL: ' . $err . ' (' . Engine::hopUrl() . ')');
        json_success('Engine started – a hop is running now' . ($spawned ? ' and a background worker was launched' : '') . '.');

    case 'engine_test':
        @set_time_limit(60);
        $r = Engine::selfTest(10);
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state');
        ActivityLog::platform('engine', 'Engine loopback test: ' . ($r['ok'] ? 'ok in ' . $r['seconds'] . ' s' : 'failed – ' . $r['error']), 'engine', $r['ok'] ? 'ok' : 'failed');
        json_response(['success' => $r['ok'], 'message' => $r['ok'] ? '🟢 Loopback works – the hop ran ' . $r['seconds'] . ' s after it was fired.' : '🔴 ' . $r['error']] + $r);

    case 'engine_toggle':
        $on = (int) !setting('engine_enabled', 1);
        set_setting('engine_enabled', (string) $on);
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state'); Cache::forget('engine:checked');
        ActivityLog::platform('engine', 'Execution engine ' . ($on ? 'enabled' : 'disabled'), 'engine');
        if ($on) { $err = null; Engine::fireHop($err); }
        json_success($on ? 'Engine enabled – the chain starts now.' : 'Engine disabled. Monitoring only runs while a worker process or an external trigger calls the scheduler.');

    case 'worker_start':
        $err = null;
        if (!Engine::spawnWorker($err, true)) json_error('Could not start a worker: ' . $err);
        Cache::forget('scheduler:health'); Cache::forget('scheduler:state');
        ActivityLog::platform('engine', 'Background worker started manually', 'engine');
        json_success('Worker process started – it registers itself within a few seconds.');

    case 'job_toggle':
    case 'job_reset':
        $name = (string) post('name', '');
        if (!isset(Scheduler::JOBS[$name])) json_error('Unknown job.', 404);
        if ($action === 'job_toggle') { DB::query("UPDATE scheduler_jobs SET enabled = 1 - enabled WHERE name = ?", [$name]); }
        else { DB::query("UPDATE scheduler_jobs SET locked_until = NULL, locked_by = NULL, status = 'idle', fail_count = 0, last_started_at = NULL WHERE name = ?", [$name]); }
        Cache::forget('scheduler:state');
        ActivityLog::platform('job', ($action === 'job_toggle' ? 'Toggled' : 'Reset') . ' background job ' . $name, 'scheduler ' . $name);
        json_success($action === 'job_toggle' ? 'Job updated.' : 'Job unlocked – it will run on the next tick.');

    default:
        json_error('Unknown action.');
}
