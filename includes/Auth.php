<?php
/**
 * Authentication: login, logout, remember-me, attempt limiting and access checks.
 */
class Auth
{
    private static ?array $user = null;

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        if (self::$user === null) {
            self::$user = DB::fetch("SELECT id, tenant_id, name, email, username, role, is_platform_admin, is_platform_owner, status, email_verified_at, last_login_at FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
            if (!self::$user) {
                self::logout(false);
                return null;
            }
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function role(): string
    {
        return self::user()['role'] ?? 'guest';
    }

    /** Workspace (tenant) of the logged-in user. */
    public static function tenantId(): int
    {
        return (int) (self::user()['tenant_id'] ?? 0);
    }

    public static function isOwner(): bool
    {
        return self::role() === 'owner';
    }

    public static function isPlatformAdmin(): bool
    {
        return (bool) (self::user()['is_platform_admin'] ?? false);
    }

    public static function isVerified(): bool
    {
        return !empty(self::user()['email_verified_at']);
    }

    /** Platform admin area (customers, plans, system) – completely separate from customer workspaces. */
    /** Owner / product creator: manages plans, pricing, subscription rules and product-level settings (implies Super Admin). */
    public static function isPlatformOwner(): bool
    {
        return (bool) (self::user()['is_platform_owner'] ?? false) && self::isPlatformAdmin();
    }

    public static function requirePlatformOwner(): void
    {
        self::requirePlatformAdmin();
        if (!self::isPlatformOwner()) {
            ActivityLog::platform('security', 'Owner-only action refused for ' . (self::user()['email'] ?? '?'), $_SERVER['REQUEST_URI'] ?? '', 'denied');
            if (is_ajax()) json_error('Only the platform Owner can change plans, pricing and product settings.', 403);
            http_error(403, 'This section is reserved for the platform Owner.');
        }
    }

    public static function requirePlatformAdmin(): void
    {
        self::requireLogin();
        if (!self::isPlatformAdmin()) {
            if (is_ajax()) json_error('Platform administrator access required.', 403);
            http_error(403, 'This area is reserved for platform administrators.');
        }
    }

    /** Legacy role name used by older pages ('admin' / 'manager' / 'staff') for the new team roles. */
    public static function legacyRole(): string
    {
        return ['owner' => 'admin', 'admin' => 'admin', 'manager' => 'manager', 'staff' => 'staff', 'viewer' => 'staff', 'notify' => 'staff'][self::role()] ?? 'staff';
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), ['owner', 'admin'], true);
    }

    public static function can(string $ability): bool
    {
        // Simple role matrix, ready for future roles.
        // Team roles: owner (everything incl. billing), admin (everything except billing / account deletion),
        // manager (monitoring + incidents), viewer (read-only), notify (alerts by email only – cannot sign in)
        $matrix = [
            'owner'   => ['*'],
            'admin'   => ['*'],
            'manager' => ['clients', 'websites', 'forms', 'domains', 'hosting', 'reports', 'notifications', 'activity', 'monitor', 'projects', 'incidents', 'status_pages', 'team.view', 'credentials.view'],
            'staff'   => ['clients.view', 'websites.view', 'forms.view', 'domains.view', 'hosting.view', 'notifications', 'reports.view', 'projects.view', 'incidents.view', 'status_pages.view', 'activity.view', 'team.view'],
            'viewer'  => ['clients.view', 'websites.view', 'forms.view', 'domains.view', 'hosting.view', 'notifications', 'reports.view', 'projects.view', 'incidents.view', 'status_pages.view', 'activity.view', 'team.view'],
            'notify'  => [],
        ];
        $role = self::role();
        if (in_array($ability, ['billing', 'account', 'tenant.delete'], true)) return $role === 'owner';
        if ($role === 'admin' && in_array($ability, ['taxonomy', 'credentials', 'users', 'team', 'settings', 'monitor'], true)) return true;
        $abilities = $matrix[$role] ?? [];
        if (in_array('*', $abilities, true)) return true;
        if (in_array($ability, $abilities, true)) return true;
        // module-level permission implies its sub-abilities, e.g. 'clients' covers 'clients.view'
        $module = explode('.', $ability)[0];
        return in_array($module, $abilities, true);
    }

    /* ---------- Login ---------- */

