<?php
/**
 * Lightweight PDO wrapper. Every query goes through prepared statements.
 * Collects query count / time per request (shown as X-DB-Queries header in development) for performance work.
 */
class DB
{
    private static ?PDO $pdo = null;
    public static int $queries = 0;
    public static float $time = 0.0;
    public static array $slow = [];
    public static bool $traceOn = false;
    public static array $trace = [];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . self::mysqlOffset() . "', SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    private static function mysqlOffset(): string
    {
        $offset = (new DateTime('now'))->getOffset();
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);
        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $t = microtime(true);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $d = microtime(true) - $t;
        self::$queries++;
        self::$time += $d;
        if ($d > 0.2) self::$slow[] = [round($d * 1000) . 'ms', preg_replace('~\s+~', ' ', substr($sql, 0, 160))];
        if (self::$traceOn) self::$trace[] = [round($d * 1000, 1), preg_replace('~\s+~', ' ', substr($sql, 0, 200))];
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = [])
    {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** Multi-row insert (used by seeding / rollups). */
    public static function insertMany(string $table, array $rows): int
    {
        if (!$rows) return 0;
        $cols = array_keys($rows[0]);
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $params = [];
        foreach ($rows as $r) foreach ($cols as $c) $params[] = $r[$c] ?? null;
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES " . implode(',', array_fill(0, count($rows), $place));
        return self::query($sql, $params)->rowCount();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        foreach ($data as $col => $val) $set[] = "`$col` = ?";
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where";
        return self::query($sql, array_merge(array_values($data), $whereParams))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    public static function begin(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollBack(): void { if (self::pdo()->inTransaction()) self::pdo()->rollBack(); }
}
