<?php
/**
 * KONFIGURATSIYA SHABLONI.
 * Nusxa oling -> config.php, qiymatlarni to'ldiring.
 *   cp config/config.sample.php config/config.php
 */
return [
    'bot' => [
        'token'    => 'BOT_TOKEN_BU_YERGA',
        'secret'   => 'GENERATE_ON_INSTALL', // install.php avtomatik generatsiya qiladi
        'api'      => 'https://api.telegram.org',
        'username' => '',
        'webhook_url' => 'https://SIZNING-DOMEN.uz/bot.php',
    ],

    'admin' => [
        'main' => 0, // bosh admin Telegram ID
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'movie_bot',
        'user'    => 'movie_bot',
        'pass'    => 'DB_PAROL',
        'charset' => 'utf8mb4',
    ],

    'state'     => ['timeout' => 600],
    'ratelimit' => ['max' => 20, 'per' => 10],
    'broadcast' => ['batch' => 25, 'sleep_ms' => 40, 'retries' => 3],

    // AI Kino Yordamchisi (Google Gemini)
    'ai' => [
        'enabled'  => true,
        'provider' => 'gemini',
        'gemini'   => [
            'api_key' => '',                 // GEMINI_API_KEY
            'model'   => 'gemini-2.5-flash', // yoki gemini-2.5-pro
            'api'     => 'https://generativelanguage.googleapis.com',
        ],
        'context_messages' => 24,
        'cache_ttl'        => 86400,
        'cooldown'         => 3,
        'max_output'       => 800,
    ],

    // Nano Coin — AI uchun ichki valyuta
    'nano' => [
        'register_bonus' => 100, // REGISTER_BONUS
        'daily_bonus'    => 10,  // DAILY_BONUS
        'ai_cost'        => 10,  // AI_REQUEST_COST
    ],

    'log'       => ['level' => 'info', 'days' => 14],
    'debug'     => false,
];
