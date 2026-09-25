<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
data_changed();

$action = post('action', '');

function load_website(int $id): array
{
    $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
    if (!$w) json_error('Website not found.', 404);
    return $w;
}

function load_page(int $id): array
{
    $p = DB::fetch("SELECT * FROM website_pages WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
    if (!$p) json_error('Page not found.', 404);
    return $p;
}

switch ($action) {
    case 'save':
        Auth::requireAbility('websites');
        $id = post_int('id');
        $data = [
            'client_id'               => post_int('client_id') ?: 0,
            'name'                    => post('name', ''),
            'url'                     => normalize_url(post('url', '')),
            'admin_url'               => post_nullable('admin_url') ? normalize_url(post('admin_url')) : null,
            // Design / reference links (all optional)
            'figma_url'               => post_nullable('figma_url') ? normalize_url(post('figma_url')) : null,
            'xd_url'                  => post_nullable('xd_url') ? normalize_url(post('xd_url')) : null,
            'demo_url'                => post_nullable('demo_url') ? normalize_url(post('demo_url')) : null,
            'reference_url'           => post_nullable('reference_url') ? normalize_url(post('reference_url')) : null,
            'technology'              => in_array(post('technology'), technologies(), true) ? post('technology') : 'Other',
            'hosting_login_ref'       => post_nullable('hosting_login_ref'),
            'monitoring_enabled'      => post('monitoring_enabled') ? 1 : 0,
            'page_monitoring_enabled' => post('page_monitoring_enabled') ? 1 : 0,
            'max_pages'               => max(0, min(500, post_int('max_pages', 0) ?? 0)),
            'expect_text'             => post_nullable('expect_text'),
            'notes'                   => post_nullable('notes'),
            'wp_login_url'            => post_nullable('wp_login_url') ? normalize_url(post('wp_login_url')) : null,
            'wp_username'             => post_nullable('wp_username'),
        ];
        $errors = [];
        if ($data['name'] === '') $errors['name'] = 'Website name is required.';
        if (!valid_url($data['url'])) $errors['url'] = 'Enter a valid URL, e.g. https://example.com';
        if ($data['admin_url'] !== null && !valid_url($data['admin_url'])) $errors['admin_url'] = 'Enter a valid URL.';
        foreach (['figma_url' => 'Figma design URL', 'xd_url' => 'Adobe XD design URL', 'demo_url' => 'HTML / demo URL', 'reference_url' => 'reference URL'] as $k => $label) {
            if ($data[$k] !== null && (!valid_url($data[$k]) || mb_strlen($data[$k]) > 500)) $errors[$k] = 'Enter a valid ' . $label . ' (https://…).';
        }
        if (!$data['client_id'] || !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$data['client_id'], Tenant::id()])) $errors['client_id'] = 'Please select a client.';

        // WordPress websites MUST have login URL, username and password (password may be kept when editing)
        $existing = $id ? load_website($id) : null;
        $wpPassword = (string) ($_POST['wp_password'] ?? '');
        $isWp = $data['technology'] === 'WordPress';
        if ($isWp) {
            if ($data['wp_login_url'] === null) $errors['wp_login_url'] = 'WordPress login URL is required for WordPress websites (e.g. https://example.com/wp-admin).';
            elseif (!valid_url($data['wp_login_url'])) $errors['wp_login_url'] = 'Enter a valid WordPress login URL.';
            if ($data['wp_username'] === null) $errors['wp_username'] = 'WordPress username / user ID is required.';
            if ($wpPassword === '' && empty($existing['wp_password'])) $errors['wp_password'] = 'WordPress password is required.';
        } else {
            if ($data['wp_login_url'] !== null && !valid_url($data['wp_login_url'])) $errors['wp_login_url'] = 'Enter a valid URL.';
        }
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($wpPassword !== '') $data['wp_password'] = encrypt_value($wpPassword);
        if (post('clear_wp_password') && !$isWp) $data['wp_password'] = null;
        if ($isWp && $data['admin_url'] === null) $data['admin_url'] = $data['wp_login_url'];

        // Optional domain / hosting details saved to their own tables (one record per website)
        $domain = [
            'domain_name'       => post_nullable('domain_name') ?: host_from_url($data['url']),
            'registrar'         => post_nullable('domain_provider'),
            'registration_date' => post_nullable('domain_registration_date'),
            'expiry_date'       => post_nullable('domain_expiry'),
            'auto_renew'        => post('domain_auto_renew') ? 1 : 0,
            'login_ref'         => post_nullable('domain_login_ref'),
        ];
        $hosting = [
            'provider'       => post_nullable('hosting_provider'),
            'server_ip'      => post_nullable('server_ip'),
            'plan'           => post_nullable('hosting_plan'),
            'start_date'     => post_nullable('hosting_start_date'),
            'expiry_date'    => post_nullable('hosting_expiry'),
            'renewal_status' => in_array(post('hosting_renewal_status'), ['auto', 'manual', 'cancelled'], true) ? post('hosting_renewal_status') : 'manual',
            'login_ref'      => post_nullable('hosting_login_ref'),
        ];
        foreach (['registration_date', 'expiry_date'] as $k) {
            if ($domain[$k] && !strtotime($domain[$k])) $domain[$k] = null;
        }
        foreach (['start_date', 'expiry_date'] as $k) {
            if ($hosting[$k] && !strtotime($hosting[$k])) $hosting[$k] = null;
        }

        DB::begin();
        try {
            if ($id) {
                if ($existing['url'] !== $data['url']) {
                    // URL changed: reset monitoring state and the discovered page list
                    $data['status'] = 'unknown';
                    $data['ssl_status'] = 'unknown';
                    $data['pages_discovered_at'] = null;
                    $data['page_health'] = 'unknown';
                    $data['pages_total'] = 0; $data['pages_ok'] = 0; $data['pages_failed'] = 0;
                    DB::delete('website_pages', 'website_id = ?', [$id]);
                }
                if (!$data['monitoring_enabled'] && $existing['monitoring_enabled']) $data['status'] = 'paused';
                if ($data['monitoring_enabled'] && $existing['status'] === 'paused') $data['status'] = 'unknown';
                DB::update('websites', $data, 'id = ?', [$id]);
                ActivityLog::add('website_updated', 'Website updated: ' . $data['name'] . ($wpPassword !== '' ? ' (WordPress password changed)' : ''), ['client_id' => $data['client_id'], 'website_id' => $id]);
                $msg = 'Website updated.';
            } else {
                $can = Tenant::canAdd('websites');
                if (!$can['ok']) { DB::rollBack(); json_error($can['message'], 403, ['upgrade' => true, 'usage' => $can]); }
                $data['tenant_id'] = Tenant::id();
                $data['created_by'] = Auth::id();
                if (!$data['monitoring_enabled']) $data['status'] = 'paused';
                $id = DB::insert('websites', $data);
                Tenant::usage(null, true);
                ActivityLog::add('website_added', 'Website added: ' . $data['name'] . ' (' . $data['url'] . ')', ['client_id' => $data['client_id'], 'website_id' => $id]);
                $msg = 'Website added.';
            }

            // Domain record
            $hasDomainInfo = $domain['registrar'] || $domain['expiry_date'] || $domain['registration_date'] || $domain['login_ref'] || post_nullable('domain_name');
            $d = DB::fetch("SELECT id FROM domains WHERE website_id = ?", [$id]);
            if ($hasDomainInfo || $d) {
                $domain['client_id'] = $data['client_id'];
                $domain['website_id'] = $id;
                if ($d) DB::update('domains', $domain, 'id = ?', [$d['id']]);
                else DB::insert('domains', $domain + ['tenant_id' => Tenant::id()]);
            }
            // Hosting record
            $hasHostingInfo = $hosting['provider'] || $hosting['expiry_date'] || $hosting['server_ip'] || $hosting['plan'];
            $h = DB::fetch("SELECT id FROM hosting WHERE website_id = ?", [$id]);
            if ($hasHostingInfo || $h) {
                $hosting['client_id'] = $data['client_id'];
                $hosting['website_id'] = $id;
                if (!$hosting['provider']) $hosting['provider'] = 'Unknown';
                if ($h) DB::update('hosting', $hosting, 'id = ?', [$h['id']]);
                else DB::insert('hosting', $hosting + ['tenant_id' => Tenant::id()]);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // First check right away (alerts are raised if the site is already broken, e.g. a parked domain)
        $checked = null;
        if (post('check_now') && $data['monitoring_enabled']) {
            try {
                set_time_limit(180);
                $w = load_website($id);
                $checked = Monitor::checkWebsite($w, true);
                Monitor::checkSsl($w, true);
                if ($data['page_monitoring_enabled'] && setting('page_monitoring_enabled', 1)) {
                    Monitor::scanPagesBatch([load_website($id)], true, true);
                }
                // Quick automatic form discovery (HTTP inspection, short budget); the cron completes the full browser scan shortly after
                if (setting('form_discovery_enabled', 1)) {
                    FormDiscovery::scanWebsite(load_website($id), ['engine' => 'http', 'budget' => 40, 'max_pages' => 25, 'trigger' => 'save']);
                    DB::update('websites', ['form_scan_requested' => 1], 'id = ?', [$id]);
                }
                Mailer::processQueue(10);
            } catch (Throwable $e) {
                app_log('warning', 'Initial check failed: ' . $e->getMessage());
            }
        }
        json_success($msg, ['id' => $id, 'redirect' => post('stay') ? null : url('websites/view.php?id=' . $id), 'checked' => $checked ? $checked['status'] : null]);

    case 'quick_add':
        // SaaS "Add website" wizard: URL (+ optional name / client) → website record; the wizard then runs discovery step by step
        Auth::requireAbility('websites');
        $url = normalize_url(post('url', ''));
        if (!valid_url($url)) json_error('Enter a valid website URL, e.g. https://example.com', 422, ['errors' => ['url' => 'Enter a valid URL']]);
        $host = host_from_url($url);
        $devLocal = APP_ENV === 'development' && preg_match('~^(localhost|127\.)~', $host); // local test sites while developing
        if (!$devLocal && (!$host || !preg_match('~\.[a-z]{2,}$~i', $host) || preg_match('~^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)~', $host) && !Auth::isPlatformAdmin())) json_error('Enter a public website URL.', 422, ['errors' => ['url' => 'Public domain required']]);
        $can = Tenant::canAdd('websites');
        if (!$can['ok']) json_error($can['message'], 403, ['upgrade' => true, 'usage' => $can]);
        if (DB::value("SELECT id FROM websites WHERE tenant_id = ? AND (url = ? OR url = ?)", [Tenant::id(), $url, rtrim($url, '/')])) json_error('This website is already in your workspace.', 422);
        $clientId = post_int('client_id') ?: 0;
        if ($clientId && !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$clientId, Tenant::id()])) $clientId = 0;
        if (!$clientId) {
            // default client of the workspace ("My Websites") – agencies can move the site to a real client later
            $clientId = (int) DB::value("SELECT id FROM clients WHERE tenant_id = ? AND name = 'My Websites' LIMIT 1", [Tenant::id()]);
            if (!$clientId) $clientId = DB::insert('clients', ['tenant_id' => Tenant::id(), 'name' => 'My Websites', 'company' => Tenant::name(), 'email' => Auth::user()['email'], 'status' => 'active', 'monitoring_enabled' => 1, 'notify_client' => 0, 'created_by' => Auth::id()]);
        }
        $name = trim((string) post('name', '')) ?: ucfirst(preg_replace('~^www\.~', '', $host));
        $tech = in_array(post('technology'), technologies(), true) ? post('technology') : 'Other';
        $id = DB::insert('websites', ['tenant_id' => Tenant::id(), 'client_id' => $clientId, 'name' => mb_substr($name, 0, 150), 'url' => $url, 'technology' => $tech, 'monitoring_enabled' => 1, 'page_monitoring_enabled' => 1,
            'form_discovery_enabled' => (int) (bool) Tenant::feature('form_discovery'), 'status' => 'unknown', 'created_by' => Auth::id()]);
        Tenant::usage(null, true);
        ActivityLog::add('website_added', 'Website added: ' . $name . ' (' . $url . ')', ['client_id' => $clientId, 'website_id' => $id]);
        json_success('Website added.', ['id' => $id, 'name' => $name, 'url' => $url, 'view' => url('websites/view.php?id=' . $id)]);

    case 'wizard_step':
        // one discovery step of the Add-Website wizard (validate → ssl → pages → forms); each step is short enough for a web request
        Auth::requireAbility('websites');
        set_time_limit(180);
        $w = load_website((int) post_int('id'));
        $step = post('step', '');
        $out = ['step' => $step];
        switch ($step) {
            case 'validate':
                $r = Monitor::checkWebsite($w, false);
                $out['ok'] = (bool) $r['up']; $out['text'] = $r['up'] ? 'Website detected · HTTP ' . $r['http_code'] . ' in ' . $r['response_ms'] . ' ms' : 'Website not reachable: ' . ($r['reason'] ?: $r['error'] ?: 'unknown');
                break;
            case 'ssl':
                if (stripos($w['url'], 'https://') !== 0) { $out['ok'] = null; $out['text'] = 'No SSL (http:// website)'; break; }
                $r = Monitor::checkSsl($w, false);
                $out['ok'] = $r['status'] === 'valid' || $r['status'] === 'expiring_soon'; $out['text'] = $r['status'] === 'valid' ? 'SSL valid' . ($r['days'] !== null ? ' · ' . $r['days'] . ' days left' : '') : 'SSL ' . str_replace('_', ' ', $r['status']) . ($r['error'] ? ' – ' . $r['error'] : '');
                break;
            case 'pages':
                $r = Monitor::discoverPages($w);
                $out['ok'] = true; $out['text'] = $r['active'] . ' pages discovered' . ($r['sitemap'] ? ' (sitemap + links)' : ' (internal links)') . (Tenant::limit('pages') !== null && $r['found'] > $r['active'] ? ' · plan limit reached: ' . $r['active'] . ' of ' . $r['found'] . ' monitored' : '');
                $out['limited'] = Tenant::limit('pages') !== null && $r['found'] > $r['active'];
                break;
            case 'forms':
                if (!Tenant::feature('form_discovery')) { $out['ok'] = null; $out['text'] = 'Automatic form discovery is not included in your plan'; break; }
                $r = FormDiscovery::scanWebsite($w, ['engine' => 'http', 'budget' => 60, 'max_pages' => 40, 'trigger' => 'wizard']);
                DB::update('websites', ['form_scan_requested' => 1], 'id = ?', [$w['id']]);
                $c = $r['counts'] ?? [];
                $out['ok'] = true; $out['text'] = ($c['forms_total'] ?? 0) . ' forms discovered' . (($c['forms_popup'] ?? 0) ? ' · ' . $c['forms_popup'] . ' popup' : '') . (($c['forms_ajax'] ?? 0) ? ' · ' . $c['forms_ajax'] . ' AJAX' : '');
                $captcha = (int) DB::value("SELECT COUNT(*) FROM forms WHERE website_id = ? AND captcha_detected IS NOT NULL", [$w['id']]);
                $out['extra'] = ($captcha ? $captcha . ' CAPTCHA-protected form(s) detected · ' : '') . 'popup forms are completed by the browser scan shortly' . (!empty($r['limited']) ? ' · ' . $r['limited'] . ' form(s) beyond your plan limit' : '');
                break;
            default:
                json_error('Unknown step.');
        }
        Mailer::processQueue(5);
        json_success('OK', $out);

    case 'get':
        $w = load_website((int) post_int('id'));
        $w['has_wp_password'] = !empty($w['wp_password']);
        unset($w['wp_password']); // never sent to the browser – see wp_reveal
        $w['domain'] = DB::fetch("SELECT * FROM domains WHERE website_id = ?", [$w['id']]);
        $w['hosting'] = DB::fetch("SELECT * FROM hosting WHERE website_id = ?", [$w['id']]);
        json_success('OK', ['website' => $w]);

    case 'wp_reveal':
        // Decrypted WordPress password – admin only, every view is logged
        Auth::requireRole('admin');
        $w = load_website((int) post_int('id'));
        if (empty($w['wp_password'])) json_error('No WordPress password stored for this website.');
        $plain = decrypt_value($w['wp_password']);
        if ($plain === null) json_error('The stored password could not be decrypted (APP_KEY changed?).');
        ActivityLog::add('credential_viewed', 'WordPress password revealed: ' . $w['name'], ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success('OK', ['password' => $plain]);

    case 'delete':
        Auth::requireRole('admin');
        $w = load_website((int) post_int('id'));
        DB::delete('websites', 'id = ?', [$w['id']]);
        ActivityLog::add('website_deleted', 'Website deleted: ' . $w['name'] . ' (' . $w['url'] . ')', ['client_id' => $w['client_id']]);
        json_success('Website deleted.', ['redirect' => url('websites/index.php')]);

    case 'toggle_monitoring':
        Auth::requireAbility('websites');
        $w = load_website((int) post_int('id'));
        $on = $w['monitoring_enabled'] ? 0 : 1;
        DB::update('websites', ['monitoring_enabled' => $on, 'status' => $on ? 'unknown' : 'paused'], 'id = ?', [$w['id']]);
        ActivityLog::add('website_updated', 'Monitoring ' . ($on ? 'enabled' : 'paused') . ' for ' . $w['name'], ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success('Monitoring ' . ($on ? 'enabled' : 'paused') . '.');

    case 'check':
        Auth::requireAbility('monitor');
        $w = load_website((int) post_int('id'));
        manual_check_guard('website:' . $w['id']);
        $result = Monitor::checkWebsite($w, true);
        $ssl = null;
        if (post('with_ssl', '1')) {
            $ssl = Monitor::checkSsl($w, true);
        }
        // the manual check counts as the last run: the next automatic one is due one plan interval from now
        $tid = !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null;
        DB::query('UPDATE websites SET next_check_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)' . ($ssl ? ', next_ssl_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)' : '') . ' WHERE id = ?', $ssl ? [Tenant::interval('website', $tid), Tenant::interval('ssl', $tid), $w['id']] : [Tenant::interval('website', $tid), $w['id']]);
        Mailer::processQueue(20);
        $w = load_website($w['id']);
        json_success('Check complete: ' . strtoupper(str_replace('_', ' ', $result['status'])) . ($result['error'] ? ' – ' . $result['error'] : ''), [
            'result' => $result, 'ssl' => $ssl, 'website' => $w,
            'status_html' => website_status_badge($w['status']), 'ssl_html' => ssl_status_badge($w['ssl_status'], $w['ssl_days_left']),
        ]);

    case 'check_ssl':
        Auth::requireAbility('monitor');
        $w = load_website((int) post_int('id'));
        $ssl = Monitor::checkSsl($w, true);
        DB::query('UPDATE websites SET next_ssl_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?', [Tenant::interval('ssl', !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null), $w['id']]);
        Mailer::processQueue(20);
        json_success('SSL check complete: ' . strtoupper(str_replace('_', ' ', $ssl['status'])) . ($ssl['error'] ? ' – ' . $ssl['error'] : ''), ['ssl' => $ssl]);

    /* ------------------------------------------------------------------ page-level monitoring */
    case 'discover_forms':
        // Full automatic form discovery scan for one website (pages → forms → popups → inventory)
        Auth::requireAbility('monitor');
        set_time_limit(320);
        $w = load_website((int) post_int('id'));
        $r = FormDiscovery::scanWebsite($w, ['budget' => 240, 'trigger' => 'manual', 'engine' => post('engine') === 'http' ? 'http' : 'auto']);
        Browser::shutdownShared();
        json_success('Form discovery complete: ' . $r['summary'], ['result' => $r]);

    case 'toggle_form_discovery':
        Auth::requireAbility('websites');
        $w = load_website((int) post_int('id'));
        $on = $w['form_discovery_enabled'] ? 0 : 1;
        DB::update('websites', ['form_discovery_enabled' => $on, 'form_scan_requested' => $on], 'id = ?', [$w['id']]);
        ActivityLog::add($on ? 'monitoring_enabled' : 'monitoring_disabled', 'Automatic form discovery ' . ($on ? 'enabled' : 'disabled') . ' for ' . $w['name'], ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success('Automatic form discovery ' . ($on ? 'enabled – the website is scanned at the next cron run.' : 'disabled for this website.'));

    case 'discover_pages':
        Auth::requireAbility('monitor');
        set_time_limit(180);
        $w = load_website((int) post_int('id'));
        $r = Monitor::discoverPages($w);
        ActivityLog::add('pages_discovered', 'Pages discovered for ' . $w['name'] . ': ' . $r['found'] . ' found (' . $r['added'] . ' new), ' . $r['active'] . ' monitored' . ($r['sitemap'] ? ' – sitemap used' : ' – from internal links'), ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success($r['found'] . ' page(s) found via ' . ($r['sitemap'] ? 'sitemap and links' : 'internal links') . ' · ' . $r['added'] . ' new · ' . $r['active'] . ' monitored.', ['result' => $r]);

    case 'scan_pages':
        Auth::requireAbility('monitor');
        set_time_limit(300);
        $w = load_website((int) post_int('id'));
        $r = Monitor::scanPagesBatch([$w], true, (bool) post('discover', '1'));
        DB::query('UPDATE websites SET next_scan_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?', [Tenant::interval('page', !empty($w['tenant_id']) ? (int) $w['tenant_id'] : null), $w['id']]);
        Mailer::processQueue(20);
        json_success('Page scan complete: ' . $r['ok'] . ' / ' . $r['pages'] . ' pages working' . ($r['failed'] ? ' – ' . $r['failed'] . ' failed' : '') . '.', ['result' => $r]);

    case 'page_check':
        Auth::requireAbility('monitor');
        $p = load_page((int) post_int('id'));
        $r = Monitor::checkPage($p, true);
        Mailer::processQueue(10);
        json_success('Page check: ' . ($r['up'] ? 'WORKING (HTTP ' . $r['http_code'] . ', ' . $r['response_ms'] . ' ms)' : 'FAILED – ' . ($r['reason'] ?: '') . ($r['error'] ? ': ' . $r['error'] : '')), ['result' => $r]);

    case 'page_add':
        Auth::requireAbility('websites');
        $w = load_website((int) post_int('website_id'));
        $host = host_from_url($w['url']);
        $added = 0; $skipped = [];
        foreach (preg_split('~[\r\n,]+~', (string) post('urls', '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $href = preg_match('~^https?://~i', $line) ? $line : (str_starts_with($line, '/') ? $line : '/' . $line);
            $url = Monitor::normalizePageUrl($href, $w['url'], $host, true);
            if (!$url) { $skipped[] = $line; continue; }
            $ex = DB::fetch("SELECT id, is_active, ignored FROM website_pages WHERE website_id = ? AND url_hash = ?", [$w['id'], md5($url)]);
            if ($ex) {
                DB::update('website_pages', ['is_active' => 1, 'ignored' => 0, 'source' => 'manual', 'priority' => 5], 'id = ?', [$ex['id']]);
            } else {
                $path = Monitor::pagePath($url);
                DB::insert('website_pages', ['tenant_id' => $w['tenant_id'], 'website_id' => $w['id'], 'url' => $url, 'url_hash' => md5($url), 'path' => $path, 'title' => null, 'source' => 'manual', 'is_active' => 1, 'priority' => 5, 'discovered_at' => date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s')]);
            }
            $added++;
        }
        if (!$added) json_error('No valid page URL entered.' . ($skipped ? ' Skipped: ' . implode(', ', array_slice($skipped, 0, 5)) . ' (must be on ' . $host . ')' : ''));
        Monitor::refreshPageSummary($w['id']);
        ActivityLog::add('page_added', $added . ' page(s) added to monitoring for ' . $w['name'], ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success($added . ' page(s) added to monitoring.' . ($skipped ? ' Skipped ' . count($skipped) . ' invalid / external URL(s).' : ''));

    case 'page_remove':
        // Removes a page from monitoring; auto-discovered pages are flagged so discovery never re-adds them
        Auth::requireAbility('websites');
        $p = load_page((int) post_int('id'));
        $w = load_website((int) $p['website_id']);
        if ($p['source'] === 'home') json_error('The homepage is always monitored.');
        DB::update('website_pages', ['is_active' => 0, 'ignored' => 1, 'status' => 'paused'], 'id = ?', [$p['id']]);
        DB::query("UPDATE page_incidents SET resolved_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE page_id = ? AND resolved_at IS NULL", [$p['id']]);
        Monitor::refreshPageSummary($w['id']);
        ActivityLog::add('page_removed', 'Page removed from monitoring: ' . $p['url'] . ' (' . $w['name'] . ')', ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success('Page removed from monitoring.');

    case 'page_restore':
        Auth::requireAbility('websites');
        $p = load_page((int) post_int('id'));
        DB::update('website_pages', ['is_active' => 1, 'ignored' => 0, 'status' => 'unknown'], 'id = ?', [$p['id']]);
        Monitor::refreshPageSummary((int) $p['website_id']);
        json_success('Page is monitored again.');

    case 'toggle_page_monitoring':
        Auth::requireAbility('websites');
        $w = load_website((int) post_int('id'));
        $on = $w['page_monitoring_enabled'] ? 0 : 1;
        DB::update('websites', ['page_monitoring_enabled' => $on], 'id = ?', [$w['id']]);
        ActivityLog::add('website_updated', 'Page monitoring ' . ($on ? 'enabled' : 'paused') . ' for ' . $w['name'], ['client_id' => $w['client_id'], 'website_id' => $w['id']]);
        json_success('Page monitoring ' . ($on ? 'enabled' : 'paused') . '.');

    default:
        json_error('Unknown action.');
}
