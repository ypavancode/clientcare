<?php
/**
 * AUTOMATIC FORM DISCOVERY
 *
 * Crawls every monitored page of a website, finds every form (normal, hidden popup/modal, AJAX, WordPress plugin,
 * same-origin iframe, third-party embed) and keeps the `forms` table in sync with the live website:
 *   new form → registered and monitored automatically · changed form → selector/technology/fields updated
 *   missing form → after two consecutive complete scans marked REMOVED (history kept) · re-appearing form → active again
 *
 * Two passes: HTTP (curl + DOM, always) and, when headless Chrome is available, a browser pass on the most relevant
 * pages that also finds JavaScript-rendered forms and clicks popup triggers (buttons / links / Bootstrap & Elementor
 * modal openers) to register the forms inside popups.  Search, login, comment and cart forms are ignored on purpose.
 */
class FormDiscovery
{
    const TRIGGER_RE = '~enquir|inquir|quote|contact|get in touch|reach us|book|appoint|apply|request|call ?back|callback|subscribe|sign ?up|register|download|brochure|demo|consult|talk to|let.?s talk|get started|free trial|estimate|schedule|reserve|join|write to us|send message|feedback~i';
    const MODAL_RE = '~(^|[\s_-])(modal|popup|pop-up|pum|elementor-popup|dialog|lightbox|offcanvas|overlay|fancybox|mfp-|drawer|slide-?in|flyout)([\s_-]|$)~i';
    const NONCE_RE = '~nonce|token|csrf|_wp_http_referer|timestamp|honey|_hp\b|\bhp_|hpot|referer|_wpcf7|wpforms\[id\]|wpforms\[post_id\]|form_id|post_id|gform_|elementor|action$~i';
    const THIRD_PARTY = ['jotform.com' => 'JotForm', 'hsforms.net' => 'HubSpot Forms', 'hsforms.com' => 'HubSpot Forms', 'typeform.com' => 'Typeform', 'docs.google.com/forms' => 'Google Forms', 'tally.so' => 'Tally',
        'forms.office.com' => 'Microsoft Forms', 'zohopublic' => 'Zoho Forms', 'zoho.com/forms' => 'Zoho Forms', 'wufoo.com' => 'Wufoo', 'formstack.com' => 'Formstack', 'cognitoforms.com' => 'Cognito Forms',
        'paperform.co' => 'Paperform', '123formbuilder' => '123FormBuilder', 'calendly.com' => 'Calendly', 'forms.gle' => 'Google Forms', 'airtable.com/embed' => 'Airtable Form', 'form.jotform' => 'JotForm'];

    /* =====================================================================
     * Scheduling
     * ===================================================================== */

    /** Websites due for a discovery scan (requested, or older than Settings → "Re-scan forms every N hours"). */
    public static function due(bool $force = false, int $limit = 0): array
    {
        if (!$force && !setting('form_discovery_enabled', 1)) return [];
        $hours = max(1, (int) setting('form_scan_interval_hours', 24));
        $sql = "SELECT w.* FROM websites w JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN plans p ON p.id = t.plan_id
                WHERE w.monitoring_enabled = 1 AND w.form_discovery_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND (t.id IS NULL OR t.status = 'active')";
        if (!$force) $sql .= " AND (w.form_scan_requested = 1 OR w.last_form_scan_at IS NULL OR w.last_form_scan_at <= DATE_SUB(NOW(), INTERVAL " . $hours . " HOUR))";
        $sql .= " ORDER BY w.form_scan_requested DESC, w.last_form_scan_at ASC, w.id ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int) $limit;
        return DB::fetchAll($sql);
    }

    public static function nextScanAt(array $w): ?string
    {
        if (!empty($w['form_scan_requested'])) return 'at the next cron run';
        if (empty($w['last_form_scan_at'])) return null;
        return date('Y-m-d H:i:s', strtotime($w['last_form_scan_at']) + max(1, (int) setting('form_scan_interval_hours', 24)) * 3600);
    }

    /* =====================================================================
     * Main entry point
     * ===================================================================== */

    /**
     * @param array $opts  engine: auto|http · budget: seconds · max_pages · browser_pages · trigger: cron|manual|save
     * @return array summary
     */
    public static function scanWebsite(array $website, array $opts = []): array
    {
        if (!Tenant::lock('formscan:' . $website['id'], 600)) {
            return ['scan_id' => 0, 'pages' => 0, 'pages_failed' => 0, 'browser_pages' => 0, 'forms_found' => 0, 'new' => 0, 'changed' => 0, 'removed' => 0, 'popups' => 0, 'ignored' => 0, 'engine' => 'http', 'complete' => false,
                'notes' => ['Skipped – another scan of this website is still running'], 'summary' => 'Skipped: a scan of this website is already running', 'duration_ms' => 0, 'counts' => [], 'skipped' => true];
        }
        try { return self::scanWebsiteLocked($website, $opts); } finally { Tenant::unlock('formscan:' . $website['id']); }
    }

