<?php
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action', '') : get('action', '');

switch ($action) {
    case 'recent':
        $rows = DB::fetchAll("SELECT id, type, category, title, message, is_read, created_at FROM notifications WHERE tenant_id = " . Tenant::id() . " ORDER BY is_read ASC, created_at DESC LIMIT 8");
        foreach ($rows as &$r) $r['time_ago'] = time_ago($r['created_at']);
        json_response(['success' => true, 'items' => $rows, 'unread' => (int) DB::value("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND tenant_id = " . Tenant::id() . "")]);

    case 'count':
        // Heartbeat: unread badge + background-processing state. If no cron/worker is alive, a short scheduler tick
        // runs AFTER this response has been sent (see Scheduler::heartbeatAfterResponse) so monitoring never stops.
        $unread = (int) DB::value("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND tenant_id = " . Tenant::id());
        $state = Scheduler::state();
        $payload = json_encode(['success' => true, 'unread' => $unread, 'scheduler' => $state['mode'], 'healthy' => $state['healthy']]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($payload));
        header('Connection: close');
        echo $payload;
        Scheduler::heartbeatAfterResponse();
        exit;

    case 'mark_read':
        require_post();
data_changed();
        $id = post_int('id');
        if (!DB::update('notifications', ['is_read' => 1], 'id = ? AND tenant_id = ?', [$id, Tenant::id()]) && !DB::value("SELECT 1 FROM notifications WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()])) json_error('Notification not found.', 404);
        json_success('Marked as read.');

    case 'mark_unread':
        require_post();
        DB::update('notifications', ['is_read' => 0], 'id = ? AND tenant_id = ?', [post_int('id'), Tenant::id()]);
        json_success('Marked as unread.');

    case 'mark_all_read':
        require_post();
        data_changed();
        DB::query("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND tenant_id = ?", [Tenant::id()]);
        json_success('All notifications marked as read.');

    case 'delete':
        require_post();
        data_changed();
        if (!DB::delete('notifications', 'id = ? AND tenant_id = ?', [post_int('id'), Tenant::id()])) json_error('Notification not found.', 404);
        json_success('Notification deleted.');

    case 'clear_read':
        require_post();
        Auth::requireRole('admin', 'manager');
        $n = DB::delete('notifications', 'is_read = 1 AND tenant_id = ?', [Tenant::id()]);
        json_success($n . ' read notification(s) cleared.');

    default:
        json_error('Unknown action.');
}
