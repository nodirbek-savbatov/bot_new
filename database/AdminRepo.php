<?php
/**
 * AdminRepo — adminlar boshqaruvi (bosh admin o'chirilmaydi).
 */
final class AdminRepo
{
    private static ?array $cache = null;

    /** Barcha admin ID lar [int, ...]. */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $rows = Database::fetchAll("SELECT user_id, is_main FROM admins ORDER BY is_main DESC, added_at ASC");
        self::$cache = array_map(static fn($r) => (int)$r['user_id'], $rows);
        return self::$cache;
    }

    /** To'liq qatorlar (is_main bilan). */
    public static function allRows(): array
    {
        return Database::fetchAll("SELECT user_id, is_main, added_at FROM admins ORDER BY is_main DESC, added_at ASC");
    }

    public static function isAdmin(int $id): bool
    {
        return in_array($id, self::all(), true);
    }

    public static function isMain(int $id): bool
    {
        return $id === (int)Config::get('admin.main');
    }

    public static function add(int $id, bool $main = false): void
    {
        Database::execute(
            "INSERT INTO admins (user_id, is_main, added_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE is_main = VALUES(is_main)",
            [$id, $main ? 1 : 0]
        );
        self::$cache = null;
    }

    public static function remove(int $id): void
    {
        if (self::isMain($id)) return; // bosh adminni o'chirib bo'lmaydi
        Database::execute("DELETE FROM admins WHERE user_id = ? AND is_main = 0", [$id]);
        self::$cache = null;
    }
}
