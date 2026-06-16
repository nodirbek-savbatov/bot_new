<?php
/**
 * bot.php — WEBHOOK KIRISH NUQTASI (front controller).
 * Telegram shu URL ga update yuboradi. Yupqa: secret tekshiruv → rate-limit → Router.
 *
 * Eski yagona-fayl versiyasi: bot.legacy.php
 */
require __DIR__ . '/bootstrap.php';

// ---- 1) Webhook secret tekshiruvi (bug #1: spoofing himoyasi) ----
$expected = (string)Config::get('bot.secret');
if ($expected !== '' && $expected !== 'GENERATE_ON_INSTALL') {
    $got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($expected, (string)$got)) {
        Logger::warning('Webhook secret mos kelmadi — rad etildi', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
        http_response_code(403);
        exit('forbidden');
    }
}

// ---- 2) Update o'qish ----
$raw = file_get_contents('php://input');
$update = json_decode((string)$raw);
if (!is_object($update)) {
    http_response_code(200);
    exit('ok');
}

try {
    // ---- 3) Rate-limit (adminlar ozod) ----
    $fromId = (int)($update->message->from->id
        ?? $update->callback_query->from->id
        ?? $update->inline_query->from->id
        ?? 0);

    if ($fromId !== 0 && !AdminRepo::isAdmin($fromId)) {
        $ok = RateLimiter::allow(
            $fromId,
            (int)Config::get('ratelimit.max', 20),
            (int)Config::get('ratelimit.per', 10)
        );
        if (!$ok) {
            Logger::info('Rate limit oshib ketdi', ['user' => $fromId]);
            http_response_code(200);
            exit('ok');
        }
    }

    // ---- 4) Asosiy ishlov ----
    Router::dispatch($update);
} catch (\Throwable $e) {
    Logger::error('Dispatch xato: ' . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
}

// Telegram'ga har doim 200 — webhook qayta urilishining oldini oladi
http_response_code(200);
echo 'ok';
