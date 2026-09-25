<?php
/** Registration, email verification and team invitations. */
class Registration
{
    /** Strict email validation: syntax + a mail server for the domain (MX / A record) when DNS is available. */
    public static function emailProblem(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 190) return 'Enter your email address.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        if (!preg_match('~^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}$~', $domain)) return 'Enter a valid email domain.';
        if (in_array($domain, ['example.com', 'example.org', 'test.com', 'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'yopmail.com', 'trashmail.com'], true)) return 'Please use a real, permanent email address.';
        if (function_exists('checkdnsrr') && APP_ENV !== 'development') {
            $ok = @checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA');
            if (!$ok) return 'The email domain "' . $domain . '" does not accept email.';
        }
        return null;
    }

    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < 8) return 'Use at least 8 characters.';
        if (!preg_match('~[A-Za-z]~', $password) || !preg_match('~[0-9]~', $password)) return 'Use letters and at least one number.';
        return null;
    }

    /** Simple per-IP throttle for public forms (registration, resend, contact) using the login_attempts table. */
    public static function throttled(string $kind, int $max, int $minutes): bool
    {
        $n = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE login = ? AND ip_address = ? AND attempted_at > ?", ['#' . $kind, client_ip(), date('Y-m-d H:i:s', time() - $minutes * 60)]);
        if ($n >= $max) return true;
        DB::insert('login_attempts', ['login' => '#' . $kind, 'ip_address' => client_ip(), 'success' => 1, 'attempted_at' => date('Y-m-d H:i:s')]);
        return false;
    }

    /** Create + email a verification link. */
    public static function sendVerification(int $userId): bool
    {
        $u = DB::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$u) return false;
        $token = bin2hex(random_bytes(32));
        $hours = max(1, (int) setting('verification_token_hours', 48));
        DB::query("UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userId]);
        DB::insert('email_verifications', ['user_id' => $userId, 'email' => $u['email'], 'token_hash' => hash('sha256', $token), 'purpose' => 'verify', 'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600), 'created_at' => date('Y-m-d H:i:s')]);
        $link = url('auth/verify.php?token=' . $token);
        $platform = setting('platform_name', 'Outline Monitor');
        $html = Mailer::template('Verify your email address', 'Welcome to ' . $platform . ', ' . e($u['name']) . '! Confirm your email address to activate your workspace and start monitoring your websites. This link is valid for ' . $hours . ' hours.',
            ['Email' => $u['email'], 'Workspace' => Tenant::name((int) $u['tenant_id'])], 'Verify email address', $link, '#198754');
        $r = Mailer::send($u['email'], 'Verify your email – ' . $platform, $html, $u['name'], 'verify_email', null, null, (int) $userId);
        return (bool) ($r['ok'] ?? $r['success'] ?? $r);
    }

    /** @return array{ok:bool, user:?array, message:string} */
    public static function verify(string $token): array
    {
        if ($token === '' || !preg_match('~^[a-f0-9]{64}$~', $token)) return ['ok' => false, 'user' => null, 'message' => 'Invalid verification link.'];
        $row = DB::fetch("SELECT v.*, u.tenant_id FROM email_verifications v JOIN users u ON u.id = v.user_id WHERE v.token_hash = ? LIMIT 1", [hash('sha256', $token)]);
        if (!$row) return ['ok' => false, 'user' => null, 'message' => 'This verification link is not valid.'];
        if ($row['used_at']) {
            $u = DB::fetch("SELECT * FROM users WHERE id = ?", [$row['user_id']]);
            return $u && $u['email_verified_at'] ? ['ok' => true, 'already' => true, 'user' => $u, 'message' => 'Your email address is already verified.'] : ['ok' => false, 'user' => null, 'message' => 'This verification link has already been used. Request a new one.'];
        }
        if (strtotime($row['expires_at']) < time()) return ['ok' => false, 'user' => null, 'message' => 'This verification link has expired. Request a new one.'];
        DB::update('email_verifications', ['used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        DB::update('users', ['email_verified_at' => date('Y-m-d H:i:s'), 'email' => $row['email']], 'id = ?', [$row['user_id']]);
        if ($row['tenant_id']) Tenant::activate((int) $row['tenant_id']);
        $u = DB::fetch("SELECT * FROM users WHERE id = ?", [$row['user_id']]);
        ActivityLog::add('email_verified', 'Email address verified: ' . $u['email'], ['user_id' => (int) $u['id']]);
        return ['ok' => true, 'user' => $u, 'message' => 'Email verified.'];
    }

    /* ---------- team invitations ---------- */

    public static function invite(int $tenantId, string $email, string $role, ?string $name, int $invitedBy): array
    {
        $email = strtolower(trim($email));
        if ($p = self::emailProblem($email)) return ['ok' => false, 'message' => $p];
        if (DB::value("SELECT id FROM users WHERE email = ?", [$email])) return ['ok' => false, 'message' => 'A user with this email already exists.'];
        if (!in_array($role, ['admin', 'manager', 'viewer', 'notify'], true)) $role = 'viewer';
        if ($role !== 'notify') {
            $can = Tenant::canAdd('users', 1, $tenantId);
            $pending = (int) DB::value("SELECT COUNT(*) FROM team_invitations WHERE tenant_id = ? AND accepted_at IS NULL AND expires_at > NOW() AND role <> 'notify'", [$tenantId]);
            if (!$can['ok'] || ($can['limit'] !== null && $can['current'] + $pending + 1 > $can['limit'])) return ['ok' => false, 'message' => $can['message'] ?: 'Team member limit reached for your plan (pending invitations count). Upgrade to invite more people.', 'limit' => true];
        }
        $token = bin2hex(random_bytes(32));
        DB::query("DELETE FROM team_invitations WHERE tenant_id = ? AND email = ? AND accepted_at IS NULL", [$tenantId, $email]);
        $id = DB::insert('team_invitations', ['tenant_id' => $tenantId, 'email' => $email, 'name' => $name ?: null, 'role' => $role, 'token_hash' => hash('sha256', $token), 'invited_by' => $invitedBy, 'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400), 'created_at' => date('Y-m-d H:i:s')]);
        $inviter = DB::fetch("SELECT name FROM users WHERE id = ?", [$invitedBy]);
        $platform = setting('platform_name', 'Outline Monitor');
        $link = url('auth/accept-invite.php?token=' . $token);
        $html = Mailer::template('You have been invited to ' . e(Tenant::name($tenantId)), e($inviter['name'] ?? 'A team member') . ' invited you to join the ' . e(Tenant::name($tenantId)) . ' workspace on ' . $platform . ' as ' . self::roleLabel($role) . '. The invitation is valid for 7 days.',
            ['Workspace' => Tenant::name($tenantId), 'Role' => self::roleLabel($role), 'Email' => $email], 'Accept invitation', $link, '#0d6efd');
        Mailer::send($email, 'Invitation to ' . Tenant::name($tenantId) . ' – ' . $platform, $html, $name, 'team_invite', null, null, $id);
        ActivityLog::add('team_invited', 'Invited ' . $email . ' as ' . self::roleLabel($role), ['user_id' => $invitedBy]);
        return ['ok' => true, 'message' => 'Invitation sent to ' . $email . '.', 'id' => $id];
    }

    public static function roleLabel(string $role): string
    {
        return ['owner' => 'Owner', 'admin' => 'Admin', 'manager' => 'Manager', 'viewer' => 'Viewer', 'staff' => 'Viewer', 'notify' => 'Notify-only'][$role] ?? ucfirst($role);
    }

    public static function roleDescriptions(): array
    {
        return ['admin' => 'Manage websites, monitoring and the team', 'manager' => 'Manage monitoring and incidents', 'viewer' => 'Read-only access to the dashboard', 'notify' => 'Receives alert emails only – no dashboard login'];
    }
}
