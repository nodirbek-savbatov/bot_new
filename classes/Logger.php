<?php
/**
 * Logger — kunlik fayllarga yozadi (logs/bot-YYYY-MM-DD.log).
 * Daraja filtri: faqat sozlangan darajadan yuqori xabarlar yoziladi.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static string $dir = '';
    private static int $minLevel = 1;
    private static int $days = 14;

    public static function init(string $dir, string $level = 'info', int $days = 14): void
    {
        self::$dir = rtrim($dir, '/\\');
        self::$minLevel = self::LEVELS[$level] ?? 1;
        self::$days = max(1, $days);
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    public static function debug(string $msg, array $ctx = []): void   { self::write('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void     { self::write('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void  { self::write('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void    { self::write('error', $msg, $ctx); }

    private static function write(string $level, string $msg, array $ctx): void
    {
        if ((self::LEVELS[$level] ?? 1) < self::$minLevel) {
            return;
        }
        if (self::$dir === '') {
            error_log("[$level] $msg");
            return;
        }
        $line = sprintf(
            "[%s] %-7s %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        $file = self::$dir . '/bot-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** Eski log fayllarni o'chiradi (cron/cleanup.php chaqiradi). */
    public static function rotate(): int
    {
        if (self::$dir === '' || !is_dir(self::$dir)) return 0;
        $deleted = 0;
        $cutoff = time() - self::$days * 86400;
        foreach (glob(self::$dir . '/bot-*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }
}
