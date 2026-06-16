<?php
/**
 * ChannelManager — kanallarni bot ichidan boshqarish (talab #11) va
 * majburiy obunani tekshirish.
 */
final class ChannelManager
{
    /**
     * Kanal username yoki ID ni getChat orqali tekshiradi.
     * Topilsa: ['chat_id','title','username','bot_admin'] qaytadi, aks holda [].
     */
    public static function validate(string $input): array
    {
        $input = ltrim(trim($input), '@');
        if ($input === '') return [];

        $chatId = is_numeric($input) ? (int)$input : '@' . $input;
        $res = Telegram::getChat($chatId);
        if (!is_array($res) || !($res['ok'] ?? false)) {
            return [];
        }
        $c = $res['result'];

        // Bot kanalda adminmi? (post/copy uchun kerak)
        $botAdmin = false;
        $member = Telegram::getChatMember($c['id'], Telegram::botId());
        if (is_array($member) && ($member['ok'] ?? false)) {
            $botAdmin = in_array($member['result']['status'] ?? '', ['administrator', 'creator'], true);
        }

        return [
            'chat_id'   => $c['id'] ?? null,
            'title'     => $c['title'] ?? ($c['username'] ?? $input),
            'username'  => $c['username'] ?? (is_numeric($input) ? '' : $input),
            'bot_admin' => $botAdmin,
        ];
    }

    /**
     * Foydalanuvchi barcha majburiy kanallarga obunami?
     * Yo'q bo'lsa obuna so'rovini ko'rsatadi va false qaytaradi.
     */
    public static function checkSubscription(int $uid): bool
    {
        $required = ChannelRepo::required();
        if (!$required) return true;

        $keyboard = [];
        $hasUnsub = false;

        foreach ($required as $ch) {
            $target = $ch['chat_id'] ? (int)$ch['chat_id'] : '@' . $ch['username'];
            $member = Telegram::getChatMember($target, $uid);
            $status = $member['result']['status'] ?? '';
            $ok = in_array($status, ['creator', 'administrator', 'member'], true);

            $title = $ch['title'] ?: ($ch['username'] ?: 'Kanal');
            $url   = $ch['username'] ? "https://t.me/{$ch['username']}" : 'https://t.me';
            $keyboard[] = [['text' => ($ok ? '✅ ' : '❌ ') . $title, 'url' => $url]];
            if (!$ok) $hasUnsub = true;
        }

        if ($hasUnsub) {
            $keyboard[] = [['text' => '🔄 Tekshirish', 'callback_data' => 'check_sub']];
            showMenu($uid, "⚠️ <b>Botdan foydalanish uchun quyidagi kanallarga obuna bo'ling:</b>", $keyboard);
            return false;
        }
        return true;
    }

    // ---- Admin panel ----

    public static function panelText(): string
    {
        $main = ChannelRepo::main();
        $base = ChannelRepo::base();
        $req  = ChannelRepo::required();

        $fmt = static fn(?array $c) => $c
            ? ('@' . ($c['username'] ?: '—') . ' (' . e($c['title'] ?: '—') . ')')
            : '<i>sozlanmagan</i>';

        $t  = "📢 <b>Kanallar boshqaruvi</b>\n\n";
        $t .= "🗄 <b>Baza kanal</b> (filmlar saqlanadi):\n   " . $fmt($base) . "\n\n";
        $t .= "📣 <b>Asosiy kanal</b> (postlar):\n   " . $fmt($main) . "\n\n";
        $t .= "🔒 <b>Majburiy obuna</b> (" . count($req) . " ta):\n";
        if ($req) {
            foreach ($req as $c) {
                $t .= "   • @" . ($c['username'] ?: $c['chat_id']) . "\n";
            }
        } else {
            $t .= "   <i>yo'q</i>\n";
        }
        return $t;
    }

    public static function panelKeyboard(): array
    {
        $kb = [
            [['text' => '🗄 Baza kanalni o\'zgartirish',  'callback_data' => 'ch_base']],
            [['text' => '📣 Asosiy kanalni o\'zgartirish', 'callback_data' => 'ch_main']],
            [['text' => '➕ Majburiy kanal qo\'shish',     'callback_data' => 'ch_req']],
        ];
        foreach (ChannelRepo::required() as $c) {
            $label = '❌ @' . ($c['username'] ?: $c['chat_id']);
            $kb[] = [['text' => $label, 'callback_data' => 'chdel:' . $c['id']]];
        }
        $kb[] = [['text' => '🔄 Yangilash', 'callback_data' => 'ch_panel']];
        return $kb;
    }

    /** Panelni yangi xabar sifatida ko'rsatadi. */
    public static function showPanel(int $chatId): void
    {
        showMenu($chatId, self::panelText(), self::panelKeyboard());
    }

    /** Panelni mavjud xabarda yangilaydi (callback). */
    public static function refreshPanel(int $chatId, int $msgId): void
    {
        Telegram::editText($chatId, $msgId, self::panelText(), [
            'reply_markup' => json_encode(['inline_keyboard' => self::panelKeyboard()]),
        ]);
    }

    /**
     * Step natijasini qo'llaydi: 'ch_base'|'ch_main'|'ch_req'.
     * Muvaffaqiyat matnini qaytaradi (admin'ga ko'rsatish uchun) yoki null (xato).
     */
    public static function apply(string $type, string $input): ?string
    {
        $info = self::validate($input);
        if (!$info || !$info['chat_id']) {
            return null;
        }
        $data = [
            'username' => $info['username'],
            'chat_id'  => $info['chat_id'],
            'title'    => $info['title'],
        ];

        if ($type === 'ch_req') {
            ChannelRepo::addRequired($data);
            return "✅ Majburiy kanal qo'shildi: <b>" . e($info['title']) . "</b>";
        }

        $single = $type === 'ch_base' ? 'base' : 'main';
        ChannelRepo::setSingle($single, $data);
        $warn = $info['bot_admin'] ? '' : "\n⚠️ Diqqat: bot bu kanalda admin emas! Post/film ishlamasligi mumkin.";
        $label = $single === 'base' ? 'Baza' : 'Asosiy';
        return "✅ $label kanal o'rnatildi: <b>" . e($info['title']) . "</b>$warn";
    }
}
