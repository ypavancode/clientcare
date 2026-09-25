<?php
/**
 * Global helper functions.
 */

/* ---------- Output / URLs ---------- */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if ($path === '') return BASE_URL . '/';
    if (preg_match('~^https?://~i', $path)) return $path;
    return BASE_URL . '/' . clean_route($path);
}

function asset(string $path): string
{
    $file = ROOT_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : APP_VERSION;
    return BASE_URL . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^https?://~', $path) ? $path : url($path)));
    exit;
}

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): void
{
    json_response(array_merge(['success' => false, 'message' => $message], $extra), $code);
}

function json_success(string $message = 'OK', array $extra = []): void
{
    json_response(array_merge(['success' => true, 'message' => $message], $extra));
}

function is_ajax(): bool
{
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
}

/* ---------- Input ---------- */

function post(string $key, $default = null)
{
    if (!isset($_POST[$key])) return $default;
    return is_array($_POST[$key]) ? $_POST[$key] : trim((string) $_POST[$key]);
}

function get(string $key, $default = null)
{
    if (!isset($_GET[$key])) return $default;
    return is_array($_GET[$key]) ? $_GET[$key] : trim((string) $_GET[$key]);
}

function post_int(string $key, ?int $default = null): ?int
{
    $v = post($key);
    return ($v === null || $v === '') ? $default : (int) $v;
}

function post_nullable(string $key): ?string
{
    $v = post($key);
    return ($v === null || $v === '') ? null : $v;
}

function valid_email(?string $email): bool
{
    return $email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_url(?string $url): bool
{
    return $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url);
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function host_from_url(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    return strtolower(preg_replace('~^www\.~i', '', $host));
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        if (is_ajax()) json_error('Invalid or expired security token. Please refresh the page and try again.', 403);
        http_response_code(403);
        exit('Invalid security token. Please go back and try again.');
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    require_csrf();
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Settings ---------- */

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        if ($refresh) Cache::forget('settings');
        try {
            $cache = Cache::remember('settings', 120, function () {
                $all = [];
                foreach (DB::fetchAll("SELECT setting_key, setting_value FROM settings") as $row) {
                    $all[$row['setting_key']] = $row['setting_value'];
                }
                return $all;
            });
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
}

function set_setting(string $key, $value): void
{
    DB::query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, $value]);
    Cache::forget('settings');
    settings_all(true);
}

/* ---------- Encryption (stored SMTP password etc.) ---------- */

function encrypt_value(?string $plain): ?string
{
    if ($plain === null || $plain === '') return $plain;
    $key = hash('sha256', APP_KEY, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return 'enc:' . base64_encode($iv . $cipher);
}

function decrypt_value(?string $stored): ?string
{
    if ($stored === null || !str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4));
    if ($raw === false || strlen($raw) < 17) return null;
    $key = hash('sha256', APP_KEY, true);
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? null : $plain;
}

/* ---------- Dates ---------- */

function date_format_setting(): string
{
    return setting('date_format', 'd-M-Y');
}

function format_date($value, ?string $format = null): string
{
    if (empty($value) || $value === '0000-00-00') return '—';
    try {
        return (new DateTime($value))->format($format ?? date_format_setting());
    } catch (Throwable $e) {
        return '—';
    }
}

function format_datetime($value): string
{
    return format_date($value, date_format_setting() . ' h:i A');
}

function time_ago($value): string
{
    if (empty($value)) return 'never';
    $ts = strtotime($value);
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . ' days ago';
    return format_date($value);
}

function days_until($date): ?int
{
    if (empty($date) || $date === '0000-00-00') return null;
    $d = DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
    if (!$d) return null;
    $d->setTime(0, 0);
    $today = new DateTime('today');
    return (int) $today->diff($d)->format('%r%a');
}

function duration_human(?int $seconds): string
{
    if ($seconds === null) return '—';
    if ($seconds < 60) return $seconds . 's';
    $m = intdiv($seconds, 60);
    if ($m < 60) return $m . 'm';
    $h = intdiv($m, 60);
    $m = $m % 60;
    if ($h < 24) return $h . 'h ' . $m . 'm';
    return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
}

/* ---------- Badges ---------- */