    private static function scanWebsiteLocked(array $website, array $opts = []): array
    {
        $t0 = microtime(true);
        $budget = max(15, (int) ($opts['budget'] ?? 240));
        $deadline = $t0 + $budget;
        $engine = ($opts['engine'] ?? 'auto') === 'http' ? 'http' : 'auto';
        $maxPages = max(1, (int) ($opts['max_pages'] ?? setting('form_scan_max_pages', 60)));
        $browserPages = max(0, (int) ($opts['browser_pages'] ?? setting('form_scan_browser_pages', 12)));
        $now = date('Y-m-d H:i:s');
        $wid = (int) $website['id'];
        $host = host_from_url($website['url']);
        $scanId = DB::insert('form_scans', ['tenant_id' => $website['tenant_id'] ?? null, 'website_id' => $wid, 'started_at' => $now, 'engine' => 'http', 'trigger_by' => mb_substr((string) ($opts['trigger'] ?? 'cron'), 0, 20)]);
        $sum = ['scan_id' => $scanId, 'pages' => 0, 'pages_failed' => 0, 'browser_pages' => 0, 'forms_found' => 0, 'new' => 0, 'changed' => 0, 'removed' => 0, 'popups' => 0, 'ignored' => 0, 'engine' => 'http', 'complete' => false, 'notes' => []];

        try {
            // 1) Pages to inspect (discover them first when the page list is missing)
            if (!(int) DB::value("SELECT COUNT(*) FROM website_pages WHERE website_id = ? AND is_active = 1", [$wid])) {
                try { Monitor::discoverPages($website); } catch (Throwable $e) { $sum['notes'][] = 'Page discovery failed: ' . $e->getMessage(); }
            }
            $pages = self::pickPages($website, $maxPages);
            if (!$pages) {
                $home = rtrim($website['url'], '/') . '/';
                $pages = [['id' => null, 'url' => $home, 'path' => '/', 'title' => null, 'clean_url' => null, 'score' => 0]];
            }

            // 2) HTTP pass – fetch all pages concurrently, parse every <form>, hidden modal forms, same-origin iframes and embeds
            $candidates = [];
            $fetched = self::fetchMany(array_column($pages, 'url'), max(3, (int) setting('check_timeout', 15)), 8);
            $pageByUrl = [];
            foreach ($pages as $p) $pageByUrl[$p['url']] = $p;
            $okPages = [];
            foreach ($fetched as $url => $res) {
                $sum['pages']++;
                $page = $pageByUrl[$url];
                if ($res['status'] < 200 || $res['status'] >= 400 || $res['body'] === '' || !preg_match('~html~i', $res['type'])) { $sum['pages_failed']++; continue; }
                $okPages[$url] = $res;
                // page title + clean URL (".php" served without extension?)
                if (preg_match('~<title[^>]*>(.*?)</title>~is', $res['body'], $m)) {
                    $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
                    if ($title !== '' && $page['id']) DB::update('website_pages', ['title' => mb_substr($title, 0, 150)], 'id = ? AND (title IS NULL OR title = "")', [$page['id']]);
                    $pageByUrl[$url]['title'] = $pageByUrl[$url]['title'] ?: $title;
                }
                if ($page['id'] && $page['clean_url'] === null) self::probeCleanUrl((int) $page['id'], $url, $host);
                foreach (self::inspectHtml($res['body'], $res['url'] ?: $url, $url, $pageByUrl[$url]['title'] ?? null, $host, $website) as $c) {
                    if ($c['skip']) { $sum['ignored']++; continue; }
                    $candidates[] = $c;
                }
                if (microtime(true) > $deadline - 5) { $sum['notes'][] = 'Time budget reached during the HTTP pass'; break; }
            }

            // 3) Browser pass – JavaScript-rendered forms + popup triggers on the most relevant pages
            $browserOk = false;
            if ($engine === 'auto' && $browserPages > 0 && microtime(true) < $deadline - 20 && Browser::available()) {
                $b = Browser::shared();
                if ($b) {
                    $sum['engine'] = 'browser';
                    DB::update('form_scans', ['engine' => 'browser'], 'id = ?', [$scanId]);
                    $order = array_values(array_filter($pages, fn($p) => isset($okPages[$p['url']]) || !isset($fetched[$p['url']])));
                    usort($order, fn($a, $b2) => $b2['score'] <=> $a['score']);
                    foreach (array_slice($order, 0, $browserPages) as $p) {
                        if (microtime(true) > $deadline - 12) { $sum['notes'][] = 'Time budget reached during the browser pass'; break; }
                        try {
                            $found = self::browserInspect($b, $p['url'], $pageByUrl[$p['url']]['title'] ?? null, $host, $website, min(30, (int) ($deadline - microtime(true) - 5)));
                            $sum['browser_pages']++;
                            foreach ($found as $c) { if ($c['skip']) { $sum['ignored']++; continue; } if ($c['kind'] === 'popup' && $c['popup_trigger']) $sum['popups']++; $candidates[] = $c; }
                            $browserOk = true;
                        } catch (Throwable $e) {
                            $sum['notes'][] = 'Browser: ' . truncate($e->getMessage(), 120);
                            app_log('warning', 'Form discovery browser pass failed for ' . $p['url'] . ': ' . $e->getMessage());
                            if (!$b->alive()) break;
                        }
                    }
                } else {
                    $sum['notes'][] = 'Browser engine unavailable – HTTP inspection only (' . (Browser::lastError() ?: 'Chrome not found') . ')';
                }
            } elseif ($engine === 'auto' && $browserPages > 0) {
                $sum['notes'][] = 'Browser engine not available on this server – popup forms are detected from hidden modal markup only';
            }

            // 4) Merge into the inventory (dedupe by fingerprint, link pages, register new, update changed)
            $merged = self::merge($candidates);
            $sum['forms_found'] = count($merged);
            $seenIds = [];
            $limited = 0;
            foreach ($merged as $c) {
                $r = self::register($website, $c, $now);
                if (!empty($r['limited'])) { $limited++; continue; }
                $seenIds[] = $r['id'];
                if ($r['new']) $sum['new']++;
                elseif ($r['changed']) $sum['changed']++;
            }
            if ($limited) { $sum['limited'] = $limited; $sum['notes'][] = $limited . ' form(s) not added – plan limit reached (upgrade to monitor them)'; }

            // 5) Removed forms: only after a complete scan (most pages fetched) – two consecutive misses → REMOVED (history kept)
            $sum['complete'] = $sum['pages'] > 0 && $sum['pages_failed'] <= max(1, (int) floor($sum['pages'] * 0.4)) && empty(array_filter($sum['notes'], fn($n) => str_contains($n, 'Time budget')));
            if ($sum['complete']) {
                $missing = DB::fetchAll("SELECT id, name, status, missed_scans FROM forms WHERE website_id = ? AND source = 'auto' AND discovery_status = 'active' AND (last_discovered_at IS NULL OR last_discovered_at < ?)" . ($seenIds ? " AND id NOT IN (" . implode(',', array_map('intval', $seenIds)) . ")" : ''), [$wid, $now]);
                foreach ($missing as $f) {
                    $missed = (int) $f['missed_scans'] + 1;
                    if ($missed >= 2) {
                        DB::update('forms', ['discovery_status' => 'removed', 'status' => 'removed', 'removed_at' => $now, 'missed_scans' => $missed, 'auto_test' => 0], 'id = ?', [$f['id']]);
                        self::recordStatus((int) $f['id'], $f['status'], 'removed', 'Form no longer found on the website (2 consecutive scans)');
                        ActivityLog::add('form_removed', 'Form removed from website (not found in 2 scans): ' . $f['name'] . ' – ' . $website['name'], ['client_id' => $website['client_id'], 'website_id' => $wid, 'form_id' => $f['id']]);
                        $sum['removed']++;
                    } else {
                        DB::update('forms', ['missed_scans' => $missed], 'id = ?', [$f['id']]);
                    }
                }
            }
        } catch (Throwable $e) {
            $sum['notes'][] = 'Scan error: ' . $e->getMessage();
            app_log('error', 'Form discovery failed for website #' . $wid . ': ' . $e->getMessage());
        }

        // 6) Counters + scan record
        $counts = self::refreshCounts($wid);
        DB::update('websites', ['last_form_scan_at' => $now, 'form_scan_requested' => 0], 'id = ?', [$wid]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $summary = sprintf('%d page(s) scanned%s · %d form(s) found · %d new · %d changed · %d removed%s · engine: %s%s',
            $sum['pages'], $sum['pages_failed'] ? ' (' . $sum['pages_failed'] . ' failed)' : '', $sum['forms_found'], $sum['new'], $sum['changed'], $sum['removed'],
            $sum['popups'] ? ' · ' . $sum['popups'] . ' popup(s) opened' : '', $sum['engine'], $sum['notes'] ? ' · ' . implode('; ', array_slice($sum['notes'], 0, 3)) : '');
        DB::update('form_scans', ['finished_at' => date('Y-m-d H:i:s'), 'pages_scanned' => $sum['pages'], 'pages_failed' => $sum['pages_failed'], 'browser_pages' => $sum['browser_pages'], 'forms_found' => $sum['forms_found'],
            'forms_new' => $sum['new'], 'forms_changed' => $sum['changed'], 'forms_removed' => $sum['removed'], 'popups_found' => $sum['popups'], 'duration_ms' => $ms,
            'status' => $sum['complete'] ? 'done' : ($sum['pages'] ? 'partial' : 'failed'), 'summary' => mb_substr($summary, 0, 500)], 'id = ?', [$scanId]);
        ActivityLog::add('forms_discovered', 'Form discovery for ' . $website['name'] . ': ' . $summary, ['client_id' => $website['client_id'], 'website_id' => $wid]);
        data_changed();
        return $sum + ['summary' => $summary, 'duration_ms' => $ms, 'counts' => $counts];
    }

    /** Pages ordered by how likely they carry lead forms (home + contact-like pages first). */
    private static function pickPages(array $website, int $max): array
    {
        $rows = DB::fetchAll("SELECT id, url, path, title, clean_url, source, priority FROM website_pages WHERE website_id = ? AND is_active = 1 AND ignored = 0 ORDER BY priority, id LIMIT 1500", [$website['id']]);
        foreach ($rows as &$r) {
            $s = 0;
            $p = strtolower($r['path'] . ' ' . ($r['title'] ?? ''));
            if ($r['source'] === 'home' || $r['path'] === '/' || $r['path'] === '') $s += 100;
            if (preg_match('~contact|enquir|inquir|quote|career|job|apply|admission|book|appoint|reservation|get-in-touch|reach|support|demo|consult|subscribe|newsletter|register|signup|sign-up|feedback|request|landing|lp-~', $p)) $s += 60;
            if (preg_match('~about|service|product|project|pricing|solution|home~', $p)) $s += 20;
            if (preg_match('~/blog/|/news/|/\d{4}/\d{2}/|/tag/|/category/|/author/|/page/\d+~', $p)) $s -= 25;
            $s -= min(30, substr_count($r['path'], '/') * 3);
            $r['score'] = $s;
        }
        unset($r);
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        return array_slice($rows, 0, $max);
    }

    /* =====================================================================
     * HTTP fetching
     * ===================================================================== */

    /** Concurrent GET of many URLs → [url => [status, body, url(final), type]] */
    public static function fetchMany(array $urls, int $timeout, int $concurrency = 8): array
    {
        $out = [];
        $urls = array_values(array_unique($urls));
        $mh = curl_multi_init();
        $handles = [];
        $queue = $urls;
        $add = function () use (&$queue, &$handles, $mh, $timeout) {
            $u = array_shift($queue);
            if ($u === null) return;
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en-US,en;q=0.9', 'X-CRM-Form-Scan: 1'],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 OutlineMediaCRM-FormScan/2.0',
                CURLOPT_BUFFERSIZE => 65536, CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $dn) => ($dn > 2500000) ? 1 : 0]);
            curl_multi_add_handle($mh, $ch);
            $handles[spl_object_id($ch)] = [$ch, $u];
        };
        for ($i = 0; $i < $concurrency; $i++) $add();
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.5);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [$h, $u] = $handles[spl_object_id($ch)];
                $body = (string) curl_multi_getcontent($ch);
                $out[$u] = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body, 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'error' => $info['result'] ? curl_error($ch) : null];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[spl_object_id($ch)]);
                $add();
                $active = 1;
            }
        } while ($active && $status === CURLM_OK);
        curl_multi_close($mh);
        foreach ($urls as $u) if (!isset($out[$u])) $out[$u] = ['status' => 0, 'body' => '', 'url' => $u, 'type' => '', 'error' => 'not fetched'];
        return $out;
    }

    /** ".php" pages: does the site also serve the clean URL (URL rewriting)? Stores website_pages.clean_url ('' = no). */
    public static function probeCleanUrl(int $pageId, string $url, string $host): void
    {
        $clean = '';
        $p = parse_url($url);
        $path = $p['path'] ?? '/';
        if (preg_match('~\.php$~i', $path)) {
            $candidate = preg_replace('~/index\.php$~i', '/', $path);
            if ($candidate === $path) $candidate = preg_replace('~\.php$~i', '', $path);
            $cu = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? $host) . (isset($p['port']) ? ':' . $p['port'] : '') . $candidate . (isset($p['query']) ? '?' . $p['query'] : '');
            $ch = curl_init($cu);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'Mozilla/5.0 OutlineMediaCRM-FormScan/2.0']);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if ($code === 200 && !preg_match('~\.php(\?|$)~i', $final) && host_from_url($final) === $host) $clean = $cu;
        }
        DB::update('website_pages', ['clean_url' => $clean], 'id = ?', [$pageId]);
    }

    /* =====================================================================
     * HTML inspection (HTTP pass)
     * ===================================================================== */

    /** All form candidates in one page's HTML (forms, hidden modal forms, same-origin iframes, third-party embeds). */
    public static function inspectHtml(string $html, string $pageUrl, string $requestedUrl, ?string $pageTitle, string $host, array $website, bool $fromIframe = false, ?string $iframeSrc = null): array
    {
        $out = [];
        $doc = self::dom($html);
        if (!$doc) return $out;
        $x = new DOMXPath($doc);
        $forms = $x->query('//form');
        $idx = 0;
        $seenActionsOnPage = [];
        foreach ($forms as $f) {
            $idx++;
            /** @var DOMElement $f */
            $parsed = FormTester::parseForm($f, $x, $pageUrl, $html);
            $c = self::baseCandidate($pageUrl, $requestedUrl, $pageTitle, 'http');
            $c['dom_id'] = $f->getAttribute('id');
            $c['name_attr'] = $f->getAttribute('name');
            $c['classes'] = trim($f->getAttribute('class'));
            $c['action'] = $parsed['action'];
            $c['method'] = $parsed['method'];
            $c['plugin'] = $parsed['plugin'];
            $c['captcha'] = $parsed['captcha_info'];
            $c['fields'] = self::fieldList($f, $x, $parsed['fields']);
            $c['html'] = mb_substr($parsed['html'], 0, 4000);
            $c['is_search'] = $parsed['is_search'] || strtolower($f->getAttribute('role')) === 'search';
            $c['is_login'] = $parsed['is_login'];
            $c['plugin_form_id'] = self::pluginFormId($parsed['fields'], $c['plugin']);
            [$c['submit_label'], $c['submit_selector']] = self::submitInfo($f, $x);
            $c['selector'] = self::selectorFor($c, $idx, $x);
            // hidden / modal container → popup form
            [$c['kind'], $c['popup_selector'], $c['popup_trigger']] = self::modalContext($f, $x);
            $c['title'] = self::titleFor($f, $x, $c);
            $c['context'] = self::contextText($f);
            $c['in_iframe'] = $fromIframe ? 1 : 0;
            $c['iframe_src'] = $iframeSrc;
            if ($fromIframe && $iframeSrc) {
                // the form lives in the iframe document: tests load that document directly; the parent page is recorded as "seen on"
                $c['seen_on'] = $requestedUrl;
                $c['page_url'] = $iframeSrc;
                $c['final_url'] = $pageUrl;
            }
            $c['ajax'] = self::isAjax($c);
            $c['skip'] = self::skipReason($c);
            $c = self::describe($c);
            $seenActionsOnPage[] = $c['action'];
            $out[] = $c;
        }
        if ($fromIframe) return $out;

        // Same-origin iframes → inspect their document; third-party form providers → inventory entry
        foreach ($x->query('//iframe[@src]') as $fr) {
            $src = trim($fr->getAttribute('src'));
            if ($src === '' || str_starts_with($src, 'about:') || str_starts_with($src, 'javascript:')) continue;
            $abs = FormTester::absolute($src, $pageUrl);
            $tp = self::thirdParty($abs);
            if ($tp) {
                $out[] = self::embedCandidate($pageUrl, $requestedUrl, $pageTitle, $tp, $abs, 'iframe[src*="' . self::attrFragment($abs) . '"]');
                continue;
            }
            if (host_from_url($abs) !== $host) continue;
            if (count($out) > 40) break;
            $res = self::fetchMany([$abs], 12, 1)[$abs] ?? null;
            if ($res && $res['status'] >= 200 && $res['status'] < 400 && preg_match('~html~i', $res['type'])) {
                foreach (self::inspectHtml($res['body'], $res['url'] ?: $abs, $requestedUrl, $pageTitle, $host, $website, true, $abs) as $c) $out[] = $c;
            }
        }
        // Script / div embeds of third-party form builders (JotForm, HubSpot, Typeform, Tally, Calendly…)
        $embedHosts = [];
        foreach ($x->query('//script[@src]') as $s) { $tp = self::thirdParty($s->getAttribute('src')); if ($tp && !in_array($tp, $embedHosts, true) && preg_match('~jotform|hsforms|hubspot|typeform|tally|calendly|paperform~i', $s->getAttribute('src'))) { $embedHosts[] = $tp; $out[] = self::embedCandidate($pageUrl, $requestedUrl, $pageTitle, $tp, $s->getAttribute('src'), 'script[src*="' . self::attrFragment($s->getAttribute('src')) . '"]'); } }
        foreach ($x->query('//*[@data-tf-widget or @data-tf-live or contains(@class,"hs-form-frame") or contains(@class,"calendly-inline-widget") or @data-tally-src or contains(@class,"jotform-form")]') as $el) {
            $tp = $el->hasAttribute('data-tf-widget') || $el->hasAttribute('data-tf-live') ? 'Typeform' : ($el->hasAttribute('data-tally-src') ? 'Tally' : (str_contains($el->getAttribute('class'), 'calendly') ? 'Calendly' : (str_contains($el->getAttribute('class'), 'hs-form') ? 'HubSpot Forms' : 'JotForm')));
            if (in_array($tp, $embedHosts, true)) continue;
            $embedHosts[] = $tp;
            $out[] = self::embedCandidate($pageUrl, $requestedUrl, $pageTitle, $tp, $el->getAttribute('data-tf-widget') ?: ($el->getAttribute('data-url') ?: ($el->getAttribute('data-tally-src') ?: $tp)), $el->getAttribute('id') ? '#' . $el->getAttribute('id') : '.' . strtok($el->getAttribute('class'), ' '));
        }
        return $out;
    }

    private static function baseCandidate(string $pageUrl, string $requestedUrl, ?string $pageTitle, string $engine): array
    {
        return ['page_url' => $requestedUrl, 'final_url' => $pageUrl, 'page_title' => $pageTitle, 'engine' => $engine, 'dom_id' => '', 'name_attr' => '', 'classes' => '', 'action' => '', 'method' => 'POST', 'plugin' => null,
            'plugin_form_id' => null, 'captcha' => ['type' => null, 'in_form' => false, 'label' => '', 'detail' => ''], 'fields' => [], 'html' => '', 'is_search' => false, 'is_login' => false, 'submit_label' => null, 'submit_selector' => null,
            'selector' => null, 'kind' => 'normal', 'popup_selector' => null, 'popup_trigger' => null, 'title' => null, 'context' => '', 'in_iframe' => 0, 'iframe_src' => null, 'ajax' => 0, 'skip' => null,
            'technology' => null, 'form_type' => 'Other', 'name' => null, 'third_party' => null, 'testable' => true, 'fingerprint' => null, 'pages' => [], 'seen_on' => null];
    }

    private static function embedCandidate(string $pageUrl, string $requestedUrl, ?string $pageTitle, string $provider, string $src, string $selector): array
    {
        $c = self::baseCandidate($pageUrl, $requestedUrl, $pageTitle, 'http');
        $c['third_party'] = $provider;
        $c['technology'] = $provider;
        $c['iframe_src'] = mb_substr($src, 0, 500);
        $c['in_iframe'] = 1;
        $c['selector'] = $selector;
        $c['action'] = $src;
        $c['title'] = $provider . ' form';
        $c['form_type'] = 'Contact';
        $c['name'] = $provider . ' form';
        $c['testable'] = false;
        $c['fingerprint'] = sha1('embed|' . preg_replace('~[?#].*$~', '', $src));
        return $c;
    }

    private static function thirdParty(string $url): ?string
    {
        $l = strtolower($url);
        foreach (self::THIRD_PARTY as $needle => $label) if (str_contains($l, $needle)) return $label;
        return null;
    }

    private static function attrFragment(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST) ?: $url;
        return str_replace('"', '', mb_substr($h, 0, 60));
    }

    private static function dom(string $html): ?DOMDocument
    {
        if (trim($html) === '') return null;
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        return $ok ? $doc : null;
    }

    /** Human readable field list [{name,type,label,required,hidden}] */
    private static function fieldList(DOMElement $f, DOMXPath $x, array $parsedFields): array
    {
        $labels = [];
        foreach ($x->query('.//input[@name]|.//textarea[@name]|.//select[@name]', $f) as $n) {
            $name = $n->getAttribute('name');
            if (isset($labels[$name])) continue;
            $label = '';
            $id = $n->getAttribute('id');
            if ($id !== '') { $l = $x->query('//label[@for="' . $id . '"]')->item(0); if ($l) $label = $l->textContent; }
            if ($label === '' && $n->parentNode instanceof DOMElement && strtolower($n->parentNode->nodeName) === 'label') $label = $n->parentNode->textContent;
            if ($label === '') $label = $n->getAttribute('placeholder') ?: $n->getAttribute('aria-label') ?: $n->getAttribute('title');
            $labels[$name] = trim(preg_replace('~\s+~', ' ', (string) $label));
        }
        $out = [];
        $seen = [];
        foreach ($parsedFields as $name => $fd) {
            $type = $fd['type'] ?? 'text';
            if ($type === 'submit') continue;
            $seen[$name] = true;
            $out[] = ['name' => mb_substr($name, 0, 80), 'type' => $type, 'label' => mb_substr($labels[$name] ?? '', 0, 80) ?: self::humanize($name), 'required' => !empty($fd['required']), 'hidden' => $type === 'hidden' || !empty($fd['honeypot'])];
        }
        // file inputs are not part of the test payload (parseForm skips them) but they matter for the inventory / type (resume upload = career form)
        foreach ($x->query('.//input[@type="file"][@name]', $f) as $n) {
            $name = $n->getAttribute('name');
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $out[] = ['name' => mb_substr($name, 0, 80), 'type' => 'file', 'label' => mb_substr($labels[$name] ?? '', 0, 80) ?: self::humanize($name), 'required' => $n->hasAttribute('required'), 'hidden' => false];
        }
        return $out;
    }

    public static function humanize(string $name): string
    {
        $n = preg_replace('~^[a-z0-9_]+\[|\]$~i', '', $name);
        $n = preg_replace('~[\[\]]+~', ' ', $n);
        $n = preg_replace('~[-_]+~', ' ', $n);
        $n = preg_replace('~(?<=[a-z])(?=[A-Z])~', ' ', $n);
        $n = trim(preg_replace('~\byour\b|\bfield\b|\binput\b~i', '', $n));
        return $n === '' ? $name : ucwords(strtolower($n));
    }

    private static function pluginFormId(array $fields, ?string $plugin): ?string
    {
        foreach (['_wpcf7', 'wpforms[id]', 'form_id', 'gform_submit', 'frm_action', 'forminator_form_id', 'nf_form_id', '_wpnonce_form'] as $k) {
            if (isset($fields[$k]) && ($fields[$k]['value'] ?? '') !== '') return $k . '=' . $fields[$k]['value'];
        }
        return null;
    }

    private static function submitInfo(DOMElement $f, DOMXPath $x): array
    {
        $btn = $x->query('.//button[not(@type) or @type="submit"]|.//input[@type="submit"]|.//input[@type="image"]', $f)->item(0);
        if (!$btn) return [null, null];
        /** @var DOMElement $btn */
        $label = trim(preg_replace('~\s+~', ' ', $btn->getAttribute('value') ?: $btn->textContent ?: $btn->getAttribute('aria-label') ?: $btn->getAttribute('title')));
        $sel = $btn->getAttribute('id') ? '#' . $btn->getAttribute('id') : ($btn->getAttribute('name') ? strtolower($btn->nodeName) . '[name="' . $btn->getAttribute('name') . '"]' : null);
        return [$label !== '' ? mb_substr($label, 0, 120) : null, $sel];
    }

    private static function selectorFor(array $c, int $idx, DOMXPath $x): string
    {
        if ($c['dom_id'] !== '' && !preg_match('~^\d|[^A-Za-z0-9_\-:.]~', $c['dom_id'])) {
            // WordPress plugin ids embed the post id (wpcf7-f12-p34-o1): use the plugin class + form id instead so the selector is stable across pages
            if ($c['plugin'] === 'cf7') return 'form.wpcf7-form' . ($c['plugin_form_id'] ? '' : '') . ($x->query('//form[contains(@class,"wpcf7-form")]')->length > 1 ? ':nth-of-type(' . $idx . ')' : '');
            return '#' . $c['dom_id'];
        }
        if ($c['name_attr'] !== '') return 'form[name="' . str_replace('"', '', $c['name_attr']) . '"]';
        if ($c['plugin'] === 'wpforms') return 'form.wpforms-form';
        if ($c['plugin'] === 'elementor') return 'form.elementor-form';
        if ($c['plugin'] === 'gravity') return 'form[id^="gform_"]';
        $cls = array_values(array_filter(preg_split('~\s+~', $c['classes']), fn($k) => $k !== '' && !preg_match('~^(form|wp-|js-|is-|has-)|\d~', $k)));
        if ($cls && $x->query('//form[contains(concat(" ",normalize-space(@class)," ")," ' . $cls[0] . ' ")]')->length === 1) return 'form.' . $cls[0];
        $act = $c['action'] ? parse_url($c['action'], PHP_URL_PATH) : '';
        if ($act && $act !== '/' && $x->query('//form[contains(@action,"' . basename($act) . '")]')->length === 1) return 'form[action*="' . basename($act) . '"]';
        return 'form:nth-of-type(' . $idx . ')';
    }

    /** Is the form inside a hidden modal / popup container? → [kind, popup_selector, popup_trigger] */
    private static function modalContext(DOMElement $f, DOMXPath $x): array
    {
        // Walk up and keep the OUTERMOST modal / hidden container (".modal" rather than ".modal-content"), preferring one with an id
        $best = null;
        $node = $f->parentNode;
        for ($i = 0; $i < 12 && $node instanceof DOMElement; $i++, $node = $node->parentNode) {
            $id = $node->getAttribute('id');
            $cls = $node->getAttribute('class');
            $style = strtolower($node->getAttribute('style'));
            $sig = $id . ' ' . $cls;
            $hidden = str_contains($style, 'display:none') || str_contains($style, 'display: none') || $node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true';
            $modal = preg_match(self::MODAL_RE, $sig) || $node->getAttribute('role') === 'dialog' || $node->getAttribute('data-elementor-type') === 'popup';
            if (!$modal && !$hidden) continue;
            if ($hidden && !$modal && !preg_match('~form|enquir|quote|contact|popup|modal~i', $sig)) continue; // hidden for other reasons (tabs, accordions)
            if ($best === null || $id !== '' || $best->getAttribute('id') === '') $best = $node;
        }
        if ($best) {
            $node = $best;
            $id = $node->getAttribute('id');
            $cls = $node->getAttribute('class');
            $sel = $id !== '' ? '#' . $id : ($node->getAttribute('data-elementor-id') ? '[data-elementor-id="' . $node->getAttribute('data-elementor-id') . '"]' : self::firstClassSelector($node));
            $trigger = null;
            if ($id !== '') {
                $q = '//*[@href="#' . $id . '" or @data-bs-target="#' . $id . '" or @data-target="#' . $id . '" or @data-modal="' . $id . '" or @data-popup="' . $id . '" or @data-mfp-src="#' . $id . '" or @data-toggle-target="#' . $id . '" or @data-open="' . $id . '" or @aria-controls="' . $id . '"]';
                $t = $x->query($q)->item(0);
                if ($t instanceof DOMElement) $trigger = self::elementSelector($t, $x);
            }
            if ($trigger === null && $node->getAttribute('data-elementor-type') === 'popup') {
                $t = $x->query('//a[contains(@href,"#elementor-action") and contains(@href,"popup")]')->item(0);
                if ($t instanceof DOMElement) $trigger = self::elementSelector($t, $x);
            }
            if ($trigger === null && preg_match('~pum-|popmake~', $cls) && preg_match('~popmake-(\d+)~', $cls, $m)) $trigger = '.popmake-' . $m[1];
            return ['popup', $sel, $trigger];
        }
        return ['normal', null, null];
    }

    private static function firstClassSelector(DOMElement $n): string
    {
        $cls = array_values(array_filter(preg_split('~\s+~', $n->getAttribute('class')), fn($k) => $k !== '' && !preg_match('~^(show|active|fade|in|open|is-|has-)$~', $k)));
        return $cls ? strtolower($n->nodeName) . '.' . $cls[0] : strtolower($n->nodeName);
    }

    private static function elementSelector(DOMElement $t, DOMXPath $x): string
    {
        if ($t->getAttribute('id') !== '') return '#' . $t->getAttribute('id');
        foreach (['data-bs-target', 'data-target', 'href', 'data-modal', 'data-popup', 'data-open', 'aria-controls', 'data-mfp-src'] as $a) {
            if ($t->getAttribute($a) !== '') {
                $sel = strtolower($t->nodeName) . '[' . $a . '="' . str_replace('"', '', $t->getAttribute($a)) . '"]';
                if ($x->query('//' . strtolower($t->nodeName) . '[@' . $a . '="' . str_replace('"', '', $t->getAttribute($a)) . '"]')->length === 1) return $sel;
                return $sel;
            }
        }
        return self::firstClassSelector($t);
    }

    private static function titleFor(DOMElement $f, DOMXPath $x, array $c): ?string
    {
        $clean = fn($s) => trim(preg_replace('~\s+~', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5)));
        foreach (['aria-label', 'title', 'data-title', 'data-name', 'data-form-name', 'data-form-title'] as $a) { $v = $clean($f->getAttribute($a)); if ($v !== '' && mb_strlen($v) < 80 && !preg_match('~^(form|contact form|form \d+)$~i', $v)) return $v; }
        $n = $x->query('.//legend|.//h1|.//h2|.//h3|.//h4|.//*[contains(@class,"form-title") or contains(@class,"form-heading") or contains(@class,"title")]', $f)->item(0);
        if ($n) { $v = $clean($n->textContent); if ($v !== '' && mb_strlen($v) < 90) return $v; }
        // inside a popup: the modal title
        $node = $f->parentNode;
        for ($i = 0; $i < 6 && $node instanceof DOMElement; $i++, $node = $node->parentNode) {
            $sig = $node->getAttribute('id') . ' ' . $node->getAttribute('class');
            if (preg_match(self::MODAL_RE, $sig) || $node->getAttribute('role') === 'dialog' || preg_match('~section|container|wrapper|form-|widget|col-|block|box~i', $sig)) {
                $h = $x->query('.//*[contains(@class,"modal-title") or contains(@class,"popup-title")]|.//h1|.//h2|.//h3|.//h4', $node)->item(0);
                if ($h) { $v = $clean($h->textContent); if ($v !== '' && mb_strlen($v) < 90) return $v; }
            }
        }
        // nearest preceding heading in document order
        $h = $x->query('preceding::*[self::h1 or self::h2 or self::h3 or self::h4][1]', $f)->item(0);
        if ($h) { $v = $clean($h->textContent); if ($v !== '' && mb_strlen($v) < 70 && !preg_match('~^(home|welcome|blog|latest|recent|our (services|products|projects|clients|team))~i', $v)) return $v; }
        return null;
    }

    private static function contextText(DOMElement $f): string
    {
        // nearest enclosing section with a reasonable amount of text – never the whole page (body) which would mix in unrelated forms
        $node = $f->parentNode;
        for ($i = 0; $i < 4 && $node instanceof DOMElement; $i++, $node = $node->parentNode) {
            if (in_array(strtolower($node->nodeName), ['body', 'html', 'main'], true)) break;
            $t = trim(preg_replace('~\s+~', ' ', $node->textContent));
            if (mb_strlen($t) > 40 && mb_strlen($t) < 1500) return mb_substr($t, 0, 400);
        }
        return '';
    }

    private static function isAjax(array $c): int
    {
        if ($c['plugin'] && in_array($c['plugin'], ['cf7', 'wpforms', 'elementor', 'gravity', 'fluent', 'ninja', 'formidable', 'forminator', 'hubspot'], true)) return 1;
        if (preg_match('~ajax|js-form|xhr~i', $c['classes'] . ' ' . $c['dom_id'])) return 1;
        if (preg_match('~data-ajax|data-remote|@submit\.prevent|ajaxForm|\$\.ajax|fetch\(~i', $c['html'])) return 1;
        return 0;
    }

    /** Forms that are not lead forms are ignored (search, login, comments, cart, filters, empty forms). */
    private static function skipReason(array $c): ?string
    {
        if ($c['is_search']) return 'search form';
        if ($c['is_login']) return 'login / password form';
        $names = strtolower(implode(' ', array_column($c['fields'], 'name')));
        $sig = strtolower($c['dom_id'] . ' ' . $c['classes'] . ' ' . $c['name_attr'] . ' ' . $c['action']);
        if (preg_match('~commentform|comment-form|\bcomment\b~', $sig) || (str_contains($names, 'comment') && str_contains($names, 'author'))) return 'comment form';
        if (preg_match('~woocommerce-cart|checkout|add-to-cart|cart_|wc-|mini-cart|variations_form~', $sig) || preg_match('~add-to-cart|quantity~', $names)) return 'cart / checkout form';
        if (preg_match('~\bsearch\b|searchform|\bs=~', $sig) || $names === 's') return 'search form';
        if (preg_match('~logout|lang(uage)?-?switch|currency|sort|filter|wpcf7-form-control-wrap$~', $sig) && !preg_match('~email|message~', $names)) return 'utility form';
        $visible = array_filter($c['fields'], fn($f) => !$f['hidden'] && !in_array($f['type'], ['submit', 'button', 'reset'], true));
        if (!$visible) return 'no input fields';
        $onlySelectsGet = strtoupper($c['method']) === 'GET' && !array_filter($visible, fn($f) => in_array($f['type'], ['text', 'email', 'tel', 'textarea', 'number', 'file', 'url'], true));
        if ($onlySelectsGet) return 'filter form';
        return null;
    }

    /** Type, technology and display name for a candidate. */
    private static function describe(array $c): array
    {
        $names = strtolower(implode(' ', array_map(fn($f) => $f['name'] . ' ' . $f['label'], array_filter($c['fields'], fn($f) => empty($f['hidden'])))));
        $types = array_column($c['fields'], 'type');
        $hasEmail = (bool) preg_match('~e-?mail~', $names) || in_array('email', $types, true);
        $hasMsg = in_array('textarea', $types, true) || preg_match('~message|comment|enquir|inquir|query|requirement~', $names);
        $hasFile = in_array('file', $types, true);
        $visibleCount = count(array_filter($c['fields'], fn($f) => empty($f['hidden'])));
        // Stage 1: the form's own signals (title, fields, submit button, ids/classes, action) – Stage 2: surrounding text only as a tie-breaker
        $own = strtolower(($c['title'] ?? '') . ' ' . $c['classes'] . ' ' . $c['dom_id'] . ' ' . $c['name_attr'] . ' ' . ($c['submit_label'] ?? '') . ' ' . $c['action'] . ' ' . $names);
        $classify = function (string $t) use ($hasFile): string {
            if (preg_match('~career|\bjobs?\b|resume|\bcv\b|vacanc|recruit|apply for|job application|upload your~', $t) || ($hasFile && preg_match('~apply|application|position~', $t))) return 'Career';
            if (preg_match('~admission|enrol|application form~', $t)) return 'Admission';
            if (preg_match('~newsletter|subscribe|subscription~', $t)) return 'Newsletter';
            if (preg_match('~quote|quotation|estimate|pricing|price~', $t)) return 'Quote';
            if (preg_match('~book|appointment|reservation|schedule|visit|demo|consult~', $t)) return 'Booking';
            if (preg_match('~enquir|inquir~', $t)) return 'Enquiry';
            if (preg_match('~download|brochure|callback|call back|\blead\b|get started|free trial|register|sign ?up~', $t)) return 'Lead';
            if (preg_match('~contact|message|get in touch|reach|write to us|feedback|support~', $t)) return 'Contact';
            if ($hasFile && preg_match('~apply|position|resume|cv\b~', $t)) return 'Career';
            return 'Other';
        };
        $type = $classify($own);
        if ($type === 'Other') {
            if ($hasEmail && $visibleCount <= 2 && !$hasMsg) $type = 'Newsletter';
            elseif ($hasEmail && $hasMsg) $type = 'Contact';
            elseif ($hasFile) $type = 'Career';
            else $type = $classify(strtolower(mb_substr($c['context'], 0, 200)));
        }
        $c['form_type'] = $type;

        if (!$c['technology']) {
            if ($c['plugin']) $c['technology'] = FormTester::pluginLabel($c['plugin']);
            elseif (preg_match('~admin-ajax\.php|wp-json~i', $c['action'] . ' ' . $c['html'])) $c['technology'] = 'WordPress AJAX';
            elseif (preg_match('~\.php(\?|$)~i', $c['action'])) $c['technology'] = 'Custom PHP';
            elseif (preg_match('~netlify|data-netlify~i', $c['html'])) $c['technology'] = 'Netlify Forms';
            elseif (preg_match('~formspree|getform|formsubmit|basin|web3forms~i', $c['action'])) $c['technology'] = 'Form API (' . parse_url($c['action'], PHP_URL_HOST) . ')';
            elseif ($c['engine'] === 'browser' && $c['action'] === '') $c['technology'] = 'JavaScript form';
            else $c['technology'] = 'HTML / Custom';
        }
        $title = $c['title'];
        if ($title !== null) {
            $title = preg_replace('~\s*[|–-]\s*.{0,40}$~u', '', $title);
            if (mb_strlen($title) < 3 || preg_match('~^(form|submit|send|name|email)$~i', $title)) $title = null;
        }
        $c['name'] = $title ?: ($type === 'Other' ? 'Form' : $type . ' Form');
        if ($c['kind'] === 'popup' && !preg_match('~popup|modal~i', $c['name'])) $c['name'] .= ' (popup)';
        return $c;
    }

    /* =====================================================================
     * Fingerprint / merge / register
     * ===================================================================== */

    public static function fingerprint(array $c, int $websiteId): string
    {
        if ($c['fingerprint']) return $c['fingerprint'];
        $stable = $c['plugin_form_id'] ?: '';
        if ($stable === '' && $c['dom_id'] !== '' && !preg_match('~wpcf7-f\d+-p\d+|-p\d+-o\d+~', $c['dom_id'])) $stable = 'id:' . $c['dom_id'];
        if ($stable === '' && $c['name_attr'] !== '') $stable = 'name:' . $c['name_attr'];
        $actionPath = '';
        if ($c['action'] !== '') {
            $ap = parse_url($c['action']);
            $pp = parse_url($c['page_url']);
            $actionPath = ($ap['path'] ?? '');
            if (($ap['host'] ?? '') && ($ap['host'] ?? '') !== ($pp['host'] ?? '')) $actionPath = ($ap['host']) . $actionPath;
            if ($actionPath === ($pp['path'] ?? '') || $actionPath === '' || preg_match('~admin-ajax\.php$~', $actionPath)) $actionPath = '';
        }
        $names = [];
        foreach ($c['fields'] as $f) { if ($f['hidden'] || preg_match(self::NONCE_RE, $f['name'])) continue; $names[] = strtolower(preg_replace('~\d+~', '#', $f['name'])); }
        sort($names);
        return sha1($websiteId . '|' . $stable . '|' . $actionPath . '|' . implode(',', $names) . '|' . ($c['in_iframe'] ? 'iframe' : ''));
    }

    /** Same form on many pages → one candidate carrying the list of pages (best page kept as primary). */
    private static function merge(array $candidates): array
    {
        $byFp = [];
        foreach ($candidates as $c) {
            $fp = $c['fingerprint'] ?: self::fingerprint($c, 0);
            $c['fingerprint'] = $fp;
            $seenOn = $c['seen_on'] ?? $c['page_url'];
            if (!isset($byFp[$fp])) {
                $c['pages'] = [['url' => $seenOn, 'title' => $c['page_title']]];
                $byFp[$fp] = $c;
                continue;
            }
            $cur = &$byFp[$fp];
            $cur['pages'][] = ['url' => $seenOn, 'title' => $c['page_title']];
            // prefer: browser-discovered details, popup trigger knowledge, better titles, contact-like pages as primary
            if ($c['engine'] === 'browser' && $cur['engine'] !== 'browser') { $pages = $cur['pages']; $cur = $c; $cur['pages'] = $pages; }
            elseif ($c['kind'] === 'popup' && $c['popup_trigger'] && !$cur['popup_trigger']) { $cur['kind'] = 'popup'; $cur['popup_trigger'] = $c['popup_trigger']; $cur['popup_selector'] = $c['popup_selector']; }
            if (self::pageScore($c['page_url']) > self::pageScore($cur['page_url'])) { $cur['page_url'] = $c['page_url']; $cur['page_title'] = $c['page_title']; $cur['final_url'] = $c['final_url']; }
            if (($cur['title'] === null || preg_match('~^(form|contact form)$~i', (string) $cur['title'])) && $c['title']) { $cur['title'] = $c['title']; $cur['name'] = $c['name']; }
            unset($cur);
        }
        return array_values($byFp);
    }

    private static function pageScore(string $url): int
    {
        $p = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('~contact|enquir|inquir|quote|career|admission|book|get-in-touch|reach~', $p)) return 3;
        if ($p === '/' || $p === '') return 2;
        return 1 - min(1, substr_count($p, '/') > 2 ? 1 : 0);
    }

    /** Insert or update the `forms` record for a merged candidate. */
    private static function register(array $website, array $c, string $now): array
    {
        $wid = (int) $website['id'];
        $fp = $c['fingerprint'];
        $pageRow = DB::fetch("SELECT id, title, clean_url FROM website_pages WHERE website_id = ? AND url = ? LIMIT 1", [$wid, $c['page_url']]);
        $fieldsJson = json_encode(array_slice($c['fields'], 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $captcha = $c['captcha']['type'] ?? null;
        $common = [
            'page_id'            => $pageRow['id'] ?? null,
            'page_title'         => mb_substr((string) ($c['page_title'] ?: ($pageRow['title'] ?? '') ?: Monitor::pathLabel((string) parse_url($c['page_url'], PHP_URL_PATH))), 0, 190),
            'fingerprint'        => $fp,
            'form_title'         => $c['title'] ? mb_substr($c['title'], 0, 190) : null,
            'form_dom_id'        => $c['dom_id'] !== '' ? mb_substr($c['dom_id'], 0, 120) : null,
            'technology'         => mb_substr((string) $c['technology'], 0, 40),
            'fields_json'        => $fieldsJson,
            'field_count'        => count(array_filter($c['fields'], fn($f) => !$f['hidden'])),
            'submit_label'       => $c['submit_label'] ? mb_substr($c['submit_label'], 0, 120) : null,
            'action_url'         => $c['action'] !== '' ? mb_substr($c['action'], 0, 500) : null,
            'in_iframe'          => $c['in_iframe'] ? 1 : 0,
            'iframe_src'         => $c['iframe_src'] ? mb_substr($c['iframe_src'], 0, 500) : null,
            'captcha_detected'   => $captcha ? mb_substr($captcha, 0, 30) : null,
            'last_discovered_at' => $now,
            'missed_scans'       => 0,
            'discovered_by'      => $c['engine'],
        ];
        $existing = DB::fetch("SELECT * FROM forms WHERE website_id = ? AND fingerprint = ? LIMIT 1", [$wid, $fp]);
        if (!$existing) {
            // A manually configured form for the same page and action/selector must not be duplicated
            $existing = DB::fetch("SELECT * FROM forms WHERE website_id = ? AND source = 'manual' AND fingerprint IS NULL AND page_url = ? AND (form_selector = ? OR (form_url IS NOT NULL AND form_url = ?) OR (form_url IS NULL AND ? = '')) LIMIT 1",
                [$wid, $c['page_url'], (string) $c['selector'], (string) $c['action'], $c['action'] === $c['page_url'] ? '' : (string) $c['action']]);
        }
        $changed = false;
        if ($existing) {
            $upd = $common;
            if ($captcha) $upd['captcha_seen_count'] = (int) $existing['captcha_seen_count'] + 1;
            if ($existing['source'] === 'auto') {
                // keep the automatically maintained configuration in sync with the live website
                $cfg = self::configFor($c, $website);
                foreach (['form_selector', 'popup_selector', 'popup_trigger', 'submit_selector', 'form_kind', 'form_url', 'method', 'ajax', 'form_type'] as $k) {
                    if (($existing[$k] ?? null) != ($cfg[$k] ?? null)) { $changed = true; $upd[$k] = $cfg[$k]; }
                }
                if ($existing['technology'] !== $common['technology'] && $existing['technology'] !== null) $changed = true;
                if ($existing['page_url'] !== $c['page_url'] && self::pageScore($c['page_url']) > self::pageScore($existing['page_url'])) { $upd['page_url'] = $c['page_url']; $changed = true; }
            }
            if ($existing['discovery_status'] === 'removed') {
                $upd['discovery_status'] = 'active';
                $upd['removed_at'] = null;
                $upd['status'] = 'not_tested';
                $upd['auto_test'] = 1;
                self::recordStatus((int) $existing['id'], $existing['status'], 'not_tested', 'Form found on the website again');
                ActivityLog::add('form_discovered', 'Form re-discovered on ' . $website['name'] . ': ' . $existing['name'], ['client_id' => $website['client_id'], 'website_id' => $wid, 'form_id' => $existing['id']]);
                $changed = true;
            }
            if ($existing['first_discovered_at'] === null) $upd['first_discovered_at'] = $now;
            DB::update('forms', $upd, 'id = ?', [$existing['id']]);
            self::linkPages((int) $existing['id'], $wid, $c['pages'], $now);
            if ($changed) ActivityLog::add('form_changed', 'Form configuration updated from the website: ' . $existing['name'] . ' (' . $website['name'] . ')', ['client_id' => $website['client_id'], 'website_id' => $wid, 'form_id' => $existing['id']]);
            return ['id' => (int) $existing['id'], 'new' => false, 'changed' => $changed];
        }
        // plan limit: never register more forms than the workspace's plan allows (the scan reports it)
        $tid = (int) ($website['tenant_id'] ?? 0);
        if ($tid) { $can = Tenant::canAdd('forms', 1, $tid); if (!$can['ok']) return ['id' => 0, 'new' => false, 'changed' => false, 'limited' => true, 'message' => $can['message']]; }
        $cfg = self::configFor($c, $website);
        $name = self::uniqueName($wid, $c);
        $data = $common + $cfg + [
            'tenant_id'   => $website['tenant_id'] ?? null,
            'website_id'  => $wid,
            'source'      => 'auto',
            'name'        => $name,
            'page_url'    => mb_substr($c['page_url'], 0, 255),
            'engine'      => 'auto',
            'auto_test'   => $c['testable'] ? 1 : 0,
            'status'      => 'not_tested',
            'notes'       => $c['testable'] ? null : 'Third-party embedded form (' . $c['technology'] . ') – the page is monitored; automated submission is not possible for embedded providers.',
            'first_discovered_at' => $now,
            'captcha_seen_count'  => $captcha ? 1 : 0,
        ];
        $id = DB::insert('forms', $data);
        if ($tid) Tenant::usage($tid, true);
        self::recordStatus($id, null, 'not_tested', 'Discovered automatically (' . $c['engine'] . ' engine)');
        self::linkPages($id, $wid, $c['pages'], $now);
        ActivityLog::add('form_discovered', 'Form discovered on ' . $website['name'] . ': ' . $name . ' – ' . $c['technology'] . ($c['kind'] === 'popup' ? ' (popup)' : '') . ($captcha ? ' · CAPTCHA: ' . form_captcha_label($captcha) : ''), ['client_id' => $website['client_id'], 'website_id' => $wid, 'form_id' => $id]);
        return ['id' => $id, 'new' => true, 'changed' => false];
    }

    /** Test configuration derived from the discovered form (what the admin used to type by hand). */
    private static function configFor(array $c, array $website): array
    {
        // form_url stays empty for discovered forms: the tester re-reads the LIVE action of the form on every test, so a
        // changed handler is followed automatically (the discovered action is kept in action_url for information)
        $formUrl = null;
        $kind =$c['kind'] === 'popup' ? 'popup' : ($c['plugin'] && in_array($c['plugin'], ['cf7', 'wpforms', 'elementor', 'gravity', 'fluent', 'ninja', 'formidable', 'forminator'], true) ? 'wordpress' : ($c['ajax'] ? 'ajax' : 'normal'));
        return [
            'form_type'       => in_array($c['form_type'], form_types(), true) ? $c['form_type'] : 'Other',
            'form_kind'       => $kind,
            'method'          => strtoupper($c['method']) === 'GET' ? 'GET' : 'POST',
            'ajax'            => $c['ajax'] || $kind !== 'normal' ? 1 : 0,
            'form_url'        => $formUrl,
            'form_selector'   => $c['selector'] ? mb_substr($c['selector'], 0, 255) : null,
            'submit_selector' => $c['submit_selector'] ? mb_substr($c['submit_selector'], 0, 255) : null,
            'popup_selector'  => $c['popup_selector'] ? mb_substr($c['popup_selector'], 0, 255) : null,
            'popup_trigger'   => $c['popup_trigger'] ? mb_substr($c['popup_trigger'], 0, 255) : null,
            'wp_plugin'       => $c['plugin'] ? mb_substr($c['plugin'], 0, 30) : null,
        ];
    }

    private static function uniqueName(int $wid, array $c): string
    {
        $base = mb_substr($c['name'], 0, 120);
        $taken = array_map('strtolower', array_column(DB::fetchAll("SELECT name FROM forms WHERE website_id = ?", [$wid]), 'name'));
        if (!in_array(strtolower($base), $taken, true)) return $base;
        $pageLabel = trim((string) ($c['page_title'] ? preg_replace('~\s*[|–-]\s*.{0,60}$~u', '', $c['page_title']) : Monitor::pathLabel((string) parse_url($c['page_url'], PHP_URL_PATH))));
        if ($pageLabel !== '' && strtolower($pageLabel) !== strtolower($base)) {
            $name = mb_substr($base . ' – ' . $pageLabel, 0, 145);
            if (!in_array(strtolower($name), $taken, true)) return $name;
        }
        for ($i = 2; $i < 50; $i++) { $n = mb_substr($base, 0, 140) . ' #' . $i; if (!in_array(strtolower($n), $taken, true)) return $n; }
        return mb_substr($base, 0, 130) . ' ' . substr($c['fingerprint'], 0, 6);
    }

    private static function linkPages(int $formId, int $wid, array $pages, string $now): void
    {
        foreach ($pages as $p) {
            $url = mb_substr($p['url'], 0, 500);
            $hash = md5($url);
            $pid = DB::value("SELECT id FROM website_pages WHERE website_id = ? AND url = ? LIMIT 1", [$wid, $url]);
            DB::query("INSERT INTO form_pages (form_id, page_id, page_url, url_hash, page_title, first_seen_at, last_seen_at) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), page_id = COALESCE(VALUES(page_id), page_id), page_title = COALESCE(VALUES(page_title), page_title)",
                [$formId, $pid ?: null, $url, $hash, $p['title'] ? mb_substr($p['title'], 0, 190) : null, $now, $now]);
        }
    }

    /* =====================================================================
     * Status history + counters (also used by Monitor::testForm)
     * ===================================================================== */

    public static function recordStatus(int $formId, ?string $from, string $to, ?string $reason = null): void
    {
        if ($from === $to) return;
        DB::insert('form_status_history', ['form_id' => $formId, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason ? mb_substr($reason, 0, 190) : null, 'changed_at' => date('Y-m-d H:i:s')]);
    }

    public static function refreshCounts(int $websiteId): array
    {
        $r = DB::fetch("SELECT SUM(status <> 'removed') AS total, SUM(status = 'working') AS working, SUM(status = 'failed') AS failed, SUM(status = 'captcha_blocked') AS blocked, SUM(status = 'removed') AS removed,
            SUM(status <> 'removed' AND form_kind = 'popup') AS popup, SUM(status <> 'removed' AND form_kind IN ('ajax','wordpress')) AS ajax, SUM(status <> 'removed' AND form_kind = 'normal') AS normal
            FROM forms WHERE website_id = ?", [$websiteId]) ?: [];
        $c = ['forms_total' => (int) ($r['total'] ?? 0), 'forms_working' => (int) ($r['working'] ?? 0), 'forms_failed' => (int) ($r['failed'] ?? 0), 'forms_blocked' => (int) ($r['blocked'] ?? 0), 'forms_removed' => (int) ($r['removed'] ?? 0),
            'forms_normal' => (int) ($r['normal'] ?? 0), 'forms_popup' => (int) ($r['popup'] ?? 0), 'forms_ajax' => (int) ($r['ajax'] ?? 0)];
        DB::update('websites', $c, 'id = ?', [$websiteId]);
        return $c;
    }

    /* =====================================================================
     * Browser pass (headless Chrome): JS-rendered forms + popup triggers
     * ===================================================================== */

    const JS_DISCOVERY = <<<'JS'
window.__crmDisc = window.__crmDisc || (function () {
  const MODAL = /(^|[\s_-])(modal|popup|pop-up|pum|elementor-popup|dialog|lightbox|offcanvas|overlay|fancybox|mfp-|drawer|slide-?in|flyout)([\s_-]|$)/i;
  const TRIG = /enquir|inquir|quote|contact|get in touch|reach us|book|appoint|apply|request|call ?back|callback|subscribe|sign ?up|register|download|brochure|demo|consult|talk to|let.?s talk|get started|free trial|estimate|schedule|reserve|join|write to us|send message|feedback/i;
  function visible(el) { if (!el || !(el instanceof Element)) return false; const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; }
  function txt(el) { return ((el && (el.innerText || el.textContent)) || '').replace(/\s+/g, ' ').trim(); }
  function esc(s) { try { return CSS.escape(s); } catch (e) { return String(s).replace(/[^A-Za-z0-9_-]/g, '\\$&'); } }
  function cssPath(el) {
    if (!el || !(el instanceof Element)) return null;
    if (el.id && !/^\d/.test(el.id) && document.querySelectorAll('#' + esc(el.id)).length === 1) return '#' + el.id;
    for (const a of ['data-bs-target', 'data-target', 'data-modal', 'data-popup', 'data-open', 'aria-controls', 'href']) { const v = el.getAttribute(a); if (v && v.length < 80 && document.querySelectorAll(el.tagName.toLowerCase() + '[' + a + '="' + v.replace(/"/g, '') + '"]').length === 1) return el.tagName.toLowerCase() + '[' + a + '="' + v.replace(/"/g, '') + '"]'; }
    const parts = []; let cur = el; let depth = 0;
    while (cur && cur !== document.body && depth < 6) {
      let seg = cur.tagName.toLowerCase();
      if (cur.id && !/^\d/.test(cur.id)) { parts.unshift('#' + cur.id); break; }
      const cls = Array.from(cur.classList).filter(c => !/^(active|show|open|fade|in|is-|has-|js-|hover|focus|elementor-element-[a-f0-9]+)$/.test(c) && !/^\d/.test(c)).slice(0, 2);
      if (cls.length) seg += '.' + cls.map(esc).join('.');
      const p = cur.parentElement; if (p) { const same = Array.from(p.children).filter(ch => ch.tagName === cur.tagName); if (same.length > 1) seg += ':nth-of-type(' + (same.indexOf(cur) + 1) + ')'; }
      parts.unshift(seg); cur = cur.parentElement; depth++;
    }
    const sel = parts.join(' > ');
    try { if (document.querySelector(sel) === el) return sel; } catch (e) {}
    return sel;
  }
  function modalAncestor(el) { let best = null; let cur = el.parentElement; for (let i = 0; i < 12 && cur && cur !== document.body; i++, cur = cur.parentElement) { const sig = (cur.id || '') + ' ' + String(cur.className || ''); if (MODAL.test(sig) || cur.getAttribute('role') === 'dialog' || cur.getAttribute('data-elementor-type') === 'popup') { if (!best || cur.id || !best.id) best = cur; } } return best; }
  function label(el) { let t = ''; try { if (el.id) { const l = document.querySelector('label[for="' + esc(el.id) + '"]'); if (l) t = txt(l); } if (!t && el.closest('label')) t = txt(el.closest('label')); } catch (e) {} return t || el.placeholder || el.getAttribute('aria-label') || el.title || ''; }
  function fields(form) { const out = []; const seen = {}; for (const el of Array.from(form.querySelectorAll('input[name],textarea[name],select[name]'))) { if (seen[el.name]) continue; seen[el.name] = 1; const type = (el.type || el.tagName).toLowerCase(); if (type === 'submit' || type === 'button' || type === 'reset') continue; const st = getComputedStyle(el); out.push({ name: el.name.slice(0, 80), type: type, label: (label(el) || '').slice(0, 80), required: !!el.required, hidden: type === 'hidden' || st.display === 'none' }); } return out.slice(0, 60); }
  function submit(form) { const b = form.querySelector('button[type=submit],input[type=submit],input[type=image]') || form.querySelector('button:not([type=button]):not([type=reset])'); if (!b) return [null, null]; const l = (b.value || txt(b) || b.getAttribute('aria-label') || '').slice(0, 120); return [l || null, b.id ? '#' + b.id : (b.name ? b.tagName.toLowerCase() + '[name="' + b.name + '"]' : null)]; }
  function title(form) { for (const a of ['aria-label', 'title', 'data-title', 'data-name', 'data-form-name']) { const v = (form.getAttribute(a) || '').trim(); if (v && v.length < 80) return v; } let h = form.querySelector('legend,h1,h2,h3,h4,.form-title,.form-heading'); if (h && txt(h).length < 90) return txt(h); const m = modalAncestor(form); if (m) { h = m.querySelector('.modal-title,.popup-title,h1,h2,h3,h4'); if (h && txt(h).length < 90) return txt(h); } let cur = form.parentElement; for (let i = 0; i < 4 && cur && cur !== document.body; i++, cur = cur.parentElement) { h = cur.querySelector('h1,h2,h3,h4'); if (h && txt(h).length < 90 && h.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING) return txt(h); } return null; }
  function context(form) { let cur = form.parentElement; for (let i = 0; i < 3 && cur && cur !== document.body; i++, cur = cur.parentElement) { const t = txt(cur); if (t.length > 40) return t.slice(0, 400); } return ''; }
  function describe(form, doc, iframeSrc) {
    doc = doc || document;
    const all = Array.from(doc.querySelectorAll('form'));
    const idx = all.indexOf(form) + 1;
    const m = modalAncestor(form);
    const [sl, ss] = submit(form);
    const ci = (window.__crm && window.__crm.captchaInfo) ? window.__crm.captchaInfo(form) : { type: null, inForm: false, label: '', detail: '' };
    return { id: form.id || '', name: form.getAttribute('name') || '', cls: String(form.className || '').slice(0, 200), action: form.getAttribute('action') || '', method: (form.getAttribute('method') || 'GET').toUpperCase(),
      visible: visible(form), modal: m ? (cssPath(m) || null) : null, modalVisible: m ? visible(m) : null, fields: fields(form), submitLabel: sl, submitSelector: ss, selector: cssPath(form) || ('form:nth-of-type(' + idx + ')'),
      captcha: ci, html: form.outerHTML.slice(0, 4000), title: title(form), context: context(form), role: form.getAttribute('role') || '', iframeSrc: iframeSrc || null, idx: idx };
  }
  function inventory() {
    const out = Array.from(document.querySelectorAll('form')).map(f => describe(f));
    for (const fr of Array.from(document.querySelectorAll('iframe'))) { try { const d = fr.contentDocument; if (!d) continue; for (const f of Array.from(d.querySelectorAll('form'))) { out.push(describe(f, d, fr.src || 'about:blank')); } } catch (e) {} }
    return out;
  }
  function visibleFormKeys() { return Array.from(document.querySelectorAll('form')).filter(visible).map((f, i) => f.id || f.getAttribute('name') || (f.action + '#' + i)); }
  function triggers(max) {
    max = max || 8; const out = []; const seen = new Set();
    const cands = Array.from(document.querySelectorAll('a,button,[role=button],input[type=button],.btn,[data-bs-toggle=modal],[data-toggle=modal],[data-elementor-open-popup],[data-popup],[data-modal],.elementor-button,[class*="popup"],[class*="modal"]'));
    for (const el of cands) {
      if (!visible(el) || out.length >= max) continue;
      const href = (el.getAttribute('href') || '').trim();
      const attrs = ['data-bs-toggle', 'data-toggle', 'data-bs-target', 'data-target', 'data-elementor-open-popup', 'data-popup', 'data-modal', 'data-open', 'data-fancybox', 'data-mfp-src', 'onclick'].map(a => el.getAttribute(a) || '').join(' ');
      const sig = txt(el).slice(0, 60) + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.id || '') + ' ' + String(el.className || '') + ' ' + attrs;
      const isModalAttr = /modal|popup|elementor-action|lightbox|fancybox|mfp/i.test(attrs) || /elementor-action/i.test(href);
      let targetsForm = false;
      if (href.startsWith('#') && href.length > 1) { try { const t = document.querySelector(href); targetsForm = !!(t && t.querySelector('form') && !visible(t)); } catch (e) {} if (!targetsForm && !isModalAttr) continue; }
      else if (href && !/^(javascript:|#|$)/.test(href) && !isModalAttr) continue; // a real link to another page
      if (!isModalAttr && !targetsForm && !TRIG.test(sig)) continue;
      if (el.closest('form')) continue;
      const sel = cssPath(el); if (!sel || seen.has(sel)) continue; seen.add(sel);
      out.push({ selector: sel, text: txt(el).slice(0, 60) || (el.getAttribute('aria-label') || '').slice(0, 60), kind: isModalAttr ? 'modal-attr' : (targetsForm ? 'anchor' : 'text') });
    }
    return out;
  }
  function newVisibleForms(beforeKeys) { const before = new Set(beforeKeys || []); return Array.from(document.querySelectorAll('form')).filter(visible).filter((f, i) => !before.has(f.id || f.getAttribute('name') || (f.action + '#' + i))).map(f => describe(f)); }
  function closePopups() { let n = 0; for (const b of Array.from(document.querySelectorAll('[data-bs-dismiss=modal],[data-dismiss=modal],.modal .close,.modal .btn-close,.dialog-close-button,.elementor-popup-modal .dialog-close-button,.pum-close,.popup-close,.close-popup,.mfp-close,.fancybox-close-small,[aria-label="Close"],[aria-label="close"]'))) { if (visible(b)) { try { b.click(); n++; } catch (e) {} } } return n; }
  return { inventory, triggers, visibleFormKeys, newVisibleForms, closePopups, visible };
})();
JS;

    private static function browserInspect(Browser $b, string $url, ?string $pageTitle, string $host, array $website, int $budget): array
    {
        $out = [];
        $start = microtime(true);
        $timeout = max(10, min(30, (int) setting('check_timeout', 15) + 10));
        $b->reset();
        $b->setExtraHeaders(['X-CRM-Form-Scan' => '1']);
        try { $b->send('Page.addScriptToEvaluateOnNewDocument', ['source' => FormTester::JS_HELPERS . "\n" . self::JS_DISCOVERY]); } catch (Throwable $e) {}
        $nav = $b->navigate($url, $timeout);
        if (!$nav['loaded'] && !$nav['status']) throw new RuntimeException('Page did not load: ' . ($nav['error'] ?: 'timeout'));
        if ($nav['status'] >= 400) throw new RuntimeException('Page returned HTTP ' . $nav['status']);
        $b->pump(1500);
        $inject = fn() => $b->evaluate(FormTester::JS_HELPERS . "\n" . self::JS_DISCOVERY . '; true');
        $inject();
        $title = $b->evaluate('document.title || ""');
        $pageTitle = $pageTitle ?: (is_string($title) ? trim($title) : null);
        $finalUrl = $nav['url'] ?: $url;

        // Forms present in the rendered DOM (visible + hidden modal forms + same-origin iframes)
        $initialKeys = $b->evaluate('window.__crmDisc.visibleFormKeys()') ?: [];
        $list = $b->evaluate('window.__crmDisc.inventory()') ?: [];
        foreach ($list as $j) $out[] = self::fromJs($j, $finalUrl, $url, $pageTitle, $host, null);

        // Time-delayed popups (appear by themselves a few seconds after load)
        $b->pump(3000);
        $auto = $b->evaluate('window.__crmDisc.newVisibleForms(' . json_encode($initialKeys) . ')') ?: [];
        foreach ($auto as $j) { $c = self::fromJs($j, $finalUrl, $url, $pageTitle, $host, null); $c['kind'] = 'popup'; $c['popup_selector'] = $c['popup_selector'] ?: ($j['modal'] ?? null); $out[] = $c; }
        if ($auto) { try { $b->evaluate('window.__crmDisc.closePopups()'); } catch (Throwable $e) {} $b->pump(500); }

        // Popup triggers: click each candidate, collect the forms that became visible, close, continue
        $triggers = $b->evaluate('window.__crmDisc.triggers(6)') ?: [];
        foreach ($triggers as $t) {
            if (microtime(true) - $start > $budget - 6) break;
            $sel = $t['selector'] ?? null;
            if (!$sel) continue;
            try {
                $before = $b->evaluate('window.__crmDisc.visibleFormKeys()') ?: [];
                $b->evaluate('(function(){ var el = window.__crm.q(' . json_encode($sel) . '); if (!el) return false; window.__crm.click(el); return true; })()');
                $b->waitFor('window.__crmDisc.newVisibleForms(' . json_encode($before) . ').length > 0', 2500, 300);
                $b->pump(600);
                $loc = $b->evaluate('location.href');
                if (is_string($loc) && rtrim($loc, '/#') !== rtrim($finalUrl, '/#') && strtok($loc, '#') !== strtok($finalUrl, '#')) {
                    // navigated away (it was a real link) – go back
                    $b->navigate($url, $timeout); $b->pump(1200); $inject();
                    continue;
                }
                $found = $b->evaluate('window.__crmDisc.newVisibleForms(' . json_encode($before) . ')') ?: [];
                foreach ($found as $j) {
                    $c = self::fromJs($j, $finalUrl, $url, $pageTitle, $host, $sel);
                    $c['kind'] = 'popup';
                    $c['popup_trigger'] = $sel;
                    $c['popup_selector'] = $j['modal'] ?? $c['popup_selector'];
                    $out[] = $c;
                }
                // close the popup again
                try { $b->send('Input.dispatchKeyEvent', ['type' => 'keyDown', 'key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27], 3); $b->send('Input.dispatchKeyEvent', ['type' => 'keyUp', 'key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27], 3); } catch (Throwable $e) {}
                $b->evaluate('window.__crmDisc.closePopups()');
                $b->pump(500);
                $still = $b->evaluate('window.__crmDisc.newVisibleForms(' . json_encode($before) . ').length') ?: 0;
                if ($still > 0) { $b->navigate($url, $timeout); $b->pump(1200); $inject(); }
            } catch (Throwable $e) {
                try { $b->navigate($url, $timeout); $b->pump(1000); $inject(); } catch (Throwable $e2) { break; }
            }
        }
        return $out;
    }

    /** Normalise a browser-side form description into a candidate. */
    private static function fromJs(array $j, string $finalUrl, string $requestedUrl, ?string $pageTitle, string $host, ?string $trigger): array
    {
        $c = self::baseCandidate($finalUrl, $requestedUrl, $pageTitle, 'browser');
        $c['dom_id'] = (string) ($j['id'] ?? '');
        $c['name_attr'] = (string) ($j['name'] ?? '');
        $c['classes'] = (string) ($j['cls'] ?? '');
        $action = trim((string) ($j['action'] ?? ''));
        $c['action'] = $action === '' ? '' : FormTester::absolute($action, $finalUrl);
        $c['method'] = strtoupper((string) ($j['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
        $c['fields'] = array_map(fn($f) => ['name' => (string) ($f['name'] ?? ''), 'type' => (string) ($f['type'] ?? 'text'), 'label' => (string) ($f['label'] ?? '') ?: self::humanize((string) ($f['name'] ?? '')), 'required' => !empty($f['required']), 'hidden' => !empty($f['hidden'])], is_array($j['fields'] ?? null) ? $j['fields'] : []);
        $c['html'] = (string) ($j['html'] ?? '');
        $fieldsForPlugin = [];
        foreach ($c['fields'] as $f) $fieldsForPlugin[$f['name']] = ['type' => $f['type'], 'value' => ''];
        // hidden field values needed for plugin form ids
        if (preg_match_all('~<input[^>]+name="(_wpcf7|wpforms\[id\]|form_id|gform_submit|frm_action)"[^>]+value="([^"]*)"~i', $c['html'], $mm, PREG_SET_ORDER)) foreach ($mm as $m) $fieldsForPlugin[$m[1]] = ['type' => 'hidden', 'value' => $m[2]];
        if (preg_match_all('~<input[^>]+value="([^"]*)"[^>]+name="(_wpcf7|wpforms\[id\]|form_id|gform_submit|frm_action)"~i', $c['html'], $mm, PREG_SET_ORDER)) foreach ($mm as $m) $fieldsForPlugin[$m[2]] = ['type' => 'hidden', 'value' => $m[1]];
        $c['plugin'] = FormTester::detectPlugin(strtolower($c['classes'] . ' ' . $c['dom_id']), $c['html'], $fieldsForPlugin, '');
        $c['plugin_form_id'] = self::pluginFormId($fieldsForPlugin, $c['plugin']);
        $ci = is_array($j['captcha'] ?? null) ? $j['captcha'] : [];
        $c['captcha'] = ['type' => $ci['type'] ?? null, 'in_form' => !empty($ci['inForm']), 'label' => (string) ($ci['label'] ?? ''), 'detail' => (string) ($ci['detail'] ?? '')];
        if (!$c['captcha']['type']) $c['captcha'] = FormTester::detectCaptcha($c['html'], $fieldsForPlugin, '');
        $c['is_search'] = ($j['role'] ?? '') === 'search' || (count($c['fields']) <= 2 && (bool) array_filter($c['fields'], fn($f) => $f['type'] === 'search' || $f['name'] === 's'));
        $c['is_login'] = (bool) array_filter($c['fields'], fn($f) => $f['type'] === 'password');
        $c['submit_label'] = $j['submitLabel'] ?? null;
        $c['submit_selector'] = $j['submitSelector'] ?? null;
        $c['selector'] = (string) ($j['selector'] ?? '') ?: null;
        $modal = $j['modal'] ?? null;
        $hiddenInModal = $modal && (empty($j['visible']) || ($j['modalVisible'] ?? true) === false);
        $c['kind'] = $hiddenInModal || $trigger ? 'popup' : 'normal';
        $c['popup_selector'] = $modal ?: null;
        $c['popup_trigger'] = $trigger;
        $c['title'] = $j['title'] ?? null;
        $c['context'] = (string) ($j['context'] ?? '');
        $c['in_iframe'] = !empty($j['iframeSrc']) ? 1 : 0;
        $c['iframe_src'] = !empty($j['iframeSrc']) ? mb_substr((string) $j['iframeSrc'], 0, 500) : null;
        if ($c['in_iframe'] && $c['iframe_src'] && host_from_url($c['iframe_src']) && host_from_url($c['iframe_src']) !== $host) { $c['third_party'] = self::thirdParty($c['iframe_src']) ?: host_from_url($c['iframe_src']); $c['technology'] = $c['third_party']; $c['testable'] = false; }
        elseif ($c['in_iframe'] && $c['iframe_src'] && preg_match('~^https?://~', $c['iframe_src'])) { $c['seen_on'] = $requestedUrl; $c['page_url'] = $c['iframe_src']; }
        $c['ajax'] = self::isAjax($c);
        $c['skip'] = self::skipReason($c);
        return self::describe($c);
    }
}
