<?php
/**
 * MessageHandler — oddiy foydalanuvchi menyulari, qidiruv, kod orqali olish.
 * Adminlar uchun ham ishlaydi (admin tugmalari AdminHandler'da iste'mol qilinmagan bo'lsa).
 */
final class MessageHandler
{
    /** Asosiy menyu tugmalari (step tozalash uchun). */
    private const MENU_BUTTONS = [
        '🔍 Kino qidirish', '📺 Seriallar', '🆕 Yangi filmlar',
        '⭐ Top filmlar', '📋 Barcha kinolar', '📞 Yordam',
        '👤 Profil', '👑 Admin panel',
    ];

    public static function handle(array $ctx): void
    {
        $cid  = $ctx['cid'];
        $text = trim($ctx['text']);
        $step = $ctx['step'];

        // Menyu tugmasi bosilsa — yarim qolgan stepni tozalaymiz
        if (in_array($text, self::MENU_BUTTONS, true)) {
            State::clear($cid);
            $step = '';
        }

        switch ($text) {
            case '🔍 Kino qidirish':
                State::set($cid, State::SEARCH_MOVIE);
                showMenu($cid,
                    "🔎 <b>Kino nomini yuboring.</b>\n\n" .
                    "Masalan:\n" .
                    "• Qasoskorlar\n" .
                    "• Tez va G'azabli\n" .
                    "• John Wick\n\n" .
                    "❌ Bekor qilish uchun /cancel"
                );
                return;

            case '📺 Seriallar':
                self::serials($cid);
                return;

            case '🆕 Yangi filmlar':
                self::latest($cid);
                return;

            case '⭐ Top filmlar':
                self::top($cid);
                return;

            case '📋 Barcha kinolar':
                self::catalog($cid);
                return;

            case '📞 Yordam':
                self::help($cid);
                return;

            case '👤 Profil':
                ProfileHandler::show($cid);
                return;

            case '👑 Admin panel':
                if (AdminRepo::isAdmin($cid)) {
                    showKeyboard($cid, "👑 <b>Admin panel</b>", Keyboard::admin());
                }
                return;

            case '/last':
                self::last($cid);
                return;
        }

        // Qidiruv stepi — foydalanuvchi qidiruvda bo'lsa, keyingi matn kino nomi sifatida olinadi
        if ($step === State::SEARCH_MOVIE) {
            self::doSearch($cid, $text);
            return;
        }

        // To'g'ridan-to'g'ri kod (kino → darhol; serial → fasl/qism navigatsiyasi)
        if (is_digits($text)) {
            if (!ChannelManager::checkSubscription($cid)) return;
            StatRepo::inc('searches');
            deletePrevMenu($cid);
            if (!openByCode($cid, (int)$text)) {
                showMenu($cid, "❌ Kod <code>" . e($text) . "</code> topilmadi.\n\n🔍 Nom bilan qidirib ko'ring.");
            }
            return;
        }

        // Noaniq xabar (faqat oddiy foydalanuvchi uchun)
        if ($text !== '' && !AdminRepo::isAdmin($cid)) {
            showMenu($cid, "🤔 Tushunmadim.\n\n🔢 Kino kodini yuboring yoki <b>🔍 Kino qidirish</b>ni bosing.");
        }
    }

    // ---- Bo'limlar ----

    private static function serials(int $cid): void
    {
        if (!ChannelManager::checkSubscription($cid)) return;
        $series = FilmRepo::seriesList();
        if (!$series) {
            showMenu($cid, "📭 Hozircha serial yo'q.");
            return;
        }
        $kb = [];
        foreach ($series as $s) {
            $kb[] = [['text' => "📺 " . $s['title'], 'callback_data' => "srl:{$s['id']}"]];
        }
        showMenu($cid, "📺 <b>Seriallar:</b>", $kb);
    }

    private static function latest(int $cid): void
    {
        if (!ChannelManager::checkSubscription($cid)) return;
        $p = FilmRepo::latestFilms(1);
        if (!$p['rows']) {
            showMenu($cid, "📭 Hozircha film yo'q.");
            return;
        }
        $kb = self::filmButtons($p['rows']);
        $nav = Keyboard::nav($p['page'], $p['pages'], 'fpage');
        if ($nav) $kb[] = $nav;
        showMenu($cid, "🆕 <b>Yangi filmlar</b> ({$p['total']} ta):", $kb);
    }

    private static function top(int $cid): void
    {
        if (!ChannelManager::checkSubscription($cid)) return;
        $top = FilmRepo::top(10);
        if (!$top) {
            showMenu($cid, "📭 Hozircha film yo'q.");
            return;
        }
        $kb         = [];
        $seenSeries = [];
        foreach ($top as $f) {
            if ($f['type'] === 'serial' && !empty($f['series_id'])) {
                $sid = (int)$f['series_id'];
                if (isset($seenSeries[$sid])) continue; // serial — bitta tugma
                $seenSeries[$sid] = true;
                $kb[] = [['text' => "👁 {$f['views']} | 📺 {$f['title']}", 'callback_data' => "srl:$sid"]];
            } else {
                $kb[] = [['text' => "👁 {$f['views']} | 🎬 {$f['title']}", 'callback_data' => "watch:{$f['code']}"]];
            }
        }
        showMenu($cid, "⭐ <b>Top 10:</b>", $kb);
    }