    public static function tooManyAttempts(string $login): bool
    {
        $since = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);
        $count = (int) DB::value(
            "SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at > ? AND (ip_address = ? OR login = ?)",
            [$since, client_ip(), $login]
        );
        return $count >= LOGIN_MAX_ATTEMPTS;
    }

    public static function recordAttempt(string $login, bool $success): void
    {
        DB::insert('login_attempts', [
            'login'        => mb_substr($login, 0, 190),
            'ip_address'   => client_ip(),
            'success'      => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
        // Housekeeping
        if (random_int(1, 20) === 1) {
            DB::query("DELETE FROM login_attempts WHERE attempted_at < ?", [date('Y-m-d H:i:s', time() - 86400 * 7)]);
        }
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function attempt(string $login, string $password, bool $remember = false): array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return ['ok' => false, 'message' => 'Please enter your email/username and password.'];
        }
        if (self::tooManyAttempts($login)) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.'];
        }

        $user = DB::fetch("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1", [$login, $login]);
        if (!$user || !password_verify($password, $user['password'])) {
            self::recordAttempt($login, false);
            return ['ok' => false, 'message' => 'Invalid login credentials.'];
        }
        if ($user['status'] !== 'active') {
            self::recordAttempt($login, false);
            return ['ok' => false, 'message' => 'Your account is inactive. Please contact your workspace owner.'];
        }
        if ($user['role'] === 'notify') {
            self::recordAttempt($login, false);
            return ['ok' => false, 'message' => 'This is a notify-only account: it receives alerts by email but cannot sign in to the dashboard.'];
        }
        if (!empty($user['tenant_id'])) {
            $t = DB::fetch("SELECT status FROM tenants WHERE id = ?", [$user['tenant_id']]);
            if ($t && in_array($t['status'], ['suspended', 'cancelled'], true) && empty($user['is_platform_admin'])) {
                self::recordAttempt($login, false);
                return ['ok' => false, 'message' => 'This workspace is ' . $t['status'] . '. Please contact support.'];
            }
        }

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }

        self::establishSession((int) $user['id']);
        self::recordAttempt($login, true);
        DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = ?', [$user['id']]);
        ActivityLog::add('login', 'Logged in', ['user_id' => (int) $user['id']]);
        if (!empty($user['is_platform_admin'])) ActivityLog::platform('login', 'Super Admin signed in', $user['email'], 'ok', ['user_id' => (int) $user['id']]);

        if ($remember) {
            self::setRememberCookie((int) $user['id']);
        }
        return ['ok' => true, 'message' => 'Login successful'];
    }

    /** Sign a user in without a password (after registration / email verification / invitation acceptance). */
    public static function loginAs(int $userId, string $how = 'registration'): void
    {
        self::establishSession($userId);
        DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = ?', [$userId]);
        ActivityLog::add('login', 'Signed in after ' . $how, ['user_id' => $userId]);
    }

    private static function establishSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        self::$user = null;
    }

    /* ---------- Remember me ---------- */

    private static function setRememberCookie(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        DB::insert('remember_tokens', [
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', time() + REMEMBER_LIFETIME),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        setcookie(SESSION_NAME . '_remember', $selector . ':' . $validator, [
            'expires'  => time() + REMEMBER_LIFETIME,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || str_starts_with(BASE_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function loginFromRememberCookie(): void
    {
        $cookie = $_COOKIE[SESSION_NAME . '_remember'] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) return;
        [$selector, $validator] = explode(':', $cookie, 2);
        try {
            $row = DB::fetch("SELECT rt.*, u.status FROM remember_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.selector = ? AND rt.expires_at > NOW()", [$selector]);
        } catch (Throwable $e) {
            return;
        }
        if ($row && $row['status'] === 'active' && hash_equals($row['token_hash'], hash('sha256', $validator))) {
            self::establishSession((int) $row['user_id']);
            DB::delete('remember_tokens', 'id = ?', [$row['id']]);
            self::setRememberCookie((int) $row['user_id']); // rotate
            ActivityLog::add('login', 'Logged in via remember-me cookie', ['user_id' => (int) $row['user_id']]);
        } else {
            self::clearRememberCookie();
        }
    }

    private static function clearRememberCookie(): void
    {
        setcookie(SESSION_NAME . '_remember', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    /* ---------- Logout ---------- */

    public static function logout(bool $log = true): void
    {
        if ($log && self::check()) {
            ActivityLog::add('logout', 'Logged out');
        }
        $cookie = $_COOKIE[SESSION_NAME . '_remember'] ?? '';
        if ($cookie !== '' && str_contains($cookie, ':')) {
            try {
                DB::delete('remember_tokens', 'selector = ?', [explode(':', $cookie, 2)[0]]);
            } catch (Throwable $e) {
            }
        }
        self::clearRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        self::$user = null;
    }

    /* ---------- Guards ---------- */

    public static function requireLogin(): void
    {
        if (!self::check() || !self::user()) {
            if (is_ajax()) json_error('Your session has expired. Please log in again.', 401);
            $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '';
            redirect('auth/login.php');
        }
        // Unverified email → only the verification screens are available
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $onAuthPage = str_contains($script, '/auth/');
        if (!self::isVerified() && !self::isPlatformAdmin() && !$onAuthPage) {
            if (is_ajax()) json_error('Please verify your email address to continue.', 403);
            redirect('auth/verify-pending.php');
        }
        if (setting('maintenance_mode', 0) && !self::isPlatformAdmin()) {
            if (is_ajax()) json_error('Scheduled maintenance in progress. Please try again shortly.', 503);
            http_error(503, setting('maintenance_message', '') ?: '');
        }
        if (self::tenantId() && Tenant::isSuspended() && !self::isPlatformAdmin() && !$onAuthPage) {
            if (is_ajax()) json_error('This workspace is suspended.', 403);
            http_error(403, 'This workspace is suspended. Please contact support.');
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        // 'admin' also admits workspace owners; 'staff' admits viewers (legacy role names used by older pages)
        if (!in_array(self::role(), $roles, true) && !in_array(self::legacyRole(), $roles, true)) {
            if (is_ajax()) json_error('You do not have permission to perform this action.', 403);
            http_response_code(403);
            $pageTitle = 'Access denied';
            include ROOT_PATH . '/includes/layout/header.php';
            echo '<div class="alert alert-danger">You do not have permission to access this page.</div>';
            include ROOT_PATH . '/includes/layout/footer.php';
            exit;
        }
    }

    public static function requireAbility(string $ability): void
    {
        self::requireLogin();
        if (!self::can($ability)) {
            if (is_ajax()) json_error('You do not have permission to perform this action.', 403);
            http_response_code(403);
            exit('Access denied.');
        }
    }

    /* ---------- Password reset ---------- */

    public static function createResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::update('users', [
            'reset_token'   => hash('sha256', $token),
            'reset_expires' => date('Y-m-d H:i:s', time() + 3600),
        ], 'id = ?', [$userId]);
        return $token;
    }

    public static function findByResetToken(string $token): ?array
    {
        if ($token === '') return null;
        return DB::fetch("SELECT * FROM users WHERE reset_token = ? AND reset_expires > NOW() AND status = 'active'", [hash('sha256', $token)]);
    }
}
