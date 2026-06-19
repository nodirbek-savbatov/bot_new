<?php
/**
 * install.php — bir martalik o'rnatuvchi.
 *   CLI:  php database/install.php [webhook_url]
 *   Web:  https://domen/database/install.php?key=<BOSH_ADMIN_ID>&url=<webhook_url>
 *
 * Bajaradi: jadval yaratish, bosh admin seed, secret generatsiya,
 * setWebhook, bot username kesh, default kanallarni seed.
 * O'rnatgandan keyin bu faylni o'chirib qo'yish tavsiya etiladi.
 */
require __DIR__ . '/../bootstrap.php';

$cli = (PHP_SAPI === 'cli');
$out = function (string $s) use ($cli): void {
    echo $s . ($cli ? "\n" : "<br>\n");
    if (!$cli) { @ob_flush(); @flush(); }
};

if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    $key = $_GET['key'] ?? '';
    if ((string)$key !== (string)Config::get('admin.main')) {
        http_response_code(403);
        exit("Ruxsat yo'q. ?key=<bosh_admin_id> bilan oching yoki CLI ishlating.");
    }
}

$webhookUrl = $cli
    ? ($argv[1] ?? (string)Config::get('bot.webhook_url'))
    : ($_GET['url'] ?? (string)Config::get('bot.webhook_url'));

$out('== Kino Bot o\'rnatish ==');

// ---- 1) DB ulanish ----
if (!Database::isConnected()) {
    $out('❌ DB ulanmadi. config/config.php → db sozlamalarini tekshiring.');
    exit(1);
}
$out('✅ DB ulanish OK');

// ---- 2) Jadvallar ----
try {
    $sql = (string)file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // izohlarni olib tashlash
    $statements = array_filter(array_map('trim', explode(';', (string)$sql)));
    foreach ($statements as $stmt) {
        if ($stmt !== '') Database::pdo()->exec($stmt);
    }
    $out('✅ Jadvallar yaratildi (' . count($statements) . ' ta so\'rov)');
} catch (\Throwable $e) {
    $out('❌ Schema xatosi: ' . $e->getMessage());
    exit(1);
}

// ---- 2.1) Migratsiya: eski bazaga Nano ustunlarini qo'shish (idempotent) ----
try {
    $colMissing = static function (string $table, string $col): bool {
        return (int)Database::value(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $col]
        ) === 0;
    };
    $migrations = [
        ['users',  'nano_balance', "ALTER TABLE users ADD COLUMN nano_balance INT NOT NULL DEFAULT 0"],
        ['users',  'last_daily',   "ALTER TABLE users ADD COLUMN last_daily DATETIME NULL"],
        ['films',  'poster',       "ALTER TABLE films ADD COLUMN poster VARCHAR(255) NOT NULL DEFAULT '' AFTER episode"],
        ['series', 'poster',       "ALTER TABLE series ADD COLUMN poster VARCHAR(255) NOT NULL DEFAULT '' AFTER title"],
    ];
    $applied = 0;
    foreach ($migrations as [$table, $col, $ddl]) {
        if ($colMissing($table, $col)) {
            Database::pdo()->exec($ddl);
            $applied++;
        }
    }
    $out($applied > 0 ? "✅ Migratsiya: $applied ta ustun qo'shildi" : '✅ Migratsiya: ustunlar mavjud');
} catch (\Throwable $e) {
    $out('⚠️ Migratsiya xatosi: ' . $e->getMessage());
}

// ---- 3) Bosh admin ----
$mainAdmin = (int)Config::get('admin.main');
if ($mainAdmin > 0) {
    AdminRepo::add($mainAdmin, true);
    $out("✅ Bosh admin: $mainAdmin");
} else {
    $out('⚠️ config.admin.main belgilanmagan!');
}

// ---- 4) Secret token ----
$secret = (string)Config::get('bot.secret');
if ($secret === '' || $secret === 'GENERATE_ON_INSTALL') {
    $secret = bin2hex(random_bytes(16));
    $cfgPath = BASE_PATH . '/config/config.php';
    $contents = (string)file_get_contents($cfgPath);
    $new = preg_replace("/'secret'\s*=>\s*'[^']*'/", "'secret' => '$secret'", $contents, 1);
    if ($new !== null && $new !== $contents) {
        file_put_contents($cfgPath, $new);
        $out('✅ Secret token generatsiya qilindi va config.php ga saqlandi');
    } else {
        $out("⚠️ Secret config.php ga yozilmadi — qo'lda kiriting: $secret");
    }
}

// ---- 5) Bot username kesh ----
$me = Telegram::call('getMe');
if (is_array($me) && ($me['ok'] ?? false)) {
    $username = $me['result']['username'] ?? '';
    SettingRepo::set('bot_username', $username);
    $out("✅ Bot: @$username");
} else {
    $out('⚠️ getMe muvaffaqiyatsiz — bot tokenini tekshiring.');
}

// ---- 6) Webhook + secret ----
if ($webhookUrl) {
    $res = Telegram::call('setWebhook', [
        'url'                  => $webhookUrl,
        'secret_token'         => $secret,
        'allowed_updates'      => json_encode(['message', 'callback_query', 'inline_query']),
        'drop_pending_updates' => 'true',
        'max_connections'      => 40,
    ]);
    if (is_array($res) && ($res['ok'] ?? false)) {
        $out("✅ Webhook o'rnatildi: $webhookUrl");
    } else {
        $out('❌ Webhook xato: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
    }
} else {
    $out("⚠️ webhook_url berilmagan — qo'lda o'rnating.");
}

// ---- 6.1) Web App chat menyu tugmasi ----
$webappUrl = (string)Config::get('webapp.url');
if ($webappUrl !== '') {
    $res = Telegram::call('setChatMenuButton', [
        'menu_button' => json_encode([
            'type' => 'web_app',
            'text' => '🎬 Kino App',
            'web_app' => ['url' => $webappUrl],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    if (is_array($res) && ($res['ok'] ?? false)) {
        $out("✅ Web App menyu tugmasi o'rnatildi: $webappUrl");
    } else {
        $out('⚠️ Menyu tugmasi o\'rnatilmadi (webapp.url HTTPS ekanini tekshiring).');
    }
}

// ---- 7) Default kanallarni seed (faqat bo'sh bo'lsa) ----
if (ChannelRepo::count() === 0) {
    $defaults = [
        ['type' => 'base',     'username' => 'k1no_vaqti_uz'],
        ['type' => 'main',     'username' => 'Kino_vaqti_Premyeralar'],
        ['type' => 'required', 'username' => 'Kino_vaqti_Premyeralar'],
    ];
    foreach ($defaults as $d) {
        $info = ChannelManager::validate($d['username']);
        $data = [
            'username' => $d['username'],
            'chat_id'  => $info['chat_id'] ?? null,
            'title'    => $info['title'] ?? $d['username'],
            'type'     => $d['type'],
            'active'   => 1,
        ];
        if ($d['type'] === 'required') {
            ChannelRepo::addRequired($data);
        } else {
            ChannelRepo::setSingle($d['type'], $data);
        }
        $mark = isset($info['chat_id']) ? '✅' : '⚠️ (tekshirib bo\'lmadi)';
        $out("  $mark {$d['type']}: @{$d['username']}");
    }
    $out('✅ Default kanallar seed qilindi');
}

$out('');
$out('🎉 O\'rnatish tugadi! Endi botga /start yuboring.');
$out('⚠️ Xavfsizlik uchun database/install.php ni o\'chirib qo\'ying.');
