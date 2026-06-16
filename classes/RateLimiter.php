<?php
/**
 * RateLimiter — oddiy fayl asosidagi flood control (fixed window).
 * DB'ga yuk solmaydi; har user uchun kichik fayl, flock bilan himoyalangan.
 * Xato bo'lsa fail-open (ruxsat beradi).
 */
final class RateLimiter
{
    private static string $dir = '';

    public static function init(string $dir): void
    {
        self::$dir = rtrim($dir, '/\\');
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    /**
     * Ruxsat bormi? $max ta xabar / $per soniya oynasida.
     * true = ruxsat, false = limit oshib ketgan.
     */
    public static function allow(int $uid, int $max, int $per): bool
    {
        if (self::$dir === '') return true;
        $file = self::$dir . "/rl_$uid";
        $now  = time();

        $fp = @fopen($file, 'c+');
        if (!$fp) return true; // fail-open

        $allowed = true;
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp) ?: '';
            $winStart = 0;
            $count = 0;
            if ($raw !== '' && str_contains($raw, ':')) {
                [$winStart, $count] = array_map('intval', explode(':', $raw, 2));
            }
            if ($now - $winStart >= $per) {
                $winStart = $now;
                $count = 0;
            }
            $count++;
            $allowed = ($count <= $max);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, "$winStart:$count");
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $allowed;
    }

    /** Eski rl_* fayllarni tozalaydi (cron). */
    public static function cleanup(int $olderThanSec = 3600): int
    {
        if (self::$dir === '' || !is_dir(self::$dir)) return 0;
        $deleted = 0;
        $cutoff = time() - $olderThanSec;
        foreach (glob(self::$dir . '/rl_*') ?: [] as $f) {
            if (filemtime($f) < $cutoff) {
                @unlink($f);
                $deleted++;
            }
        }
        return $deleted;
    }
}
