<?php
/**
 * broadcast_worker.php — broadcast navbatini partiyalab yuboradi (bug #8).
 * System cron har daqiqada chaqiradi:
 *   * * * * * /usr/bin/php /path/movie_bot/cron/broadcast_worker.php >> /path/movie_bot/logs/cron.log 2>&1
 *
 * Webhook'ni bloklamaydi; rate-limit (sleep) bilan flood'ning oldini oladi;
 * 403 (user bloklagan) bo'lsa userni blokga belgilaydi; xatoda retry.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/../bootstrap.php';

$batch    = (int)Config::get('broadcast.batch', 25);
$sleepMs  = (int)Config::get('broadcast.sleep_ms', 40);
$retries  = (int)Config::get('broadcast.retries', 3);

$rows = BroadcastRepo::nextBatch($batch);
if (!$rows) {
    exit(0); // navbat bo'sh
}

$sent = 0;
$failed = 0;
foreach ($rows as $row) {
    $id       = (int)$row['id'];
    $target   = (int)$row['target_id'];
    $from     = (int)$row['from_chat_id'];
    $msgId    = (int)$row['message_id'];
    $attempts = (int)$row['attempts'] + 1;

    $res = Telegram::copy($target, $from, $msgId);

    if (is_array($res) && ($res['ok'] ?? false)) {
        BroadcastRepo::markSent($id);
        $sent++;
    } else {
        $code = (int)($res['error_code'] ?? 0);
        // 403 = user botni bloklagan, 400 = chat topilmadi → qayta urinmaymiz
        if ($code === 403 || $code === 400) {
            UserRepo::setBlocked($target, true);
            BroadcastRepo::markFailed($id, $retries, $retries); // darhol failed
        } else {
            BroadcastRepo::markFailed($id, $attempts, $retries);
        }
        $failed++;
    }

    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

Logger::info("Broadcast worker: sent=$sent failed=$failed pending=" . BroadcastRepo::pendingCount());
exit(0);
