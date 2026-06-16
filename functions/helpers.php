<?php
/**
 * helpers.php — global yordamchi funksiyalar.
 */

/** HTML uchun xavfsiz escape (parse_mode=HTML). Bug #3 himoyasi. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Matnni belgilangan uzunlikgacha qisqartiradi. */
function truncate(string $s, int $len): string
{
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}

/** Faqat raqamlardan iboratmi (bo'sh emas). */
function is_digits(string $s): bool
{
    return $s !== '' && ctype_digit($s);
}

/**
 * Film/serial uchun caption (HTML, escape qilingan).
 */
function filmCaption(array $f, bool $withCode = true): string
{
    $icon = ($f['type'] === 'serial') ? '📺' : '🎬';
    $cap  = "$icon <b>" . e($f['title']) . "</b>\n";

    if ($f['type'] === 'serial' && (int)$f['season'] > 0) {
        $cap .= "📌 {$f['season']}-fasl, {$f['episode']}-qism\n";
    }
    if (!empty($f['description'])) {
        $cap .= "\n" . e((string)$f['description']) . "\n";
    }
    $cap .= "\n👁 <b>{$f['views']}</b>   👍 {$f['likes']}  👎 {$f['dislikes']}";
    if ($withCode) {
        $cap .= "\n\n🔢 Kod: <code>{$f['code']}</code>";
    }
    return $cap;
}

/**
 * Xabardan foydalanuvchi ismini yig'adi.
 */
function fullName(object $from): string
{
    return trim(($from->first_name ?? '') . ' ' . ($from->last_name ?? ''));
}

// =====================================================================
// UI XABAR HAYOT-SIKLI (bug #5 yechimi)
// Ikki turdagi bot xabari qat'iy ajratilgan:
//   1) menu_msg_id — navigatsiya/inline menyular (yangi kelganda eskisi o'chadi)
//   2) kbd_msg_id  — pastki reply-keyboard tashuvchi (faqat rejim almashganda)
// Reply-keyboardli xabar HECH QACHON menyu o'chirish siklida bo'lmaydi.
// =====================================================================

/** Oldingi navigatsiya menyusini o'chiradi. */
function deletePrevMenu(int $chatId): void
{
    $mid = State::menuId($chatId);
    if ($mid) {
        Telegram::delete($chatId, $mid);
        State::setMenu($chatId, null);
    }
}

/** Navigatsiya menyusini ko'rsatadi: eskisini o'chirib yangisini yuboradi. */
function showMenu(int $chatId, string $text, array $inlineKeyboard = []): ?array
{
    deletePrevMenu($chatId);
    $opts = [];
    if ($inlineKeyboard) {
        $opts['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
    }
    $r = Telegram::send($chatId, $text, $opts);
    if (is_array($r) && ($r['ok'] ?? false)) {
        State::setMenu($chatId, (int)$r['result']['message_id']);
    }
    return $r;
}

/**
 * Pastki reply-keyboardni yangilaydi — faqat rejim almashganda (admin↔user, /start).
 * Yangi xabar yuborilgandan KEYIN eskisi o'chiriladi → tugmalar miltillamaydi/yo'qolmaydi.
 */
function showKeyboard(int $chatId, string $text, string $replyMarkup): ?array
{
    $old = State::kbdId($chatId);
    $r = Telegram::send($chatId, $text, ['reply_markup' => $replyMarkup]);
    if (is_array($r) && ($r['ok'] ?? false)) {
        State::setKbd($chatId, (int)$r['result']['message_id']);
        if ($old) {
            Telegram::delete($chatId, $old);
        }
    }
    return $r;
}

// =====================================================================
// BAZA / FILM YETKAZISH
// =====================================================================

/** Baza kanal manzili (chat_id yoki @username). */
function baseTarget(): int|string|null
{
    $b = ChannelRepo::base();
    if (!$b) return null;
    return $b['chat_id'] ? (int)$b['chat_id'] : ($b['username'] ? '@' . $b['username'] : null);
}

/** Asosiy (post) kanal manzili. */
function mainTarget(): int|string|null
{
    $m = ChannelRepo::main();
    if (!$m) return null;
    return $m['chat_id'] ? (int)$m['chat_id'] : ($m['username'] ? '@' . $m['username'] : null);
}

/**
 * Filmni foydalanuvchiga yetkazadi (baza kanaldan copyMessage).
 * Video o'chirilmaydi; ko'rish hisoblanadi.
 */
function deliverFilm(int $chatId, int $code): bool
{
    $film = FilmRepo::get($code);
    if (!$film) return false;

    $base = baseTarget();
    if ($base === null) {
        Logger::error('Baza kanal sozlanmagan — film yetkazib bo\'lmadi', ['code' => $code]);
        return false;
    }

    $res = Telegram::copy($chatId, $base, (int)$film['msg_id'], [
        'caption'      => filmCaption($film),
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => Keyboard::reactions($film)]),
    ]);

    if (is_array($res) && ($res['ok'] ?? false)) {
        FilmRepo::addView($code);
        StatRepo::inc('views');
        return true;
    }
    return false;
}

/**
 * Filmni asosiy kanalga post qiladi (admin "Kanalga yuborish").
 */
function postToChannel(int $code): bool
{
    $film = FilmRepo::get($code);
    if (!$film) return false;
    $base = baseTarget();
    $main = mainTarget();
    if ($base === null || $main === null) {
        Logger::error('Kanal post: baza yoki asosiy kanal sozlanmagan');
        return false;
    }
    $botUser = Telegram::username();

    $res = Telegram::copy($main, $base, (int)$film['msg_id'], [
        'caption'      => filmCaption($film, false) . "\n\n▶️ Olish: @$botUser → <code>{$film['code']}</code>",
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode([
            'inline_keyboard' => [[
                ['text' => '▶️ Filmni olish', 'url' => "https://t.me/$botUser?start={$film['code']}"],
            ]],
        ]),
    ]);
    if (!is_array($res) || !($res['ok'] ?? false)) {
        return false;
    }

    // Bot API 7.0+ — options InputPollOption obyektlari ({text}) bo'lishi kerak
    Telegram::call('sendPoll', [
        'chat_id'      => $main,
        'question'     => '🎬 "' . truncate($film['title'], 200) . '" — sizga yoqdimi?',
        'options'      => json_encode([
            ['text' => '👍 Yoqdi'],
            ['text' => '👎 Yoqmadi'],
            ['text' => "🤔 Hali ko'rmadim"],
        ], JSON_UNESCAPED_UNICODE),
        'is_anonymous' => 'true',
        'type'         => 'regular',
    ]);
    return true;
}

/**
 * Foydalanuvchilar ro'yxatini TXT faylga yozadi, yo'lni qaytaradi.
 */
function exportUsersTxt(): string
{
    $users = UserRepo::allForExport();
    $lines = ["Foydalanuvchilar ro'yxati | " . date('Y-m-d H:i:s'), str_repeat('=', 50)];
    $i = 1;
    foreach ($users as $u) {
        $uname   = $u['username'] ? "@{$u['username']}" : '—';
        $blocked = (int)$u['blocked'] ? ' [BLOKLANGAN]' : '';
        $lines[] = "$i. {$u['name']} | $uname | ID: {$u['id']} | {$u['joined']}$blocked";
        $i++;
    }
    $lines[] = str_repeat('=', 50);
    $lines[] = 'Jami: ' . count($users) . ' ta';

    $path = BASE_PATH . '/logs/users_export.txt';
    file_put_contents($path, implode("\n", $lines));
    return $path;
}
