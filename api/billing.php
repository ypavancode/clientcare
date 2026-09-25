<?php
/** Billing actions (no payment provider yet): switch to Free, start a trial of a paid plan, request an upgrade. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
if (!Auth::isOwner() && !Auth::isAdmin()) json_error('Only the workspace owner or an admin can change the plan.', 403);
data_changed();
$tenantId = Tenant::id();
$tenant = Tenant::current();
$action = post('action', '');

switch ($action) {
    case 'change_plan':
        if (!Auth::isOwner()) json_error('Only the workspace owner can change the plan.', 403);
        $plan = Tenant::planByCode((string) post('plan', ''));
        if (!$plan || $plan['price_monthly'] === null) json_error('Unknown plan.');
        $current = Tenant::plan();
        if ($plan['code'] === $current['code'] && empty($current['expired_from'])) json_error('This is already your current plan.');
        if ((int) $plan['price_monthly'] === 0) {
            Tenant::changePlan($tenantId, (int) $plan['id'], 'active', null, 'Switched to Free by ' . Auth::user()['name']);
            ActivityLog::add('plan_changed', 'Plan changed to Free');
            json_success('Your workspace now uses the Free plan.');
        }
        // paid plan without payment processing: one free trial per plan
        if (DB::value("SELECT id FROM subscriptions WHERE tenant_id = ? AND plan_id = ? AND trial_ends_at IS NOT NULL", [$tenantId, $plan['id']])) json_error('You have already used the free trial of this plan. Payments are being enabled – contact us to activate the plan.', 403);
        $days = max(1, (int) ($plan['trial_days'] ?: setting('trial_days', 14)));
        Tenant::changePlan($tenantId, (int) $plan['id'], 'trial', date('Y-m-d H:i:s', time() + $days * 86400), $days . '-day free trial started by ' . Auth::user()['name']);
        ActivityLog::add('plan_changed', 'Started a ' . $days . '-day trial of the ' . $plan['name'] . ' plan');
        json_success('Your ' . $days . '-day free trial of the ' . $plan['name'] . ' plan has started.');

    case 'request_upgrade':
        $plan = Tenant::planByCode((string) post('plan', ''));
        if (!$plan) json_error('Unknown plan.');
        $u = Auth::user();
        $body = Mailer::template('Upgrade request', 'A workspace asked to upgrade its plan.', ['Workspace' => $tenant['name'], 'Current plan' => Tenant::plan()['name'], 'Requested plan' => $plan['name'], 'Requested by' => $u['name'] . ' <' . $u['email'] . '>', 'Usage' => json_encode(Tenant::usage())], '', url('platform/customer.php?id=' . $tenantId), '#0d6efd');
        foreach (Notifier::platformAdminRecipients() as $r) Mailer::send($r['email'], 'Upgrade request – ' . $tenant['name'] . ' → ' . $plan['name'], $body, $r['name'], 'upgrade_request');
        Notifier::create('info', 'billing', 'Upgrade request: ' . $tenant['name'] . ' → ' . $plan['name'], 'Requested by ' . $u['name'] . ' (' . $u['email'] . ')', ['tenant_id' => null]);
        ActivityLog::add('plan_upgrade_requested', 'Upgrade to ' . $plan['name'] . ' requested');
        json_success('Thank you – your upgrade request has been sent. Our team will activate the ' . $plan['name'] . ' plan and contact you for payment.');

    case 'billing_email':
        $email = strtolower(trim((string) post('billing_email', '')));
        if (!valid_email($email)) json_error('Enter a valid billing email.', 422);
        DB::update('tenants', ['billing_email' => $email], 'id = ?', [$tenantId]);
        Tenant::forget($tenantId);
        json_success('Billing email updated.');

    default:
        json_error('Unknown action.');
}
