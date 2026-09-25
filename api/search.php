<?php
/**
 * Global search. Uses InnoDB FULLTEXT indexes (fast on hundreds of thousands of rows) with a prefix LIKE
 * fallback for very short terms; every group is limited so the response stays small.
 * Every query is scoped to the caller's workspace (tenant) – a Super Admin "viewing as" a customer searches that workspace.
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$q = trim((string) get('q', ''));
if (mb_strlen($q) < 2) json_response(['success' => true, 'groups' => []]);
$tid = Tenant::id();
if ($tid <= 0) json_response(['success' => true, 'groups' => []]);

// Boolean-mode full-text query: each word must match as a prefix ("out*" finds "outline")
// Only letters, digits, '.', '_' and '-' reach the boolean-mode parser (operators such as + - < > ( ) ~ * " @ % would break the query)
$words = array_filter(preg_split('#\s+#', trim((string) preg_replace('#[^\p{L}\p{N}._-]+#u', ' ', $q))), fn($w) => $w !== '' && preg_match('#[\p{L}\p{N}]#u', $w));
$ft = '';
foreach ($words as $w) { if (mb_strlen($w) >= 2) $ft .= '+' . $w . '* '; }
$ft = trim($ft);
$useFt = $ft !== '' && mb_strlen($q) >= 3;
$esc = addcslashes($q, '%_\\'); // LIKE wildcards typed by the user are matched literally
$like = $esc . '%';
$likeAny = '%' . $esc . '%';

$groups = [];

$rows = $useFt
    ? DB::fetchAll("SELECT id, name, company, status FROM clients WHERE tenant_id = ? AND MATCH(name, company, email) AGAINST (? IN BOOLEAN MODE) ORDER BY name LIMIT 6", [$tid, $ft])
    : DB::fetchAll("SELECT id, name, company, status FROM clients WHERE tenant_id = ? AND (name LIKE ? OR company LIKE ? OR email LIKE ?) ORDER BY name LIMIT 6", [$tid, $like, $like, $like]);
if (!$rows) $rows = DB::fetchAll("SELECT id, name, company, status FROM clients WHERE tenant_id = ? AND (phone LIKE ? OR email LIKE ?) ORDER BY name LIMIT 6", [$tid, $likeAny, $likeAny]);
$groups['Clients'] = array_map(fn($r) => ['label' => $r['name'] . ($r['company'] ? ' · ' . $r['company'] : ''), 'meta' => ucfirst($r['status']), 'icon' => 'bi-person', 'url' => 'clients/view.php?id=' . $r['id']], $rows);

$rows = $useFt
    ? DB::fetchAll("SELECT id, name, url FROM websites WHERE tenant_id = ? AND MATCH(name, url) AGAINST (? IN BOOLEAN MODE) ORDER BY name LIMIT 6", [$tid, $ft])
    : DB::fetchAll("SELECT id, name, url FROM websites WHERE tenant_id = ? AND (name LIKE ? OR url LIKE ?) ORDER BY name LIMIT 6", [$tid, $like, $likeAny]);
if (!$rows && $useFt) $rows = DB::fetchAll("SELECT id, name, url FROM websites WHERE tenant_id = ? AND url LIKE ? ORDER BY name LIMIT 6", [$tid, $likeAny]);
$groups['Websites'] = array_map(fn($r) => ['label' => $r['name'], 'meta' => host_from_url($r['url']), 'icon' => 'bi-globe2', 'url' => 'websites/view.php?id=' . $r['id']], $rows);

$rows = DB::fetchAll("SELECT p.id, p.title, p.path, p.url, p.status, p.website_id, w.name AS website_name FROM website_pages p JOIN websites w ON w.id = p.website_id WHERE p.tenant_id = ? AND p.is_active = 1 AND (p.url LIKE ? OR p.title LIKE ?) ORDER BY p.status, p.priority LIMIT 6", [$tid, $likeAny, $likeAny]);
$groups['Pages'] = array_map(fn($r) => ['label' => ($r['title'] ?: Monitor::pathLabel($r['path'])) . ' · ' . $r['website_name'], 'meta' => in_array($r['status'], ['online', 'redirecting'], true) ? 'working' : ($r['status'] === 'unknown' ? 'not checked' : 'FAILED'), 'icon' => 'bi-file-earmark-text', 'url' => 'websites/view.php?id=' . $r['website_id'] . '&tab=pages&highlight=' . $r['id']], $rows);

$rows = $useFt
    ? DB::fetchAll("SELECT id, domain_name, registrar FROM domains WHERE tenant_id = ? AND MATCH(domain_name, registrar) AGAINST (? IN BOOLEAN MODE) ORDER BY domain_name LIMIT 5", [$tid, $ft])
    : DB::fetchAll("SELECT id, domain_name, registrar FROM domains WHERE tenant_id = ? AND domain_name LIKE ? ORDER BY domain_name LIMIT 5", [$tid, $like]);
if (!$rows && $useFt) $rows = DB::fetchAll("SELECT id, domain_name, registrar FROM domains WHERE tenant_id = ? AND domain_name LIKE ? ORDER BY domain_name LIMIT 5", [$tid, $likeAny]);
$groups['Domains'] = array_map(fn($r) => ['label' => $r['domain_name'], 'meta' => $r['registrar'] ?: '', 'icon' => 'bi-hdd-network', 'url' => 'domains/index.php?highlight=' . $r['id']], $rows);

$rows = $useFt
    ? DB::fetchAll("SELECT f.id, f.name, f.status, f.website_id, w.name AS website_name FROM forms f JOIN websites w ON w.id = f.website_id WHERE f.tenant_id = ? AND MATCH(f.name, f.page_url, f.recipient_email) AGAINST (? IN BOOLEAN MODE) ORDER BY f.name LIMIT 6", [$tid, $ft])
    : DB::fetchAll("SELECT f.id, f.name, f.status, f.website_id, w.name AS website_name FROM forms f JOIN websites w ON w.id = f.website_id WHERE f.tenant_id = ? AND f.name LIKE ? ORDER BY f.name LIMIT 6", [$tid, $like]);
$groups['Forms'] = array_map(fn($r) => ['label' => $r['name'] . ' · ' . $r['website_name'], 'meta' => str_replace('_', ' ', $r['status']), 'icon' => 'bi-ui-checks', 'url' => 'forms/index.php?website_id=' . $r['website_id'] . '&highlight=' . $r['id']], $rows);

$rows = $useFt
    ? DB::fetchAll("SELECT p.id, p.project_name, p.status, c.name AS client_name FROM website_projects p JOIN clients c ON c.id = p.client_id WHERE p.tenant_id = ? AND MATCH(p.project_name, p.website_name, p.website_url) AGAINST (? IN BOOLEAN MODE) ORDER BY p.project_name LIMIT 6", [$tid, $ft])
    : DB::fetchAll("SELECT p.id, p.project_name, p.status, c.name AS client_name FROM website_projects p JOIN clients c ON c.id = p.client_id WHERE p.tenant_id = ? AND (p.project_name LIKE ? OR p.website_url LIKE ?) ORDER BY p.project_name LIMIT 6", [$tid, $like, $likeAny]);
if (!$rows) $rows = DB::fetchAll("SELECT p.id, p.project_name, p.status, c.name AS client_name FROM website_projects p JOIN clients c ON c.id = p.client_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id WHERE p.tenant_id = ? AND (c.name LIKE ? OR d.name LIKE ? OR t.name LIKE ?) ORDER BY p.project_name LIMIT 6", [$tid, $likeAny, $likeAny, $likeAny]);
$groups['Website Projects'] = array_map(fn($r) => ['label' => $r['project_name'] . ' · ' . $r['client_name'], 'meta' => project_status_label($r['status']), 'icon' => 'bi-kanban', 'url' => 'projects/view.php?id=' . $r['id']], $rows);

json_response(['success' => true, 'groups' => $groups]);