function status_pill(string $cls, string $label, bool $dot = true): string
{
    return '<span class="badge badge-status bg-' . $cls . '-subtle text-' . $cls . '">' . ($dot ? '<span class="dot"></span>' : '') . e($label) . '</span>';
}

function website_status_badge(?string $status): string
{
    $map = [
        'online'       => ['success', 'Online'],
        'down'         => ['danger', 'Down'],
        'redirecting'  => ['info', 'Redirecting'],
        'ssl_error'    => ['danger', 'SSL Error'],
        'server_error' => ['danger', 'Server Error'],
        'timeout'      => ['danger', 'Timeout'],
        'parked'       => ['danger', 'Parked / Placeholder'],
        'content_error'=> ['danger', 'Content Missing'],
        'unknown'      => ['secondary', 'Not Checked'],
        'paused'       => ['secondary', 'Paused'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', ucfirst((string) $status ?: 'Unknown')];
    return status_pill($cls, $label);
}

function ssl_status_badge(?string $status, $days = null): string
{
    $map = [
        'valid'         => ['success', 'Valid'],
        'expiring_soon' => ['warning', 'Expiring Soon'],
        'expired'       => ['danger', 'Expired'],
        'error'         => ['danger', 'SSL Error'],
        'unknown'       => ['secondary', 'Not Checked'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', 'Not Checked'];
    if ($days !== null && in_array($status, ['valid', 'expiring_soon'], true)) $label .= ' · ' . $days . 'd';
    return status_pill($cls, $label);
}

function form_status_badge(?string $status): string
{
    $map = [
        'working'         => ['success', 'Working'],
        'failed'          => ['danger', 'Failed'],
        'captcha_blocked' => ['warning', 'CAPTCHA Protected'],
        'not_tested'      => ['secondary', 'Not Tested'],
        'disabled'        => ['secondary', 'Disabled'],
        'removed'         => ['dark', 'Removed / Not Found'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', 'Not Tested'];
    return status_pill($cls, $label);
}

/** Display URL of a monitored page: the clean URL (without .php) when the site serves it, otherwise the real URL. */
function page_display_url(?string $cleanUrl, string $url): string
{
    return ($cleanUrl !== null && $cleanUrl !== '') ? $cleanUrl : $url;
}

/** Source badge for a form record: discovered automatically vs. added by hand. */
function form_source_badge(?string $source): string
{
    return $source === 'auto' ? '<span class="badge bg-success-subtle text-success" title="Discovered automatically by the website scan">auto</span>' : '<span class="badge bg-secondary-subtle text-secondary" title="Added manually">manual</span>';
}

/* ---------- Form test outcomes (precise classification of every automated test) ---------- */

/** outcome => [colour, label, result value stored in form_tests.result, sends a failure alert?] */
function form_outcomes(): array
{
    return [
        // outcome => [badge tone, label, result (success|failed|blocked), sends failure alerts?, explanation]
        'working'         => ['success', 'Working', 'success', false, 'Page loaded, the form was found, the submission was accepted by the server.'],
        'email_unknown'   => ['success', 'Working · Email Not Verified', 'success', false, 'The submission was accepted by the server. Email delivery could not be verified (no IMAP test mailbox configured).'],
        'failed'          => ['danger', 'Submission Failed', 'failed', true, 'The page and form are available but the submission was rejected or returned an error.'],
        'server_error'    => ['danger', 'Server Error', 'failed', true, 'The form handler answered with a server error (HTTP 5xx).'],
        'timeout'         => ['danger', 'Timeout', 'failed', true, 'The form handler did not answer within the timeout.'],
        'js_error'        => ['danger', 'JavaScript Error', 'failed', true, 'The page\'s own JavaScript crashed on submit, so no request was sent.'],
        'network_error'   => ['danger', 'Connection Failed', 'failed', true, 'The form page or its handler could not be reached (DNS, connection, network).'],
        'not_found'       => ['dark', 'Form Not Found', 'failed', true, 'The page loaded but the form (or its selector) was not found on it.'],
        'captcha_blocked' => ['warning', 'CAPTCHA Protected', 'blocked', false, 'Form page is available and the form is present, but automated submission cannot be completed because CAPTCHA verification requires a legitimate user interaction. Not a failure – no alert is sent.'],
        'interference'    => ['warning', 'Needs Attention · Widget Overlap', 'blocked', false, 'A third-party widget (chat, cookie banner) covers the submit button, so the submission could not be verified.'],
        'config_error'    => ['warning', 'Needs Attention · Configuration', 'failed', false, 'The test configuration needs a change (selector, popup trigger, engine) before the form can be tested.'],
    ];
}

function form_outcome_label(?string $outcome): string
{
    return form_outcomes()[$outcome][1] ?? ($outcome ? ucwords(str_replace('_', ' ', $outcome)) : '—');
}

function form_outcome_explanation(?string $outcome): string
{
    return form_outcomes()[$outcome][4] ?? '';
}

function form_outcome_badge(?string $outcome, bool $dot = true): string
{
    if (!$outcome) return '<span class="text-muted">—</span>';
    [$cls, $label] = form_outcomes()[$outcome] ?? ['secondary', ucwords(str_replace('_', ' ', $outcome))];
    return status_pill($cls, strtoupper($label), $dot);
}

function form_captcha_types(): array
{
    return ['none' => 'None', 'recaptcha' => 'Google reCAPTCHA (v2 / v3)', 'hcaptcha' => 'hCaptcha', 'turnstile' => 'Cloudflare Turnstile', 'other' => 'Other CAPTCHA / anti-bot'];
}

function form_captcha_label(?string $type): string
{
    $map = ['recaptcha' => 'Google reCAPTCHA', 'recaptcha_v2' => 'Google reCAPTCHA v2', 'recaptcha_v3' => 'Google reCAPTCHA v3', 'hcaptcha' => 'hCaptcha', 'turnstile' => 'Cloudflare Turnstile',
        'captcha_field' => 'CAPTCHA field', 'antibot' => 'Anti-bot plugin', 'other' => 'CAPTCHA / anti-bot', 'none' => 'None'];
    return $map[$type] ?? ($type ? ucwords(str_replace('_', ' ', $type)) : 'None');
}

/** Design / reference links of a website: key => [label, icon, url] (only the ones that are set). */
function website_design_links(array $w): array
{
    $defs = ['figma_url' => ['Figma', 'bi-vector-pen'], 'xd_url' => ['Adobe XD', 'bi-palette'], 'demo_url' => ['HTML Demo', 'bi-window-stack'], 'reference_url' => ['Reference', 'bi-link-45deg']];
    $out = [];
    foreach ($defs as $k => [$label, $icon]) if (!empty($w[$k])) $out[$k] = [$label, $icon, $w[$k]];
    return $out;
}

/** Page-level health of a website: GOOD (all pages working), WARNING (some failed), FAILED (homepage or most pages failed). */
function page_health_badge(?string $health, $total = null, $failed = null): string
{
    $map = [
        'good'    => ['success', 'GOOD'],
        'warning' => ['warning', 'WARNING'],
        'failed'  => ['danger', 'FAILED'],
        'unknown' => ['secondary', 'Not Scanned'],
    ];
    [$cls, $label] = $map[$health] ?? ['secondary', 'Not Scanned'];
    if ($total !== null && $health !== 'unknown') $label .= ' · ' . ((int) $total - (int) $failed) . '/' . (int) $total;
    return status_pill($cls, $label);
}

/** Status badge for a single monitored page (same statuses as websites, shorter wording). */
function page_status_badge(?string $status): string
{
    if ($status === 'online' || $status === 'redirecting') return status_pill('success', 'Working');
    if ($status === 'unknown' || $status === null) return status_pill('secondary', 'Not Checked');
    if ($status === 'paused') return status_pill('secondary', 'Paused');
    return status_pill('danger', 'Failed');
}

function credential_types(): array
{
    return ['wordpress' => 'WordPress Login', 'domain' => 'Domain Login', 'hosting' => 'Hosting Login', 'other' => 'Other Login', 'cpanel' => 'cPanel / Control Panel', 'ftp' => 'FTP / SFTP', 'email' => 'Email Account', 'database' => 'Database'];
}

/** Per-type icon, field labels and placeholders for the login modal / client page (v1.8). */
function credential_type_meta(?string $type): array
{
    $m = [
        'wordpress' => ['icon' => 'bi-wordpress', 'group' => 'WordPress Login', 'provider' => 'Website / site name', 'provider_ph' => 'e.g. example.com', 'url' => 'WordPress login URL', 'url_ph' => 'https://example.com/wp-admin', 'user' => 'WordPress username / email'],
        'domain'    => ['icon' => 'bi-hdd-network', 'group' => 'Domain Login', 'provider' => 'Domain / registrar name', 'provider_ph' => 'GoDaddy, Namecheap, BigRock…', 'url' => 'Login URL', 'url_ph' => 'https://www.godaddy.com/', 'user' => 'Username / Email'],
        'hosting'   => ['icon' => 'bi-server', 'group' => 'Hosting Login', 'provider' => 'Hosting provider', 'provider_ph' => 'Hostinger, GoDaddy, AWS…', 'url' => 'Login URL', 'url_ph' => 'https://hpanel.hostinger.com/', 'user' => 'Username / Email'],
        'cpanel'    => ['icon' => 'bi-terminal', 'group' => 'Other Login', 'provider' => 'Server / provider', 'provider_ph' => 'e.g. Hostinger server 12', 'url' => 'cPanel URL', 'url_ph' => 'https://example.com:2083', 'user' => 'Username'],
        'ftp'       => ['icon' => 'bi-folder2-open', 'group' => 'Other Login', 'provider' => 'Server / host', 'provider_ph' => 'ftp.example.com', 'url' => 'FTP host / URL', 'url_ph' => 'sftp://ftp.example.com:22', 'user' => 'FTP username'],
        'email'     => ['icon' => 'bi-envelope', 'group' => 'Other Login', 'provider' => 'Mail provider', 'provider_ph' => 'Google Workspace, Zoho, cPanel mail…', 'url' => 'Webmail URL', 'url_ph' => 'https://mail.example.com', 'user' => 'Email address'],
        'database'  => ['icon' => 'bi-database', 'group' => 'Other Login', 'provider' => 'Database / server', 'provider_ph' => 'MySQL on server 12', 'url' => 'phpMyAdmin / host URL', 'url_ph' => 'https://example.com/phpmyadmin', 'user' => 'Database user'],
        'other'     => ['icon' => 'bi-key', 'group' => 'Other Login', 'provider' => 'Provider / service', 'provider_ph' => 'e.g. Google Analytics, Cloudflare, SMTP…', 'url' => 'Login URL', 'url_ph' => 'https://', 'user' => 'Username / Email'],
    ];
    return $m[$type] ?? $m['other'];
}

/** Masked password placeholder (the real value is only ever fetched over AJAX by an admin). */
function password_mask(): string
{
    return '••••••••••';
}

function client_status_badge(?string $s): string
{
    $map = ['active' => 'success', 'inactive' => 'warning', 'archived' => 'secondary'];
    return status_pill($map[$s] ?? 'secondary', ucfirst((string) $s), false);
}

function expiry_badge($date, int $warnDays = 30): string
{
    $days = days_until($date);
    if ($days === null) return '<span class="text-muted">—</span>';
    if ($days < 0) return status_pill('danger', 'Expired ' . abs($days) . 'd ago', false);
    if ($days <= $warnDays) return status_pill('warning', $days . ' days left', false);
    return status_pill('success', $days . ' days left', false);
}

/* ---------- Misc ---------- */

/**
 * Client IP for throttles, audit and analytics. Proxy / CDN headers (Cloudflare, X-Forwarded-For) are honoured ONLY
 * when the platform is configured to sit behind such a proxy (setting analytics_trust_proxy = 1); otherwise anyone
 * could spoof them to bypass login / reset / registration rate limits.
 */
function client_ip(): string
{
    static $trust = null;
    if ($trust === null) { try { $trust = (bool) setting('analytics_trust_proxy', 0); } catch (Throwable $e) { $trust = false; } }
    if ($trust) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function app_log(string $level, string $message, array $context = []): void
{
    if (!is_dir(LOG_PATH)) @mkdir(LOG_PATH, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message;
    if ($context) $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    @file_put_contents(LOG_PATH . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** SQL list of website statuses that count as "not working" (for IN (...) clauses). */
function down_statuses_sql(): string
{
    return "'down','server_error','timeout','ssl_error','parked','content_error'";
}

function technologies(): array
{
    return ['HTML', 'PHP', 'WordPress', 'CodeIgniter', 'Laravel', 'React', 'Other'];
}

/* ---------- Tenant helpers ---------- */

/** Fetch a row that must belong to the current workspace, or stop with 404 (JSON for AJAX). */
function own_or_404(string $table, int $id, string $what = 'Record'): array
{
    $row = $id > 0 ? DB::fetch("SELECT * FROM `$table` WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]) : null;
    if (!$row) {
        if (is_ajax()) json_error($what . ' not found.', 404);
        http_error(404, $what . ' not found.');
    }
    return $row;
}

/** Manual "Check Now" rate limit per target (seconds from Settings → manual_check_cooldown_seconds). */
function manual_check_guard(string $key): void
{
    $cool = max(0, (int) setting('manual_check_cooldown_seconds', 60));
    if ($cool === 0 || Auth::isPlatformAdmin()) return;
    $k = 'manual:' . Tenant::id() . ':' . $key;
    $last = Cache::get($k);
    if ($last && time() - (int) $last < $cool) json_error('Please wait ' . ($cool - (time() - (int) $last)) . ' s before running this check again.', 429);
    Cache::set($k, time(), $cool + 5);
}

/** Usage badge "7 / 10" with colour by percentage (null limit = unlimited). */
function usage_badge(int $current, ?int $limit): string
{
    if ($limit === null) return '<span class="badge bg-success-subtle text-success">' . number_format($current) . ' · unlimited</span>';
    $pct = $limit ? (int) round($current / max(1, $limit) * 100) : 100;
    $cls = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
    return '<span class="badge bg-' . $cls . '-subtle text-' . $cls . '">' . number_format($current) . ' / ' . number_format($limit) . '</span>';
}

/** Progress bar for the usage cards. */
function usage_bar(array $u): string
{
    $limit = $u['limit'];
    $pct = $u['pct'];
    $cls = $u['state'] === 'full' ? 'bg-danger' : ($u['state'] === 'warn' ? 'bg-warning' : 'bg-success');
    $label = $limit === null ? number_format($u['current']) . ' <span class="text-muted small">· unlimited</span>' : number_format($u['current']) . ' <span class="text-muted">/ ' . number_format($limit) . '</span>';
    $note = $limit === null ? '' : ($u['state'] === 'full' ? '<div class="small-xs text-danger fw-500">Plan limit reached – <a href="' . url('billing/index.php') . '">upgrade to continue</a></div>' : ($u['state'] === 'warn' ? '<div class="small-xs text-warning fw-500">' . $pct . '% used</div>' : ''));
    return '<div class="d-flex justify-content-between align-items-baseline"><span class="stat-label">' . e($u['label']) . '</span><span class="fw-600">' . $label . '</span></div>'
        . ($limit === null ? '' : '<div class="progress mt-1" style="height:6px"><div class="progress-bar ' . $cls . '" style="width:' . $pct . '%"></div></div>') . $note;
}

/* ---------- Website projects ---------- */

/** Project statuses in workflow order: key => [label, css class]. Colours are fixed here so they stay consistent everywhere. */
function project_statuses(): array
{
    return [
        'design'           => ['Design', 'ps-design'],
        'development'      => ['Development', 'ps-development'],
        'testing'          => ['Testing', 'ps-testing'],
        'client_review'    => ['Client Review', 'ps-client_review'],
        'changes_required' => ['Changes Required', 'ps-changes_required'],
        'ready_for_launch' => ['Ready for Launch', 'ps-ready_for_launch'],
        'live'             => ['Live', 'ps-live'],
        'on_hold'          => ['On Hold', 'ps-on_hold'],
        'cancelled'        => ['Cancelled', 'ps-cancelled'],
    ];
}

function project_status_label(?string $status): string
{
    return project_statuses()[$status][0] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function project_status_badge(?string $status, bool $large = false): string
{
    [$label, $cls] = project_statuses()[$status] ?? [ucwords(str_replace('_', ' ', (string) $status)), 'ps-on_hold'];
    return '<span class="badge badge-status ' . $cls . ($large ? ' badge-lg' : '') . '"><span class="dot"></span>' . e($label) . '</span>';
}

function project_kind_badge(?string $kind): string
{
    return $kind === 'existing'
        ? '<span class="badge bg-info-subtle text-info">Existing Website</span>'
        : '<span class="badge bg-brand">New Website</span>';
}

/** Active departments (cached). */
function departments_options(bool $includeInactive = false): array
{
    return Cache::remember('departments:' . Tenant::id() . ':' . ($includeInactive ? 'all' : 'active'), 300, fn() => DB::fetchAll("SELECT * FROM departments WHERE (tenant_id IS NULL OR tenant_id = " . Tenant::id() . ") " . ($includeInactive ? '' : "AND status = 'active' ") . "ORDER BY sort_order, name"));
}

/** Active website types with their department (cached). */
function website_types_options(bool $includeInactive = false): array
{
    return Cache::remember('website_types:' . Tenant::id() . ':' . ($includeInactive ? 'all' : 'active'), 300, fn() => DB::fetchAll("SELECT t.*, d.name AS department_name FROM website_types t LEFT JOIN departments d ON d.id = t.department_id WHERE (t.tenant_id IS NULL OR t.tenant_id = " . Tenant::id() . ") " . ($includeInactive ? '' : "AND t.status = 'active' ") . "ORDER BY d.sort_order, d.name, t.sort_order, t.name"));
}

function taxonomy_changed(): void
{
    foreach (['departments:all', 'departments:active', 'website_types:all', 'website_types:active'] as $k) Cache::forget(Tenant::id() ? str_replace(':', ':' . Tenant::id() . ':', $k) : $k);
    data_changed();
}

function form_types(): array
{
    return ['Contact', 'Enquiry', 'Lead', 'Quote', 'Career', 'Admission', 'Newsletter', 'Booking', 'Other'];
}

/**
 * Type-ahead select for large lists (clients / websites / users). Renders a text input backed by api/lookup.php
 * and a hidden input carrying the id, so pages never embed thousands of <option> tags.
 */
function remote_select(string $name, string $type, $selectedId = null, string $placeholder = 'Type to search…', array $opts = []): string
{
    $label = '';
    if ($selectedId) {
        $tid = (int) Tenant::id(); // labels are resolved inside the caller's workspace only – never another tenant's rows
        if ($type === 'clients') $label = (string) DB::value("SELECT CONCAT(name, IF(company IS NULL OR company = '', '', CONCAT(' · ', company))) FROM clients WHERE id = ? AND tenant_id = ?", [$selectedId, $tid]);
        elseif ($type === 'websites') { $r = DB::fetch("SELECT name, url FROM websites WHERE id = ? AND tenant_id = ?", [$selectedId, $tid]); $label = $r ? $r['name'] . ' · ' . host_from_url($r['url']) : ''; }
        elseif ($type === 'users') $label = (string) DB::value("SELECT name FROM users WHERE id = ? AND tenant_id = ?", [$selectedId, $tid]);
    }
    $attrs = '';
    foreach ($opts as $k => $v) $attrs .= ' data-' . e($k) . '="' . e($v) . '"';
    $req = !empty($opts['required']) ? ' required' : '';
    return '<div class="remote-select" data-type="' . e($type) . '"' . $attrs . '>'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($selectedId ?: '') . '"' . $req . '>'
        . '<div class="input-group"><input type="text" class="form-control rs-input' . (!empty($opts['small']) ? ' form-control-sm' : '') . '" placeholder="' . e($placeholder) . '" value="' . e($label) . '" autocomplete="off"' . (!empty($opts['small']) ? ' style="min-width:160px"' : '') . '>'
        . '<button type="button" class="btn btn-outline-secondary rs-clear' . (!empty($opts['small']) ? ' btn-sm' : '') . '" tabindex="-1" title="Clear"><i class="bi bi-x"></i></button></div>'
        . '<div class="rs-results"></div></div>';
}

/** Small option lists only (users are few compared to clients). Kept for backwards compatibility with limits. */
function all_users_options(): array
{
    if (Tenant::id()) return Cache::remember('users:options:' . Tenant::id(), 60, fn() => DB::fetchAll("SELECT id, name FROM users WHERE tenant_id = " . Tenant::id() . " AND status = 'active' AND role <> 'notify' ORDER BY name"));
    return Cache::remember('users:options', 60, fn() => DB::fetchAll("SELECT id, name FROM users WHERE status = 'active' ORDER BY name LIMIT 500"));
}

/** Cached per-request/short-lived counters so list pages and the header do not re-run the same COUNT queries. */
function cached_count(string $key, string $sql, array $params = [], int $ttl = 20): int
{
    return (int) Cache::remember(Cache::vkey('data', 'count:' . $key), $ttl, fn() => (int) DB::value($sql, $params));
}

/** Call after any create/update/delete so cached counters and dashboard statistics refresh. */
function data_changed(): void
{
    Cache::bump('data');
}

function truncate(?string $s, int $len = 60): string
{
    $s = (string) $s;
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}

function company_name(): string
{
    return setting('company_name', 'Outline Media');
}

function company_logo_url(string $variant = 'light'): string
{
    $custom = setting('logo_file');
    if ($custom && is_file(UPLOAD_PATH . '/' . $custom)) {
        return url('uploads/' . $custom);
    }
    return asset('images/logo-' . $variant . '.svg');
}

/* ---------- Forms (v1.6) ---------- */

function form_kinds(): array
{
    return ['normal', 'popup', 'ajax', 'wordpress'];
}

function form_kind_label(?string $kind): string
{
    return ['normal' => 'Normal Form', 'popup' => 'Popup Form', 'ajax' => 'AJAX / JavaScript Form', 'wordpress' => 'WordPress Form'][$kind ?? 'normal'] ?? 'Form';
}

function form_kind_badge(?string $kind, ?string $plugin = null): string
{
    $map = ['normal' => ['secondary', 'Normal'], 'popup' => ['brand', 'Popup'], 'ajax' => ['info', 'AJAX'], 'wordpress' => ['primary', 'WordPress']];
    [$cls, $label] = $map[$kind ?? 'normal'] ?? ['secondary', 'Normal'];
    if ($plugin) $label .= ' · ' . FormTester::pluginLabel($plugin);
    return status_pill($cls, $label, false);
}

/** Allowed automatic form-test intervals (minutes). */
function form_intervals(): array
{
    return [10 => 'Every 10 minutes', 15 => 'Every 15 minutes', 20 => 'Every 20 minutes', 30 => 'Every 30 minutes', 60 => 'Every hour'];
}

function ssl_health_badge(?string $status): string
{
    return in_array($status, ['expired', 'error'], true) ? status_pill('danger', 'FAILED') : (in_array($status, ['valid', 'expiring_soon'], true) ? status_pill('success', 'WORKING') : status_pill('secondary', 'NOT CHECKED'));
}

/** Meaningful empty state: icon, title, short explanation and (optional) primary action HTML. */
function empty_state(string $icon, string $title, string $text = '', string $action = '', string $class = ''): string
{
    return '<div class="empty-state ' . e($class) . '"><i class="bi ' . e($icon) . '"></i><div class="es-title">' . e($title) . '</div>' . ($text !== '' ? '<div class="es-text">' . e($text) . '</div>' : '') . $action . '</div>';
}

/** Skeleton placeholder block (shown while charts / lazy content load). */
function skeleton_block(int $lines = 3, string $height = ''): string
{
    $h = '';
    for ($i = 0; $i < $lines; $i++) $h .= '<div class="skeleton skeleton-line ' . ($i % 3 === 1 ? 'w-75' : ($i % 3 === 2 ? 'w-50' : '')) . '"></div>';
    return '<div class="skeleton-wrap"' . ($height ? ' style="min-height:' . e($height) . '"' : '') . '>' . $h . '</div>';
}
/** 95 → "1 min 35 s", 4000 → "1 h 6 min" (scheduler health). */
function human_seconds(int $s): string
{
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return intdiv($s, 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '');
    return intdiv($s, 3600) . ' h' . (intdiv($s % 3600, 60) ? ' ' . intdiv($s % 3600, 60) . ' min' : '');
}
