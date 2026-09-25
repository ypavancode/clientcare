<?php
/** Team API: members of the current workspace (owner / admin / manager / viewer / notify-only), invitations, profile. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();

$action = post('action', '');
$me = Auth::user();
$tid = Tenant::id();

if (!in_array($action, ['profile', 'my_password'], true)) Auth::requireAbility('team');

function load_user(int $id): array
{
    $u = DB::fetch("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
    if (!$u) json_error('Team member not found.', 404);
    return $u;
}
$roles = ['admin', 'manager', 'viewer', 'notify'];

switch ($action) {
    case 'invite':
        $r = Registration::invite($tid, (string) post('email', ''), (string) post('role', 'viewer'), post_nullable('name'), (int) $me['id']);
        if (!$r['ok']) json_error($r['message'], !empty($r['limit']) ? 403 : 422, ['upgrade' => !empty($r['limit'])]);
        json_success($r['message'], ['id' => $r['id']]);

    case 'cancel_invite':
        $n = DB::delete('team_invitations', 'id = ? AND tenant_id = ? AND accepted_at IS NULL', [post_int('id'), $tid]);
        json_success($n ? 'Invitation cancelled.' : 'Invitation not found.');

    case 'resend_invite':
        $inv = DB::fetch("SELECT * FROM team_invitations WHERE id = ? AND tenant_id = ? AND accepted_at IS NULL", [post_int('id'), $tid]);
        if (!$inv) json_error('Invitation not found.', 404);
        $r = Registration::invite($tid, $inv['email'], $inv['role'], $inv['name'], (int) $me['id']);
        if (!$r['ok']) json_error($r['message']);
        json_success('Invitation re-sent to ' . $inv['email'] . '.');

    case 'save':
        // edit an existing member (name, role, status, phone); new members join through invitations
        $id = (int) post_int('id');
        $u = load_user($id);
        $data = ['name' => trim((string) post('name', '')), 'phone' => post_nullable('phone'), 'job_title' => post_nullable('job_title')];
        $role = in_array(post('role'), $roles, true) ? post('role') : $u['role'];
        $status = post('status') === 'inactive' ? 'inactive' : 'active';
        if ($data['name'] === '') json_error('Name is required.', 422, ['errors' => ['name' => 'Required']]);
        if ($u['role'] === 'owner') { $role = 'owner'; $status = 'active'; }
        elseif ($u['id'] == $me['id'] && ($role !== $u['role'] || $status !== 'active')) json_error('You cannot change your own role or deactivate yourself.');
        if ($u['role'] === 'notify' && $role !== 'notify' && $status === 'active') { $can = Tenant::canAdd('users'); if (!$can['ok']) json_error($can['message'], 403, ['upgrade' => true]); }
        if ($u['status'] === 'inactive' && $status === 'active' && $role !== 'notify') { $can = Tenant::canAdd('users'); if (!$can['ok']) json_error($can['message'], 403, ['upgrade' => true]); }
        $data['role'] = $role; $data['status'] = $status;
        DB::update('users', $data, 'id = ?', [$id]);
        if ($status === 'inactive') DB::delete('remember_tokens', 'user_id = ?', [$id]);
        Cache::forget('users:options:' . $tid);
        ActivityLog::add('user_updated', 'Team member updated: ' . $data['name'] . ' (' . Registration::roleLabel($role) . ')');
        json_success('Team member updated.');

    case 'get':
        $u = load_user((int) post_int('id'));
        unset($u['password'], $u['reset_token'], $u['totp_secret']);
        json_success('OK', ['user' => $u]);

    case 'status':
        $u = load_user((int) post_int('id'));
        if ($u['id'] == $me['id']) json_error('You cannot deactivate your own account.');
        if ($u['role'] === 'owner') json_error('The workspace owner cannot be deactivated.');
        $new = $u['status'] === 'active' ? 'inactive' : 'active';
        if ($new === 'active' && $u['role'] !== 'notify') { $can = Tenant::canAdd('users'); if (!$can['ok']) json_error($can['message'], 403, ['upgrade' => true]); }
        DB::update('users', ['status' => $new], 'id = ?', [$u['id']]);
        if ($new === 'inactive') DB::delete('remember_tokens', 'user_id = ?', [$u['id']]);
        Cache::forget('users:options:' . $tid);
        ActivityLog::add('user_updated', 'Team member ' . $u['name'] . ' set to ' . $new);
        json_success('Team member ' . ($new === 'active' ? 'activated' : 'deactivated') . '.');

    case 'reset_link':
        $u = load_user((int) post_int('id'));
        $token = Auth::createResetToken((int) $u['id']);
        $link = url('auth/reset-password.php?token=' . $token);
        $html = Mailer::template('Reset your password', 'A workspace admin requested a password reset for your ' . setting('platform_name', 'Outline Monitor') . ' account. The link is valid for 1 hour.', ['Account' => $u['email'], 'Workspace' => Tenant::name()], 'Reset password', $link, '#0d6efd');
        $r = Mailer::send($u['email'], 'Password reset – ' . setting('platform_name', 'Outline Monitor'), $html, $u['name'], 'password_reset');
        ActivityLog::add('user_updated', 'Password reset link generated for ' . $u['name']);
        json_success($r['ok'] ? 'Reset link emailed to ' . $u['email'] . '.' : 'Email could not be sent (' . $r['error'] . '). Share this link manually:', ['link' => $link, 'emailed' => $r['ok']]);

    case 'transfer_owner':
        if (!Auth::isOwner()) json_error('Only the current owner can transfer ownership.', 403);
        $u = load_user((int) post_int('id'));
        if ($u['role'] === 'notify' || $u['status'] !== 'active') json_error('Choose an active dashboard member.');
        DB::update('users', ['role' => 'admin'], 'id = ?', [$me['id']]);
        DB::update('users', ['role' => 'owner'], 'id = ?', [$u['id']]);
        DB::update('tenants', ['owner_user_id' => $u['id']], 'id = ?', [$tid]);
        Tenant::forget($tid);
        ActivityLog::add('user_updated', 'Workspace ownership transferred to ' . $u['name']);
        json_success('Ownership transferred to ' . $u['name'] . '. You are now an admin.');

    case 'delete':
        $u = load_user((int) post_int('id'));
        if ($u['id'] == $me['id']) json_error('You cannot delete your own account.');
        if ($u['role'] === 'owner') json_error('The workspace owner cannot be deleted – transfer ownership first.');
        DB::delete('users', 'id = ?', [$u['id']]);
        Cache::forget('users:options:' . $tid);
        ActivityLog::add('user_deleted', 'Team member removed: ' . $u['name'] . ' (' . $u['email'] . ')');
        json_success('Team member removed.');

    case 'profile':
        $data = ['name' => trim((string) post('name', '')), 'phone' => post_nullable('phone'), 'job_title' => post_nullable('job_title')];
        $email = strtolower(trim((string) post('email', '')));
        $errors = [];
        if ($data['name'] === '') $errors['name'] = 'Name is required.';
        if ($p = Registration::emailProblem($email)) $errors['email'] = $p;
        elseif (DB::value("SELECT id FROM users WHERE email = ? AND id <> ?", [$email, $me['id']])) $errors['email'] = 'This email is already in use.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        $msg = 'Profile updated.';
        if ($email !== strtolower($me['email'])) {
            // email change → must be verified again before the dashboard can be used
            $data['email'] = $email;
            $data['email_verified_at'] = null;
            DB::update('users', $data, 'id = ?', [$me['id']]);
            Registration::sendVerification((int) $me['id']);
            $msg = 'Profile updated – please verify your new email address (we sent you a link).';
        } else {
            DB::update('users', $data, 'id = ?', [$me['id']]);
        }
        ActivityLog::add('user_updated', 'Profile updated');
        json_success($msg, ['redirect' => $email !== strtolower($me['email']) ? url('auth/verify-pending.php') : null]);

    case 'my_password':
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $row = DB::fetch("SELECT password FROM users WHERE id = ?", [$me['id']]);
        if (!password_verify($current, $row['password'])) json_error('Current password is incorrect.', 422, ['errors' => ['current_password' => 'Incorrect password.']]);
        if ($p = Registration::passwordProblem($new)) json_error($p, 422, ['errors' => ['password' => $p]]);
        if ($new !== $confirm) json_error('Passwords do not match.', 422, ['errors' => ['password_confirm' => 'Does not match.']]);
        DB::update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$me['id']]);
        DB::delete('remember_tokens', 'user_id = ? AND selector <> ?', [$me['id'], explode(':', $_COOKIE[SESSION_NAME . '_remember'] ?? ':', 2)[0]]);
        ActivityLog::add('user_updated', 'Changed own password');
        json_success('Password changed.');

    default:
        json_error('Unknown action.');
}
