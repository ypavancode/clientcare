<?php
/**
 * Type-ahead lookups for large select lists (clients, websites, users, forms).
 * Replaces rendering 50,000 <option> tags: the browser asks for the 15 best matches as the user types.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$type = get('type', '');
$q = trim((string) get('q', ''));
$id = (int) get('id', 0);            // resolve a single id to its label
$clientId = (int) get('client_id', 0); // dependent filter for websites/forms
$like = $q . '%';
$likeAny = '%' . $q . '%';
$limit = 15;
$items = [];

switch ($type) {
    case 'clients':
        if ($id) {
            $r = DB::fetch("SELECT id, name, company FROM clients WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
            if ($r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name'] . ($r['company'] ? ' · ' . $r['company'] : '')];
            break;
        }
        if ($q === '') {
            $rows = DB::fetchAll("SELECT id, name, company FROM clients WHERE tenant_id = " . Tenant::id() . " AND status <> 'archived' ORDER BY id DESC LIMIT $limit");
        } elseif (mb_strlen($q) >= 3 && preg_match('#^[\w\s.\-@]+$#u', $q)) {
            // FULLTEXT prefix search – indexed, fast on 50k+ clients
            $ft = implode(' ', array_map(fn($w) => '+' . $w . '*', array_filter(preg_split('#\s+#', $q))));
            $rows = DB::fetchAll("SELECT id, name, company FROM clients WHERE tenant_id = " . Tenant::id() . " AND status <> 'archived' AND MATCH(name, company, email) AGAINST (? IN BOOLEAN MODE) ORDER BY name LIMIT $limit", [$ft]);
        } else {
            $rows = DB::fetchAll("SELECT id, name, company FROM clients WHERE tenant_id = " . Tenant::id() . " AND status <> 'archived' AND name LIKE ? ORDER BY name LIMIT $limit", [$like]);
        }
        if ($q !== '' && count($rows) < 3) {
            $rows = DB::fetchAll("SELECT id, name, company FROM clients WHERE tenant_id = " . Tenant::id() . " AND status <> 'archived' AND (name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ?) ORDER BY name LIMIT $limit", [$likeAny, $likeAny, $likeAny, $likeAny]);
        }
        foreach ($rows as $r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name'] . ($r['company'] ? ' · ' . $r['company'] : '')];
        break;

    case 'websites':
        if ($id) {
            $r = DB::fetch("SELECT id, name, url, client_id FROM websites WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
            if ($r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name'] . ' · ' . host_from_url($r['url']), 'client_id' => (int) $r['client_id']];
            break;
        }
        $where = ['w.tenant_id = ' . Tenant::id()];
        $params = [];
        if ($clientId) { $where[] = "w.client_id = ?"; $params[] = $clientId; }
        if ($q !== '' && !$clientId && mb_strlen($q) >= 3 && preg_match('#^[\w\s.\-]+$#u', $q)) {
            $where[] = "MATCH(w.name, w.url) AGAINST (? IN BOOLEAN MODE)";
            $params[] = implode(' ', array_map(fn($w) => '+' . $w . '*', array_filter(preg_split('#\s+#', $q))));
        } elseif ($q !== '') { $where[] = "(w.name LIKE ? OR w.url LIKE ?)"; $params[] = $like; $params[] = $likeAny; }
        $rows = DB::fetchAll("SELECT w.id, w.name, w.url, w.client_id FROM websites w WHERE " . implode(' AND ', $where) . " ORDER BY w.name LIMIT $limit", $params);
        foreach ($rows as $r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name'] . ' · ' . host_from_url($r['url']), 'client_id' => (int) $r['client_id']];
        break;

    case 'users':
        if ($id) {
            $r = DB::fetch("SELECT id, name FROM users WHERE id = ? AND tenant_id = ?", [$id, Tenant::id()]);
            if ($r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name']];
            break;
        }
        $rows = $q === ''
            ? DB::fetchAll("SELECT id, name FROM users WHERE tenant_id = " . Tenant::id() . " AND status = 'active' ORDER BY name LIMIT $limit")
            : DB::fetchAll("SELECT id, name FROM users WHERE tenant_id = " . Tenant::id() . " AND status = 'active' AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT $limit", [$likeAny, $likeAny]);
        foreach ($rows as $r) $items[] = ['id' => (int) $r['id'], 'label' => $r['name']];
        break;

    default:
        json_error('Unknown lookup type.');
}
json_response(['success' => true, 'items' => $items]);
