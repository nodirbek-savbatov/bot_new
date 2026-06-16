<?php
/**
 * InlineHandler — inline_query (@bot Avatar).
 * Bug #6 yechimi: har natijaga URL tugma (t.me/bot?start=CODE) — bot bo'lmagan
 * chatda ham ishlaydi, natija yuborilgandan keyin tugma qoladi. Past cache, personal.
 */
final class InlineHandler
{
    public static function handle(object $inline): void
    {
        $q  = trim((string)($inline->query ?? ''));
        $id = (string)$inline->id;

        $botUser = Telegram::username();

        // Bo'sh so'rovda — top filmlar; aks holda nom bo'yicha qidiruv
        $films = ($q === '') ? FilmRepo::top(15) : FilmRepo::searchByName($q, 20);

        $results = [];
        foreach ($films as $f) {
            $icon = $f['type'] === 'serial' ? '📺' : '🎬';
            $results[] = [
                'type'        => 'article',
                'id'          => (string)$f['code'],
                'title'       => "$icon " . $f['title'],
                'description' => "Kod: {$f['code']}  |  👁 {$f['views']}",
                'input_message_content' => [
                    'message_text' => "$icon <b>" . e($f['title']) . "</b>\n\n"
                        . "▶️ Olish uchun pastdagi tugmani bosing yoki kod: <code>{$f['code']}</code>",
                    'parse_mode' => 'HTML',
                ],
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '▶️ Filmni olish', 'url' => "https://t.me/$botUser?start={$f['code']}"],
                    ]],
                ],
            ];
        }

        Telegram::answerInline($id, $results, [
            'cache_time'  => 5,
            'is_personal' => 'true',
        ]);
    }
}
