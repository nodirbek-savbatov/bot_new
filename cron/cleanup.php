<?php
/**
 * cleanup.php — kunlik tozalash.
 * System cron (kuniga bir marta), masalan:
 *   30 3 * * * /usr/bin/php /path/movie_bot/cron/cleanup.php >> /path/movie_bot/logs/cron.log 2>&1
 *
 * Bajaradi: eskirgan FSM holatlari, eski loglar, rate-limit fayllari, bajarilgan broadcast yozuvlari.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/../bootstrap.php';

$timeout = (int)Config::get('state.timeout', 600);

$states   = State::cleanup($timeout);
$logs     = Logger::rotate();
$rl       = RateLimiter::cleanup(3600);
$bc       = BroadcastRepo::purgeDone(3);

Logger::info("Cleanup: states=$states logs=$logs rl=$rl broadcast=$bc");
echo "Cleanup done: states=$states logs=$logs rl=$rl broadcast=$bc\n";
exit(0);
