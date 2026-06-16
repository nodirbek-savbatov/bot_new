<?php
/**
 * UserRepo — foydalanuvchilar.
 */
final class UserRepo
{
    /** Foydalanuvchini qo'shadi yoki last_seen/ism/username yangilaydi. */
    public static function touch(int $id, string $name, string $username): void
    {
        Database::execute(
            "INSERT INTO users (id, name, username, joined, last_seen, blocked)
             VALUES (?, ?, ?, NOW(), NOW(), 0)
             ON DUPLICATE KEY UPDATE name = VALUES(name), username = VALUES(username), last_seen = NOW()",
            [$id, mb_substr($name, 0, 128), mb_substr($username, 0, 64)]
        );
    }

    public static function count(): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM users");
    }

    public static function isBlocked(int $id): bool
    {
        return (int)Database::value("SELECT blocked FROM users WHERE id = ?", [$id]) === 1;
    }

    public static function setBlocked(int $id, bool $blocked): void
    {
        Database::execute("UPDATE users SET blocked = ? WHERE id = ?", [$blocked ? 1 : 0, $id]);
    }

    /** Sahifalangan ro'yxat: ['rows'=>[], 'total'=>int, 'pages'=>int]. */
    public static function paginate(int $page, int $perPage = 10): array
    {
        $total  = self::count();
        $pages  = max(1, (int)ceil($total / $perPage));
        $page   = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT id, name, username, joined, blocked FROM users
             ORDER BY joined DESC LIMIT $perPage OFFSET $offset"
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** TXT eksport uchun barcha foydalanuvchilar. */
    public static function allForExport(): array
    {
        return Database::fetchAll("SELECT id, name, username, joined, blocked FROM users ORDER BY joined ASC");
    }

    /** Broadcast navbati uchun bloklanmaganlar ID si (generator emas — oddiy massiv). */
    public static function activeIds(): array
    {
        $rows = Database::fetchAll("SELECT id FROM users WHERE blocked = 0");
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }
}
