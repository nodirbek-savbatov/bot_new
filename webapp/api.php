<?php
/**
 * webapp/api.php — Web App (Mini App) backend kirish nuqtasi.
 *
 * Mavjud bot infratuzilmasidan (bootstrap, Database, repos, Telegram) foydalanadi —
 * YANGI backend yozilmagan. Faqat Web App so'rovlarini qabul qiladi:
 *   1) initData (HMAC) tekshiruvi  → soxta so'rov rad etiladi
 *   2) WebAppHandler::process()    → bot bilan bir xil mantiq, bir xil DB
 *   3) JSON javob                  → app ochiq qoladi (toast'lar ishlaydi)
 *
 * So'rov: POST application/json  { "action": "...", "movie_id": 123, ... }
 * initData: `X-Telegram-Init-Data` header yoki body.initData.
 */
require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 1) So'rov tanasi ----
$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = [];

// ---- 2) initData autentifikatsiya ----
$initData = (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ($body['initData'] ?? ''));
$user = WebApp::validate(
    $initData,
    (string)Config::get('bot.token'),
    (int)Config::get('webapp.auth_ttl', 86400)
);

// Faqat debug rejimida (brauzerda sinash uchun) test foydalanuvchiga ruxsat.
if ($user === null && Config::get('debug')) {
    $user = ['id' => (int)Config::get('admin.main'), 'first_name' => 'Test', 'last_name' => '', 'username' => ''];
}
if ($user === null) {
    api_out(['ok' => false, 'error' => 'auth'], 401);
}

$userId = (int)$user['id'];

// ---- 3) Foydalanuvchini yangilash + blok + rate-limit (bot bilan bir xil qoidalar) ----
UserRepo::touch(
    $userId,
    trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
    (string)($user['username'] ?? '')
);

if (UserRepo::isBlocked($userId) && !AdminRepo::isAdmin($userId)) {
    api_out(['ok' => false, 'error' => 'blocked'], 403);
}

if (!AdminRepo::isAdmin($userId)) {
    $ok = RateLimiter::allow(
        $userId,
        (int)Config::get('ratelimit.max', 20),
        (int)Config::get('ratelimit.per', 10)
    );
    if (!$ok) {
        api_out(['ok' => false, 'error' => 'rate_limit'], 429);
    }
}

// ---- 4) Amalni bajarish ----
try {
    $result = WebAppHandler::process($userId, $body);
} catch (\Throwable $e) {
    Logger::error('WebApp API xato: ' . $e->getMessage(), [
        'action' => $body['action'] ?? '',
        'line'   => $e->getLine(),
    ]);
    api_out(['ok' => false, 'error' => 'server'], 500);
}

api_out(is_array($result) ? $result : ['ok' => false, 'error' => 'bad_result']);
