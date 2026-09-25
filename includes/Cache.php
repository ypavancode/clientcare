<?php
/**
 * Small cache layer: APCu when available (shared memory, fastest), otherwise files in cache/.
 * Used for settings, header counters, dashboard statistics and other frequently repeated queries.
 *
 *   Cache::remember('key', 30, fn() => expensive())   // cached for 30 seconds
 *   Cache::forget('key');  Cache::flush('prefix');
 */
class Cache
{
    private static ?bool $apcu = null;
    private static array $local = [];   // per-request memo

    private static function dir(): string
    {
        $dir = ROOT_PATH . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    /** Shared database cache (config CACHE_DRIVER = 'database'): one cache for every application server. */
    private static function db(): bool
    {
        return defined('CACHE_DRIVER') && CACHE_DRIVER === 'database';
    }

    private static function apcu(): bool
    {
        if (self::db()) return false;
        if (self::$apcu === null) {
            self::$apcu = function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'));
        }
        return self::$apcu;
    }

    private static function key(string $key): string
    {
        return 'omcrm:' . DB_NAME . ':' . $key;
    }

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$local)) return self::$local[$key];
        if (self::db()) {
            try { $row = DB::fetch("SELECT v, expires_at FROM cache_store WHERE k = ?", [self::key($key)]); } catch (Throwable $e) { return $default; }
            if (!$row || (int) $row['expires_at'] < time()) return $default;
            $v = @unserialize((string) $row['v'], ['allowed_classes' => false]);
            return self::$local[$key] = $v;
        }
        if (self::apcu()) {
            $ok = false;
            $v = apcu_fetch(self::key($key), $ok);
            return $ok ? (self::$local[$key] = $v) : $default;
        }
        $file = self::dir() . '/' . md5(self::key($key)) . '.cache';
        if (!is_file($file)) return $default;
        $raw = @file_get_contents($file);
        if ($raw === false) return $default;
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['e']) || $data['e'] < time()) {
            @unlink($file);
            return $default;
        }
        return self::$local[$key] = $data['v'];
    }

    public static function set(string $key, $value, int $ttl = 60): void
    {
        self::$local[$key] = $value;
        if (self::db()) {
            try { DB::query("INSERT INTO cache_store (k, v, expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), expires_at = VALUES(expires_at)", [self::key($key), serialize($value), time() + $ttl]); } catch (Throwable $e) {}
            return;
        }
        if (self::apcu()) {
            apcu_store(self::key($key), $value, $ttl);
            return;
        }
        $file = self::dir() . '/' . md5(self::key($key)) . '.cache';
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize(['e' => time() + $ttl, 'v' => $value]), LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function remember(string $key, int $ttl, callable $fn)
    {
        $v = self::get($key, '__miss__');
        if ($v !== '__miss__') return $v;
        $v = $fn();
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function forget(string $key): void
    {
        unset(self::$local[$key]);
        if (self::db()) { try { DB::query("DELETE FROM cache_store WHERE k = ?", [self::key($key)]); } catch (Throwable $e) {} return; }
        if (self::apcu()) {
            apcu_delete(self::key($key));
            return;
        }
        @unlink(self::dir() . '/' . md5(self::key($key)) . '.cache');
    }

    /** Remove every cached entry (used after data changes that affect many counters). */
    public static function flush(): void
    {
        self::$local = [];
        if (self::db()) { try { DB::query("DELETE FROM cache_store WHERE k LIKE ?", [self::key('') . '%']); } catch (Throwable $e) {} return; }
        if (self::apcu()) {
            apcu_delete(new APCUIterator('~^' . preg_quote(self::key(''), '~') . '~'));
            return;
        }
        foreach (glob(self::dir() . '/*.cache') ?: [] as $f) @unlink($f);
    }

    /**
     * Version tags: keys built with vkey() are invalidated together by bump() without touching other entries.
     *   Cache::remember(Cache::vkey('data', 'dashboard:stats'), 30, ...);  Cache::bump('data');
     */
    public static function version(string $tag): int
    {
        $v = self::get('ver:' . $tag);
        if ($v === null) { $v = 1; self::set('ver:' . $tag, $v, 86400 * 30); }
        return (int) $v;
    }

    public static function vkey(string $tag, string $key): string
    {
        return $tag . ':v' . self::version($tag) . ':' . $key;
    }

    public static function bump(string $tag): void
    {
        self::set('ver:' . $tag, self::version($tag) + 1, 86400 * 30);
    }

    /** Delete expired file entries (called occasionally from cron housekeeping). */
    public static function gc(): int
    {
        if (self::db()) { try { return DB::query("DELETE FROM cache_store WHERE expires_at < ? LIMIT 20000", [time()])->rowCount(); } catch (Throwable $e) { return 0; } }
        if (self::apcu()) return 0;
        $n = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $f) {
            $data = @unserialize((string) @file_get_contents($f), ['allowed_classes' => false]);
            if (!is_array($data) || ($data['e'] ?? 0) < time()) { @unlink($f); $n++; }
        }
        return $n;
    }
}
