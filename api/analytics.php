<?php
/** Analytics management + data endpoints (workspace users). */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$action = get('action', '') ?: post('action', '');
$loadSite = function (int $id): array { $w = DB::fetch("SELECT * FROM websites WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]); if (!$w) json_error('Website not found.', 404); return $w; };
$rangeOf = function (string $level): array {
    $r = in_array(get('range'), ['today', 'yesterday', '7d', '30d', '90d', '180d', '12m', 'custom'], true) ? get('range') : '30d';
    if ($level !== 'full' && in_array($r, ['12m', '180d', 'custom'], true)) $r = '90d';
    return Analytics::range($r, get('from'), get('to'));
};
switch ($action) {
    case 'report':
        $w = $loadSite((int) get('id', 0));
        $level = Analytics::level();
        if ($level === 'none') json_error('Visitor analytics is not included in your plan.', 403);
        [$from, $to] = $rangeOf($level);
        json_response(['success' => true, 'level' => $level, 'retention_days' => Analytics::retentionDays(), 'realtime' => Analytics::realtimeAllowed()] + Analytics::report((int) $w['id'], $from, $to, $level, Analytics::geoLevel()));

    case 'realtime':
        $w = $loadSite((int) get('id', 0));
        if (Analytics::level() === 'none') json_error('Not available on your plan.', 403);
        if (!Analytics::realtimeAllowed()) json_error('Real-time analytics is not included in your plan.', 403, ['upgrade' => true]);
        $rt = Analytics::realtime((int) $w['id'], Analytics::level() === 'full');
        if (Analytics::geoLevel() !== 'city') foreach (['visitors', 'recent'] as $k) foreach ($rt[$k] as &$row) { $row['city'] = null; if (Analytics::geoLevel() === 'country') $row['region'] = null; }
        json_response(['success' => true] + $rt);

    case 'tracking':
        // installation facts for the setup card (real data only)
        $w = $loadSite((int) get('id', 0));
        [$k, $label, $tone, $help] = Analytics::installStatus($w);
        $last = DB::fetch("SELECT path, created_at FROM analytics_events WHERE website_id = ? AND event = 'pageview' ORDER BY id DESC LIMIT 1", [$w['id']]);
        json_response(['success' => true, 'status' => $k, 'label' => $label, 'tone' => $tone, 'help' => $help, 'key' => $w['analytics_key'], 'script_url' => $w['analytics_key'] ? Analytics::scriptUrl($w['analytics_key']) : null, 'snippet' => $w['analytics_key'] ? Analytics::snippet($w['analytics_key']) : null,
            'first_activity' => $w['analytics_first_event_at'], 'last_activity' => $w['analytics_last_event_at'], 'last_page' => $last['path'] ?? null, 'last_page_at' => $last['created_at'] ?? null]);

    case 'export':
        $w = $loadSite((int) get('id', 0));
        $level = Analytics::level();
        if ($level === 'none') json_error('Not available on your plan.', 403);
        if (!Tenant::feature('reports') || in_array(Tenant::feature('reports'), ['none', 'basic'], true)) http_error(403, 'CSV export is available on the Business plan and above.');
        [$from, $to] = $rangeOf($level);
        $csv = Analytics::exportCsv(Analytics::report((int) $w['id'], $from, $to, $level, Analytics::geoLevel()), $w['name']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="analytics-' . preg_replace('~[^a-z0-9]+~i', '-', $w['name']) . '-' . $from . '-' . $to . '.csv"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;

    case 'verify':
        require_post();
        Auth::requireAbility('websites');
        $w = $loadSite((int) post_int('id'));
        if (!$w['analytics_enabled']) json_error('Enable tracking first.');
        $r = Analytics::verifyInstall($w);
        $lastEvent = DB::value("SELECT analytics_last_event_at FROM websites WHERE id = ?", [$w['id']]);
        json_response(['success' => $r['found'] && $r['key_ok'], 'message' => $r['message'] . ($lastEvent ? ' Last page view received ' . time_ago($lastEvent) . '.' : ' No page view has been received yet.'), 'found' => $r['found'], 'key_ok' => $r['key_ok'], 'http' => $r['http']]);

    case 'enable':
    case 'disable':
    case 'regenerate':
    case 'any_origin':
        require_post();
        data_changed();
        Auth::requireAbility('websites');
        if (Analytics::level() === 'none') json_error('Visitor analytics is not included in your plan – upgrade to enable tracking.', 403, ['upgrade' => true]);
        $w = $loadSite((int) post_int('id'));
        if ($action === 'enable') { $key = Analytics::enable((int) $w['id']); Cache::forget('analytics:site:' . $key); ActivityLog::add('analytics_enabled', 'Visitor analytics enabled for ' . $w['name'], ['website_id' => (int) $w['id']]); json_success('Tracking enabled. Add the script to the website to start collecting.', ['key' => $key, 'snippet' => Analytics::snippet($key), 'script_url' => Analytics::scriptUrl($key)]); }
        if ($action === 'disable') { DB::update('websites', ['analytics_enabled' => 0], 'id = ?', [$w['id']]); Cache::forget('analytics:site:' . $w['analytics_key']); ActivityLog::add('analytics_disabled', 'Visitor analytics paused for ' . $w['name'], ['website_id' => (int) $w['id']]); json_success('Tracking paused. Existing data is kept.'); }
        // a new ID invalidates the installed snippet: the activity stamps restart so the status reads "Tracking Not Detected" until the
        // first page view with the new ID arrives (collected history is kept)
        if ($action === 'regenerate') { Cache::forget('analytics:site:' . $w['analytics_key']); $key = Analytics::newKey(); DB::update('websites', ['analytics_key' => $key, 'analytics_enabled' => 1, 'analytics_first_event_at' => null, 'analytics_last_event_at' => null], 'id = ?', [$w['id']]); ActivityLog::add('analytics_key', 'Analytics tracking ID regenerated for ' . $w['name'], ['website_id' => (int) $w['id']]); json_success('New tracking ID generated – update the script on the website. The status shows Tracking Active once the first page view with the new ID arrives.', ['key' => $key, 'snippet' => Analytics::snippet($key), 'script_url' => Analytics::scriptUrl($key)]); }
        DB::update('websites', ['analytics_any_origin' => post('value') ? 1 : 0], 'id = ?', [$w['id']]); Cache::forget('analytics:site:' . $w['analytics_key']);
        json_success(post('value') ? 'Events from any domain are accepted (staging / multiple domains).' : 'Only the website\'s own domain can send events.');

    default:
        json_error('Unknown action.', 404);
}
