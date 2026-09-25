<?php
/**
 * Activity log writer (workspace activity + Super Admin audit trail).
 */
class ActivityLog
{
    /**
     * @param string $action      e.g. login, client_created, website_down, page_down
     * @param string $description Human readable text
     * @param array  $opts        user_id, tenant_id, client_id, website_id, form_id, platform (bool), target (string), result (ok|failed|denied)
     */
    public static function add(string $action, string $description, array $opts = []): void
    {
        try {
            $userId = $opts['user_id'] ?? (class_exists('Auth') && !IS_CLI ? (Auth::id() ?: null) : null);
            $tenantId = $opts['tenant_id'] ?? (Tenant::id() ?: null);
            if (!$tenantId && !empty($opts['website_id'])) $tenantId = DB::value("SELECT tenant_id FROM websites WHERE id = ?", [$opts['website_id']]) ?: null;
            if (!$tenantId && !empty($opts['client_id'])) $tenantId = DB::value("SELECT tenant_id FROM clients WHERE id = ?", [$opts['client_id']]) ?: null;
            if (!$tenantId && $userId) $tenantId = DB::value("SELECT tenant_id FROM users WHERE id = ?", [$userId]) ?: null;
            DB::insert('activity_logs', [
                'tenant_id'   => $tenantId,
                'is_platform' => !empty($opts['platform']) || str_starts_with($action, 'platform_') ? 1 : 0,
                'user_id'     => $userId ?: null,
                'action'      => mb_substr($action, 0, 60),
                'description' => mb_substr($description, 0, 1000),
                'target'      => isset($opts['target']) ? mb_substr((string) $opts['target'], 0, 190) : null,
                'result'      => isset($opts['result']) ? mb_substr((string) $opts['result'], 0, 20) : null,
                'client_id'   => $opts['client_id'] ?? null,
                'website_id'  => $opts['website_id'] ?? null,
                'form_id'     => $opts['form_id'] ?? null,
                'ip_address'  => IS_CLI ? 'cron' : client_ip(),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            app_log('error', 'ActivityLog failed: ' . $e->getMessage());
        }
    }

    /**
     * Super Admin audit entry: who did what to which target, with the outcome. Never stores secrets – callers pass
     * labels (names, hosts, plan codes), not passwords or keys.
     */
    public static function platform(string $action, string $description, string $target = '', string $result = 'ok', array $opts = []): void
    {
        self::add(str_starts_with($action, 'platform_') ? $action : 'platform_' . $action, $description, $opts + ['platform' => true, 'target' => $target ?: null, 'result' => $result]);
    }

    /** Readable label for an action code. */
    public static function label(string $action): string
    {
        return ucwords(str_replace('_', ' ', preg_replace('~^platform_~', '', $action)));
    }

    public static function icon(string $action): string
    {
        $map = [
            'login' => 'bi-box-arrow-in-right text-success', 'logout' => 'bi-box-arrow-right text-secondary',
            'client_created' => 'bi-person-plus text-primary', 'client_updated' => 'bi-person-gear text-info', 'client_deleted' => 'bi-person-x text-danger',
            'project_created' => 'bi-kanban text-primary', 'project_updated' => 'bi-kanban text-info', 'project_status_changed' => 'bi-arrow-right-circle text-warning', 'project_deleted' => 'bi-kanban text-danger',
            'project_live' => 'bi-rocket-takeoff text-success', 'monitoring_enabled' => 'bi-toggle-on text-success', 'monitoring_disabled' => 'bi-toggle-off text-secondary', 'taxonomy_changed' => 'bi-tags text-secondary',
            'website_added' => 'bi-globe2 text-primary', 'website_updated' => 'bi-globe2 text-info', 'website_deleted' => 'bi-globe2 text-danger',
            'website_down' => 'bi-exclamation-octagon text-danger', 'website_recovered' => 'bi-check-circle text-success', 'website_status_changed' => 'bi-activity text-warning',
            'page_down' => 'bi-file-earmark-x text-danger', 'page_recovered' => 'bi-file-earmark-check text-success', 'pages_discovered' => 'bi-diagram-3 text-info', 'page_added' => 'bi-file-earmark-plus text-primary', 'page_removed' => 'bi-file-earmark-minus text-secondary',
            'ssl_warning' => 'bi-shield-exclamation text-warning', 'ssl_expired' => 'bi-shield-x text-danger', 'ssl_failed' => 'bi-shield-x text-danger', 'ssl_recovered' => 'bi-shield-check text-success',
            'form_added' => 'bi-ui-checks text-primary', 'form_tested' => 'bi-clipboard-check text-info', 'form_failed' => 'bi-x-octagon text-danger', 'form_recovered' => 'bi-check2-circle text-success',
            'credential_added' => 'bi-key text-primary', 'credential_updated' => 'bi-key text-info', 'credential_deleted' => 'bi-key text-danger', 'credential_viewed' => 'bi-eye text-warning',
            'settings_changed' => 'bi-gear text-secondary', 'user_created' => 'bi-person-plus text-primary', 'user_updated' => 'bi-person-gear text-info', 'user_deleted' => 'bi-person-x text-danger',
            'domain_expiring' => 'bi-calendar-x text-warning', 'hosting_expiring' => 'bi-hdd-network text-warning', 'report_exported' => 'bi-download text-secondary',
            // Super Admin audit actions
            'platform_login' => 'bi-shield-lock text-brand', 'platform_client_created' => 'bi-building-add text-primary', 'platform_client_updated' => 'bi-building-gear text-info', 'platform_client_deleted' => 'bi-building-x text-danger',
            'platform_client_activated' => 'bi-toggle-on text-success', 'platform_client_deactivated' => 'bi-toggle-off text-warning', 'platform_tenant_status' => 'bi-toggle-on text-warning', 'platform_tenant_deleted' => 'bi-building-x text-danger',
            'platform_website_updated' => 'bi-globe2 text-info', 'platform_website_deleted' => 'bi-globe2 text-danger', 'platform_website_enabled' => 'bi-play-circle text-success', 'platform_website_disabled' => 'bi-pause-circle text-warning', 'platform_tracking_key' => 'bi-key text-warning',
            'platform_plan_changed' => 'bi-tags text-info', 'platform_plan_saved' => 'bi-tags text-primary', 'platform_pricing_changed' => 'bi-currency-rupee text-info', 'platform_trial_extended' => 'bi-hourglass-split text-info', 'platform_subscription_status' => 'bi-credit-card text-info',
            'platform_smtp_changed' => 'bi-envelope-gear text-warning', 'platform_smtp_test' => 'bi-envelope-check text-info', 'platform_setting_changed' => 'bi-gear text-secondary', 'platform_impersonate' => 'bi-eye text-warning',
            'platform_email_retry' => 'bi-arrow-repeat text-info', 'platform_job' => 'bi-robot text-secondary', 'platform_security' => 'bi-shield-exclamation text-danger',
        ];
        return $map[$action] ?? 'bi-dot text-secondary';
    }
}
