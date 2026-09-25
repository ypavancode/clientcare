<?php
/**
 * Shared WHERE builder for the Website Projects list, export and statistics so every view filters identically.
 * @param callable $g  getter for request values: $g('key', default)
 * @return array{0: string[], 1: array}
 */
function project_filters(callable $g): array
{
    $where = ['p.tenant_id = ' . Tenant::id()];
    $params = [];
    $status = (string) $g('status');
    if ($status === 'in_progress') $where[] = "p.status IN ('design','development','testing','client_review','changes_required','ready_for_launch')";
    elseif ($status !== '' && isset(project_statuses()[$status])) { $where[] = 'p.status = ?'; $params[] = $status; }
    if (in_array($g('kind'), ['new', 'existing'], true)) { $where[] = 'p.project_kind = ?'; $params[] = $g('kind'); }
    if ((int) $g('client_id')) { $where[] = 'p.client_id = ?'; $params[] = (int) $g('client_id'); }
    if ((int) $g('department_id')) { $where[] = 'p.department_id = ?'; $params[] = (int) $g('department_id'); }
    if ((int) $g('website_type_id')) { $where[] = 'p.website_type_id = ?'; $params[] = (int) $g('website_type_id'); }
    if ($g('technology') && in_array($g('technology'), technologies(), true)) { $where[] = 'p.technology = ?'; $params[] = $g('technology'); }
    if ((int) $g('assigned')) { $where[] = 'p.assigned_user_id = ?'; $params[] = (int) $g('assigned'); }
    if ($g('start_from') && strtotime($g('start_from'))) { $where[] = 'p.start_date >= ?'; $params[] = date('Y-m-d', strtotime($g('start_from'))); }
    if ($g('start_to') && strtotime($g('start_to'))) { $where[] = 'p.start_date <= ?'; $params[] = date('Y-m-d', strtotime($g('start_to'))); }
    if ($g('launch_from') && strtotime($g('launch_from'))) { $where[] = 'COALESCE(p.actual_launch_date, p.expected_launch_date) >= ?'; $params[] = date('Y-m-d', strtotime($g('launch_from'))); }
    if ($g('launch_to') && strtotime($g('launch_to'))) { $where[] = 'COALESCE(p.actual_launch_date, p.expected_launch_date) <= ?'; $params[] = date('Y-m-d', strtotime($g('launch_to'))); }
    if ($g('monitoring') === 'active') $where[] = 'w.monitoring_enabled = 1';
    elseif ($g('monitoring') === 'inactive') $where[] = '(w.id IS NULL OR w.monitoring_enabled = 0)';
    return [$where, $params];
}
