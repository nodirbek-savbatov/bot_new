<?php
/**
 * WebAppRepo — Web App ma'lumotlari: sevimlilar, ko'rilganlar tarixi va
 * bildirishnoma dedup (anti-spam). Mavjud `films`/`users` jadvallaridan foydalanadi.
 */
final class WebAppRepo
{
    // =================== SEVIMLILAR ===================

    public static function isFavorite(int $userId, int $code): bool
    {
        return Database::value(
            "SELECT 1 FROM favorites WHERE user_id = ? AND film_code = ?",
            [$userId, $code]
        ) !== null;
    }

    /** Foydalanuvchining barcha sevimli kodlari (ro'yxatlarda tez belgilash uchun). */
    public static function favoriteCodes(int $userId): array
    {
        $rows = Database::fetchAll("SELECT film_code FROM favorites WHERE user_id = ?", [$userId]);
        return array_map(static fn($r) => (int)$r['film_code'], $rows);
    }

    /** Qo'shadi. Yangi qo'shilgan bo'lsa true, allaqachon bor edi bo'lsa false. */
    public static function addFavorite(int $userId, int $code): bool
    {
        $n = Database::execute(
            "INSERT IGNORE INTO favorites (user_id, film_code, created_at) VALUES (?, ?, NOW())",
            [$userId, $code]
        );
        return $n > 0;
    }

    public static function removeFavorite(int $userId, int $code): void
    {
        Database::execute("DELETE FROM favorites WHERE user_id = ? AND film_code = ?", [$userId, $code]);
    }

    /** Sevimlilar (film ma'lumotlari bilan, yangi qo'shilgan birinchi). */
    public static function favorites(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        return Database::fetchAll(
            "SELECT f.*, fav.created_at AS fav_at
             FROM favorites fav JOIN films f ON f.code = fav.film_code
             WHERE fav.user_id = ?
             ORDER BY fav.created_at DESC
             LIMIT $limit",
            [$userId]
        );
    }

    public static function favCount(int $userId): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM favorites WHERE user_id = ?", [$userId]);
    }

    // =================== KO'RILGANLAR TARIXI ===================

    /** Tarixga yozadi yoki ko'rilgan vaqtini yangilaydi (user+film bo'yicha yagona). */
    public static function addHistory(int $userId, int $code): void
    {
        Database::execute(
            "INSERT INTO watch_history (user_id, film_code, watched_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE watched_at = NOW()",
            [$userId, $code]
        );
    }

    public static function history(int $userId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 100));
        return Database::fetchAll(
            "SELECT f.*, h.watched_at
             FROM watch_history h JOIN films f ON f.code = h.film_code
             WHERE h.user_id = ?
             ORDER BY h.watched_at DESC
             LIMIT $limit",
            [$userId]
        );
    }

    public static function historyCount(int $userId): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM watch_history WHERE user_id = ?", [$userId]);
    }

    // =================== BILDIRISHNOMA DEDUP (anti-spam) ===================

    /**
     * Bir xil action ketma-ket bajarilganda takroriy xabar yuborilmasligi uchun.
     * Oxirgi imzo bilan bir xil va `ttl` soniya ichida bo'lsa — false (yubormaslik).
     * Aks holda imzoni yangilab true qaytaradi (yuborish mumkin).
     */
    public static function shouldNotify(int $userId, string $signature, int $ttl): bool
    {
        $row = Database::fetch(
            "SELECT signature, UNIX_TIMESTAMP(updated_at) AS ts FROM notify_log WHERE user_id = ?",
            [$userId]
        );
        if ($row !== null
            && $row['signature'] === $signature
            && (time() - (int)$row['ts']) < $ttl
        ) {
            return false;
        }
        Database::execute(
            "INSERT INTO notify_log (user_id, signature, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE signature = VALUES(signature), updated_at = NOW()",
            [$userId, $signature]
        );
        return true;
    }
}
