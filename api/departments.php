<?php
/** Department (industry) and Website Type management – admin only. */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
Auth::requireRole('admin');

$action = post('action', '');
$kind = post('kind') === 'type' ? 'type' : 'department';
$table = $kind === 'type' ? 'website_types' : 'departments';
$label = $kind === 'type' ? 'Website type' : 'Department';

switch ($action) {
    case 'save':
        $id = post_int('id');
        $name = trim(post('name', ''));
        if ($name === '' || mb_strlen($name) > 100) json_error('Name is required (max 100 characters).', 422, ['errors' => ['name' => 'Required']]);
        $data = ['name' => $name, 'sort_order' => max(0, min(9999, post_int('sort_order', 100) ?? 100)), 'status' => post('status') === 'inactive' ? 'inactive' : 'active'];
        if ($kind === 'type') {
            $data['department_id'] = post_int('department_id') ?: null;
            if ($data['department_id'] && !DB::value("SELECT id FROM departments WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)", [$data['department_id'], Tenant::id()])) json_error('Select a valid department.');
        }
        $dupSql = $kind === 'type' ? "SELECT id FROM website_types WHERE name = ? AND (department_id <=> ?) AND id <> ?" : "SELECT id FROM departments WHERE name = ? AND id <> ?";
        $dupParams = $kind === 'type' ? [$name, $data['department_id'], $id ?: 0] : [$name, $id ?: 0];
        if (DB::value($dupSql, $dupParams)) json_error($label . ' "' . $name . '" already exists.', 422, ['errors' => ['name' => 'Already exists']]);
        if ($id) {
            if (!DB::value("SELECT id FROM $table WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()])) json_error($label . ' not found or read-only (platform default).', 404);
            DB::update($table, $data, 'id = ?', [$id]);
            $msg = $label . ' updated.';
        } else {
            $id = DB::insert($table, $data + ['tenant_id' => Tenant::id()]);
            $msg = $label . ' added.';
        }
        taxonomy_changed();
        ActivityLog::add('taxonomy_changed', $label . ' saved: ' . $name);
        json_success($msg, ['id' => $id]);

    case 'toggle':
        $id = post_int('id');
        $row = DB::fetch("SELECT * FROM $table WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
        if (!$row) json_error($label . ' not found.', 404);
        $new = $row['status'] === 'active' ? 'inactive' : 'active';
        DB::update($table, ['status' => $new], 'id = ?', [$id]);
        taxonomy_changed();
        ActivityLog::add('taxonomy_changed', $label . ' ' . $row['name'] . ' set to ' . $new);
        json_success($label . ($new === 'active' ? ' activated.' : ' deactivated.'));

    case 'delete':
        $id = post_int('id');
        $row = DB::fetch("SELECT * FROM $table WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
        if (!$row) json_error($label . ' not found.', 404);
        $col = $kind === 'type' ? 'website_type_id' : 'department_id';
        $used = (int) DB::value("SELECT COUNT(*) FROM website_projects WHERE $col = ?", [$id]);
        if ($used > 0) json_error($label . ' "' . $row['name'] . '" is used by ' . $used . ' project(s). Deactivate it instead, or move those projects first.');
        if ($kind === 'department' && (int) DB::value("SELECT COUNT(*) FROM website_types WHERE department_id = ?", [$id]) > 0) json_error('This department still has website types. Delete or move them first.');
        DB::delete($table, 'id = ?', [$id]);
        taxonomy_changed();
        ActivityLog::add('taxonomy_changed', $label . ' deleted: ' . $row['name']);
        json_success($label . ' deleted.');

    case 'get':
        $row = DB::fetch("SELECT * FROM $table WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)", [post_int('id'), Tenant::id()]);
        if (!$row) json_error($label . ' not found.', 404);
        json_success('OK', ['item' => $row]);

    default:
        json_error('Unknown action.');
}
