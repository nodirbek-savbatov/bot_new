<?php
/**
 * Database — PDO MySQL singleton + qulay so'rov helperlari.
 * Barcha so'rovlar prepared statement (SQL injection yo'q).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Lazy ulanish — birinchi so'rovda ochiladi. */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $host    = (string)Config::get('db.host', '127.0.0.1');
        $port    = (int)Config::get('db.port', 3306);
        $name    = (string)Config::get('db.name', 'movie_bot');
        $charset = (string)Config::get('db.charset', 'utf8mb4');
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

        try {
            self::$pdo = new PDO($dsn, (string)Config::get('db.user'), (string)Config::get('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            Logger::error('DB ulanish xatosi: ' . $e->getMessage());
            throw $e;
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Bitta qator (yoki null). */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Barcha qatorlar. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Bitta skalyar qiymat (birinchi ustun). */
    public static function value(string $sql, array $params = []): mixed
    {
        $val = self::query($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    /** INSERT/UPDATE/DELETE — ta'sirlangan qatorlar soni. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** INSERT qilib, yangi ID qaytaradi. */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int)self::pdo()->lastInsertId();
    }

    /** Tranzaksiya ichida callback ishga tushiradi. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('Tranzaksiya bekor qilindi: ' . $e->getMessage());
            throw $e;
        }
    }

    /** Ulanish bormi (install/health-check uchun). */
    public static function isConnected(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
