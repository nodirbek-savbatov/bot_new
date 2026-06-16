<?php
/**
 * BroadcastRepo — broadcast navbati (async yuborish, cron worker qayta ishlaydi).
 */
final class BroadcastRepo
{
    /** Barcha aktiv foydalanuvchilarni navbatga qo'shadi. Qo'shilgan soni qaytadi. */
    public static function enqueueAll(int $fromChatId, int $messageId): int
    {
        return Database::execute(
            "INSERT INTO broadcast_queue (target_id, from_chat_id, message_id, status, attempts, created_at)
             SELECT id, ?, ?, 'pending', 0, NOW() FROM users WHERE blocked = 0",
            [$fromChatId, $messageId]
        );
    }

    /** Navbatdan keyingi partiyani oladi. */
    public static function nextBatch(int $limit): array
    {
        return Database::fetchAll(
            "SELECT * FROM broadcast_queue WHERE status = 'pending' ORDER BY id LIMIT $limit"
        );
    }

    public static function markSent(int $id): void
    {
        Database::execute("UPDATE broadcast_queue SET status='sent' WHERE id=?", [$id]);
    }

    public static function markFailed(int $id, int $attempts, int $maxRetries): void
    {
        $status = $attempts >= $maxRetries ? 'failed' : 'pending';
        Database::execute(
            "UPDATE broadcast_queue SET status=?, attempts=? WHERE id=?",
            [$status, $attempts, $id]
        );
    }

    public static function pendingCount(): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM broadcast_queue WHERE status='pending'");
    }

    /** Yuborilgan/muvaffaqiyatsiz yozuvlarni tozalaydi (cleanup). */
    public static function purgeDone(int $olderThanDays = 3): int
    {
        return Database::execute(
            "DELETE FROM broadcast_queue
             WHERE status IN ('sent','failed') AND created_at < (NOW() - INTERVAL ? DAY)",
            [$olderThanDays]
        );
    }
}
