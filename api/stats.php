<?php
/**
 * Chart / analytics data for the dashboards (lazy-loaded by the browser after the page has painted).
 *   GET api/stats?action=charts&days=30        workspace charts (tenant scoped, cached)
 *   GET api/stats?action=platform&days=30      platform analytics (Super Admin only)
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$action = get('action', 'charts');
$days = max(7, min(90, (int) get('days', 30)));
switch ($action) {
    case 'charts':
        json_response(['success' => true] + Stats::charts($days));
    case 'platform':
        Auth::requirePlatformAdmin();
        json_response(['success' => true] + Stats::platform($days));
    default:
        json_error('Unknown action.', 404);
}
