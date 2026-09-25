<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();
Auth::requireAbility('hosting');

$action = post('action', '');

switch ($action) {
    case 'save':
        $id = post_int('id');
        $data = [
            'client_id'      => post_int('client_id') ?: 0,
            'website_id'     => post_int('website_id') ?: null,
            'provider'       => post('provider', ''),
            'server_ip'      => post_nullable('server_ip'),
            'plan'           => post_nullable('plan'),
            'start_date'     => post_nullable('start_date'),
            'expiry_date'    => post_nullable('expiry_date'),
            'renewal_status' => in_array(post('renewal_status'), ['auto', 'manual', 'cancelled'], true) ? post('renewal_status') : 'manual',
            'login_ref'      => post_nullable('login_ref'),
            'notes'          => post_nullable('notes'),
        ];
        $errors = [];
        if ($data['provider'] === '') $errors['provider'] = 'Hosting provider is required.';
        if (!$data['client_id'] || !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$data['client_id'], Tenant::id()])) $errors['client_id'] = 'Please select a client.';
        if ($data['website_id'] && !DB::value("SELECT id FROM websites WHERE id = ? AND tenant_id = ?", [$data['website_id'], Tenant::id()])) $data['website_id'] = null;
        foreach (['start_date', 'expiry_date'] as $k) {
            if ($data[$k] && !strtotime($data[$k])) $errors[$k] = 'Invalid date.';
        }
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($id) {
            if (!DB::value("SELECT id FROM hosting WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()])) json_error('Hosting record not found.', 404);
            DB::update('hosting', $data, 'id = ?', [$id]);
            ActivityLog::add('hosting_updated', 'Hosting updated: ' . $data['provider'], ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
            json_success('Hosting updated.');
        }
        $id = DB::insert('hosting', $data + ['tenant_id' => Tenant::id()]);
        ActivityLog::add('hosting_added', 'Hosting added: ' . $data['provider'], ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
        json_success('Hosting added.', ['id' => $id]);

    case 'get':
        $h = DB::fetch("SELECT * FROM hosting WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$h) json_error('Hosting record not found.', 404);
        json_success('OK', ['hosting' => $h]);

    case 'delete':
        Auth::requireRole('admin', 'manager');
        $h = DB::fetch("SELECT * FROM hosting WHERE id = ? AND tenant_id = ?", [post_int('id'), Tenant::id()]);
        if (!$h) json_error('Hosting record not found.', 404);
        DB::delete('hosting', 'id = ?', [$h['id']]);
        ActivityLog::add('hosting_deleted', 'Hosting deleted: ' . $h['provider'], ['client_id' => $h['client_id']]);
        json_success('Hosting record deleted.');

    default:
        json_error('Unknown action.');
}
