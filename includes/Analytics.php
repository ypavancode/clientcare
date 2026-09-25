<?php
/**
 * Visitor analytics (v3.2): privacy-friendly, cookie-less website analytics.
 *
 *   <script async src="https://your-crm/t.js" data-site="SITEKEY"></script>
 *
 * The snippet (api/tracker.php) sends one small beacon per page view to /collect (api/collect.php). Every device is
 * counted once (an anonymous id generated in the visitor's browser, hashed with the site key server-side – no IP is
 * stored with events, no cookies). Events are rolled up into daily tables on write, so dashboards read a few hundred
 * small indexed rows even with millions of page views. Raw events are kept only for the plan's retention window.
 */
class Analytics
{
    public const DIMS = ['country', 'region', 'city', 'device', 'browser', 'os', 'source', 'referrer', 'lang', 'campaign', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'event', 'hour', 'screen'];
    public const REALTIME_MINUTES = 5;

    /* ---------- keys & enablement ---------- */

    public static function enable(int $websiteId): string
    {
        $w = DB::fetch("SELECT id, analytics_key FROM websites WHERE id = ?", [$websiteId]);
        $key = $w['analytics_key'] ?: self::newKey();
        DB::update('websites', ['analytics_enabled' => 1, 'analytics_key' => $key], 'id = ?', [$websiteId]);
        return $key;
    }

    public static function newKey(): string
    {
        // SITE-XXXXXXXX: unambiguous upper-case letters / digits, easy to read out and to spot in the page source
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do { $k = 'SITE-'; for ($i = 0; $i < 8; $i++) $k .= $alphabet[random_int(0, strlen($alphabet) - 1)]; } while (DB::value("SELECT id FROM websites WHERE analytics_key = ?", [$k]));
        return $k;
    }

    /** Per-site tracking script URL (the key is embedded, nothing else to configure on the website). */
    public static function scriptUrl(string $key): string
    {
        return BASE_URL . '/analytics/' . $key . '.js';
    }

    public static function snippet(string $key): string
    {
        return '<script async src="' . self::scriptUrl($key) . '"></script>';
    }

    /** Real-time view allowed by the plan? (plans without the flag keep it – older feature sets) */
    public static function realtimeAllowed(?int $tenantId = null): bool
    {
        $f = Tenant::plan($tenantId)['features'] ?? [];
        return !array_key_exists('realtime', $f) || (bool) $f['realtime'];
    }

    /** Geographic detail the plan includes: country | region | city */
    public static function geoLevel(?int $tenantId = null): string
    {
        $g = (string) (Tenant::feature('geo', $tenantId) ?: '');
        return in_array($g, ['country', 'region', 'city'], true) ? $g : (self::level($tenantId) === 'full' ? 'city' : 'country');
    }

    /** Monthly custom-event quota (0 / missing = unlimited). */
    public static function eventsQuota(?int $tenantId = null): array
    {
        $tid = $tenantId ?? Tenant::id();
        $limit = (int) (Tenant::feature('max_events_month', $tid) ?: 0) ?: null;
        $used = (int) Cache::remember("analytics:equota:$tid:" . date('Y-m'), 60, fn() => DB::value("SELECT COALESCE(SUM(events),0) FROM analytics_daily WHERE tenant_id = ? AND day >= ?", [$tid, date('Y-m-01')]));
        return ['current' => $used, 'limit' => $limit, 'pct' => $limit ? min(100, (int) round($used / max(1, $limit) * 100)) : 0];
    }

