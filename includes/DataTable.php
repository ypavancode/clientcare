<?php
/**
 * Server-side DataTables helper: paging, sorting and searching happen in MySQL, so list pages stay fast
 * with hundreds of thousands of rows. Only the requested page (25-100 rows) is rendered.
 *
 * $cfg = [
 *   'from'     => 'clients c LEFT JOIN users u ON u.id = c.assigned_user_id',
 *   'where'    => ["c.status <> 'archived'"],  'params' => [],
 *   'columns'  => [ ['db' => 'c.name', 'search' => true], ['db' => 'c.email', 'search' => true], ['db' => null] ... ] // index = DataTables column index
 *   'select'   => 'c.*, u.name AS assigned_name, (SELECT ...) AS site_count',
 *   'default_order' => 'c.name ASC',
 *   'row'      => fn(array $r): array => [cell html, ...]   // returns cells in column order
 *   'row_attr' => fn(array $r): array => ['data-row-id' => $r['id']]
 *   'count_from' => optional cheaper FROM for COUNT(*) (without heavy joins)
 * ]
 */
class DataTable
{
    public static function serve(array $cfg): void
    {
        $draw = (int) ($_GET['draw'] ?? $_POST['draw'] ?? 0);
        $start = max(0, (int) ($_GET['start'] ?? $_POST['start'] ?? 0));
        $length = (int) ($_GET['length'] ?? $_POST['length'] ?? 25);
        if ($length < 1 || $length > 250) $length = 25;
        $search = trim((string) ($_GET['search']['value'] ?? $_POST['search']['value'] ?? ''));
        $order = $_GET['order'] ?? $_POST['order'] ?? [];

        $where = $cfg['where'] ?? ['1=1'];
        $params = $cfg['params'] ?? [];

        // Global search across searchable columns
        if ($search !== '') {
            $terms = preg_split('~\s+~', $search, 4);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $parts = [];
                foreach ($cfg['columns'] as $col) {
                    if (!empty($col['search']) && !empty($col['db'])) {
                        $parts[] = $col['db'] . ' LIKE ?';
                        $params[] = $like;
                    }
                }
                if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Ordering
        $orderSql = $cfg['default_order'] ?? '';
        if ($order && isset($order[0]['column'])) {
            $idx = (int) $order[0]['column'];
            $dir = strtolower($order[0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
            $col = $cfg['columns'][$idx] ?? null;
            if ($col && !empty($col['db']) && empty($col['nosort'])) {
                // Single-column ORDER BY so MySQL can walk the column's index and stop at LIMIT (no filesort of the whole table)
                $expr = $col['order'] ?? $col['db'];
                $orderSql = $expr . ' ' . $dir;
            }
        }
        $orderSql = $orderSql ? 'ORDER BY ' . $orderSql : '';

        // Row counts are the most expensive part of paging huge tables; cache them for 30 s (invalidated by data_changed()).
        // The count for the current filters is what DataTables shows; without a search term total = filtered.
        $countFrom = $cfg['count_from'] ?? $cfg['from'];
        $countKey = 'dt:count:' . md5($countFrom . '|' . $whereSql . '|' . json_encode($params));
        $filtered = (int) Cache::remember(Cache::vkey('data', $countKey), 30, fn() => (int) DB::value("SELECT COUNT(*) FROM " . $countFrom . " " . $whereSql, $params));
        $total = $filtered;

        if (!empty($cfg['page_from']) && !empty($cfg['key'])) {
            // Two-phase paging: find the page's primary keys using only the light tables needed for filtering/sorting,
            // then load the full rows (heavy joins + correlated subqueries) for those few ids only.
            // page_from may be a callable receiving the ORDER BY / WHERE text so it can add joins only when they are referenced.
            $pageFrom = is_callable($cfg['page_from']) ? ($cfg['page_from'])($orderSql . ' ' . $whereSql) : $cfg['page_from'];
            $ids = array_column(DB::fetchAll("SELECT " . $cfg['key'] . " AS k FROM " . $pageFrom . " " . $whereSql . " " . $orderSql . " LIMIT " . (int) $length . " OFFSET " . (int) $start, $params), 'k');
            $rows = [];
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $rows = DB::fetchAll("SELECT " . $cfg['select'] . " FROM " . $cfg['from'] . " WHERE " . $cfg['key'] . " IN ($ph) ORDER BY FIELD(" . $cfg['key'] . ", $ph)", array_merge($ids, $ids));
            }
        } else {
            $rows = DB::fetchAll("SELECT " . $cfg['select'] . " FROM " . $cfg['from'] . " " . $whereSql . " " . $orderSql . " LIMIT " . (int) $length . " OFFSET " . (int) $start, $params);
        }

        $data = [];
        foreach ($rows as $r) {
            $cells = ($cfg['row'])($r);
            if (isset($cfg['row_attr'])) {
                $cells['DT_RowAttr'] = ($cfg['row_attr'])($r);
            }
            $data[] = $cells;
        }
        json_response(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data, 'debug' => APP_ENV === 'development' ? ['queries' => DB::$queries, 'ms' => round(DB::$time * 1000)] : null]);
    }
}
