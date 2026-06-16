<?php
/**
 * SettingRepo — key/value sozlamalar (bot_username kesh, flaglar).
 */
final class SettingRepo
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $val = Database::value("SELECT v FROM settings WHERE k = ?", [$key]);
        $val = ($val === null) ? $default : (string)$val;
        self::$cache[$key] = $val;
        return $val;
    }

    public static function set(string $key, string $value): void
    {
        Database::execute(
            "INSERT INTO settings (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }
}
