<?php
/**
 * ChannelRepo — kanallar (main / base / required).
 *  - main: kanal post yuboriladigan asosiy kanal
 *  - base: filmlar saqlanadigan "baza" kanali (copyMessage manbasi)
 *  - required: majburiy obuna kanallari (bir nechta bo'lishi mumkin)
 */
final class ChannelRepo
{
    public static function count(): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM channels");
    }

    public static function all(): array
    {
        return Database::fetchAll("SELECT * FROM channels ORDER BY type, sort, id");
    }

    /** Bitta turdagi (main/base) kanal. */
    public static function single(string $type): ?array
    {
        return Database::fetch(
            "SELECT * FROM channels WHERE type = ? AND active = 1 ORDER BY id LIMIT 1",
            [$type]
        );
    }

    public static function main(): ?array { return self::single('main'); }
    public static function base(): ?array { return self::single('base'); }

    /** Majburiy obuna kanallari. */
    public static function required(): array
    {
        return Database::fetchAll(
            "SELECT * FROM channels WHERE type = 'required' AND active = 1 ORDER BY sort, id"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetch("SELECT * FROM channels WHERE id = ?", [$id]);
    }

    /** main/base — bitta bo'lishi kerak: eskisini o'chirib yangisini qo'yadi. */
    public static function setSingle(string $type, array $d): int
    {
        return Database::transaction(static function () use ($type, $d): int {
            Database::execute("DELETE FROM channels WHERE type = ?", [$type]);
            return Database::insert(
                "INSERT INTO channels (username, chat_id, title, type, sort, active)
                 VALUES (?, ?, ?, ?, 0, 1)",
                [$d['username'] ?? '', $d['chat_id'] ?? null, $d['title'] ?? '', $type]
            );
        });
    }

    public static function addRequired(array $d): int
    {
        $sort = (int)Database::value("SELECT COALESCE(MAX(sort),0)+1 FROM channels WHERE type='required'");
        return Database::insert(
            "INSERT INTO channels (username, chat_id, title, type, sort, active)
             VALUES (?, ?, ?, 'required', ?, 1)",
            [$d['username'] ?? '', $d['chat_id'] ?? null, $d['title'] ?? '', $sort]
        );
    }

    public static function remove(int $id): void
    {
        Database::execute("DELETE FROM channels WHERE id = ?", [$id]);
    }
}
