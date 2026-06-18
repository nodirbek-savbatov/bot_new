<?php
/**
 * StartHandler — /start (deep-link kod + majburiy obuna + salomlashuv).
 */
final class StartHandler
{
    public static function handle(array $ctx): void
    {
        $cid = $ctx['cid'];
        StatRepo::inc('starts');
        State::clear($cid);
        NanoRepo::grantRegisterBonus($cid); // yangi user → ro'yxatdan o'tish bonusi (bir martalik)

        if (!ChannelManager::checkSubscription($cid)) {
            return; // obuna so'rovi ko'rsatildi
        }

        $parts = explode(' ', trim((string)$ctx['text']));
        $param = $parts[1] ?? '';
        $isAdmin = AdminRepo::isAdmin($cid);

        // Deep-link: /start <kod> (kino → darhol; serial → fasl/qism navigatsiyasi)
        if (is_digits($param)) {
            deletePrevMenu($cid);
            if (!openByCode($cid, (int)$param)) {
                Telegram::send($cid, "❌ Film topilmadi. Kod: <code>" . e($param) . "</code>");
            }
            self::sendKeyboard($cid, $isAdmin);
            return;
        }

        // Oddiy /start yoki /start check — eski navigatsiya menyusini tozalaymiz
        deletePrevMenu($cid);
        $text = $isAdmin
            ? "👑 <b>Xush kelibsiz, Admin!</b>"
            : "🎬 <b>Assalomu alaykum, " . e($ctx['name']) . "!</b>\n\n"
              . "Kino kodini yuboring yoki menyudan foydalaning.\n\n/last — Oxirgi yuklangan film";
        self::sendKeyboard($cid, $isAdmin, $text);
    }

    private static function sendKeyboard(int $cid, bool $isAdmin, string $text = ''): void
    {
        if ($text === '') {
            $text = $isAdmin ? '👑 Admin panel' : '🎬 Asosiy menyu';
        }
        $kb = $isAdmin ? Keyboard::admin() : Keyboard::main(false);
        showKeyboard($cid, $text, $kb);
    }
}
