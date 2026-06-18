<?php
/**
 * ContactHandler — "Adminga xabar" rejimi (foydalanuvchi → adminlar).
 *
 * Router::message() dan `if (ContactHandler::intercept($ctx)) return;` ko'rinishida chaqiriladi.
 *   - Rejim FAOL bo'lmaganda intercept() `false` qaytaradi → eski oqim teginilmaydi.
 *   - "📨 Adminga xabar" tugmasi (yoki AI dagi "Admindan so'rash" tugmasi) rejimni boshlaydi.
 *   - Bitta xabar barcha adminlarga yetkaziladi, so'ng avtomatik asosiy menyuga qaytadi.
 *   - Admin xabar tagidagi "✍️ Javob berish" tugmasi orqali javob beradi (CallbackHandler: reply:ID).
 */
final class ContactHandler
{
    public const STEP      = 'contact_admin';
    public const ENTER_BTN = '📨 Adminga xabar';
    public const EXIT_BTN  = '❌ Adminga xabar rejimidan chiqish';

    /** @return bool true = xabar iste'mol qilindi (eski handlerlar ishlamaydi). */
    public static function intercept(array $ctx): bool
    {
        $cid  = (int)$ctx['cid'];
        $text = trim((string)$ctx['text']);

        // 1) Kirish tugmasi — rejimda bo'lmagan userlar uchun ham ishlaydi.
        if ($text === self::ENTER_BTN) {
            self::enter($cid);
            return true;
        }

        // 2) Rejim faol emas → eski oqim davom etadi.
        if (State::step($cid) !== self::STEP) {
            return false;
        }

        // 3) Global buyruqlar rejimni yopadi, lekin Router o'z ishini bajaradi.
        if (in_array($text, ['/start', '/bekor', '/cancel'], true)) {
            State::clear($cid);
            return false;
        }

        // 4) Chiqish tugmasi.
        if ($text === self::EXIT_BTN) {
            self::leave($cid, "✅ Bekor qilindi. Asosiy menyuga qaytdingiz.");
            return true;
        }

        // 5) Asosiy: xabarni adminlarga yetkazish.
        self::send($ctx);
        return true;
    }

    /** Rejimga kirish (AI "Admindan so'rash" tugmasi ham shu yerga keladi). */
    public static function enter(int $cid): void
    {
        State::set($cid, self::STEP);
        showKeyboard($cid,
            "📨 <b>Adminga xabar</b>\n\n" .
            "Qaysi kinoni qidiryapsiz? Kino <b>nomini</b> (va imkon bo'lsa yilini) yozib yuboring — " .
            "adminlarga yetkazamiz, ular sizga javob berishadi.\n\n" .
            "Chiqish: <b>" . self::EXIT_BTN . "</b>",
            Keyboard::contact()
        );
    }

    /** Rejimdan chiqish — asosiy menyuni tiklaydi. */
    private static function leave(int $cid, string $msg): void
    {
        State::clear($cid);
        $isAdmin = AdminRepo::isAdmin($cid);
        showKeyboard($cid, $msg, $isAdmin ? Keyboard::admin() : Keyboard::main(false));
    }

    /** Xabarni barcha adminlarga yuboradi va foydalanuvchini menyuga qaytaradi. */
    private static function send(array $ctx): void
    {
        $cid      = (int)$ctx['cid'];
        $text     = trim((string)$ctx['text']);
        $hasMedia = !empty($ctx['video']) || !empty($ctx['document']);

        // Bo'sh/qo'llab-quvvatlanmaydigan xabar — rejimda qoldiramiz.
        if ($text === '' && !$hasMedia) {
            Telegram::send($cid, "💬 Iltimos, kino nomini <b>matn</b> ko'rinishida yozing.");
            return;
        }

        $admins = AdminRepo::all();
        if (!$admins) {
            self::leave($cid, "⚠️ Hozircha xabarni qabul qiladigan admin yo'q. Keyinroq urinib ko'ring.");
            return;
        }

        $uname = $ctx['username'] ? '@' . e((string)$ctx['username']) : '—';
        $head  = "📨 <b>Foydalanuvchidan kino so'rovi</b>\n\n" .
                 "👤 <b>" . e((string)$ctx['name']) . "</b>\n" .
                 "🆔 <code>$cid</code> | $uname\n\n";
        $body  = $text !== '' ? "💬 " . e($text) : "📎 <i>(media yuborildi)</i>";

        $replyMarkup = json_encode(['inline_keyboard' => Keyboard::adminReply($cid)]);

        $delivered = 0;
        foreach ($admins as $adminId) {
            if ($adminId === $cid) continue; // o'ziga yubormaymiz
            $r = Telegram::send($adminId, $head . $body, ['reply_markup' => $replyMarkup]);
            if (is_array($r) && ($r['ok'] ?? false)) {
                $delivered++;
            }
            // Media bo'lsa — asl xabarni ham nusxalaymiz.
            if ($hasMedia) {
                Telegram::copy($adminId, $cid, (int)$ctx['mid']);
            }
        }

        StatRepo::inc('contact');

        $msg = $delivered > 0
            ? "✅ <b>Xabaringiz adminlarga yuborildi!</b>\n\nTez orada javob berishadi. Asosiy menyuga qaytdingiz."
            : "⚠️ Xabarni yuborib bo'lmadi. Keyinroq urinib ko'ring.";
        self::leave($cid, $msg);
    }

    /**
     * Admin javobini foydalanuvchiga yetkazadi (matn yoki media).
     * AdminHandler 'contact_reply' stepidan chaqiriladi.
     */
    public static function replyToUser(int $targetId, array $ctx): bool
    {
        $text     = trim((string)$ctx['text']);
        $hasMedia = !empty($ctx['video']) || !empty($ctx['document']);

        if ($hasMedia) {
            $cap = $text !== '' ? "📩 <b>Admin javobi:</b>\n\n" . e($text) : "📩 <b>Admin javobi</b>";
            $res = Telegram::copy($targetId, (int)$ctx['cid'], (int)$ctx['mid'], ['caption' => $cap, 'parse_mode' => 'HTML']);
        } else {
            $res = Telegram::send($targetId, "📩 <b>Admin javobi:</b>\n\n" . e($text));
        }
        return is_array($res) && ($res['ok'] ?? false);
    }
}
