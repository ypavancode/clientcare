<?php
/**
 * Website Projects API: create / edit projects (new website projects and existing websites), status workflow with
 * history, client creation inline, and the link to the monitoring module (one shared websites record, never duplicated).
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action', '') : get('action', '');

function load_project(int $id): array
{
    $p = DB::fetch("SELECT p.*, c.name AS client_name FROM website_projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND p.tenant_id = ?", [$id, Tenant::id()]);
    if (!$p) json_error('Project not found.', 404);
    return $p;
}

/** Find (by client + host) or create the shared monitoring website record for a project. */
function link_website(array $project, bool $monitoring, ?int $currentWebsiteId = null): ?int
{
    if (empty($project['website_url'])) return $currentWebsiteId;
    $host = host_from_url($project['website_url']);
    $w = $currentWebsiteId ? DB::fetch("SELECT * FROM websites WHERE id = ?", [$currentWebsiteId]) : null;
    if (!$w) {
        // Reuse an existing website of the same client with the same host – never duplicate
        $w = DB::fetch("SELECT * FROM websites WHERE client_id = ? AND (url = ? OR url LIKE ? OR url LIKE ?) ORDER BY id LIMIT 1",
            [$project['client_id'], $project['website_url'], 'https://' . $host . '%', 'http://' . $host . '%']);
    }
    $tech = $project['technology'] && in_array($project['technology'], technologies(), true) ? $project['technology'] : 'Other';
    if ($w) {
        $upd = ['name' => $project['website_name'] ?: $w['name'], 'technology' => $tech];
        if ($w['url'] !== $project['website_url'] && $currentWebsiteId) $upd['url'] = $project['website_url'];
        DB::update('websites', $upd, 'id = ?', [$w['id']]);
        return (int) $w['id'];
    }
    if (!Tenant::canAdd('websites')['ok']) return $currentWebsiteId;
    $id = DB::insert('websites', [
        'tenant_id'               => Tenant::id(),
        'client_id'               => $project['client_id'],
        'name'                    => $project['website_name'] ?: $project['project_name'],
        'url'                     => $project['website_url'],
        'technology'              => $tech,
        'monitoring_enabled'      => $monitoring ? 1 : 0,
        'page_monitoring_enabled' => $monitoring ? 1 : 0,
        'status'                  => $monitoring ? 'unknown' : 'paused',
        'notes'                   => 'Created from website project: ' . $project['project_name'],
        'created_by'              => Auth::id(),
    ]);
    ActivityLog::add('website_added', 'Website added from project: ' . ($project['website_name'] ?: $project['project_name']) . ' (' . $project['website_url'] . ')', ['client_id' => $project['client_id'], 'website_id' => $id]);
    return $id;
}

function set_monitoring(int $websiteId, bool $on, array $project): void
{
    $w = DB::fetch("SELECT * FROM websites WHERE id = ?", [$websiteId]);
    if (!$w) return;
    DB::update('websites', ['monitoring_enabled' => $on ? 1 : 0, 'page_monitoring_enabled' => $on ? 1 : 0, 'status' => $on ? 'unknown' : 'paused'], 'id = ?', [$websiteId]);
    ActivityLog::add($on ? 'monitoring_enabled' : 'monitoring_disabled', 'Monitoring ' . ($on ? 'enabled' : 'disabled') . ' for ' . $w['name'] . ' (project: ' . $project['project_name'] . ')', ['client_id' => $project['client_id'], 'website_id' => $websiteId]);
    if ($on) {
        try {
            $w['monitoring_enabled'] = 1;
            Monitor::checkWebsite($w, true);
            if (stripos($w['url'], 'https://') === 0) Monitor::checkSsl($w, true);
            Mailer::processQueue(10);
        } catch (Throwable $e) {
            app_log('warning', 'Initial check after enabling monitoring failed: ' . $e->getMessage());
        }
    }
}

function add_history(int $projectId, ?string $from, string $to, ?string $note = null): void
{
    DB::insert('project_status_history', ['project_id' => $projectId, 'from_status' => $from, 'to_status' => $to, 'note' => $note ? mb_substr($note, 0, 500) : null, 'changed_by' => Auth::id() ?: null, 'changed_at' => date('Y-m-d H:i:s')]);
}

