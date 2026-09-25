<?php
/**
 * TENANT (customer workspace) context, plans, limits and usage.
 *
 * Every customer-owned row carries tenant_id. Pages and APIs read the current tenant from the logged-in user
 * (Tenant::id()) and every query is scoped with it. Cron workers run across tenants and read plan intervals per website.
 */
class Tenant
{
    private static array $rows = [];
    private static array $plans = [];
    private static array $usage = [];
    private static ?int $override = null;

    const LIMIT_KEYS = ['websites' => 'max_websites', 'pages' => 'max_pages', 'forms' => 'max_forms', 'users' => 'max_users', 'status_pages' => 'max_status_pages'];
    const LABELS = ['websites' => 'websites', 'pages' => 'monitored pages', 'forms' => 'forms', 'users' => 'team members', 'status_pages' => 'status pages'];

    /* ---------- context ---------- */

    /** Current tenant id (0 in CLI / public context). Platform admins can impersonate via Tenant::act(). */
    public static function id(): int
    {
        if (self::$override !== null) return self::$override;
        if (IS_CLI || !class_exists('Auth') || !Auth::check()) return 0;
        $u = Auth::user();
        // platform admins can "view as" a customer workspace (read-only support access, logged)
        if (!empty($u['is_platform_admin']) && !empty($_SESSION['act_as_tenant'])) return (int) $_SESSION['act_as_tenant'];
        return (int) ($u['tenant_id'] ?? 0);
    }

    /** Run as a given tenant (platform admin "view as customer", cron per-tenant work). */
    public static function act(?int $tenantId): void
    {
        self::$override = $tenantId;
    }

    public static function current(?int $id = null): ?array
    {
        $id = $id ?? self::id();
        if ($id <= 0) return null;
        if (!array_key_exists($id, self::$rows)) self::$rows[$id] = DB::fetch("SELECT * FROM tenants WHERE id = ?", [$id]);
        return self::$rows[$id];
    }

    public static function forget(?int $id = null): void
    {
        if ($id === null) { self::$rows = []; self::$plans = []; self::$usage = []; return; }
        unset(self::$rows[$id], self::$plans[$id], self::$usage[$id]);
    }

    public static function name(?int $id = null): string
    {
        return (string) (self::current($id)['name'] ?? company_name());
    }

    /* ---------- plans ---------- */

    public static function allPlans(bool $publicOnly = false): array
    {
        return Cache::remember('plans:' . ($publicOnly ? 'public' : 'all'), 300, function () use ($publicOnly) {
            $rows = DB::fetchAll("SELECT * FROM plans WHERE status = 'active'" . ($publicOnly ? " AND is_public = 1" : '') . " ORDER BY sort_order, id");
            return array_map([self::class, 'decodePlan'], $rows);
        });
    }

    public static function planByCode(string $code): ?array
    {
        foreach (self::allPlans() as $p) if ($p['code'] === $code) return $p;
        return null;
    }

    public static function decodePlan(array $p): array
    {
        $p['features'] = json_decode((string) ($p['features'] ?? ''), true) ?: [];
        $p['highlights'] = json_decode((string) ($p['highlights'] ?? ''), true) ?: [];
        return $p;
    }

    /** Effective plan of a tenant (subscription → tenant.plan_id → free). */
    public static function plan(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? self::id();
        if (isset(self::$plans[$tenantId])) return self::$plans[$tenantId];
        $t = self::current($tenantId);
        $planId = (int) ($t['plan_id'] ?? 0);
        $plan = null;
        if ($planId) { foreach (self::allPlans() as $p) if ((int) $p['id'] === $planId) { $plan = $p; break; } }
        if (!$plan) $plan = self::planByCode('free') ?: self::decodePlan(['id' => 0, 'code' => 'free', 'name' => 'Free', 'max_websites' => 2, 'max_pages' => 100, 'max_forms' => 20, 'max_users' => 1, 'max_status_pages' => 0, 'website_interval' => 60, 'page_interval' => 60, 'form_interval' => 60, 'ssl_interval' => 60, 'retention_days' => 7, 'features' => '{}', 'highlights' => '[]']);
        // expired trial → fall back to the free plan's limits (data is kept, monitoring continues at free-plan pace)
        if ($t && $t['subscription_status'] === 'trial' && $t['trial_ends_at'] && strtotime($t['trial_ends_at']) < time() && $plan['code'] !== 'free') {
            $free = self::planByCode('free');
            if ($free) { $free['expired_from'] = $plan['name']; $plan = $free; }
        }
        return self::$plans[$tenantId] = $plan;
    }

