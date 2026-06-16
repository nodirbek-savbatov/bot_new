<?php
/**
 * State — foydalanuvchi holati (FSM). step/*.txt o'rnida `states` jadvali.
 * Imkoniyatlari:
 *  - step + ixtiyoriy data (JSON)
 *  - timeout (config.state.timeout) — eskirgan holat avtomatik bekor
 *  - menu_msg_id / kbd_msg_id — UI xabarlarini alohida kuzatish (bug #5)
 */
final class State
{
    /** Kino qidiruv rejimi step nomi (FSM). */
    public const SEARCH_MOVIE = 'search';

    private static array $cache = [];

    private static function load(int $uid): array
    {
        if (isset(self::$cache[$uid])) return self::$cache[$uid];

        $row = Database::fetch(
            "SELECT step, data, menu_msg_id, kbd_msg_id, UNIX_TIMESTAMP(updated_at) AS ts
             FROM states WHERE user_id = ?",
            [$uid]
        );
        if ($row === null) {
            $row = ['step' => '', 'data' => [], 'menu_msg_id' => null, 'kbd_msg_id' => null, 'ts' => 0];
        } else {
            $row['data']        = $row['data'] ? (json_decode((string)$row['data'], true) ?: []) : [];
            $row['menu_msg_id'] = $row['menu_msg_id'] !== null ? (int)$row['menu_msg_id'] : null;
            $row['kbd_msg_id']  = $row['kbd_msg_id'] !== null ? (int)$row['kbd_msg_id'] : null;
            $row['ts']          = (int)$row['ts'];
        }
        return self::$cache[$uid] = $row;
    }

    private static function persist(int $uid): void
    {
        $r = self::$cache[$uid];
        Database::execute(
            "INSERT INTO states (user_id, step, data, menu_msg_id, kbd_msg_id, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE step = VALUES(step), data = VALUES(data),
                 menu_msg_id = VALUES(menu_msg_id), kbd_msg_id = VALUES(kbd_msg_id), updated_at = NOW()",
            [
                $uid,
                $r['step'],
                $r['data'] ? json_encode($r['data'], JSON_UNESCAPED_UNICODE) : null,
                $r['menu_msg_id'],
                $r['kbd_msg_id'],
            ]
        );
        self::$cache[$uid]['ts'] = time();
    }

    // ---- Step ----

    public static function step(int $uid): string
    {
        $r = self::load($uid);
        if ($r['step'] !== '' && self::isExpired($uid)) {
            return ''; // eskirgan — bo'sh deb hisoblanadi
        }
        return $r['step'];
    }

    public static function isExpired(int $uid): bool
    {
        $r = self::load($uid);
        if ($r['step'] === '' || $r['ts'] === 0) return false;
        // Qidiruv rejimi uchun qisqaroq alohida timeout (5 daqiqa) qo'llanadi.
        if ($r['step'] === self::SEARCH_MOVIE) {
            $timeout = (int)Config::get('state.search_timeout', 300);
        } else {
            $timeout = (int)Config::get('state.timeout', 600);
        }
        return (time() - $r['ts']) > $timeout;
    }

    public static function set(int $uid, string $step, ?array $data = null): void
    {
        $r = self::load($uid);
        $r['step'] = $step;
        if ($data !== null) $r['data'] = $data;
        self::$cache[$uid] = $r;
        self::persist($uid);
    }

    public static function data(int $uid): array
    {
        return self::load($uid)['data'] ?? [];
    }

    public static function mergeData(int $uid, array $patch): void
    {
        $r = self::load($uid);
        $r['data'] = array_merge($r['data'] ?? [], $patch);
        self::$cache[$uid] = $r;
        self::persist($uid);
    }

    /** Stepni va datani tozalaydi (UI kuzatuvi saqlanadi). */
    public static function clear(int $uid): void
    {
        $r = self::load($uid);
        $r['step'] = '';
        $r['data'] = [];
        self::$cache[$uid] = $r;
        self::persist($uid);
    }

    // ---- UI xabarlarini kuzatish ----

    public static function menuId(int $uid): ?int
    {
        return self::load($uid)['menu_msg_id'];
    }

    public static function setMenu(int $uid, ?int $msgId): void
    {
        $r = self::load($uid);
        $r['menu_msg_id'] = $msgId;
        self::$cache[$uid] = $r;
        self::persist($uid);
    }

    public static function kbdId(int $uid): ?int
    {
        return self::load($uid)['kbd_msg_id'];
    }

    public static function setKbd(int $uid, ?int $msgId): void
    {
        $r = self::load($uid);
        $r['kbd_msg_id'] = $msgId;
        self::$cache[$uid] = $r;
        self::persist($uid);
    }

    // ---- Cron tozalash ----

    /** Eskirgan holatlarni tozalaydi; juda eski bo'sh qatorlarni o'chiradi. */
    public static function cleanup(int $timeout): int
    {
        $cleared = Database::execute(
            "UPDATE states SET step = '', data = NULL
             WHERE step <> '' AND updated_at < (NOW() - INTERVAL ? SECOND)",
            [$timeout]
        );
        Database::execute(
            "DELETE FROM states
             WHERE step = '' AND menu_msg_id IS NULL AND kbd_msg_id IS NULL
               AND updated_at < (NOW() - INTERVAL 7 DAY)"
        );
        return $cleared;
    }
}
