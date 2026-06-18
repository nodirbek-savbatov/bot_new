<?php
/**
 * AiHandler — AI rejimini bot bilan bog'lovchi yagona nuqta.
 *
 * Router::message() dan `if (AiHandler::intercept($ctx)) return;` ko'rinishida chaqiriladi.
 *   - AI rejimi FAOL bo'lmagan userlar uchun intercept() `false` qaytaradi → eski oqim teginilmaydi.
 *   - "🤖 AI Yordamchi" tugmasi rejimni boshlaydi; "❌ AI Rejimidan Chiqish" tugmasi tugatadi.
 *   - Rejim faol bo'lsa, barcha matn xabarlari AI ga uzatiladi (coin yechib).
 *
 * Tartib (spec): cooldown → balans tekshir → coin yech → AI → javob → tranzaksiya log.
 */
final class AiHandler
{
    public const ENTER_BTN = '🤖 AI Yordamchi';
    public const EXIT_BTN  = '❌ AI Rejimidan Chiqish';

    /** @return bool true = xabar iste'mol qilindi (eski handlerlar ishlamaydi). */
    public static function intercept(array $ctx): bool
    {
        $cid  = (int)$ctx['cid'];
        $text = trim((string)$ctx['text']);

        // 1) Kirish tugmasi — AI rejimida bo'lmagan userlar uchun ham ishlaydi.
        if ($text === self::ENTER_BTN) {
            if (!(bool)Config::get('ai.enabled', true)) {
                showMenu($cid, "🤖 AI yordamchi hozircha o'chirilgan.");
                return true;
            }
            self::enter($cid);
            return true;
        }

        // 2) AI rejimi faol emas → eski oqim davom etadi.
        if (!AiRepo::isActive($cid)) {
            return false;
        }

        // 3) Global buyruqlar AI sessiyasini yopadi, lekin Router o'z ishini bajaradi.
        if (in_array($text, ['/start', '/bekor', '/cancel'], true)) {
            AiRepo::stop($cid);
            AiRepo::clearMessages($cid);
            return false; // Router /start yoki /bekor ni bajaradi (menyu tiklanadi)
        }

        // 4) Chiqish tugmasi.
        if ($text === self::EXIT_BTN) {
            self::leave($cid);
            return true;
        }

        // 5) Media yoki bo'sh matn — AI ga yubormaymiz.
        if ($text === '' || !empty($ctx['video']) || !empty($ctx['document'])) {
            Telegram::send($cid, "💬 Menga matn yozing — masalan: <i>«jangari kino tavsiya qil»</i>.");
            return true;
        }

        // 6) Asosiy AI so'rovi.
        self::process($cid, $text);
        return true;
    }

    // ---- Rejimga kirish / chiqish ----

    private static function enter(int $cid): void
    {
        AiRepo::start($cid);
        State::clear($cid);                  // yarim qolgan stepni tozalaymiz
        NanoRepo::grantRegisterBonus($cid);  // har ehtimolga qarshi (idempotent)

        $balance = NanoRepo::balance($cid);
        $cost    = NanoRepo::cfg('ai_cost', 10);

        showKeyboard($cid,
            "🤖 <b>AI Kino Yordamchi</b>\n" .
            "🪙 Nano Coin: <b>$balance</b>\n\n" .
            "Menga yozing — kino tavsiya qilaman, qidiraman yoki suhbatlashaman.\n" .
            "💸 Har bir savol: <b>$cost</b> Nano Coin\n\n" .
            "Chiqish: <b>❌ AI Rejimidan Chiqish</b>",
            Keyboard::ai()
        );
    }

    private static function leave(int $cid): void
    {
        AiRepo::stop($cid);
        AiRepo::clearMessages($cid);
        State::clear($cid);
        $isAdmin = AdminRepo::isAdmin($cid);
        showKeyboard($cid,
            "✅ AI rejimidan chiqdingiz.\nAsosiy menyuga qaytdingiz.",
            $isAdmin ? Keyboard::admin() : Keyboard::main(false)
        );
    }

    // ---- Asosiy so'rov ----

    private static function process(int $cid, string $text): void
    {
        // 1) Yengil flood himoya (coin narxidan tashqari).
        $cooldown = (int)Config::get('ai.cooldown', 3);
        if (!AiRepo::cooldownOk($cid, $cooldown)) {
            Telegram::send($cid, "⏳ Birozdan keyin urinib ko'ring.");
            return;
        }

        // 2) Balans tekshiruvi.
        $cost    = NanoRepo::cfg('ai_cost', 10);
        $balance = NanoRepo::balance($cid);
        if ($balance < $cost) {
            Telegram::send($cid,
                "❌ <b>Nano Coin yetarli emas.</b>\n\n" .
                "🪙 Balans: <b>$balance</b> (kerak: <b>$cost</b>)\n" .
                "🎁 <b>👤 Profil</b> orqali kunlik bonusni oling."
            );
            return;
        }

        // 3) "yozmoqda..." ko'rsatkichi.
        Telegram::call('sendChatAction', ['chat_id' => $cid, 'action' => 'typing']);

        // 4) Kontekst (joriy savoldan oldingi tarix).
        $history = AiRepo::recent($cid, (int)Config::get('ai.context_messages', 24));

        // 5) Coin yechish (atomik — manfiy balans/double-spend yo'q; log shu yerda).
        if (!NanoRepo::debit($cid, $cost, 'ai_request')) {
            Telegram::send($cid, "❌ Nano Coin yetarli emas.");
            return;
        }

        // 6) AI so'rovi.
        $res = AiService::ask($cid, $text, $history);

        // 7) Xato bo'lsa — coin qaytariladi.
        if (!($res['ok'] ?? false)) {
            NanoRepo::credit($cid, $cost, 'refund', 'ai_fail:' . ($res['error'] ?? '?'));
            $newBal = NanoRepo::balance($cid);
            $msg = (($res['error'] ?? '') === 'no_key')
                ? "⚙️ AI hali sozlanmagan (API kalit kiritilmagan). Admin bilan bog'laning."
                : "❌ Hozir javob bera olmadim, birozdan keyin urinib ko'ring.\n🪙 Coin qaytarildi.";
            Telegram::send($cid, "$msg\n🪙 Qoldiq: <b>$newBal</b>");
            return;
        }

        // 8) Xotiraga yozamiz + javobni yuboramiz (qoldiq bilan).
        AiRepo::addMessage($cid, 'user', $text);
        AiRepo::addMessage($cid, 'model', (string)$res['text']);

        $balance = NanoRepo::balance($cid);

        // AI aniq kino TAVSIYA qildi-yu, u bazada topilmagan bo'lsa — "Admindan so'rash" tugmasi.
        // Oddiy suhbatda (tavsiya yo'q) yoki kino bazada topilganda tugma chiqmaydi.
        $opts = [];
        if (($res['recommended'] ?? false) && (int)($res['dbCount'] ?? 0) === 0) {
            $opts['reply_markup'] = json_encode(['inline_keyboard' => Keyboard::askAdmin()]);
        }

        Telegram::send($cid, self::format((string)$res['text']) . "\n\n🪙 Qoldiq: <b>$balance</b>", $opts);
    }

    /**
     * AI matnini Telegram HTML uchun xavfsizlaydi: avval escape, keyin oddiy markdownni
     * (**qalin**, `kod`) HTML teglariga aylantiradi.
     */
    private static function format(string $text): string
    {
        $safe = e($text);
        $safe = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $safe);
        $safe = preg_replace('/`([^`]+?)`/su', '<code>$1</code>', $safe);
        return $safe ?? $text;
    }
}
