<?php
/**
 * AiPrompt — AI uchun system prompt va kontekst yig'uvchi.
 *
 * Asosiy g'oya: BAZA BIRINCHI. Foydalanuvchi so'roviga mos kinolar avval bot bazasidan
 * (FilmRepo::searchFuzzy — noto'g'ri yozilgan nomlarni ham topadi) qidiriladi va promptga
 * kontekst sifatida qo'shiladi. Gemini'ga "faqat shu kodlardan foydalan" deb buyuriladi.
 * Bazada topilmasagina Gemini o'z bilimidan foydalanadi (va "botda yo'q" deб belgilaydi).
 */
final class AiPrompt
{
    /** Joriy so'rovga mos bazadagi kinolar (kontekst manbai). */
    public static function dbFilms(string $message): array
    {
        $message = trim($message);
        if ($message === '') return [];
        return FilmRepo::searchFuzzy($message, 8);
    }

    /** Kesh kaliti uchun baza imzosi (mos kodlar to'plami). */
    public static function signature(array $dbFilms): string
    {
        $codes = array_map(static fn($f) => (int)$f['code'], $dbFilms);
        sort($codes);
        return implode(',', $codes);
    }

    /** To'liq system prompt: rol + qoidalar + baza konteksti + foydalanuvchi didi. */
    public static function system(int $uid, array $dbFilms): string
    {
        $bot = Telegram::username();

        $base =
            "Sen \"@$bot\" Telegram kino botining professional AI yordamchisisan. " .
            "Doimo O'ZBEK TILIDA, qisqa, samimiy va emoji bilan javob ber.\n\n" .
            "VAZIFALARING:\n" .
            "• Oddiy suhbat (salom, rahmat, qalaysan) — samimiy javob ber.\n" .
            "• Kino topish, tavsiya qilish (janr, aktyor, yil, tavsif bo'yicha).\n" .
            "• Foydalanuvchi kino tavsifini yozsa (mas. \"kosmos haqida kino\") mos kinoni top.\n" .
            "• Kino haqida qisqa ma'lumot (janr, yil, reyting, syujet) — bilsang ayt.\n\n" .
            "ENG MUHIM QOIDA — BAZA BIRINCHI:\n" .
            "• Quyidagi [BAZADAGI MOS KINOLAR] ro'yxatida foydalanuvchi so'ragan kino bo'lsa, " .
            "AVVAL o'shani tavsiya qil va kodini ko'rsat. Foydalanuvchiga \"botda <kod> raqamini " .
            "yuboring, kino sizga keladi\" deб ayt.\n" .
            "• FAQAT ro'yxatdagi kodlardan foydalan — o'zingdan kod O'YLAB CHIQARMA.\n" .
            "• Ro'yxatda mos kino bo'lmasa, o'z bilimingdan foydalanib tavsiya ber, lekin " .
            "\"bu kino hozircha bot bazasida yo'q\" deб ochiq ayt.\n";

        return $base . self::dbBlock($dbFilms) . self::tasteBlock($uid);
    }

    /** Bazadagi mos kinolar bloki. */
    private static function dbBlock(array $dbFilms): string
    {
        if (!$dbFilms) {
            return "\n[BAZADAGI MOS KINOLAR]\n(So'rov bo'yicha bazada aniq moslik topilmadi.)\n";
        }
        $lines = ["\n[BAZADAGI MOS KINOLAR] — faqat shu kodlardan foydalan:"];
        foreach ($dbFilms as $f) {
            $type = ($f['type'] === 'serial') ? 'serial' : 'film';
            $desc = trim((string)($f['description'] ?? ''));
            $desc = $desc !== '' ? ' — ' . mb_substr($desc, 0, 160) : '';
            $extra = ($type === 'serial' && (int)$f['season'] > 0)
                ? " ({$f['season']}-fasl {$f['episode']}-qism)" : '';
            $lines[] = "• \"{$f['title']}\"$extra [kod: {$f['code']}, $type, 👁 {$f['views']}]$desc";
        }
        return implode("\n", $lines) . "\n";
    }

    /** Foydalanuvchi didi — oxirgi ko'rgan kinolari (did asosida tavsiya uchun). */
    private static function tasteBlock(int $uid): string
    {
        $rows = WebAppRepo::history($uid, 8);
        if (!$rows) {
            return "\n[FOYDALANUVCHI DIDI]\n(Hali ko'rilgan kino yo'q — umumiy tavsiyalar ber.)\n";
        }
        $titles = array_map(static fn($f) => '"' . $f['title'] . '"', $rows);
        return "\n[FOYDALANUVCHI DIDI] oxirgi ko'rgan kinolari: " . implode(', ', $titles) .
               ".\nAgar foydalanuvchi tavsiya so'rasa, shu didga mos kinolarni hisobga ol.\n";
    }
}
