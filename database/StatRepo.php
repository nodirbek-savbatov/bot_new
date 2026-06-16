<?php
/**
 * StatRepo — kunlik metrikalar (starts, views, searches, ...).
 */
final class StatRepo
{
    public static function inc(string $metric): void
    {
        Database::execute(
            "INSERT INTO stats (day, metric, cnt) VALUES (CURDATE(), ?, 1)
             ON DUPLICATE KEY UPDATE cnt = cnt + 1",
            [$metric]
        );
    }

    public static function today(string $metric): int
    {
        return (int)Database::value(
            "SELECT cnt FROM stats WHERE day = CURDATE() AND metric = ?",
            [$metric]
        );
    }

    public static function total(string $metric): int
    {
        return (int)Database::value("SELECT COALESCE(SUM(cnt),0) FROM stats WHERE metric = ?", [$metric]);
    }
}
