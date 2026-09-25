<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();
Auth::requireAbility('domains');

$action = post('action', '');

switch ($action) {
    case 'save':
        $id = post_int('id');
        $data = [
            'client_id'         => post_int('client_id') ?: 0,
            'website_id'        => post_int('website_id') ?: null,
            'domain_name'       => strtolower(trim(preg_replace('~^https?://~i', '', post('domain_name', '')), '/')),
            'registrar'         => post_nullable('registrar'),
            'registration_date' => post_nullable('registration_date'),
            'expiry_date'       => post_nullable('expiry_date'),
            'auto_renew'        => post('auto_renew') ? 1 : 0,
            'login_ref'         => post_nullable('login_ref'),
            'notes'             => post_nullable('notes'),
        ];
        $errors = [];
        if ($data['domain_name'] === '' || !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $data['domain_name'])) $errors['domain_name'] = 'Enter a valid domain name, e.g. example.com';
        if (!$data['client_id'] || !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$data['client_id'], Tenant::id()])) $errors['client_id'] = 'Please select a client.';
        if ($data['website_id'] && !DB::value("SELECT id FROM websites WHERE id = ? AND tenant_id = ?", [$data['website_id'], Tenant::id()])) $data['website_id'] = null;
        foreach (['registration_date', 'expiry_date'] as $k) {
            if ($data[$k] && !strtotime($data[$k])) $errors[$k] = 'Invalid date.';
        }
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($id) {
            if (!DB::value("SELECT id FROM domains WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()])) json_error('Domain not found.', 404);
            DB::update('domains', $data, 'id = ?', [$id]);
            ActivityLog::add('domain_updated', 'Domain updated: ' . $data['domain_name'], ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
            json_success('Domain updated.');
        }
        $id = DB::insert('domains', $data + ['tenant_id' => Tenant::id()]);
        ActivityLog::add('domain_added', 'Domain added: ' . $data['domain_name'], ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
        json_success('Domain added.', ['id' => $id]);

    case 'get':
        $d = DB::fetch("SELECT * FROM domains WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$d) json_error('Domain not found.', 404);
        json_success('OK', ['domain' => $d]);

    case 'delete':
        Auth::requireRole('admin', 'manager');
        $d = DB::fetch("SELECT * FROM domains WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$d) json_error('Domain not found.', 404);
        DB::delete('domains', 'id = ?', [$d['id']]);
        ActivityLog::add('domain_deleted', 'Domain deleted: ' . $d['domain_name'], ['client_id' => $d['client_id']]);
        json_success('Domain deleted.');

    default:
        json_error('Unknown action.');
}
