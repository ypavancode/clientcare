<?php
/**
 * Database session handler (v3.4) – lets any number of application servers share one login state.
 * Enabled with  define('SESSION_DRIVER', 'database');  in config/config.php (default 'files' = PHP's own handler).
 * Rows live in the `sessions` table; expired rows are removed by PHP's GC and by the daily housekeeping job.
 */
class SessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private array $cache = [];

    public static function enable(): void
    {
        $h = new self();
        session_set_save_handler($h, true);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $row = DB::fetch("SELECT data, last_activity FROM sessions WHERE id = ?", [$id]);
        if (!$row) return '';
        if ((int) $row['last_activity'] < time() - SESSION_LIFETIME) { $this->destroy($id); return ''; }
        $this->cache[$id] = md5((string) $row['data']);
        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        if (isset($this->cache[$id]) && $this->cache[$id] === md5($data)) return $this->updateTimestamp($id, $data);
        DB::query("INSERT INTO sessions (id, data, user_id, tenant_id, ip, last_activity) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), user_id = VALUES(user_id), tenant_id = VALUES(tenant_id), ip = VALUES(ip), last_activity = VALUES(last_activity)",
            [$id, $data, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), time()]);
        $this->cache[$id] = md5($data);
        return true;
    }

    public function destroy(string $id): bool
    {
        DB::query("DELETE FROM sessions WHERE id = ?", [$id]);
        unset($this->cache[$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return DB::query("DELETE FROM sessions WHERE last_activity < ? LIMIT 5000", [time() - max($max_lifetime, SESSION_LIFETIME)])->rowCount();
    }

    public function validateId(string $id): bool
    {
        return (bool) DB::value("SELECT 1 FROM sessions WHERE id = ?", [$id]);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        DB::query("UPDATE sessions SET last_activity = ? WHERE id = ?", [time(), $id]);
        return true;
    }
}