    public static function limit(string $kind, ?int $tenantId = null): ?int
    {
        $col = self::LIMIT_KEYS[$kind] ?? null;
        if (!$col) return null;
        $v = self::plan($tenantId)[$col] ?? null;
        return $v === null ? null : (int) $v;
    }

    public static function feature(string $name, ?int $tenantId = null)
    {
        return self::plan($tenantId)['features'][$name] ?? 0;
    }

    /** Effective monitoring interval in minutes: never faster than the plan, never faster than the platform setting. */
    /**
     * Monitoring interval in minutes for a kind (website | page | form | ssl). The SUBSCRIPTION PLAN is the source of
     * truth (v3.9); the platform-wide settings only apply when the plan has no value, and monitoring_min_interval is
     * a global floor so no plan can be set below what the server can sustain.
     */
    public static function interval(string $kind, ?int $tenantId = null): int
    {
        $plan = self::plan($tenantId);
        $planMin = (int) ($plan[$kind . '_interval'] ?? 0);
        if ($planMin <= 0) $planMin = (int) setting(['website' => 'website_check_interval', 'page' => 'website_check_interval', 'form' => 'form_check_interval', 'ssl' => 'ssl_check_interval'][$kind] ?? 'website_check_interval', 60);
        $floor = max(1, (int) setting('monitoring_min_interval', 1));
        return max($floor, max(1, $planMin));
    }

    /* ---------- usage & limits ---------- */

    public static function usage(?int $tenantId = null, bool $refresh = false): array
    {
        $tenantId = $tenantId ?? self::id();
        if (!$refresh && isset(self::$usage[$tenantId])) return self::$usage[$tenantId];
        $u = [
            'websites'     => (int) DB::value("SELECT COUNT(*) FROM websites WHERE tenant_id = ?", [$tenantId]),
            'pages'        => (int) DB::value("SELECT COUNT(*) FROM website_pages WHERE tenant_id = ? AND is_active = 1", [$tenantId]),
            'forms'        => (int) DB::value("SELECT COUNT(*) FROM forms WHERE tenant_id = ? AND status <> 'removed'", [$tenantId]),
            'users'        => (int) DB::value("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status = 'active' AND role <> 'notify'", [$tenantId]),
            'status_pages' => (int) DB::value("SELECT COUNT(*) FROM status_pages WHERE tenant_id = ?", [$tenantId]),
        ];
        return self::$usage[$tenantId] = $u;
    }

    /** @return array{ok:bool, current:int, limit:?int, remaining:?int, message:string} */
    public static function canAdd(string $kind, int $n = 1, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? self::id();
        $limit = self::limit($kind, $tenantId);
        $current = self::usage($tenantId)[$kind] ?? 0;
        if ($limit === null) return ['ok' => true, 'current' => $current, 'limit' => null, 'remaining' => null, 'message' => ''];
        $ok = $current + $n <= $limit;
        $label = self::LABELS[$kind] ?? $kind;
        $plan = self::plan($tenantId);
        return ['ok' => $ok, 'current' => $current, 'limit' => $limit, 'remaining' => max(0, $limit - $current),
            'message' => $ok ? '' : 'Plan limit reached: ' . $current . ' / ' . $limit . ' ' . $label . ' on the ' . $plan['name'] . ' plan. Upgrade your plan to add more.'];
    }

    public static function remaining(string $kind, ?int $tenantId = null): ?int
    {
        $limit = self::limit($kind, $tenantId);
        return $limit === null ? null : max(0, $limit - (self::usage($tenantId)[$kind] ?? 0));
    }

