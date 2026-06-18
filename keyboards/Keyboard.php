<?php
/**
 * Keyboard — barcha klaviaturalar (reply va inline).
 * callback_data ':' bilan ajratiladi (action:arg1:arg2) — 64 bayt limitidan past.
 */
final class Keyboard
{
    /** Pastki asosiy menyu (reply keyboard). */
    public static function main(bool $isAdmin = false): string
    {
        // Web App "🎬 Kino App" reply-keyboardda EMAS — chetdagi menyu tugmasi
        // (setChatMenuButton, install.php) orqali ochiladi.
        $kb = [
            [['text' => '🔍 Kino qidirish'], ['text' => '📺 Seriallar']],
            [['text' => '🆕 Yangi filmlar'], ['text' => '⭐ Top filmlar']],
            [['text' => '📋 Barcha kinolar'], ['text' => '📞 Yordam']],
            [['text' => '🤖 AI Yordamchi'],  ['text' => '👤 Profil']],
            [['text' => '📨 Adminga xabar']],
        ];

        if ($isAdmin) {
            $kb[] = [['text' => '👑 Admin panel']];
        }
        return json_encode(['resize_keyboard' => true, 'is_persistent' => true, 'keyboard' => $kb]);
    }

    /** Admin paneli (reply keyboard). */
    public static function admin(): string
    {
        $kb = [
            [['text' => '➕ Film yuklash'],     ['text' => '📺 Serial yuklash']],
            [['text' => '✏️ Tahrirlash'],       ['text' => "🗑 O'chirish"]],
            [['text' => '📨 Xabar yuborish'],   ['text' => '📊 Statistika']],
            [['text' => '👥 Foydalanuvchilar'], ['text' => '📢 Kanallar']],
            [['text' => '🔐 Adminlar'],         ['text' => '⚙️ Sozlamalar']],
            [['text' => '🪙 Nano Coin'],        ['text' => '👤 Oddiy rejim']],
        ];

        return json_encode([
            'resize_keyboard' => true,
            'is_persistent'   => true,
            'keyboard'        => $kb,
        ]);
    }

    /** Step jarayonida bekor qilish (inline). */
    public static function cancel(): array
    {
        return [[['text' => '❌ Bekor qilish', 'callback_data' => 'cancel']]];
    }

    /** Like / dislike tugmalari. */
    public static function reactions(array $f): array
    {
        return [[
            ['text' => "👍 {$f['likes']}",    'callback_data' => "like:{$f['code']}"],
            ['text' => "👎 {$f['dislikes']}", 'callback_data' => "dislike:{$f['code']}"],
        ]];
    }

    /** Film tahrirlash menyusi. */
    public static function editFilm(int $code): array
    {
        return [
            [['text' => "✏️ Nomini o'zgartir",   'callback_data' => "etitle:$code"]],
            [['text' => "📝 Tavsifini o'zgartir", 'callback_data' => "edesc:$code"]],
            [['text' => "🗑 O'chirish",           'callback_data' => "del:$code"]],
        ];
    }

    /** Saqlangandan keyin kanalga yuborish tasdig'i. */
    public static function postConfirm(int $code): array
    {
        return [[
            ['text' => '📢 Kanalga yuborish', 'callback_data' => "post:$code"],
            ['text' => "❌ Yo'q",             'callback_data' => "nopost:$code"],
        ]];
    }

    /** O'chirishni tasdiqlash. */
    public static function deleteConfirm(int $code): array
    {
        return [[
            ['text' => '✅ Ha, o\'chirilsin', 'callback_data' => "del:$code"],
            ['text' => "❌ Yo'q",             'callback_data' => 'cancel'],
        ]];
    }

    /** Xabar yuborish turi. */
    public static function broadcastChoice(): array
    {
        return [
            [['text' => '📢 Barchaga',      'callback_data' => 'bc_all']],
            [['text' => '👤 Bitta userga', 'callback_data' => 'bc_one']],
        ];
    }

    // ================= SERIAL NAVIGATSIYASI =================

    /** Serial yuklash: "avval mavjudmi?" — Ha / Yo'q. */
    public static function serialExists(): array
    {
        return [
            [
                ['text' => '✅ Ha (mavjud serial)', 'callback_data' => 'srexist'],
                ['text' => "🆕 Yo'q (yangi)",       'callback_data' => 'srnew'],
            ],
            [['text' => '❌ Bekor qilish', 'callback_data' => 'cancel']],
        ];
    }

    /** Yuklashda mos seriallar ro'yxati — qaysisiga qism qo'shilishini tanlash. */
    public static function seriesPick(array $rows): array
    {
        $kb = [];
        foreach ($rows as $r) {
            $cnt = (int)($r['episodes'] ?? 0);
            $kb[] = [[
                'text'          => "📺 {$r['title']} ($cnt qism)",
                'callback_data' => "srpick:{$r['id']}",
            ]];
        }
        return $kb;
    }

