<?php
/** Status pages: create / edit / delete (plan-limited; white-label options on Business / Agency). */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
require_post();
Auth::requireAbility('status_pages');
data_changed();
$tid = Tenant::id();
$action = post('action', '');

switch ($action) {
    case 'save':
        $id = post_int('id');
        $existing = $id ? own_or_404('status_pages', $id, 'Status page') : null;
        $name = trim((string) post('name', ''));
        $slug = strtolower(trim((string) post('slug', '')));
        $slug = trim(preg_replace('~[^a-z0-9-]+~', '-', $slug ?: $name), '-');
        $ids = array_values(array_filter(array_map('intval', (array) post('website_ids', []))));
        if ($ids) $ids = array_map('intval', array_column(DB::fetchAll("SELECT id FROM websites WHERE tenant_id = ? AND id IN (" . implode(',', $ids) . ")", [$tid]), 'id'));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'Name is required.';
        if ($slug === '' || strlen($slug) < 3 || strlen($slug) > 60 || in_array($slug, ['api', 'status', 'platform', 'login', 'admin'], true)) $errors['slug'] = 'Use 3-60 letters, numbers or dashes.';
        elseif (DB::value("SELECT id FROM status_pages WHERE slug = ? AND id <> ?", [$slug, $id ?: 0])) $errors['slug'] = 'This address is already taken.';
        $custom = post_nullable('custom_domain') ? strtolower(preg_replace('~^https?://~i', '', trim(post('custom_domain')))) : null;
        if ($custom !== null && !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $custom)) $errors['custom_domain'] = 'Enter a hostname like status.example.com';
        if ($custom !== null && !Tenant::feature('custom_domain')) $errors['custom_domain'] = 'Custom domains are available on the Business plan and above.';
        $hideBranding = post('hide_branding') ? 1 : 0;
        if ($hideBranding && !Tenant::feature('white_label')) $hideBranding = 0;
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        $data = ['name' => $name, 'slug' => $slug, 'description' => post_nullable('description'), 'is_public' => post('is_public') ? 1 : 0, 'custom_domain' => $custom, 'website_ids' => $ids ? json_encode($ids) : null,
            'show_forms' => post('show_forms') ? 1 : 0, 'show_ssl' => post('show_ssl') ? 1 : 0, 'show_incidents' => post('show_incidents') ? 1 : 0, 'show_uptime' => post('show_uptime') ? 1 : 0,
            'theme_color' => preg_match('~^#[0-9a-fA-F]{6}$~', (string) post('theme_color')) ? post('theme_color') : '#FCAF17', 'footer_text' => post_nullable('footer_text'), 'hide_branding' => $hideBranding];
        if ($existing) {
            DB::update('status_pages', $data, 'id = ?', [$id]);
            ActivityLog::add('status_page_updated', 'Status page updated: ' . $name);
            json_success('Status page saved.', ['id' => $id]);
        }
        $can = Tenant::canAdd('status_pages');
        if (!$can['ok']) json_error($can['limit'] === 0 ? 'Status pages are available on the Professional plan and above. Upgrade to publish a status page.' : $can['message'], 403, ['upgrade' => true]);
        $id = DB::insert('status_pages', $data + ['tenant_id' => $tid, 'created_at' => date('Y-m-d H:i:s')]);
        Tenant::usage(null, true);
        ActivityLog::add('status_page_created', 'Status page created: ' . $name . ' (/status/' . $slug . ')');
        json_success('Status page created.', ['id' => $id, 'url' => url('status/public.php?slug=' . $slug)]);

    case 'get':
        $p = own_or_404('status_pages', (int) post_int('id'), 'Status page');
        $p['website_ids'] = json_decode((string) $p['website_ids'], true) ?: [];
        json_success('OK', ['page' => $p]);

    case 'delete':
        $p = own_or_404('status_pages', (int) post_int('id'), 'Status page');
        DB::delete('status_pages', 'id = ?', [$p['id']]);
        Tenant::usage(null, true);
        ActivityLog::add('status_page_deleted', 'Status page deleted: ' . $p['name']);
        json_success('Status page deleted.');

    default:
        json_error('Unknown action.');
}