    /** Usage rows for dashboards: kind => [current, limit, pct, state] */
    public static function usageSummary(?int $tenantId = null): array
    {
        $out = [];
        $u = self::usage($tenantId);
        foreach (self::LIMIT_KEYS as $kind => $col) {
            $limit = self::limit($kind, $tenantId);
            $cur = $u[$kind];
            $pct = $limit ? min(100, (int) round($cur / max(1, $limit) * 100)) : 0;
            $out[$kind] = ['label' => ucfirst(self::LABELS[$kind]), 'current' => $cur, 'limit' => $limit, 'pct' => $pct, 'state' => $limit === null ? 'ok' : ($pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : 'ok'))];
        }
        return $out;
    }

    public static function trialDaysLeft(?int $tenantId = null): ?int
    {
        $t = self::current($tenantId);
        if (!$t || $t['subscription_status'] !== 'trial' || !$t['trial_ends_at']) return null;
        return (int) ceil((strtotime($t['trial_ends_at']) - time()) / 86400);
    }

    public static function isSuspended(?int $tenantId = null): bool
    {
        $t = self::current($tenantId);
        return $t && in_array($t['status'], ['suspended', 'cancelled'], true);
    }

    /* ---------- per-tenant settings (overlay on the platform settings) ---------- */

    public static function setting(string $key, $default = null, ?int $tenantId = null)
    {
        $tenantId = $tenantId ?? self::id();
        if ($tenantId <= 0) return setting($key, $default);
        $all = Cache::remember('tenant_settings:' . $tenantId, 120, fn() => array_column(DB::fetchAll("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?", [$tenantId]), 'setting_value', 'setting_key'));
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') return $all[$key];
        return setting($key, $default);
    }

    /** Tenant overlay value only – no fallback to the global platform setting. */
    public static function ownSetting(string $key, ?int $tenantId = null, $default = null)
    {
        $tenantId = $tenantId ?? self::id();
        if ($tenantId <= 0) return $default;
        $all = Cache::remember('tenant_settings:' . $tenantId, 120, fn() => array_column(DB::fetchAll("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?", [$tenantId]), 'setting_value', 'setting_key'));
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function setSetting(string $key, $value, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? self::id();
        if ($tenantId <= 0) { set_setting($key, $value); return; }
        DB::query("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$tenantId, $key, (string) $value]);
        Cache::forget('tenant_settings:' . $tenantId);
    }

    /* ---------- registration / lifecycle ---------- */

    public static function slugFor(string $name): string
    {
        $base = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)), '-') ?: 'workspace';
        $base = substr($base, 0, 50);
        $slug = $base;
        for ($i = 2; DB::value("SELECT id FROM tenants WHERE slug = ?", [$slug]); $i++) $slug = $base . '-' . $i;
        return $slug;
    }

    /** Create a workspace with its owner (unverified) and a trial / free subscription. Returns [tenantId, userId]. */
    public static function register(array $in): array
    {
        $planCode = $in['plan'] ?? 'free';
        $plan = self::planByCode($planCode) ?: self::planByCode('free');
        $isFree = $plan['code'] === 'free' || $plan['price_monthly'] === 0 || $plan['price_monthly'] === '0';
        $trialDays = $isFree ? 0 : max(1, (int) ($plan['trial_days'] ?: setting('trial_days', 14)));
        $now = date('Y-m-d H:i:s');
        DB::begin();
        try {
            $tenantId = DB::insert('tenants', [
                'name' => $in['company'], 'slug' => self::slugFor($in['company']), 'plan_id' => $plan['id'], 'status' => 'pending',
                'subscription_status' => $isFree ? 'free' : 'trial', 'trial_ends_at' => $isFree ? null : date('Y-m-d H:i:s', time() + $trialDays * 86400),
                'billing_email' => $in['email'], 'phone' => $in['phone'] ?? null, 'timezone' => $in['timezone'] ?? null, 'created_at' => $now,
            ]);
            $userId = DB::insert('users', [
                'tenant_id' => $tenantId, 'name' => $in['name'], 'email' => $in['email'], 'username' => self::usernameFor($in['email']),
                'password' => password_hash($in['password'], PASSWORD_DEFAULT), 'role' => 'owner', 'status' => 'active', 'terms_accepted_at' => $now, 'created_at' => $now,
            ]);
            DB::update('tenants', ['owner_user_id' => $userId], 'id = ?', [$tenantId]);
            DB::insert('subscriptions', ['tenant_id' => $tenantId, 'plan_id' => $plan['id'], 'status' => $isFree ? 'active' : 'trial', 'started_at' => $now,
                'trial_ends_at' => $isFree ? null : date('Y-m-d H:i:s', time() + $trialDays * 86400), 'current_period_start' => $now, 'amount' => $isFree ? 0 : (int) $plan['price_monthly'], 'currency' => $plan['currency'] ?? 'INR', 'notes' => $isFree ? 'Free plan' : $trialDays . '-day free trial']);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        return [$tenantId, $userId];
    }

    public static function usernameFor(string $email): string
    {
        $base = preg_replace('~[^a-z0-9._-]~', '', strtolower(strtok($email, '@'))) ?: 'user';
        $base = substr($base, 0, 50);
        $u = $base;
        for ($i = 2; DB::value("SELECT id FROM users WHERE username = ?", [$u]); $i++) $u = $base . $i;
        return $u;
    }

    /** Owner verified their email → activate the workspace. */
    public static function activate(int $tenantId): void
    {
        DB::update('tenants', ['status' => 'active'], 'id = ? AND status = "pending"', [$tenantId]);
        self::forget($tenantId);
    }

    /** Change plan (platform admin / future billing). */
    public static function changePlan(int $tenantId, int $planId, string $status = 'active', ?string $trialEndsAt = null, ?string $note = null): void
    {
        $now = date('Y-m-d H:i:s');
        DB::query("UPDATE subscriptions SET status = 'cancelled', cancelled_at = ?, ended_at = ? WHERE tenant_id = ? AND status IN ('trial','active','past_due')", [$now, $now, $tenantId]);
        $plan = DB::fetch("SELECT * FROM plans WHERE id = ?", [$planId]);
        DB::insert('subscriptions', ['tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => $status === 'trial' ? 'trial' : 'active', 'started_at' => $now, 'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $now, 'amount' => (int) ($plan['price_monthly'] ?? 0), 'currency' => $plan['currency'] ?? 'INR', 'notes' => $note]);
        DB::update('tenants', ['plan_id' => $planId, 'subscription_status' => $status === 'trial' ? 'trial' : ((int) ($plan['price_monthly'] ?? 0) === 0 && ($plan['code'] ?? '') === 'free' ? 'free' : 'active'), 'trial_ends_at' => $trialEndsAt], 'id = ?', [$tenantId]);
        self::forget($tenantId);
        // the new plan's intervals take effect immediately (upgrade → overdue targets are due now; downgrade → stretched)
        if (class_exists('Scheduler')) { try { Scheduler::reschedule($tenantId); } catch (Throwable $e) { app_log('warning', 'reschedule after plan change failed: ' . $e->getMessage()); } }
    }

    /* ---------- job locks (prevent the same website / form being scanned by two workers) ---------- */

    public static function lock(string $key, int $seconds = 300): bool
    {
        $now = date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', time() + $seconds);
        $owner = (IS_CLI ? 'cli' : 'web') . ':' . getmypid();
        try {
            // NOTE: ON DUPLICATE KEY UPDATE assignments are evaluated left to right and later ones see the new values –
            // owner MUST be assigned before locked_until, otherwise an expired lock is extended but never re-owned
            // (a crashed process would then hold the lock forever).
            $n = DB::query("INSERT INTO monitor_locks (lock_key, locked_until, owner) VALUES (?,?,?) ON DUPLICATE KEY UPDATE owner = IF(locked_until < ?, VALUES(owner), owner), locked_until = IF(locked_until < ?, VALUES(locked_until), locked_until)", [$key, $until, $owner, $now, $now])->rowCount();
            if ($n === 0) return false; // row existed and was not expired
            $row = DB::fetch("SELECT owner FROM monitor_locks WHERE lock_key = ?", [$key]);
            return ($row['owner'] ?? '') === $owner;
        } catch (Throwable $e) {
            return true; // locking table missing → do not block monitoring
        }
    }

    public static function unlock(string $key): void
    {
        try { DB::delete('monitor_locks', 'lock_key = ?', [$key]); } catch (Throwable $e) {}
    }
}
