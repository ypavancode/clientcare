<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();

$action = post('action', '');

switch ($action) {
    case 'save':
        Auth::requireAbility('clients');
        $id = post_int('id');
        $data = [
            'name'               => post('name', ''),
            'company'            => post_nullable('company'),
            'email'              => post_nullable('email'),
            'phone'              => post_nullable('phone'),
            'whatsapp'           => post_nullable('whatsapp'),
            'address'            => post_nullable('address'),
            'status'             => in_array(post('status'), ['active', 'inactive', 'archived'], true) ? post('status') : 'active',
            'assigned_user_id'   => post_int('assigned_user_id') ?: null,
            'monitoring_enabled' => post('monitoring_enabled') ? 1 : 0,
            'notify_client'      => post('notify_client') ? 1 : 0,
            'notes'              => post_nullable('notes'),
        ];
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 150) $errors['name'] = 'Client name is required.';
        if ($data['email'] !== null && !valid_email($data['email'])) $errors['email'] = 'Enter a valid email address.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);

        if ($id) {
            $existing = DB::fetch("SELECT id, name FROM clients WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
            if (!$existing) json_error('Client not found.', 404);
            DB::update('clients', $data, 'id = ?', [$id]);
            ActivityLog::add('client_updated', 'Client updated: ' . $data['name'], ['client_id' => $id]);
            json_success('Client updated.', ['id' => $id]);
        }
        $data['created_by'] = Auth::id();
        $data['tenant_id'] = Tenant::id();
        $id = DB::insert('clients', $data);
        ActivityLog::add('client_created', 'Client created: ' . $data['name'], ['client_id' => $id]);
        json_success('Client added.', ['id' => $id, 'redirect' => post('stay') ? null : url('clients/view.php?id=' . $id)]);

    case 'status':
        Auth::requireAbility('clients');
        $id = post_int('id');
        $status = post('status');
        if (!$id || !in_array($status, ['active', 'inactive', 'archived'], true)) json_error('Invalid request.');
        $client = DB::fetch("SELECT name FROM clients WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
        if (!$client) json_error('Client not found.', 404);
        $upd = ['status' => $status];
        if ($status !== 'active' && post('disable_monitoring')) $upd['monitoring_enabled'] = 0;
        DB::update('clients', $upd, 'id = ?', [$id]);
        ActivityLog::add('client_updated', 'Client ' . $client['name'] . ' marked as ' . $status, ['client_id' => $id]);
        json_success('Client marked as ' . $status . '.');

    case 'delete':
        Auth::requireRole('admin');
        $id = post_int('id');
        $client = DB::fetch("SELECT name FROM clients WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
        if (!$client) json_error('Client not found.', 404);
        DB::delete('clients', 'id = ?', [$id]); // cascades to websites, pages, forms, credentials, notifications
        ActivityLog::add('client_deleted', 'Client permanently deleted: ' . $client['name'] . ' (including all websites, pages, forms and login details)');
        json_success('Client and all related records deleted.', ['redirect' => url('clients/index.php')]);

    case 'get':
        $id = post_int('id');
        $client = DB::fetch("SELECT * FROM clients WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
        if (!$client) json_error('Client not found.', 404);
        json_success('OK', ['client' => $client]);

    default:
        json_error('Unknown action.');
}
