<?php
/**
 * ProfileHandler — bot ichidagi foydalanuvchi profili (🪙 Nano Coin balansi + 🎁 kunlik bonus).
 * Web App profili (WebAppHandler::profileData) bilan bir xil ma'lumotni botda ko'rsatadi.
 */
final class ProfileHandler
{
    /** Profil menyusini ko'rsatadi (yangi xabar). */
    public static function show(int $cid): void
    {
        NanoRepo::grantRegisterBonus($cid); // idempotent — yangi user bo'lsa bonus beriladi
        showMenu($cid, self::text($cid), Keyboard::profileInline(NanoRepo::dailyAvailable($cid)));
    }

    /** Kunlik bonusni oladi va profil xabarini yangilaydi (callback: nano_daily). */
    public static function claim(int $cid, int $msgId): void
    {
        $r = NanoRepo::claimDaily($cid);
        $note = $r['ok']
            ? "🎉 Kunlik bonus olindi: <b>+{$r['amount']}</b> 🪙"
            : "⏳ Bugun allaqachon olgansiz. Keyingisi: " . self::waitText((int)($r['wait'] ?? 0));

        Telegram::editText($cid, $msgId, self::text($cid, $note), [
            'reply_markup' => json_encode(['inline_keyboard' => Keyboard::profileInline(NanoRepo::dailyAvailable($cid))]),
        ]);
        State::setMenu($cid, $msgId);
    }

    // ---- Ichki ----

    private static function text(int $cid, string $note = ''): string
    {
        $balance   = NanoRepo::balance($cid);
        $favs      = WebAppRepo::favCount($cid);
        $hist      = WebAppRepo::historyCount($cid);
        $available = NanoRepo::dailyAvailable($cid);

        $bonusLine = $available
            ? "🎁 Kunlik bonus: <b>Mavjud</b> ✅"
            : "🎁 Kunlik bonus: ⏳ " . self::waitText(NanoRepo::nextDailyWait($cid));

        $t = "👤 <b>Profil</b>\n\n" .
             "🆔 ID: <code>$cid</code>\n" .
             "🪙 Nano Coin: <b>$balance</b>\n" .
             "$bonusLine\n\n" .
             "📝 Ko'rilgan: <b>$hist</b>   ⭐ Sevimli: <b>$favs</b>\n\n" .
             "🤖 AI yordamchidan foydalanish uchun <b>🤖 AI Yordamchi</b> tugmasini bosing.";

        if ($note !== '') {
            $t .= "\n\n$note";
        }
        return $t;
    }

    private static function waitText(int $sec): string
    {
        if ($sec <= 0) return 'tez orada';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($h > 0) return "{$h} soat {$m} daqiqa";
        if ($m > 0) return "{$m} daqiqa";
        return 'tez orada';
    }
}