switch ($action) {

    case 'save':
        require_post();
        Auth::requireAbility('projects');
        data_changed();
        $id = post_int('id');
        $existing = $id ? load_project($id) : null;
        $errors = [];

        // ---- Client: existing or create new inline ----
        $clientMode = post('client_mode', 'existing');
        $clientId = post_int('client_id') ?: 0;
        if ($clientMode === 'new') {
            $cname = post('new_client_name', '');
            $cemail = post_nullable('new_client_email');
            if ($cname === '') $errors['new_client_name'] = 'Client name is required.';
            if ($cemail !== null && !valid_email($cemail)) $errors['new_client_email'] = 'Enter a valid email.';
            if (!$errors) {
                // Avoid duplicate clients: reuse a client with the same email or exact name
                $dup = $cemail ? DB::fetch("SELECT id, name FROM clients WHERE email = ? AND tenant_id = ? LIMIT 1", [$cemail, Tenant::id()]) : null;
                if (!$dup) $dup = DB::fetch("SELECT id, name FROM clients WHERE name = ? AND tenant_id = ? LIMIT 1", [$cname, Tenant::id()]);
                if ($dup) {
                    $clientId = (int) $dup['id'];
                } else {
                    $clientId = DB::insert('clients', [
                        'tenant_id' => Tenant::id(), 'name' => $cname, 'company' => post_nullable('new_client_company'), 'email' => $cemail, 'phone' => post_nullable('new_client_phone'),
                        'whatsapp' => post_nullable('new_client_whatsapp'), 'status' => 'active', 'monitoring_enabled' => 1, 'notify_client' => 1, 'created_by' => Auth::id(),
                    ]);
                    ActivityLog::add('client_created', 'Client created from website project: ' . $cname, ['client_id' => $clientId]);
                }
            }
        } elseif (!$clientId || !DB::value("SELECT id FROM clients WHERE id = ? AND tenant_id = ?", [$clientId, Tenant::id()])) {
            $errors['client_id'] = 'Please select a client.';
        }

        $kind = post('project_kind') === 'existing' ? 'existing' : 'new';
        $status = post('status', '');
        $statuses = array_keys(project_statuses());
        if (!in_array($status, $statuses, true)) $status = $existing['status'] ?? ($kind === 'existing' ? 'live' : 'design');
        $url = post_nullable('website_url') ? normalize_url(post('website_url')) : null;
        $data = [
            'client_id'            => $clientId,
            'project_name'         => post('project_name', ''),
            'website_name'         => post_nullable('website_name'),
            'website_url'          => $url,
            'project_kind'         => $kind,
            'status'               => $status,
            'department_id'        => post_int('department_id') ?: null,
            'website_type_id'      => post_int('website_type_id') ?: null,
            'technology'           => in_array(post('technology'), technologies(), true) ? post('technology') : null,
            'start_date'           => post_nullable('start_date'),
            'expected_launch_date' => post_nullable('expected_launch_date'),
            'actual_launch_date'   => post_nullable('actual_launch_date'),
            'notes'                => post_nullable('notes'),
            'assigned_user_id'     => post_int('assigned_user_id') ?: null,
        ];
        if ($data['project_name'] === '') $errors['project_name'] = 'Project name is required.';
        if ($url !== null && !valid_url($url)) $errors['website_url'] = 'Enter a valid URL, e.g. https://example.com';
        if ($kind === 'existing' && $url === null) $errors['website_url'] = 'The website URL is required for an existing website.';
        foreach (['start_date', 'expected_launch_date', 'actual_launch_date'] as $k) {
            if ($data[$k] && !strtotime($data[$k])) $errors[$k] = 'Invalid date.';
        }
        if ($data['department_id'] && !DB::value("SELECT id FROM departments WHERE id = ?", [$data['department_id']])) $errors['department_id'] = 'Select a department.';
        if ($data['website_type_id'] && !DB::value("SELECT id FROM website_types WHERE id = ?", [$data['website_type_id']])) $errors['website_type_id'] = 'Select a website type.';
        if ($errors) json_error('Please correct the highlighted fields.', 422, ['errors' => $errors]);
        if ($data['status'] === 'live' && !$data['actual_launch_date']) $data['actual_launch_date'] = date('Y-m-d');
        if (!$data['start_date'] && !$existing) $data['start_date'] = date('Y-m-d');

        DB::begin();
        try {
            if ($existing) {
                DB::update('website_projects', $data, 'id = ?', [$id]);
                if ($existing['status'] !== $data['status']) add_history($id, $existing['status'], $data['status'], post_nullable('status_note'));
                // Keep the linked website in sync (name/url/technology) – link or create when a URL is now available
                $wid = link_website($data + ['id' => $id], false, $existing['website_id'] ? (int) $existing['website_id'] : null);
                if ($wid !== ($existing['website_id'] ? (int) $existing['website_id'] : null)) DB::update('website_projects', ['website_id' => $wid], 'id = ?', [$id]);
                ActivityLog::add($existing['status'] !== $data['status'] ? 'project_status_changed' : 'project_updated',
                    ($existing['status'] !== $data['status'] ? 'Project status changed to ' . project_status_label($data['status']) . ': ' : 'Project updated: ') . $data['project_name'],
                    ['client_id' => $clientId, 'website_id' => $wid]);
                DB::commit();
                json_success('Project updated.', ['id' => $id]);
            }
            $data['created_by'] = Auth::id();
            $data['tenant_id'] = Tenant::id();
            $id = DB::insert('website_projects', $data);
            add_history($id, null, $data['status'], $kind === 'existing' ? 'Existing website added to the CRM' : 'Project created');
            // Existing websites (or any project with a URL) get their shared monitoring record now; monitoring is only
            // switched on for existing/live websites when the "enable monitoring" box is ticked.
            $monitorNow = post('enable_monitoring') ? true : false;
            $wid = $url ? link_website($data + ['id' => $id], $monitorNow) : null;
            if ($wid) DB::update('website_projects', ['website_id' => $wid], 'id = ?', [$id]);
            ActivityLog::add('project_created', ($kind === 'existing' ? 'Existing website added: ' : 'Website project created: ') . $data['project_name'], ['client_id' => $clientId, 'website_id' => $wid]);
            DB::commit();
            if ($wid && $monitorNow) {
                $w = DB::fetch("SELECT * FROM websites WHERE id = ?", [$wid]);
                if ($w && !$w['monitoring_enabled']) set_monitoring($wid, true, $data + ['id' => $id]);
                elseif ($w) { try { Monitor::checkWebsite($w, true); } catch (Throwable $e) {} }
            }
            json_success($kind === 'existing' ? 'Existing website added.' : 'Website project created.', ['id' => $id, 'redirect' => post('stay') ? null : url('projects/view.php?id=' . $id)]);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

    case 'get':
        Auth::requireAbility('projects.view');
        $p = load_project((int) (post_int('id') ?? get('id', 0)));
        $p['client_mode'] = 'existing';
        json_success('OK', ['project' => $p]);

    case 'status':
        require_post();
        Auth::requireAbility('projects');
        data_changed();
        $p = load_project((int) post_int('id'));
        $to = post('status', '');
        if (!isset(project_statuses()[$to])) json_error('Invalid status.');
        if ($to === $p['status']) json_success('Status unchanged.');
        $upd = ['status' => $to];
        if ($to === 'live' && !$p['actual_launch_date']) $upd['actual_launch_date'] = date('Y-m-d');
        DB::update('website_projects', $upd, 'id = ?', [$p['id']]);
        add_history((int) $p['id'], $p['status'], $to, post_nullable('note'));
        ActivityLog::add($to === 'live' ? 'project_live' : 'project_status_changed', 'Project "' . $p['project_name'] . '" moved from ' . project_status_label($p['status']) . ' to ' . project_status_label($to) . (post_nullable('note') ? ' – ' . post('note') : ''), ['client_id' => $p['client_id'], 'website_id' => $p['website_id']]);
        $monitoring = null;
        if ($p['website_id']) $monitoring = (int) DB::value("SELECT monitoring_enabled FROM websites WHERE id = ?", [$p['website_id']]);
        json_success('Status changed to ' . project_status_label($to) . '.', [
            'status' => $to, 'badge' => project_status_badge($to),
            'offer_monitoring' => $to === 'live' && (!$p['website_id'] || !$monitoring), 'has_url' => (bool) $p['website_url'],
        ]);

    case 'enable_monitoring':
    case 'disable_monitoring':
        require_post();
        Auth::requireAbility('websites');
        data_changed();
        $p = load_project((int) post_int('id'));
        $on = $action === 'enable_monitoring';
        if ($on && !$p['website_url']) json_error('Add the website URL to the project first – monitoring needs a URL to check.');
        $wid = $p['website_id'] ? (int) $p['website_id'] : null;
        if (!$wid) {
            $wid = link_website($p, false);
            DB::update('website_projects', ['website_id' => $wid], 'id = ?', [$p['id']]);
        }
        set_monitoring($wid, $on, $p);
        Mailer::processQueue(5);
        json_success($on ? 'Monitoring enabled – the website is now checked automatically (uptime, pages, SSL, forms).' : 'Monitoring disabled for this website.', ['website_id' => $wid]);

    case 'delete':
        require_post();
        Auth::requireRole('admin', 'manager');
        data_changed();
        $p = load_project((int) post_int('id'));
        DB::delete('website_projects', 'id = ?', [$p['id']]); // the shared website/monitoring record is kept
        ActivityLog::add('project_deleted', 'Project deleted: ' . $p['project_name'], ['client_id' => $p['client_id'], 'website_id' => $p['website_id']]);
        json_success('Project deleted. The linked website and its monitoring data were kept.', ['redirect' => url('projects/index.php')]);

    case 'history':
        Auth::requireAbility('projects.view');
        load_project((int) get('id', 0));
        $rows = DB::fetchAll("SELECT h.*, u.name AS user_name FROM project_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.project_id = ? ORDER BY h.changed_at DESC, h.id DESC", [(int) get('id', 0)]);
        json_success('OK', ['history' => $rows]);

    case 'types':
        // website types for a department (for dependent selects)
        $dept = (int) get('department_id', 0);
        $rows = array_values(array_filter(website_types_options(), fn($t) => !$dept || !$t['department_id'] || (int) $t['department_id'] === $dept));
        json_success('OK', ['types' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'department' => $t['department_name']], $rows)]);

    case 'export':
        Auth::requireAbility('projects.view');
        // CSV export honouring the same filters as the table
        $g = fn($k, $d = '') => get($k, $d);
        $where = ['1=1']; $params = [];
        require_once ROOT_PATH . '/includes/ProjectFilters.php';
        [$where, $params] = project_filters($g);
        $rows = DB::fetchAll("SELECT p.*, c.name AS client_name, d.name AS department_name, t.name AS type_name, u.name AS assigned_name
            FROM website_projects p JOIN clients c ON c.id = p.client_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN website_types t ON t.id = p.website_type_id LEFT JOIN users u ON u.id = p.assigned_user_id
            WHERE " . implode(' AND ', $where) . " ORDER BY p.status, p.project_name LIMIT 50000", $params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="website-projects-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Project', 'Client', 'Website', 'URL', 'Kind', 'Status', 'Department', 'Website Type', 'Technology', 'Start Date', 'Expected Launch', 'Actual Launch', 'Assigned', 'Created', 'Updated']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['project_name'], $r['client_name'], $r['website_name'], $r['website_url'], $r['project_kind'] === 'existing' ? 'Existing Website' : 'New Website', project_status_label($r['status']), $r['department_name'], $r['type_name'], $r['technology'], $r['start_date'], $r['expected_launch_date'], $r['actual_launch_date'], $r['assigned_name'], $r['created_at'], $r['updated_at']]);
        }
        fclose($out);
        ActivityLog::add('report_exported', 'Website projects exported (CSV)');
        exit;

    default:
        json_error('Unknown action.');
}
