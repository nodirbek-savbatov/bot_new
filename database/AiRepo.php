<?php
/**
 * AiRepo — AI yordamchisi uchun ma'lumot qatlami:
 *   - ai_sessions : foydalanuvchining AI rejimi faol/yo'qligi
 *   - ai_messages : suhbat xotirasi (kontekst — oxirgi N xabar)
 *   - ai_cache    : javob keshi (bir xil savol → API qayta chaqirilmaydi)
 */
final class AiRepo
{
    // =================== SESSION ===================

    public static function isActive(int $uid): bool
    {
        return (int)Database::value("SELECT active FROM ai_sessions WHERE user_id = ?", [$uid]) === 1;
    }

    public static function start(int $uid): void
    {
        Database::execute(
            "INSERT INTO ai_sessions (user_id, active, started_at, updated_at) VALUES (?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE active = 1, started_at = NOW(), updated_at = NOW()",
            [$uid]
        );
    }

    public static function stop(int $uid): void
    {
        Database::execute(
            "INSERT INTO ai_sessions (user_id, active, updated_at) VALUES (?, 0, NOW())
             ON DUPLICATE KEY UPDATE active = 0, updated_at = NOW()",
            [$uid]
        );
    }

    /**
     * Flood himoya: oxirgi AI so'rovidan (oxirgi 'user' xabari) $sec soniya o'tdimi.
     * true = ruxsat, false = juda tez.
     */
    public static function cooldownOk(int $uid, int $sec): bool
    {
        if ($sec <= 0) {
            return true;
        }
        $ts = Database::value(
            "SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM ai_messages WHERE user_id = ? AND role = 'user'",
            [$uid]
        );
        return $ts === null || (time() - (int)$ts) >= $sec;
    }

    // =================== SUHBAT XOTIRASI ===================

    public static function addMessage(int $uid, string $role, string $content): void
    {
        if (!in_array($role, ['user', 'model'], true) || $content === '') {
            return;
        }
        Database::execute(
            "INSERT INTO ai_messages (user_id, role, content, created_at) VALUES (?, ?, ?, NOW())",
            [$uid, $role, mb_substr($content, 0, 6000)]
        );
        self::trim($uid);
    }

    /** Oxirgi N xabar (eskidan yangiga — Gemini contents tartibida). */
    public static function recent(int $uid, int $limit = 24): array
    {
        $limit = max(2, min($limit, 60));
        return Database::fetchAll(
            "SELECT role, content FROM (
                SELECT id, role, content FROM ai_messages WHERE user_id = ? ORDER BY id DESC LIMIT $limit
             ) t ORDER BY id ASC",
            [$uid]
        );
    }

    public static function clearMessages(int $uid): void
    {
        Database::execute("DELETE FROM ai_messages WHERE user_id = ?", [$uid]);
    }

    /** Faqat oxirgi N xabarni saqlab, eskilarini o'chiradi. */
    private static function trim(int $uid): void
    {
        $keep = (int)Config::get('ai.context_messages', 24);
        $keep = max(2, min($keep, 60));
        $cut = Database::value(
            "SELECT id FROM ai_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1 OFFSET $keep",
            [$uid]
        );
        if ($cut !== null) {
            Database::execute("DELETE FROM ai_messages WHERE user_id = ? AND id <= ?", [$uid, (int)$cut]);
        }
    }

    // =================== JAVOB KESHI ===================

    public static function cacheGet(string $key, int $ttl): ?string
    {
        if ($ttl <= 0) {
            return null;
        }
        $row = Database::fetch(
            "SELECT response FROM ai_cache WHERE cache_key = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
            [$key, $ttl]
        );
        return $row['response'] ?? null;
    }

    public static function cacheSet(string $key, string $response): void
    {
        Database::execute(
            "INSERT INTO ai_cache (cache_key, response, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE response = VALUES(response), created_at = NOW()",
            [$key, $response]
        );
    }

    // =================== CRON TOZALASH ===================

    /** @return array{cache:int, messages:int} */
    public static function cleanup(int $cacheTtl, int $msgKeepDays = 7): array
    {
        $cache = Database::execute(
            "DELETE FROM ai_cache WHERE created_at < (NOW() - INTERVAL ? SECOND)",
            [max(3600, $cacheTtl)]
        );
        $msgs = Database::execute(
            "DELETE FROM ai_messages WHERE created_at < (NOW() - INTERVAL ? DAY)",
            [max(1, $msgKeepDays)]
        );
        return ['cache' => $cache, 'messages' => $msgs];
    }
}