    /** Serialning fasllari (har qatorda 3 ta). */
    public static function seasons(int $seriesId, array $seasons): array
    {
        $kb  = [];
        $row = [];
        foreach ($seasons as $s) {
            $row[] = ['text' => "📺 {$s['season']}-fasl", 'callback_data' => "ssn:$seriesId:{$s['season']}"];
            if (count($row) === 3) { $kb[] = $row; $row = []; }
        }
        if ($row) $kb[] = $row;
        return $kb;
    }

    /**
     * Bir faslning qismlari — paginatsiya bilan, har qatorda 4 ta.
     * Qismlar slice shu yerda bajariladi (to'liq ro'yxat beriladi).
     */
    public static function episodesPage(int $seriesId, int $season, array $allEps, int $page, int $perPage = 16): array
    {
        $total = count($allEps);
        $pages = max(1, (int)ceil($total / $perPage));
        $page  = max(1, min($page, $pages));
        $slice = array_slice($allEps, ($page - 1) * $perPage, $perPage);

        $kb  = [];
        $row = [];
        foreach ($slice as $ep) {
            $row[] = ['text' => "▶️ {$ep['episode']}-qism", 'callback_data' => "ep:{$ep['code']}"];
            if (count($row) === 4) { $kb[] = $row; $row = []; }
        }
        if ($row) $kb[] = $row;

        if ($pages > 1) {
            $nav = [];
            if ($page > 1)      $nav[] = ['text' => '⬅️', 'callback_data' => "eps:$seriesId:$season:" . ($page - 1)];
            $nav[] = ['text' => "$page/$pages", 'callback_data' => 'noop'];
            if ($page < $pages) $nav[] = ['text' => '➡️', 'callback_data' => "eps:$seriesId:$season:" . ($page + 1)];
            $kb[] = $nav;
        }

        $kb[] = [['text' => '🔙 Fasllar', 'callback_data' => "srl:$seriesId"]];
        return $kb;
    }

    /** Sahifalash navigatsiyasi (prefix:page formatida). Bitta sahifada bo'sh. */
    public static function nav(int $page, int $pages, string $prefix): array
    {
        if ($pages <= 1) return [];
        $btns = [];
        if ($page > 1)      $btns[] = ['text' => '⬅️', 'callback_data' => "$prefix:" . ($page - 1)];
        $btns[] = ['text' => "$page/$pages", 'callback_data' => 'noop'];
        if ($page < $pages) $btns[] = ['text' => '➡️', 'callback_data' => "$prefix:" . ($page + 1)];
        return $btns;
    }

    // ================= AI / Nano Coin =================

    /** AI rejimi reply-keyboardi — faqat chiqish tugmasi (boshqa menyular yashirin). */
    public static function ai(): string
    {
        return json_encode([
            'resize_keyboard' => true,
            'is_persistent'   => true,
            'keyboard'        => [[['text' => '❌ AI Rejimidan Chiqish']]],
        ]);
    }

    /** AI bazada kino topa olmaganda — javob tagiga "admindan so'rash" inline tugmasi. */
    public static function askAdmin(): array
    {
        return [[['text' => "📨 Admindan kinoni so'rash", 'callback_data' => 'ask_admin']]];
    }

    /** "Adminga xabar" rejimi reply-keyboardi — faqat chiqish tugmasi. */
    public static function contact(): string
    {
        return json_encode([
            'resize_keyboard' => true,
            'is_persistent'   => true,
            'keyboard'        => [[['text' => '❌ Adminga xabar rejimidan chiqish']]],
        ]);
    }

    /** Adminga keladigan foydalanuvchi xabari tagidagi "javob berish" inline tugmasi. */
    public static function adminReply(int $userId): array
    {
        return [[['text' => '✍️ Javob berish', 'callback_data' => "reply:$userId"]]];
    }

    /** Profil inline tugmalari: kunlik bonus (agar mavjud). */
    public static function profileInline(bool $dailyAvailable): array
    {
        $kb = [];
        if ($dailyAvailable) {
            $kb[] = [['text' => '🎁 Kunlik bonusni olish', 'callback_data' => 'nano_daily']];
        }
        return $kb;
    }

    /** Admin Nano Coin paneli (inline). */
    public static function adminNano(): array
    {
        return [
            [
                ['text' => '💰 Coin berish',  'callback_data' => 'nano_give'],
                ['text' => '💸 Coin yechish', 'callback_data' => 'nano_take'],
            ],
            [
                ['text' => "🔍 Balans ko'rish", 'callback_data' => 'nano_view'],
                ['text' => '🏆 Eng boylar',      'callback_data' => 'nano_top'],
            ],
            [['text' => '📜 Tranzaksiyalar', 'callback_data' => 'nano_txns:1']],
            [
                ['text' => '✏️ Register', 'callback_data' => 'nano_cfg_reg'],
                ['text' => '✏️ Kunlik',   'callback_data' => 'nano_cfg_daily'],
                ['text' => '✏️ AI narxi', 'callback_data' => 'nano_cfg_cost'],
            ],
        ];
    }
}