    private static function catalog(int $cid): void
    {
        if (!ChannelManager::checkSubscription($cid)) return;
        $data = FilmRepo::catalog();

        $text = "🎬 <b>BARCHA FILMLAR</b> (" . count($data['films']) . " ta)\n\n";
        foreach ($data['films'] as $f) {
            $text .= "• " . e($f['title']) . " — <code>{$f['code']}</code>\n";
        }
        if (!empty($data['serials'])) {
            $text .= "\n📺 <b>BARCHA SERIALLAR</b> (" . count($data['serials']) . " ta)\n\n";
            foreach ($data['serials'] as $title => $seasons) {
                $text .= "📺 <b>" . e($title) . "</b>\n";
                foreach ($seasons as $season => $eps) {
                    $codes = array_map(fn($e) => "<code>{$e['code']}</code>", $eps);
                    $text .= "  {$season}-fasl: " . implode(', ', $codes) . "\n";
                }
                $text .= "\n";
            }
        }

        deletePrevMenu($cid);
        // Uzun bo'lsa bo'lib yuboramiz
        if (mb_strlen($text) > 4000) {
            $chunks = mb_str_split($text, 4000);
            $last = null;
            foreach ($chunks as $chunk) {
                $last = Telegram::send($cid, $chunk);
            }
            if (is_array($last) && ($last['ok'] ?? false)) {
                State::setMenu($cid, (int)$last['result']['message_id']);
            }
        } else {
            showMenu($cid, $text);
        }
    }

    private static function help(int $cid): void
    {
        $botUser = Telegram::username();
        $main = ChannelRepo::main();
        $kanal = $main ? ('@' . $main['username']) : '—';
        showMenu($cid,
            "📞 <b>Yordam</b>\n\n" .
            "🔢 Kino kodini yuboring\n" .
            "🔍 <b>Kino qidirish</b> — nom bo'yicha\n" .
            "📺 <b>Seriallar</b> — barcha seriallar\n" .
            "📋 <b>Barcha kinolar</b> — to'liq ro'yxat\n" .
            "🆕 <b>Yangi filmlar</b> / ⭐ <b>Top filmlar</b>\n\n" .
            "🔎 Inline: <code>@$botUser Avatar</code>\n\n" .
            "📢 Kanal: $kanal"
        );
    }

    private static function last(int $cid): void
    {
        if (!ChannelManager::checkSubscription($cid)) return;
        $last = FilmRepo::lastCode();
        if (!$last) {
            showMenu($cid, "📭 Hozircha film yo'q.");
            return;
        }
        deletePrevMenu($cid);
        deliverFilm($cid, $last);
    }

    private static function doSearch(int $cid, string $q): void
    {
        // Qidiruv bir martalik — natija chiqishi bilan state avtomatik tozalanadi.
        State::clear($cid);

        $q = trim($q);
        if ($q === '') {
            showMenu($cid, "❌ Hech narsa topilmadi.\n\nBoshqa nom bilan urinib ko'ring.");
            return;
        }

        if (!ChannelManager::checkSubscription($cid)) return;

        StatRepo::inc('searches');

        // To'g'ridan-to'g'ri kod kiritilgan bo'lsa — kino yetkaziladi / serial navigatsiyasi ochiladi.
        if (is_digits($q)) {
            deletePrevMenu($cid);
            if (!openByCode($cid, (int)$q)) {
                showMenu($cid, "❌ Hech narsa topilmadi.\n\nBoshqa nom bilan urinib ko'ring.");
            }
            return;
        }

        // Nom bo'yicha fuzzy qidiruv (ko'p so'zli, katta-kichik harfga befarq).
        $results = FilmRepo::searchFuzzy($q);
        if (!$results) {
            showMenu($cid, "❌ Hech narsa topilmadi.\n\nBoshqa nom bilan urinib ko'ring.");
            return;
        }
        $kb = self::filmButtons($results);
        showMenu($cid, "🔎 <b>\"" . e($q) . "\"</b> — " . count($results) . " ta natija topildi:", $kb);
    }

    /**
     * Film tugmalari massivi (kino va serial AJRATILGAN):
     *  - kino  → "watch:CODE" (darhol yetkaziladi);
     *  - serial → bitta tugma "srl:SERIES_ID" (fasl/qism navigatsiyasi); qismlar guruhlanadi.
     */
    private static function filmButtons(array $films): array
    {
        $kb         = [];
        $seenSeries = [];
        foreach ($films as $f) {
            if ($f['type'] === 'serial' && !empty($f['series_id'])) {
                $sid = (int)$f['series_id'];
                if (isset($seenSeries[$sid])) continue; // bitta serial — bitta tugma
                $seenSeries[$sid] = true;
                $kb[] = [['text' => "📺 " . $f['title'], 'callback_data' => "srl:$sid"]];
            } else {
                $kb[] = [['text' => "🎬 " . $f['title'] . " (#{$f['code']})", 'callback_data' => "watch:{$f['code']}"]];
            }
        }
        return $kb;
    }
}
