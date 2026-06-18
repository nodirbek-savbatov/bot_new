<?php
/**
 * BOOTSTRAP — barcha modullarni yuklaydi va infratuzilmani tayyorlaydi.
 * Ham webhook (bot.php), ham CLI/cron skriptlar shu fayldan foydalanadi.
 */
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tashkent');

define('BASE_PATH', __DIR__);

// ---- 1) Konfiguratsiya ----
$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("config/config.php topilmadi. 'config/config.sample.php' dan nusxa oling.");
}
$config = require $configFile;

// ---- 2) Yadro klasslar ----
require BASE_PATH . '/classes/Config.php';
Config::load($config);

require BASE_PATH . '/classes/Logger.php';
Logger::init(BASE_PATH . '/logs', (string)Config::get('log.level', 'info'), (int)Config::get('log.days', 14));

require BASE_PATH . '/classes/Database.php';
require BASE_PATH . '/classes/Telegram.php';
require BASE_PATH . '/classes/State.php';
require BASE_PATH . '/classes/RateLimiter.php';
require BASE_PATH . '/classes/WebApp.php';
require BASE_PATH . '/classes/Router.php';

Telegram::init((string)Config::get('bot.token'), (string)Config::get('bot.api', 'https://api.telegram.org'));
RateLimiter::init(BASE_PATH . '/logs/rl');

// ---- 3) Ma'lumot qatlami (repositories) ----
foreach (['SettingRepo', 'UserRepo', 'AdminRepo', 'FilmRepo', 'StatRepo', 'ChannelRepo', 'BroadcastRepo', 'WebAppRepo', 'NanoRepo', 'AiRepo'] as $repo) {
    require BASE_PATH . "/database/$repo.php";
}

// ---- 4) Helperlar + global yordamchilar (db(), tg(), ...) ----
require BASE_PATH . '/functions/helpers.php';

// ---- 5) Klaviaturalar / admin / inline / handlerlar ----
require BASE_PATH . '/keyboards/Keyboard.php';
require BASE_PATH . '/admin/ChannelManager.php';
require BASE_PATH . '/admin/NanoAdmin.php';
require BASE_PATH . '/inline/InlineHandler.php';

// AI Kino Yordamchisi moduli (alohida service'lar)
foreach (['GeminiClient', 'AiPrompt', 'AiService', 'AiHandler'] as $ai) {
    require BASE_PATH . "/ai/$ai.php";
}

foreach (['StartHandler', 'MessageHandler', 'AdminHandler', 'CallbackHandler', 'WebAppHandler', 'ProfileHandler', 'ContactHandler'] as $h) {
    require BASE_PATH . "/handlers/$h.php";
}

// ---- 6) Global xato/exception tutqichlari ----
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    Logger::error("PHP xato: $str", ['file' => $file, 'line' => $line, 'no' => $no]);
    return true; // throw qilmaymiz — bot ishlashda davom etsin
});

set_exception_handler(function (\Throwable $e): void {
    Logger::error('Tutilmagan exception: ' . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    if (Config::get('debug')) {
        http_response_code(500);
        echo 'Xato: ' . $e->getMessage();
    }
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::error('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
    }
});

// Production'da xatolar ekranga chiqmasin
if (!Config::get('debug')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
