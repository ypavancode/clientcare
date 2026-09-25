<?php
/**
 * Client login credentials (hosting, domain, cPanel, FTP, email, other) – ADMIN ONLY.
 * Passwords are AES-256 encrypted with APP_KEY before they are stored and are never included in page HTML:
 * they are only returned by the "reveal" action, which is logged in the activity log.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
Auth::requireRole('admin');
data_changed();

$action = post('action', '');

function load_credential(int $id): array
{
    $c = DB::fetch("SELECT * FROM client_credentials WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
    if (!$c) json_error('Login record not found.', 404);
    return $c;
}

switch ($action) {
    case 'save':
        $id = post_int('id');
        $data = [
            'client_id'  => post_int('client_id') ?: 0,
            'website_id' => post_int('website_id') ?: null,
            'type'       => array_key_exists(post('type'), credential_types()) ? post('type') : 'other',
            'label'      => post('label', ''),
            'provider'   => post_nullable('provider') !== null ? mb_substr(post('provider'), 0, 120) : null,
            'login_url'  => post_nullable('login_url') ? normalize_url(post('login_url')) : null,
            'username'   => post_nullable('username'),
            'notes'      => post_nullable('notes'),
        ];
        $errors = [];
        if ($data['label'] === '' || mb_strlen($data['label']) > 120) $errors['label'] = 'Login name is required (max 120 characters).';
        if ($data['username'] !== null && mb_strlen($data['username']) > 190) $errors['username'] = 'Username is too long (max 190 characters).';
        if (!$data['client_id'] || !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$data['client_id'], Tenant::id()])) $errors['client_id'] = 'Client not found.';
        if ($data['login_url'] !== null && !valid_url($data['login_url'])) $errors['login_url'] = 'Enter a valid URL, e.g. https://cpanel.example.com:2083';
        if ($data['website_id'] && !DB::value("SELECT id FROM websites WHERE id = ? AND client_id = ?", [$data['website_id'], $data['client_id']])) $data['website_id'] = null;
        $password = (string) ($_POST['password'] ?? '');
        if (!$id && $password === '') $errors['password'] = 'Password is required.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($password !== '') $data['password'] = encrypt_value($password);

        if ($id) {
            $existing = load_credential($id);
            DB::update('client_credentials', $data, 'id = ?', [$id]);
            ActivityLog::add('credential_updated', 'Login details updated: ' . $data['label'] . ' (' . credential_types()[$data['type']] . ')' . ($password !== '' ? ' – password changed' : ''), ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
            json_success('Login details updated.', ['id' => $id]);
        }
        $data['created_by'] = Auth::id();
        $data['tenant_id'] = Tenant::id();
        $id = DB::insert('client_credentials', $data);
        ActivityLog::add('credential_added', 'Login details added: ' . $data['label'] . ' (' . credential_types()[$data['type']] . ')', ['client_id' => $data['client_id'], 'website_id' => $data['website_id']]);
        json_success('Login details saved.', ['id' => $id]);

    case 'get':
        $c = load_credential((int) post_int('id'));
        $c['has_password'] = $c['password'] !== null && $c['password'] !== '';
        unset($c['password']);
        json_success('OK', ['credential' => $c]);

    case 'reveal':
        // Returns the decrypted password for one record. Logged so every view is traceable.
        $c = load_credential((int) post_int('id'));
        $plain = decrypt_value($c['password']);
        if ($plain === null) json_error('The stored password could not be decrypted (APP_KEY changed?).');
        ActivityLog::add('credential_viewed', 'Password revealed: ' . $c['label'] . ' (' . credential_types()[$c['type']] . ')', ['client_id' => $c['client_id'], 'website_id' => $c['website_id']]);
        json_success('OK', ['password' => $plain]);

    case 'delete':
        $c = load_credential((int) post_int('id'));
        DB::delete('client_credentials', 'id = ?', [$c['id']]);
        ActivityLog::add('credential_deleted', 'Login details deleted: ' . $c['label'] . ' (' . credential_types()[$c['type']] . ')', ['client_id' => $c['client_id'], 'website_id' => $c['website_id']]);
        json_success('Login details deleted.');

    default:
        json_error('Unknown action.');
}