    /** utm_* parameters of a URL query (lower-cased, trimmed, capped). */
    public static function utm(string $query): array
    {
        parse_str($query, $qs);
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) { $v = mb_substr(trim((string) ($qs[$k] ?? '')), 0, 100); if ($v !== '') $out[$k] = mb_strtolower($v); }
        if (!$out && !empty($qs['gclid'])) $out = ['utm_source' => 'google', 'utm_medium' => 'cpc'];
        if (!$out && !empty($qs['fbclid'])) $out = ['utm_source' => 'facebook', 'utm_medium' => 'social'];
        return $out;
    }

    /** Plan access: none | basic | full */
    public static function level(?int $tenantId = null): string
    {
        if (!setting('analytics_enabled', 1)) return 'none';
        $v = Tenant::feature('analytics', $tenantId);
        return in_array($v, ['basic', 'full'], true) ? $v : 'none';
    }

    /** Analytics history the plan keeps (days). */
    public static function retentionDays(?int $tenantId = null): int
    {
        return max(7, (int) (Tenant::feature('analytics_retention', $tenantId) ?: 30));
    }

    /** Monthly pageview quota: label/current/limit/pct/state (+ reset date). */
    public static function quota(?int $tenantId = null): array
    {
        $tid = $tenantId ?? Tenant::id();
        $plan = Tenant::plan($tid);
        $limit = isset($plan['max_pageviews_month']) && $plan['max_pageviews_month'] !== null ? (int) $plan['max_pageviews_month'] : null;
        $used = (int) Cache::remember("analytics:quota:$tid:" . date('Y-m'), 60, fn() => DB::value("SELECT COALESCE(SUM(pageviews),0) FROM analytics_daily WHERE tenant_id = ? AND day >= ?", [$tid, date('Y-m-01')]));
        $pct = $limit ? min(100, (int) round($used / max(1, $limit) * 100)) : 0;
        return ['label' => 'Analytics page views', 'current' => $used, 'limit' => $limit, 'pct' => $pct, 'state' => $limit === null ? 'ok' : ($pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : 'ok')), 'resets' => date('Y-m-01', strtotime('first day of next month'))];
    }

    /** Installation status for one website row: not_installed | waiting | active | no_recent_data | paused */
    public static function installStatus(array $w): array
    {
        if (!$w['analytics_enabled'] && !$w['analytics_key']) return ['not_installed', 'Not installed', 'secondary', 'Enable tracking to get the snippet.'];
        if (!$w['analytics_enabled']) return ['paused', 'Paused', 'secondary', 'Tracking is paused – no new page views are recorded.'];
        if (!$w['analytics_last_event_at']) return ['waiting', 'Tracking Not Detected', 'warning', 'No page view has been received yet. Add the script to the site <head> and open the website.'];
        $age = time() - strtotime($w['analytics_last_event_at']);
        if ($age > 7 * 86400) return ['no_recent_data', 'Tracking Not Detected', 'danger', 'No page views for 7+ days (last ' . time_ago($w['analytics_last_event_at']) . '). Check that the script is still on the site.'];
        return ['active', 'Tracking Active', 'success', 'Receiving page views – last one ' . time_ago($w['analytics_last_event_at']) . '.'];
    }

    /* ---------- collection ---------- */

    /** Validate + store one beacon: view (page view), leave (time on page) or event (custom). Returns [accepted, reason]. Never throws. */
    public static function collect(array $p, string $ip, string $ua, ?string $origin): array
    {
        $key = preg_replace('~[^A-Za-z0-9-]~', '', (string) ($p['k'] ?? ''));
        if (strlen($key) < 8) return [false, 'bad key'];
        $w = Cache::remember('analytics:site:' . $key, 60, fn() => DB::fetch("SELECT w.id, w.tenant_id, w.url, w.analytics_enabled, w.analytics_any_origin, t.status AS tenant_status FROM websites w JOIN tenants t ON t.id = w.tenant_id WHERE w.analytics_key = ?", [$key]) ?: []);
        if (!$w || !$w['analytics_enabled'] || $w['tenant_status'] !== 'active') return [false, 'unknown site'];
        if (self::level((int) $w['tenant_id']) === 'none') return [false, 'plan'];
        if (self::isBot($ua)) return [false, 'bot'];
        $type = in_array($p['e'] ?? 'view', ['view', 'leave', 'event'], true) ? $p['e'] : 'view';
        $url = (string) ($p['u'] ?? '');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') return [false, 'bad url'];
        if (!$w['analytics_any_origin'] && !self::hostMatches($host, host_from_url($w['url']))) return [false, 'origin'];
        // per-IP flood protection (200 beacons / minute per site)
        $flood = 'analytics:flood:' . $w['id'] . ':' . self::ipHash($ip) . ':' . date('YmdHi');
        $n = (int) Cache::remember($flood, 60, fn() => 0);
        if ($n > 200) return [false, 'rate'];
        Cache::set($flood, $n + 1, 60);

        $now = date('Y-m-d H:i:s'); $day = date('Y-m-d');
        $vid = substr(preg_replace('~[^A-Za-z0-9_-]~', '', (string) ($p['v'] ?? '')), 0, 64);
        $sid = substr(preg_replace('~[^A-Za-z0-9_-]~', '', (string) ($p['s'] ?? '')), 0, 64);
        // no client id (storage blocked) → daily fingerprint of salted-ip+ua that cannot be reversed (the raw IP is never stored)
        $visitorHash = $vid !== '' ? md5($key . '|' . $vid) : md5($key . '|' . self::ipHash($ip) . '|' . $ua . '|' . $day);
        $sessionHash = $sid !== '' ? md5($key . '|' . $sid) : md5($visitorHash . '|' . floor(time() / 1800));
        $path = mb_substr('/' . ltrim((string) ($parts['path'] ?? '/'), '/'), 0, 500);
        $query = (string) ($parts['query'] ?? '');

        if ($type === 'leave') {
            $sec = (int) ($p['d'] ?? 0);
            if ($sec < 1 || $sec > 7200) return [false, 'bad duration'];
            self::applyLeave((int) $w['id'], $path, $sec, $day);
            return [true, 'leave'];
        }
        if ($type === 'event') {
            $name = mb_substr(trim(preg_replace('~[^\w .:/-]~u', '', (string) ($p['n'] ?? ''))), 0, 40);
            if ($name === '') return [false, 'bad event'];
            $eq = self::eventsQuota((int) $w['tenant_id']);
            if ($eq['limit'] !== null && $eq['current'] >= $eq['limit']) return [false, 'event quota'];
            self::applyEvent($w, $visitorHash, $sessionHash, $path, $name, $now, $day);
            return [true, 'event'];
        }

        // monthly page-view quota (plan) – warn at 80 / 90 / 100 %, stop recording at 100 %
        $q = self::quota((int) $w['tenant_id']);
        if ($q['limit'] !== null) {
            foreach ([100, 90, 80] as $th) { if ($q['pct'] >= $th) { self::quotaNotice((int) $w['tenant_id'], $th, $q); break; } }
            if ($q['current'] >= $q['limit']) return [false, 'quota'];
        }
        $title = mb_substr(trim((string) ($p['t'] ?? '')), 0, 190) ?: null;
        $ref = (string) ($p['r'] ?? '');
        $refHost = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?: ''));
        if ($refHost !== '' && self::hostMatches($refHost, $host)) $refHost = ''; // internal navigation
        $refHost = preg_replace('~^www\.~', '', $refHost);
        $source = self::classifySource($refHost, $query);
        $campaign = self::campaign($query);
        $utm = self::utm($query);
        [$device, $browser, $os] = self::parseUa($ua, (int) ($p['w'] ?? 0));
        $geo = self::geo($ip, (string) ($p['z'] ?? ''));
        $lang = substr(preg_replace('~[^a-zA-Z-]~', '', (string) ($p['l'] ?? '')), 0, 10) ?: null;
        $lang = $lang ? strtolower(explode('-', $lang)[0]) : null;
        $sw = (int) ($p['w'] ?? 0); $sh = (int) ($p['h'] ?? 0);
        $screen = $sw > 0 ? self::screenBucket($sw) : '';

        $ev = ['tenant_id' => $w['tenant_id'], 'website_id' => $w['id'], 'visitor_hash' => $visitorHash, 'session_hash' => $sessionHash, 'path' => $path, 'title' => $title, 'event' => 'pageview', 'referrer_host' => $refHost ?: null, 'source' => $source,
            'country' => $geo['country'], 'region' => $geo['region'], 'city' => $geo['city'], 'device' => $device, 'browser' => $browser, 'os' => $os, 'screen_w' => $sw ?: null, 'lang' => $lang, 'is_new_device' => 0, 'created_at' => $now];
        $extra = ['campaign' => $campaign, 'screen' => $screen, 'day' => $day, 'utm' => $utm];
        // Asynchronous ingestion: append the raw event only (one cheap INSERT) and let the analytics queue do the
        // roll-up writes in batches. Falls back to immediate roll-up when no worker is alive (shared hosting).
        if (setting('analytics_async', 1) && Cache::remember('scheduler:workers_alive', 30, fn() => Scheduler::workersAlive()) > 0) {
            DB::insert('analytics_events', $ev + ['processed' => 0, 'raw' => json_encode($extra)]);
            return [true, 'queued'];
        }
        $id = DB::insert('analytics_events', $ev + ['processed' => 1]);
        self::apply($ev + $extra, (int) $id);
        return [true, 'ok'];
    }

    /** Time on page: add the seconds to today's (or, right after midnight, yesterday's) page + daily rows. */
    private static function applyLeave(int $wid, string $path, int $seconds, string $day): void
    {
        $ph = md5($path);
        foreach ([$day, date('Y-m-d', strtotime($day) - 86400)] as $d) {
            $n = DB::query("UPDATE analytics_daily_pages SET duration_sum = duration_sum + ?, duration_n = duration_n + 1 WHERE website_id = ? AND day = ? AND path_hash = ?", [$seconds, $wid, $d, $ph])->rowCount();
            if ($n) { DB::query("UPDATE analytics_daily SET duration_sum = duration_sum + ?, duration_n = duration_n + 1 WHERE website_id = ? AND day = ?", [$seconds, $wid, $d]); return; }
        }
    }

    /** Custom event (button click, download, CTA…): raw row + daily counter + "event" dimension. */
    private static function applyEvent(array $w, string $visitorHash, string $sessionHash, string $path, string $name, string $now, string $day): void
    {
        DB::insert('analytics_events', ['tenant_id' => $w['tenant_id'], 'website_id' => $w['id'], 'visitor_hash' => $visitorHash, 'session_hash' => $sessionHash, 'path' => $path, 'title' => null, 'event' => $name, 'source' => 'direct', 'device' => 'desktop', 'processed' => 1, 'created_at' => $now]);
        DB::query("INSERT INTO analytics_daily (website_id, tenant_id, day, events) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE events = events + 1", [$w['id'], $w['tenant_id'], $day]);
        DB::query("INSERT INTO analytics_daily_dims (website_id, day, dim, value, pageviews, visitors) VALUES (?,?,'event',?,1,0) ON DUPLICATE KEY UPDATE pageviews = pageviews + 1", [$w['id'], $day, mb_substr($name, 0, 120)]);
        Cache::forget("analytics:equota:{$w['tenant_id']}:" . date('Y-m'));
    }
    /** Roll one page view into the visitor / session / daily / dimension / page tables (idempotent per event id). */
    private static function apply(array $e, int $eventId): void
    {
        $wid = (int) $e['website_id']; $now = $e['created_at']; $day = $e['day'] ?? substr($now, 0, 10); $ph = md5($e['path']);
        $newDevice = DB::query("INSERT IGNORE INTO analytics_visitors (website_id, visitor_hash, first_seen_at, last_seen_at, visits, pageviews, country, device) VALUES (?,?,?,?,1,1,?,?)", [$wid, $e['visitor_hash'], $now, $now, $e['country'], $e['device']])->rowCount() === 1;
        if (!$newDevice) DB::query("UPDATE analytics_visitors SET last_seen_at = ?, pageviews = pageviews + 1 WHERE website_id = ? AND visitor_hash = ?", [$now, $wid, $e['visitor_hash']]);
        $newToday = DB::query("INSERT IGNORE INTO analytics_visitors_daily (website_id, day, visitor_hash) VALUES (?,?,?)", [$wid, $day, $e['visitor_hash']])->rowCount() === 1;
        // sessions: first page = entry (+ provisional bounce); the second page of the same session cancels the bounce
        $newSession = DB::query("INSERT IGNORE INTO analytics_sessions_daily (website_id, day, session_hash, pageviews, landing_hash) VALUES (?,?,?,1,?)", [$wid, $day, $e['session_hash'], $ph])->rowCount() === 1;
        $secondPage = false; $landing = null;
        if (!$newSession) {
            $sd = DB::fetch("SELECT pageviews, landing_hash FROM analytics_sessions_daily WHERE website_id = ? AND day = ? AND session_hash = ?", [$wid, $day, $e['session_hash']]);
            DB::query("UPDATE analytics_sessions_daily SET pageviews = pageviews + 1 WHERE website_id = ? AND day = ? AND session_hash = ?", [$wid, $day, $e['session_hash']]);
            if ($sd && (int) $sd['pageviews'] === 1) { $secondPage = true; $landing = $sd['landing_hash']; }
        }
        if ($newSession && !$newDevice) DB::query("UPDATE analytics_visitors SET visits = visits + 1 WHERE website_id = ? AND visitor_hash = ?", [$wid, $e['visitor_hash']]);
        $newPageVisitor = DB::query("INSERT IGNORE INTO analytics_page_visitors_daily (website_id, day, path_hash, visitor_hash) VALUES (?,?,?,?)", [$wid, $day, $ph, $e['visitor_hash']])->rowCount() === 1;
        DB::query("INSERT INTO analytics_daily (website_id, tenant_id, day, pageviews, visitors, new_visitors, sessions, bounces) VALUES (?,?,?,1,?,?,?,?) ON DUPLICATE KEY UPDATE pageviews = pageviews + 1, visitors = visitors + VALUES(visitors), new_visitors = new_visitors + VALUES(new_visitors), sessions = sessions + VALUES(sessions), bounces = bounces + VALUES(bounces)",
            [$wid, $e['tenant_id'], $day, $newToday ? 1 : 0, $newDevice ? 1 : 0, $newSession ? 1 : 0, $newSession ? 1 : 0]);
        if ($secondPage) {
            DB::query("UPDATE analytics_daily SET bounces = GREATEST(bounces, 1) - 1 WHERE website_id = ? AND day = ?", [$wid, $day]);
            if ($landing) DB::query("UPDATE analytics_daily_pages SET bounces = GREATEST(bounces, 1) - 1 WHERE website_id = ? AND day = ? AND path_hash = ?", [$wid, $day, $landing]);
        }
        $screen = $e['screen'] ?? ($e['screen_w'] ? self::screenBucket((int) $e['screen_w']) : '');
        $dims = ['country' => $e['country'] ?: 'XX', 'region' => $e['region'] ? ($e['region'] . ($e['country'] ? ', ' . $e['country'] : '')) : '', 'city' => $e['city'] ? ($e['city'] . ($e['region'] ? ', ' . $e['region'] : '') . ($e['country'] ? ' · ' . $e['country'] : '')) : '', 'device' => $e['device'], 'browser' => $e['browser'] ?: 'Other', 'os' => $e['os'] ?: 'Other', 'source' => $e['source'], 'referrer' => (string) $e['referrer_host'], 'lang' => (string) $e['lang'], 'campaign' => (string) ($e['campaign'] ?? ''), 'hour' => (string) (int) date('G', strtotime($now)), 'screen' => $screen];
        foreach ((array) ($e['utm'] ?? []) as $k => $v) $dims[$k] = (string) $v;
        foreach ($dims as $dim => $val) {
            if ($val === '' || $val === null) continue;
            DB::query("INSERT INTO analytics_daily_dims (website_id, day, dim, value, pageviews, visitors) VALUES (?,?,?,?,1,?) ON DUPLICATE KEY UPDATE pageviews = pageviews + 1, visitors = visitors + VALUES(visitors)", [$wid, $day, $dim, mb_substr($val, 0, 120), $newToday ? 1 : 0]);
        }
        DB::query("INSERT INTO analytics_daily_pages (website_id, day, path, path_hash, title, pageviews, visitors, entries, bounces) VALUES (?,?,?,?,?,1,?,?,?) ON DUPLICATE KEY UPDATE pageviews = pageviews + 1, visitors = visitors + VALUES(visitors), entries = entries + VALUES(entries), bounces = bounces + VALUES(bounces), title = IFNULL(VALUES(title), title)", [$wid, $day, $e['path'], $ph, $e['title'], $newPageVisitor ? 1 : 0, $newSession ? 1 : 0, $newSession ? 1 : 0]);
        DB::query("UPDATE analytics_events SET processed = 1, is_new_device = ?, raw = NULL WHERE id = ?", [$newDevice ? 1 : 0, $eventId]);
        // first / last activity: every new device, otherwise at most once a minute (10 % sampling left low-traffic sites showing stale
        // "last event" times and, after 7 days without a lucky sample, a false "Tracking Not Detected")
        DB::query("UPDATE websites SET analytics_last_event_at = ?, analytics_first_event_at = IFNULL(analytics_first_event_at, ?) WHERE id = ? AND (analytics_last_event_at IS NULL OR analytics_last_event_at < ? OR ? = 1)", [$now, $now, $wid, date('Y-m-d H:i:s', strtotime($now) - 60), $newDevice ? 1 : 0]);
        Cache::forget("analytics:quota:{$e['tenant_id']}:" . date('Y-m', strtotime($now)));
    }
    /** Analytics queue job: roll up raw events that the beacon appended. Returns the number processed. */
    public static function processPending(int $limit = 2000): int
    {
        $rows = DB::fetchAll("SELECT * FROM analytics_events WHERE processed = 0 ORDER BY id ASC LIMIT " . (int) $limit);
        foreach ($rows as $r) {
            $extra = $r['raw'] ? (json_decode($r['raw'], true) ?: []) : [];
            try { self::apply($r + $extra, (int) $r['id']); }
            catch (Throwable $e) { app_log('error', 'analytics rollup failed for event #' . $r['id'] . ': ' . $e->getMessage()); DB::query("UPDATE analytics_events SET processed = 1 WHERE id = ?", [$r['id']]); }
        }
        return count($rows);
    }

    private static function quotaNotice(int $tenantId, int $threshold, array $q): void
    {
        if (!Tenant::lock('analytics:quota:' . $tenantId . ':' . date('Ym') . ':' . $threshold, 40 * 86400)) return;
        try {
            Tenant::act($tenantId);
            $msg = $threshold >= 100 ? 'Your plan\'s monthly analytics limit (' . number_format((int) $q['limit']) . ' page views) has been reached. New page views are not recorded until ' . format_date($q['resets']) . '. Upgrade to keep collecting.' : 'You have used ' . $threshold . '% of your monthly analytics limit (' . number_format($q['current']) . ' of ' . number_format((int) $q['limit']) . ' page views). It resets on ' . format_date($q['resets']) . '.';
            Notifier::create($threshold >= 100 ? 'warning' : 'info', 'billing', 'Analytics usage at ' . $threshold . '%', $msg, ['link' => 'billing/index.php']);
        } catch (Throwable $e) {} finally { Tenant::act(null); }
    }

    public static function hostMatches(string $host, string $siteHost): bool
    {
        $h = preg_replace('~^www\.~', '', strtolower($host)); $s = preg_replace('~^www\.~', '', strtolower($siteHost));
        if ($s === '' || $h === '') return false;
        return $h === $s || str_ends_with($h, '.' . $s);
    }

    public static function isBot(string $ua): bool
    {
        return $ua === '' || preg_match('~bot|crawl|spider|slurp|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python-requests|php/|go-http|java/|okhttp|facebookexternalhit|preview|scan~i', $ua) === 1;
    }

    public static function classifySource(string $refHost, string $query): string
    {
        if (preg_match('~(^|&)utm_~', $query) || preg_match('~(^|&)(gclid|fbclid|msclkid)=~', $query)) return 'campaign';
        if ($refHost === '') return 'direct';
        if (preg_match('~google\.|bing\.com|yahoo\.|duckduckgo|yandex|baidu|ecosia|ask\.com~', $refHost)) return 'search';
        if (preg_match('~facebook|fb\.com|instagram|twitter|t\.co$|x\.com|linkedin|pinterest|reddit|youtube|whatsapp|telegram|tiktok|threads\.net~', $refHost)) return 'social';
        return 'referral';
    }

    /** utm_campaign (or source / medium) → one label, '' when the URL has no campaign parameters. */
    public static function campaign(string $query): string
    {
        parse_str($query, $qs);
        $c = trim((string) ($qs['utm_campaign'] ?? '')); $s = trim((string) ($qs['utm_source'] ?? '')); $m = trim((string) ($qs['utm_medium'] ?? ''));
        if ($c === '' && $s === '' && $m === '') return !empty($qs['gclid']) ? 'google ads' : (!empty($qs['fbclid']) ? 'facebook' : '');
        return mb_substr(trim(($c !== '' ? $c : 'campaign') . ($s !== '' || $m !== '' ? ' (' . trim($s . ' / ' . $m, ' /') . ')' : '')), 0, 100);
    }

    public static function screenBucket(int $w): string
    {
        if ($w < 480) return '< 480'; if ($w < 768) return '480–767'; if ($w < 1024) return '768–1023'; if ($w < 1366) return '1024–1365'; if ($w < 1920) return '1366–1919'; return '1920+';
    }

    /** [device, browser, os] from the user agent (+ screen width hint). */
    public static function parseUa(string $ua, int $screenW = 0): array
    {
        $u = strtolower($ua);
        $device = 'desktop';
        if (preg_match('~ipad|tablet|kindle|silk|playbook~', $u) || (str_contains($u, 'android') && !str_contains($u, 'mobile'))) $device = 'tablet';
        elseif (preg_match('~mobi|iphone|ipod|android|windows phone|blackberry|opera mini~', $u)) $device = 'mobile';
        elseif ($screenW && $screenW < 768) $device = 'mobile';
        $os = 'Other';
        if (str_contains($u, 'windows')) $os = 'Windows'; elseif (str_contains($u, 'android')) $os = 'Android'; elseif (preg_match('~iphone|ipad|ipod~', $u)) $os = 'iOS'; elseif (str_contains($u, 'mac os')) $os = 'macOS'; elseif (str_contains($u, 'cros')) $os = 'ChromeOS'; elseif (str_contains($u, 'linux')) $os = 'Linux';
        $browser = 'Other';
        if (str_contains($u, 'edg/') || str_contains($u, 'edge/')) $browser = 'Edge'; elseif (str_contains($u, 'opr/') || str_contains($u, 'opera')) $browser = 'Opera'; elseif (str_contains($u, 'samsungbrowser')) $browser = 'Samsung Internet'; elseif (str_contains($u, 'firefox/')) $browser = 'Firefox'; elseif (str_contains($u, 'chrome/') || str_contains($u, 'crios/')) $browser = 'Chrome'; elseif (str_contains($u, 'safari/')) $browser = 'Safari'; elseif (str_contains($u, 'msie') || str_contains($u, 'trident/')) $browser = 'Internet Explorer';
        return [$device, $browser, $os];
    }

    /** Keyed, one-way hash of an IP (32 hex chars). Plain md5(ip) is brute-forceable over the IPv4 space; the APP_KEY salt makes
     *  the stored hashes (geo cache, flood counters, storage-less visitor ids) useless without the server secret. */
    public static function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, defined('APP_KEY') ? APP_KEY : 'om-analytics'), 0, 32);
    }

    /** Client IP – honours proxy / CDN headers only when analytics_trust_proxy = 1. */
    public static function clientIp(): string
    {
        if (setting('analytics_trust_proxy', 0)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                if (!empty($_SERVER[$h])) { $ip = trim(explode(',', $_SERVER[$h])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Country / region / city for an IP (cached 30 days by hashed IP; CDN headers first; timezone as a last resort). */
    public static function geo(string $ip, string $tz = ''): array
    {
        $none = ['country' => null, 'region' => null, 'city' => null];
        // Cloudflare's country header is only trustworthy behind Cloudflare (same switch as the proxy IP headers) – otherwise anyone could spoof it
        if (setting('analytics_trust_proxy', 0) && !empty($_SERVER['HTTP_CF_IPCOUNTRY']) && preg_match('~^[A-Z]{2}$~', $_SERVER['HTTP_CF_IPCOUNTRY'])) $none['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
        $private = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($private || !setting('analytics_geo_lookup', 1)) return $none['country'] ? $none : self::tzCountry($tz) + $none;
        $h = self::ipHash($ip);
        $row = DB::fetch("SELECT country, region, city FROM analytics_geo_cache WHERE ip_hash = ? AND looked_up_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", [$h]);
        if ($row) return $row;
        $slot = 'analytics:geo:' . date('YmdHi');
        $n = (int) Cache::remember($slot, 60, fn() => 0);
        if ($n >= 40) return self::tzCountry($tz) + $none; // free ip-api.com allowance: 45 / minute
        Cache::set($slot, $n + 1, 60);
        $res = $none;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1.5, 'ignore_errors' => true]]);
            $json = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,regionName,city', false, $ctx);
            $d = $json ? json_decode($json, true) : null;
            if (($d['status'] ?? '') === 'success') $res = ['country' => $d['countryCode'] ?: null, 'region' => mb_substr((string) $d['regionName'], 0, 80) ?: null, 'city' => mb_substr((string) $d['city'], 0, 80) ?: null];
        } catch (Throwable $e) {}
        if (!$res['country']) $res = self::tzCountry($tz) + $res;
        DB::query("INSERT INTO analytics_geo_cache (ip_hash, country, region, city, looked_up_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE country = VALUES(country), region = VALUES(region), city = VALUES(city), looked_up_at = NOW()", [$h, $res['country'], $res['region'], $res['city']]);
        return $res;
    }

    /** Rough country from an IANA timezone (used when no lookup is possible). */
    public static function tzCountry(string $tz): array
    {
        $map = ['Asia/Kolkata' => 'IN', 'Asia/Calcutta' => 'IN', 'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Denver' => 'US', 'America/Los_Angeles' => 'US', 'America/Phoenix' => 'US', 'Europe/London' => 'GB', 'Europe/Berlin' => 'DE', 'Europe/Paris' => 'FR', 'Europe/Madrid' => 'ES', 'Europe/Rome' => 'IT', 'Europe/Amsterdam' => 'NL', 'Asia/Dubai' => 'AE', 'Asia/Singapore' => 'SG', 'Asia/Tokyo' => 'JP', 'Asia/Shanghai' => 'CN', 'Asia/Hong_Kong' => 'HK', 'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU', 'Australia/Perth' => 'AU', 'America/Toronto' => 'CA', 'America/Vancouver' => 'CA', 'America/Sao_Paulo' => 'BR', 'Africa/Johannesburg' => 'ZA', 'Asia/Karachi' => 'PK', 'Asia/Dhaka' => 'BD', 'Asia/Colombo' => 'LK', 'Asia/Kathmandu' => 'NP', 'Asia/Riyadh' => 'SA', 'Asia/Qatar' => 'QA', 'Asia/Kuala_Lumpur' => 'MY', 'Asia/Jakarta' => 'ID', 'Asia/Manila' => 'PH', 'Asia/Bangkok' => 'TH', 'Asia/Seoul' => 'KR', 'Europe/Dublin' => 'IE', 'Europe/Zurich' => 'CH', 'Europe/Stockholm' => 'SE', 'Europe/Oslo' => 'NO', 'Europe/Warsaw' => 'PL', 'Europe/Istanbul' => 'TR', 'Pacific/Auckland' => 'NZ', 'America/Mexico_City' => 'MX', 'Africa/Lagos' => 'NG', 'Africa/Nairobi' => 'KE', 'Africa/Cairo' => 'EG', 'Asia/Ho_Chi_Minh' => 'VN', 'Europe/Moscow' => 'RU'];
        return ['country' => $map[$tz] ?? null];
    }

    /* ---------- reading ---------- */

    /** Resolve a range key or custom dates (clamped to the plan's retention) → [from, to, days]. */
    public static function range(string $r, ?string $from = null, ?string $to = null, ?int $tenantId = null): array
    {
        $maxDays = self::retentionDays($tenantId);
        if ($r === 'custom' && $from && $to && strtotime($from) && strtotime($to)) {
            $f = date('Y-m-d', strtotime($from)); $t = min(date('Y-m-d'), date('Y-m-d', strtotime($to)));
            if ($f > $t) [$f, $t] = [$t, $f];
            $days = (int) ((strtotime($t) - strtotime($f)) / 86400) + 1;
            if ($days > $maxDays) { $days = $maxDays; $f = date('Y-m-d', strtotime($t) - ($days - 1) * 86400); }
            return [$f, $t, $days];
        }
        if ($r === 'yesterday') { $d = date('Y-m-d', time() - 86400); return [$d, $d, 1]; }
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '180d' => 180, '12m' => 365][$r] ?? 30;
        $days = min($days, $maxDays);
        return [date('Y-m-d', time() - ($days - 1) * 86400), date('Y-m-d'), $days];
    }

    /** Everything the analytics page needs for one website (cached 60 s). */
    public static function report(int $websiteId, string $from, string $to, string $level = 'full', string $geo = 'city'): array
    {
        $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        return Cache::remember("analytics:report:$websiteId:$from:$to:$level:$geo", 60, function () use ($websiteId, $from, $to, $days, $level, $geo) {
            $labels = []; $zero = [];
            for ($i = 0; $i < $days; $i++) { $d = date('Y-m-d', strtotime($from) + $i * 86400); $labels[] = $d; $zero[$d] = 0; }
            $pv = $zero; $vis = $zero; $new = $zero; $sess = $zero; $bounces = 0; $durSum = 0; $durN = 0; $events = 0;
            foreach (DB::fetchAll("SELECT day, pageviews, visitors, new_visitors, sessions, bounces, duration_sum, duration_n, events FROM analytics_daily WHERE website_id = ? AND day BETWEEN ? AND ?", [$websiteId, $from, $to]) as $r) { $d = $r['day']; if (!isset($zero[$d])) continue; $pv[$d] = (int) $r['pageviews']; $vis[$d] = (int) $r['visitors']; $new[$d] = (int) $r['new_visitors']; $sess[$d] = (int) $r['sessions']; $bounces += (int) $r['bounces']; $durSum += (int) $r['duration_sum']; $durN += (int) $r['duration_n']; $events += (int) $r['events']; }
            $tot = ['pageviews' => array_sum($pv), 'visitors' => array_sum($vis), 'new_visitors' => array_sum($new), 'sessions' => array_sum($sess), 'bounces' => $bounces, 'events' => $events];
            $tot['returning'] = max(0, $tot['visitors'] - $tot['new_visitors']);
            $tot['bounce_rate'] = $tot['sessions'] ? round($bounces / $tot['sessions'] * 100, 1) : null;
            $tot['avg_time'] = $durN ? (int) round($durSum / $durN) : null;                       // average time on a page (s)
            $tot['avg_session'] = $tot['sessions'] ? (int) round($durSum / $tot['sessions']) : null;  // engaged time per session (s)
            // unique visitors over the whole range (distinct devices) – exact while the raw events are kept, else the daily-unique sum
            $rawDays = max(7, (int) setting('analytics_raw_retention_days', 90));
            $tot['unique_visitors'] = (strtotime($from) >= time() - $rawDays * 86400) ? (int) DB::value("SELECT COUNT(DISTINCT visitor_hash) FROM analytics_events WHERE website_id = ? AND created_at BETWEEN ? AND ? AND event = 'pageview'", [$websiteId, $from . ' 00:00:00', $to . ' 23:59:59']) : $tot['visitors'];
            $prev = DB::fetch("SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS v, COALESCE(SUM(sessions),0) AS s FROM analytics_daily WHERE website_id = ? AND day BETWEEN ? AND ?", [$websiteId, date('Y-m-d', strtotime($from) - $days * 86400), date('Y-m-d', strtotime($from) - 86400)]);
            $dims = [];
            foreach (self::DIMS as $dim) {
                if ($level !== 'full' && in_array($dim, ['referrer', 'hour', 'screen'], true)) continue;
                if ($dim === 'city' && $geo !== 'city') continue;
                if ($dim === 'region' && $geo === 'country') continue;
                $dims[$dim] = DB::fetchAll("SELECT value, SUM(pageviews) AS pageviews, SUM(visitors) AS visitors FROM analytics_daily_dims WHERE website_id = ? AND dim = ? AND day BETWEEN ? AND ? GROUP BY value ORDER BY visitors DESC, pageviews DESC LIMIT " . ($dim === 'hour' ? 24 : 12), [$websiteId, $dim, $from, $to]);
            }
            $pages = DB::fetchAll("SELECT path, MAX(title) AS title, SUM(pageviews) AS pageviews, SUM(visitors) AS visitors, SUM(entries) AS entries, SUM(bounces) AS bounces, SUM(duration_sum) AS duration_sum, SUM(duration_n) AS duration_n FROM analytics_daily_pages WHERE website_id = ? AND day BETWEEN ? AND ? GROUP BY path_hash, path ORDER BY pageviews DESC LIMIT 25", [$websiteId, $from, $to]);
            foreach ($pages as &$pg) { $pg['avg_time'] = $pg['duration_n'] ? (int) round($pg['duration_sum'] / $pg['duration_n']) : null; $pg['bounce_rate'] = $pg['entries'] ? round(min($pg['bounces'], $pg['entries']) / $pg['entries'] * 100) : null; unset($pg['duration_sum'], $pg['duration_n']); }
            unset($pg);
            $landing = DB::fetchAll("SELECT path, SUM(entries) AS entries, SUM(bounces) AS bounces FROM analytics_daily_pages WHERE website_id = ? AND day BETWEEN ? AND ? AND entries > 0 GROUP BY path_hash, path ORDER BY entries DESC LIMIT 8", [$websiteId, $from, $to]);
            // exit pages from raw events (last page of each session) – only within the raw retention window
            $exits = $level === 'full' ? DB::fetchAll("SELECT e.path, COUNT(*) AS exits FROM analytics_events e JOIN (SELECT MAX(id) AS mid FROM analytics_events WHERE website_id = ? AND created_at BETWEEN ? AND ? AND event = 'pageview' GROUP BY session_hash) x ON x.mid = e.id GROUP BY e.path ORDER BY exits DESC LIMIT 8", [$websiteId, $from . ' 00:00:00', $to . ' 23:59:59']) : [];
            // heatmap: weekday × hour from raw events (cheap: indexed range, grouped)
            $heat = [];
            if ($level === 'full') foreach (DB::fetchAll("SELECT WEEKDAY(created_at) AS d, HOUR(created_at) AS h, COUNT(*) AS n FROM analytics_events WHERE website_id = ? AND created_at BETWEEN ? AND ? AND event = 'pageview' GROUP BY d, h", [$websiteId, $from . ' 00:00:00', $to . ' 23:59:59']) as $r) $heat[(int) $r['d']][(int) $r['h']] = (int) $r['n'];
            $unique = (int) DB::value("SELECT COUNT(*) FROM analytics_visitors WHERE website_id = ?", [$websiteId]);
            $rt = self::realtime($websiteId, $level === 'full');
            return [
                'labels' => array_map(fn($d) => date('d M', strtotime($d)), $labels), 'from' => $from, 'to' => $to, 'days' => $days, 'geo' => $geo,
                'series' => ['pageviews' => array_values($pv), 'visitors' => array_values($vis), 'new_visitors' => array_values($new), 'sessions' => array_values($sess)],
                'totals' => $tot + ['unique_devices' => $unique, 'active_now' => $rt['active'], 'views_per_visitor' => $tot['visitors'] ? round($tot['pageviews'] / $tot['visitors'], 1) : 0, 'prev_pageviews' => (int) ($prev['pv'] ?? 0), 'prev_visitors' => (int) ($prev['v'] ?? 0), 'prev_sessions' => (int) ($prev['s'] ?? 0)],
                'dims' => $dims, 'pages' => $pages, 'landing' => $landing, 'exits' => $exits, 'heat' => $heat, 'recent' => $rt['recent'],
            ];
        });
    }
    /** Live view: active visitors (last 5 min) with their current page / location / device / source, pages being viewed, latest page views. Not cached (cheap, indexed). */
    public static function realtime(int $websiteId, bool $full = true): array
    {
        $since = date('Y-m-d H:i:s', time() - self::REALTIME_MINUTES * 60);
        $active = (int) DB::value("SELECT COUNT(DISTINCT visitor_hash) FROM analytics_events WHERE website_id = ? AND created_at >= ? AND event = 'pageview'", [$websiteId, $since]);
        $pages = DB::fetchAll("SELECT path, COUNT(DISTINCT visitor_hash) AS n FROM analytics_events WHERE website_id = ? AND created_at >= ? AND event = 'pageview' GROUP BY path ORDER BY n DESC LIMIT 6", [$websiteId, $since]);
        $visitors = $active ? DB::fetchAll("SELECT e.path, e.title, e.referrer_host, e.source, e.country, e.region, e.city, e.device, e.browser, e.os, e.is_new_device, e.created_at FROM analytics_events e JOIN (SELECT visitor_hash, MAX(id) AS mid FROM analytics_events WHERE website_id = ? AND created_at >= ? AND event = 'pageview' GROUP BY visitor_hash) x ON x.mid = e.id ORDER BY e.id DESC LIMIT 50", [$websiteId, $since]) : [];
        $by = ['country' => [], 'device' => [], 'source' => []];
        foreach ($visitors as $v) { $by['country'][$v['country'] ?: 'XX'] = ($by['country'][$v['country'] ?: 'XX'] ?? 0) + 1; $by['device'][$v['device']] = ($by['device'][$v['device']] ?? 0) + 1; $by['source'][$v['source']] = ($by['source'][$v['source']] ?? 0) + 1; }
        foreach ($by as &$b) arsort($b);
        unset($b);
        $recent = $full ? DB::fetchAll("SELECT path, title, referrer_host, source, country, region, city, device, browser, os, is_new_device, created_at FROM analytics_events WHERE website_id = ? AND event = 'pageview' ORDER BY id DESC LIMIT 25", [$websiteId]) : [];
        return ['active' => $active, 'pages' => $pages, 'visitors' => $full ? $visitors : [], 'by' => $by, 'recent' => $recent, 'minutes' => self::REALTIME_MINUTES, 'at' => date('H:i:s')];
    }
    /** Workspace overview: per website totals for the range (cached 60 s). */
    public static function overview(int $tenantId, string $from, string $to): array
    {
        return Cache::remember("analytics:overview:$tenantId:$from:$to", 60, function () use ($tenantId, $from, $to) {
            $sites = DB::fetchAll("SELECT w.id, w.name, w.url, w.analytics_enabled, w.analytics_key, w.analytics_last_event_at, COALESCE(SUM(d.pageviews),0) AS pageviews, COALESCE(SUM(d.visitors),0) AS visitors, COALESCE(SUM(d.new_visitors),0) AS new_visitors FROM websites w LEFT JOIN analytics_daily d ON d.website_id = w.id AND d.day BETWEEN ? AND ? WHERE w.tenant_id = ? GROUP BY w.id ORDER BY pageviews DESC, w.name", [$from, $to, $tenantId]);
            $active = [];
            foreach (DB::fetchAll("SELECT website_id, COUNT(DISTINCT visitor_hash) AS n FROM analytics_events WHERE tenant_id = ? AND created_at >= ? AND event = 'pageview' GROUP BY website_id", [$tenantId, date('Y-m-d H:i:s', time() - self::REALTIME_MINUTES * 60)]) as $r) $active[$r['website_id']] = (int) $r['n'];
            $series = DB::fetchAll("SELECT day, SUM(pageviews) AS pageviews, SUM(visitors) AS visitors FROM analytics_daily WHERE tenant_id = ? AND day BETWEEN ? AND ? GROUP BY day ORDER BY day", [$tenantId, $from, $to]);
            $top = DB::fetchAll("SELECT dim, value, SUM(visitors) AS visitors FROM analytics_daily_dims d JOIN websites w ON w.id = d.website_id WHERE w.tenant_id = ? AND d.day BETWEEN ? AND ? AND d.dim IN ('country','device','source') GROUP BY dim, value ORDER BY visitors DESC", [$tenantId, $from, $to]);
            $dims = ['country' => [], 'device' => [], 'source' => []];
            foreach ($top as $r) if (count($dims[$r['dim']]) < 8) $dims[$r['dim']][] = $r;
            return ['sites' => $sites, 'active' => $active, 'series' => $series, 'dims' => $dims, 'totals' => ['pageviews' => array_sum(array_column($sites, 'pageviews')), 'visitors' => array_sum(array_column($sites, 'visitors')), 'active_now' => array_sum($active), 'tracking' => count(array_filter($sites, fn($s) => $s['analytics_enabled']))]];
        });
    }

    /** Platform-wide (Super Admin) analytics totals, cached 2 min. */
    public static function platformStats(): array
    {
        return Cache::remember('analytics:platform', 120, function () {
            $m = DB::fetch("SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS v FROM analytics_daily WHERE day >= ?", [date('Y-m-01')]);
            $d30 = DB::fetch("SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS v FROM analytics_daily WHERE day >= ?", [date('Y-m-d', time() - 29 * 86400)]);
            return [
                'tracked' => (int) DB::value("SELECT COUNT(*) FROM websites WHERE analytics_enabled = 1"),
                'active' => (int) DB::value("SELECT COUNT(*) FROM websites WHERE analytics_enabled = 1 AND analytics_last_event_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
                'pv_month' => (int) $m['pv'], 'visitors_month' => (int) $m['v'], 'pv_30d' => (int) $d30['pv'], 'visitors_30d' => (int) $d30['v'],
                'events' => (int) DB::value("SELECT COUNT(*) FROM analytics_events"),
                'devices' => (int) DB::value("SELECT COUNT(*) FROM analytics_visitors"),
                'top' => DB::fetchAll("SELECT w.id, w.name, t.name AS tenant_name, SUM(d.pageviews) AS pageviews, SUM(d.visitors) AS visitors FROM analytics_daily d JOIN websites w ON w.id = d.website_id JOIN tenants t ON t.id = w.tenant_id WHERE d.day >= ? GROUP BY w.id ORDER BY pageviews DESC LIMIT 8", [date('Y-m-d', time() - 29 * 86400)]),
                'by_tenant' => DB::fetchAll("SELECT t.id, t.name, p.name AS plan_name, p.max_pageviews_month, SUM(d.pageviews) AS pageviews FROM analytics_daily d JOIN tenants t ON t.id = d.tenant_id LEFT JOIN plans p ON p.id = t.plan_id WHERE d.day >= ? GROUP BY t.id ORDER BY pageviews DESC LIMIT 10", [date('Y-m-01')]),
            ];
        });
    }

    /** Fetch the website's home page and look for the snippet + key. */
    public static function verifyInstall(array $w): array
    {
        $r = ['found' => false, 'key_ok' => false, 'http' => 0, 'message' => ''];
        try {
            // SSRF guard: only http(s) URLs that resolve to public addresses are fetched, and every redirect hop is re-validated
            $url = (string) $w['url']; $html = ''; $hops = 0;
            while (true) {
                if (!self::publicHttpUrl($url)) { $r['message'] = 'Only public http(s) websites can be verified (' . e(host_from_url($url)) . ' is not a public address).'; return $r; }
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 0, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'OutlineMonitor-AnalyticsVerify/1.0']);
                $html = (string) curl_exec($ch); $r['http'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
                if ($r['http'] >= 300 && $r['http'] < 400 && $next !== '' && $hops < 4) { $url = $next; $hops++; continue; }
                break;
            }
            if ($r['http'] < 200 || $r['http'] >= 400 || $html === '') { $r['message'] = 'The website could not be fetched (HTTP ' . $r['http'] . ').'; return $r; }
            $r['found'] = (bool) preg_match('~/t\.js[^>]*data-site=|data-site=[^>]*/t\.js|/analytics/[A-Za-z0-9-]{8,24}\.js~i', $html);
            $r['key_ok'] = $r['found'] && $w['analytics_key'] && str_contains($html, $w['analytics_key']);
            $r['message'] = !$r['found'] ? 'Snippet not found on the home page. Add it inside <head> and try again.' : (!$r['key_ok'] ? 'A tracking snippet was found but with a different site key – update it to the key shown here.' : 'Installation detected – the snippet with the correct key is present.');
        } catch (Throwable $e) { $r['message'] = 'Verification failed: ' . $e->getMessage(); }
        return $r;
    }

    /** True for http(s) URLs whose host resolves to a public (non-private, non-loopback, non-link-local) address. */
    public static function publicHttpUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
        $host = strtolower(trim($p['host'], '[]'));
        if (defined('APP_ENV') && APP_ENV === 'development' && in_array($host, ['localhost', '127.0.0.1'], true)) return true; // local test sites during development only
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(array_column((array) @dns_get_record($host, DNS_A), 'ip'), array_column((array) @dns_get_record($host, DNS_AAAA), 'ipv6'));
        if (!$ips) return false;
        foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        return true;
    }

    /** CSV export (Business+): daily series, pages and dimensions for the range. */
    public static function exportCsv(array $rep, string $siteName): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Website', $siteName, 'From', $rep['from'], 'To', $rep['to']]);
        fputcsv($out, []); fputcsv($out, ['Day', 'Visitors', 'Page views', 'Sessions', 'New devices']);
        foreach ($rep['labels'] as $i => $l) fputcsv($out, [$l, $rep['series']['visitors'][$i], $rep['series']['pageviews'][$i], $rep['series']['sessions'][$i], $rep['series']['new_visitors'][$i]]);
        fputcsv($out, []); fputcsv($out, ['Page', 'Visitors', 'Views', 'Entries', 'Avg time (s)', 'Bounce rate %']);
        foreach ($rep['pages'] as $p) fputcsv($out, [$p['path'], $p['visitors'], $p['pageviews'], $p['entries'], $p['avg_time'] ?? '', $p['bounce_rate'] ?? '']);
        foreach ($rep['dims'] as $dim => $rows) { if (!$rows) continue; fputcsv($out, []); fputcsv($out, [ucfirst($dim), 'Visitors', 'Page views']); foreach ($rows as $r) fputcsv($out, [$r['value'], $r['visitors'], $r['pageviews']]); }
        rewind($out); $csv = stream_get_contents($out); fclose($out);
        return $csv;
    }

    /** Housekeeping: raw events (global raw retention), daily tables per plan retention, geo cache, day sets. */
    public static function prune(): array
    {
        $out = ['events' => 0, 'daily' => 0, 'geo' => 0, 'visitor_days' => 0];
        $raw = max(7, (int) setting('analytics_raw_retention_days', 90));
        do { $n = DB::query("DELETE FROM analytics_events WHERE created_at < ? LIMIT 20000", [date('Y-m-d H:i:s', time() - $raw * 86400)])->rowCount(); $out['events'] += $n; } while ($n === 20000);
        // plan-based history: every tenant keeps only the analytics history its plan includes (+ platform maximum 2 years)
        $out['visitors'] = 0;
        foreach (DB::fetchAll("SELECT id FROM tenants") as $t) {
            $days = min(730, self::retentionDays((int) $t['id']));
            $cut = date('Y-m-d', time() - $days * 86400);
            // raw events and known devices never outlive the plan's retention either (the global raw window is only the upper bound)
            $rawCut = date('Y-m-d H:i:s', time() - min($raw, $days) * 86400);
            do { $n = DB::query("DELETE FROM analytics_events WHERE created_at < ? AND website_id IN (SELECT id FROM websites WHERE tenant_id = ?) LIMIT 20000", [$rawCut, $t['id']])->rowCount(); $out['events'] += $n; } while ($n === 20000);
            $out['visitors'] += DB::query("DELETE FROM analytics_visitors WHERE last_seen_at < ? AND website_id IN (SELECT id FROM websites WHERE tenant_id = ?)", [$cut . ' 00:00:00', $t['id']])->rowCount();
            $out['daily'] += DB::query("DELETE FROM analytics_daily WHERE tenant_id = ? AND day < ?", [$t['id'], $cut])->rowCount();
            $out['daily'] += DB::query("DELETE d FROM analytics_daily_dims d JOIN websites w ON w.id = d.website_id WHERE w.tenant_id = ? AND d.day < ?", [$t['id'], $cut])->rowCount();
            $out['daily'] += DB::query("DELETE d FROM analytics_daily_pages d JOIN websites w ON w.id = d.website_id WHERE w.tenant_id = ? AND d.day < ?", [$t['id'], $cut])->rowCount();
        }
        $out['geo'] = DB::query("DELETE FROM analytics_geo_cache WHERE looked_up_at < ?", [date('Y-m-d H:i:s', time() - 60 * 86400)])->rowCount();
        $out['visitor_days'] = DB::query("DELETE FROM analytics_visitors_daily WHERE day < ?", [date('Y-m-d', time() - 3 * 86400)])->rowCount() + DB::query("DELETE FROM analytics_sessions_daily WHERE day < ?", [date('Y-m-d', time() - 3 * 86400)])->rowCount() + DB::query("DELETE FROM analytics_page_visitors_daily WHERE day < ?", [date('Y-m-d', time() - 3 * 86400)])->rowCount();
        return $out;
    }

    /** Country code → [flag emoji, name]. */
    public static function country(string $code): array
    {
        $names = ['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'United Arab Emirates', 'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'SG' => 'Singapore', 'NL' => 'Netherlands', 'ES' => 'Spain', 'IT' => 'Italy', 'BR' => 'Brazil', 'JP' => 'Japan', 'CN' => 'China', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'NP' => 'Nepal', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'MY' => 'Malaysia', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'EG' => 'Egypt', 'TR' => 'Turkey', 'RU' => 'Russia', 'MX' => 'Mexico', 'SE' => 'Sweden', 'NO' => 'Norway', 'PL' => 'Poland', 'IE' => 'Ireland', 'CH' => 'Switzerland', 'NZ' => 'New Zealand', 'KR' => 'South Korea', 'HK' => 'Hong Kong', 'TH' => 'Thailand', 'VN' => 'Vietnam', 'XX' => 'Unknown'];
        $c = strtoupper($code);
        if (!preg_match('~^[A-Z]{2}$~', $c) || $c === 'XX') return ['<i class="bi bi-globe2 text-muted"></i>', $names['XX']];
        // flag images (Windows has no flag emoji); tiny PNGs from flagcdn.com, lazy-loaded
        $flag = '<img src="https://flagcdn.com/16x12/' . strtolower($c) . '.png" width="16" height="12" alt="" loading="lazy" style="vertical-align:-1px;border-radius:2px">';
        return [$flag, $names[$c] ?? $c];
    }
}
