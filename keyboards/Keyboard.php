<?php
/**
 * Keyboard — barcha klaviaturalar (reply va inline).
 * callback_data ':' bilan ajratiladi (action:arg1:arg2) — 64 bayt limitidan past.
 */
final class Keyboard
{
    /**
     * Web App tugmasi qatorini qaytaradi (config'da HTTPS url bo'lsa).
     * Bo'lmasa bo'sh massiv — keyboardlar tugmasiz ishlayveradi.
     */
    private static function webAppRow(): array
    {
        $webapp = (string)Config::get('webapp.url', '');
        if ($webapp === '') {
            return [];
        }
        return [['text' => '🎬 Kino App', 'web_app' => ['url' => $webapp]]];
    }

    /** Pastki asosiy menyu (reply keyboard). */
    public static function main(bool $isAdmin = false): string
    {
        $kb = [
            [['text' => '🔍 Kino qidirish'], ['text' => '📺 Seriallar']],
            [['text' => '🆕 Yangi filmlar'], ['text' => '⭐ Top filmlar']],
            [['text' => '📋 Barcha kinolar'], ['text' => '📞 Yordam']],
        ];

        // Web App tugmasi (reply-keyboard web_app — sendData() shu yo'l orqali ishlaydi).
        $row = self::webAppRow();
        if ($row !== []) {
            array_unshift($kb, $row);
        }

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
            [['text' => '👤 Oddiy rejim']],
        ];

        // Web App tugmasi — admin rejimida ham eng tepada ko'rinsin.
        $row = self::webAppRow();
        if ($row !== []) {
            array_unshift($kb, $row);
        }

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
}
