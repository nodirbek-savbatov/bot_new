<?php
/**
 * AdminHandler — admin tugmalari va step (FSM) ishlovchisi.
 * handle() true qaytarsa — xabar iste'mol qilindi (MessageHandler ishlamaydi).
 */
final class AdminHandler
{
    /** Admin reply-keyboard tugmalari — bosilganda yarim qolgan step tozalanadi. */
    private const ADMIN_BUTTONS = [
        '➕ Film yuklash', '📺 Serial yuklash', '✏️ Tahrirlash', "🗑 O'chirish",
        '📨 Xabar yuborish', '📊 Statistika', '👥 Foydalanuvchilar', '📢 Kanallar',
        '🔐 Adminlar', '⚙️ Sozlamalar', '🪙 Nano Coin', '👤 Oddiy rejim',
    ];

    public static function handle(array $ctx): bool
    {
        $cid      = $ctx['cid'];
        $text     = trim($ctx['text']);
        $step     = $ctx['step'];
        $hasMedia = $ctx['video'] || $ctx['document'];

        // ---- 1) VIDEO/DOCUMENT steplari (matn steplaridan oldin) ----
        if ($step === 'film_video' && $hasMedia)   return self::filmVideo($ctx);
        if ($step === 'serial_video' && $hasMedia) return self::serialVideo($ctx);

        // Admin tugmasi bosilsa — yarim qolgan stepni tozalaymiz
        if (in_array($text, self::ADMIN_BUTTONS, true)) {
            State::clear($cid);
            $step = '';
        }

        // ---- 2) Reply-keyboard tugmalari ----
        switch ($text) {
            case '➕ Film yuklash':
                State::set($cid, 'film_video', []);
                showMenu($cid, "➕ <b>Film yuklash</b>\n\nFilmni yuboring (video yoki fayl):", Keyboard::cancel());
                return true;

            case '📺 Serial yuklash':
                State::set($cid, 'serial_title', ['type' => 'serial']);
                showMenu($cid, "📺 <b>Serial yuklash</b>\n\nSerial nomini yuboring:", Keyboard::cancel());
                return true;

            case '✏️ Tahrirlash':
                State::set($cid, 'edit_find');
                showMenu($cid, "✏️ Tahrirlanadigan film <b>kodini</b> yuboring:", Keyboard::cancel());
                return true;

            case "🗑 O'chirish":
                State::set($cid, 'delete_film');
                showMenu($cid, "🗑 O'chiriladigan film <b>kodini</b> yuboring:", Keyboard::cancel());
                return true;

            case '📨 Xabar yuborish':
                showMenu($cid, "📨 <b>Xabar yuborish</b>\n\nKimga yuboramiz?", Keyboard::broadcastChoice());
                return true;

            case '📊 Statistika':
                self::stats($cid);
                return true;

            case '👥 Foydalanuvchilar':
                self::users($cid);
                return true;

            case '📢 Kanallar':
                ChannelManager::showPanel($cid);
                return true;

            case '🔐 Adminlar':
                self::admins($cid);
                return true;

            case '⚙️ Sozlamalar':
                self::settings($cid);
                return true;

            case '🪙 Nano Coin':
                NanoAdmin::showPanel($cid);
                return true;

            case '👤 Oddiy rejim':
                State::clear($cid);
                showKeyboard($cid, "👤 Oddiy foydalanuvchi rejimi", Keyboard::main(true));
                return true;
        }

        // ---- 3) Matn steplari ----
        switch ($step) {
            case 'film_title':       return self::filmTitle($ctx);
            case 'film_desc':        return self::filmDesc($ctx);
            case 'serial_title':     return self::serialTitle($ctx);
            case 'serial_season':    return self::serialSeason($ctx);
            case 'serial_episode':   return self::serialEpisode($ctx);
            case 'serial_desc':      return self::serialDesc($ctx);
            case 'edit_find':        return self::editFind($ctx);
            case 'edit_title':       return self::editTitle($ctx);
            case 'edit_desc':        return self::editDesc($ctx);
            case 'delete_film':      return self::deleteFilm($ctx);
            case 'admin_add':        return self::adminAdd($ctx);
            case 'broadcast_all':    return self::broadcastAll($ctx);
            case 'broadcast_one':    return self::broadcastOne($ctx);
            case 'broadcast_one_msg':return self::broadcastOneMsg($ctx);
            case 'contact_reply':    return self::contactReply($ctx);
            case 'ch_base':
            case 'ch_main':
            case 'ch_req':           return self::channelApply($ctx, $step);

            // Nano Coin admin steplari (NanoAdmin'ga delegatsiya)
            case 'nano_give_id':
            case 'nano_give_amount':
            case 'nano_take_id':
            case 'nano_take_amount':
            case 'nano_view_id':
            case 'nano_set_register':
            case 'nano_set_daily':
            case 'nano_set_cost':     return NanoAdmin::step($ctx, $step);
        }

        return false; // iste'mol qilinmadi
    }

    // ================= FILM =================

    private static function filmVideo(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $base = baseTarget();
        if ($base === null) {
            State::clear($cid);
            showMenu($cid, "❌ Baza kanal sozlanmagan. Avval <b>📢 Kanallar</b> → baza kanalni o'rnating.");
            return true;
        }
        $res = Telegram::copy($base, $cid, $ctx['mid']);
        $msgId = $res['result']['message_id'] ?? null;
        if ($msgId) {
            State::mergeData($cid, ['msg_id' => (int)$msgId]);
            State::set($cid, 'film_title');
            showMenu($cid, "✅ Video yuklandi!\n\nFilm <b>nomini</b> yuboring:", Keyboard::cancel());
        } else {
            showMenu($cid, "❌ Xato. Videoni qayta yuboring.", Keyboard::cancel());
        }
        return true;
    }

    private static function filmTitle(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $text = trim($ctx['text']);
        if ($text === '') {
            showMenu($cid, "❌ Iltimos, matn ko'rinishida nom yuboring.", Keyboard::cancel());
            return true;
        }
        State::mergeData($cid, ['title' => $text]);
        State::set($cid, 'film_desc');
        showMenu($cid,
            "✅ Nom: <b>" . e($text) . "</b>\n\nKino haqida yozing (tavsif, yil va h.k.).\nO'tkazib yuborish: <code>-</code>",
            Keyboard::cancel()
        );
        return true;
    }

    private static function filmDesc(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $d = State::data($cid);
        $desc = ($ctx['text'] === '-') ? '' : $ctx['text'];
        $code = FilmRepo::createFilm((int)($d['msg_id'] ?? 0), (string)($d['title'] ?? ''), $desc);
        State::clear($cid);
        $film = FilmRepo::get($code);
        showMenu($cid, "✅ <b>Film saqlandi!</b>\n\n" . filmCaption($film), Keyboard::postConfirm($code));
        return true;
    }

    // ================= SERIAL =================

    private static function serialTitle(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $text = trim($ctx['text']);
        if ($text === '') {
            showMenu($cid, "❌ Serial nomini matn sifatida yuboring.", Keyboard::cancel());
            return true;
        }
        State::mergeData($cid, ['title' => $text]);
        State::set($cid, 'serial_season');
        showMenu($cid, "✅ Serial: <b>" . e($text) . "</b>\n\n<b>Fasl</b> raqamini kiriting:", Keyboard::cancel());
        return true;
    }

    private static function serialSeason(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam kiriting (fasl).", Keyboard::cancel());
            return true;
        }
        State::mergeData($cid, ['season' => (int)$ctx['text']]);
        State::set($cid, 'serial_episode');
        showMenu($cid, "✅ Fasl: <b>{$ctx['text']}</b>\n\n<b>Qism</b> raqamini kiriting:", Keyboard::cancel());
        return true;
    }

    private static function serialEpisode(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam kiriting (qism).", Keyboard::cancel());
            return true;
        }
        State::mergeData($cid, ['episode' => (int)$ctx['text']]);
        State::set($cid, 'serial_video');
        showMenu($cid, "✅ Qism: <b>{$ctx['text']}</b>\n\nEndi <b>videoni</b> yuboring:", Keyboard::cancel());
        return true;
    }

    private static function serialVideo(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $base = baseTarget();
        if ($base === null) {
            State::clear($cid);
            showMenu($cid, "❌ Baza kanal sozlanmagan. Avval <b>📢 Kanallar</b> → baza kanalni o'rnating.");
            return true;
        }
        $res = Telegram::copy($base, $cid, $ctx['mid']);
        $msgId = $res['result']['message_id'] ?? null;
        if ($msgId) {
            State::mergeData($cid, ['msg_id' => (int)$msgId]);
            State::set($cid, 'serial_desc');
            showMenu($cid, "✅ Video yuklandi!\n\nSerial haqida yozing yoki <code>-</code>:", Keyboard::cancel());
        } else {
            showMenu($cid, "❌ Xato. Videoni qayta yuboring.", Keyboard::cancel());
        }
        return true;
    }

    private static function serialDesc(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $d = State::data($cid);
        $desc = ($ctx['text'] === '-') ? '' : $ctx['text'];
        $code = FilmRepo::createSerial(
            (int)($d['msg_id'] ?? 0),
            (string)($d['title'] ?? ''),
            $desc,
            (int)($d['season'] ?? 0),
            (int)($d['episode'] ?? 0)
        );
        State::clear($cid);
        $film = FilmRepo::get($code);
        showMenu($cid, "✅ <b>Serial qism saqlandi!</b>\n\n" . filmCaption($film), Keyboard::postConfirm($code));
        return true;
    }

    // ================= TAHRIRLASH =================

    private static function editFind(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam (kod) kiriting.", Keyboard::cancel());
            return true;
        }
        $code = (int)$ctx['text'];
        $film = FilmRepo::get($code);
        State::clear($cid);
        if (!$film) {
            showMenu($cid, "❌ Bunday kodli film topilmadi.");
            return true;
        }
        showMenu($cid, "✏️ <b>" . e($film['title']) . "</b> (#{$film['code']})\n\nNimani tahrirlamoqchisiz?", Keyboard::editFilm($code));
        return true;
    }

    private static function editTitle(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (trim($ctx['text']) === '') {
            showMenu($cid, "❌ Yangi nomni matn sifatida yuboring.", Keyboard::cancel());
            return true;
        }
        $d = State::data($cid);
        FilmRepo::update((int)($d['edit_code'] ?? 0), ['title' => $ctx['text']]);
        State::clear($cid);
        showMenu($cid, "✅ Nom yangilandi: <b>" . e($ctx['text']) . "</b>");
        return true;
    }

    private static function editDesc(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $d = State::data($cid);
        $desc = ($ctx['text'] === '-') ? '' : $ctx['text'];
        FilmRepo::update((int)($d['edit_code'] ?? 0), ['description' => $desc]);
        State::clear($cid);
        showMenu($cid, "✅ Tavsif yangilandi.");
        return true;
    }

    // ================= O'CHIRISH =================

    private static function deleteFilm(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam (kod) kiriting.", Keyboard::cancel());
            return true;
        }
        $code = (int)$ctx['text'];
        $film = FilmRepo::get($code);
        State::clear($cid);
        if (!$film) {
            showMenu($cid, "❌ Bunday kodli film topilmadi.");
            return true;
        }
        showMenu($cid, "🗑 <b>" . e($film['title']) . "</b> (#{$code}) o'chirilsinmi?", Keyboard::deleteConfirm($code));
        return true;
    }

    // ================= ADMIN QO'SHISH =================

    private static function adminAdd(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!AdminRepo::isMain($cid)) {
            State::clear($cid);
            return true;
        }
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam (Telegram ID) yuboring.", Keyboard::cancel());
            return true;
        }
        $newId = (int)$ctx['text'];
        AdminRepo::add($newId);
        State::clear($cid);
        showMenu($cid, "✅ Admin qo'shildi: <code>$newId</code>");
        Telegram::send($newId, "🎉 Siz admin etib tayinlandingiz! /start bosing.", ['reply_markup' => Keyboard::admin()]);
        return true;
    }

    // ================= BROADCAST =================

    private static function broadcastAll(array $ctx): bool
    {
        $cid = $ctx['cid'];
        State::clear($cid);
        $n = BroadcastRepo::enqueueAll($cid, $ctx['mid']);
        showMenu($cid,
            "✅ Xabar navbatga qo'shildi: <b>$n</b> ta foydalanuvchi.\n\n" .
            "⏳ Cron worker har daqiqada partiyalab yuboradi."
        );
        return true;
    }

    private static function broadcastOne(array $ctx): bool
    {
        $cid = $ctx['cid'];
        if (!is_digits(trim($ctx['text']))) {
            showMenu($cid, "❌ Faqat raqam (foydalanuvchi ID) yuboring.", Keyboard::cancel());
            return true;
        }
        State::mergeData($cid, ['target' => (int)$ctx['text']]);
        State::set($cid, 'broadcast_one_msg');
        showMenu($cid, "📨 Yuboriladigan xabarni yuboring (matn/rasm/video):", Keyboard::cancel());
        return true;
    }

    private static function broadcastOneMsg(array $ctx): bool
    {
        $cid = $ctx['cid'];
        $d = State::data($cid);
        $target = (int)($d['target'] ?? 0);
        State::clear($cid);
        $res = $target ? Telegram::copy($target, $cid, $ctx['mid']) : null;
        $ok = is_array($res) && ($res['ok'] ?? false);
        showMenu($cid, $ok ? "✅ Xabar yuborildi." : "❌ Yuborib bo'lmadi (foydalanuvchi botni bloklagan bo'lishi mumkin).");
        return true;
    }

    // ================= ADMINGA XABARGA JAVOB =================

    /** "✍️ Javob berish" tugmasidan keyin — admin javobini foydalanuvchiga yuboradi. */
    private static function contactReply(array $ctx): bool
    {
        $cid    = $ctx['cid'];
        $d      = State::data($cid);
        $target = (int)($d['reply_target'] ?? 0);
        State::clear($cid);

        if ($target === 0) {
            showMenu($cid, "❌ Foydalanuvchi aniqlanmadi. Qaytadan urinib ko'ring.");
            return true;
        }

        $ok = ContactHandler::replyToUser($target, $ctx);
        showMenu($cid, $ok
            ? "✅ Javob yuborildi: <code>$target</code>"
            : "❌ Yuborib bo'lmadi (foydalanuvchi botni bloklagan bo'lishi mumkin)."
        );
        return true;
    }

    // ================= KANAL =================

    private static function channelApply(array $ctx, string $step): bool
    {
        $cid = $ctx['cid'];
        if (trim($ctx['text']) === '') {
            showMenu($cid, "❌ Kanal username (@kanal) yoki ID sini yuboring.", Keyboard::cancel());
            return true;
        }
        $msg = ChannelManager::apply($step, $ctx['text']);
        State::clear($cid);
        if ($msg === null) {
            showMenu($cid,
                "❌ Kanal topilmadi yoki bot unga kira olmadi.\n" .
                "Username/ID to'g'riligini va botni kanalga <b>admin</b> qilganingizni tekshiring.",
                Keyboard::cancel()
            );
        } else {
            Telegram::send($cid, $msg);
            ChannelManager::showPanel($cid);
        }
        return true;
    }

    // ================= PANELLAR =================

    private static function stats(int $cid): void
    {
        showMenu($cid,
            "📊 <b>Statistika</b>\n\n" .
            "👥 Foydalanuvchilar: <b>" . UserRepo::count() . "</b> ta\n" .
            "🎬 Filmlar: <b>" . FilmRepo::countFilms() . "</b> ta\n" .
            "📦 Jami yozuvlar: <b>" . FilmRepo::countAll() . "</b> ta\n" .
            "🔢 Oxirgi kod: <b>" . FilmRepo::lastCode() . "</b>\n\n" .
            "📅 <b>Bugun:</b>\n" .
            "• /start: " . StatRepo::today('starts') . "\n" .
            "• Ko'rishlar: " . StatRepo::today('views') . "\n" .
            "• Qidiruvlar: " . StatRepo::today('searches') . "\n\n" .
            "📈 <b>Jami:</b>\n" .
            "• /start: " . StatRepo::total('starts') . "\n" .
            "• Ko'rishlar: " . StatRepo::total('views')
        );
    }

    private static function users(int $cid): void
    {
        $p = UserRepo::paginate(1);
        $kb = [];
        $nav = Keyboard::nav($p['page'], $p['pages'], 'users');
        if ($nav) $kb[] = $nav;
        $kb[] = [['text' => '📥 TXT yuklab olish', 'callback_data' => 'export_users']];
        showMenu($cid, self::usersText($p), $kb);
    }

    /** Foydalanuvchilar ro'yxati matni (CallbackHandler ham ishlatadi). */
    public static function usersText(array $p): string
    {
        $t = "👥 <b>Foydalanuvchilar</b> ({$p['total']} ta) — {$p['page']}/{$p['pages']} bet\n\n";
        foreach ($p['rows'] as $u) {
            $uname = $u['username'] ? "@{$u['username']}" : '—';
            $t .= "• <b>" . e($u['name']) . "</b> | $uname | <code>{$u['id']}</code>\n";
        }
        return $t;
    }

    private static function admins(int $cid): void
    {
        if (!AdminRepo::isMain($cid)) {
            showMenu($cid, "⚠️ Adminlarni faqat <b>bosh admin</b> boshqaradi.");
            return;
        }
        showMenu($cid, self::adminsText(), self::adminsKeyboard());
    }

    public static function adminsText(): string
    {
        return "🔐 <b>Adminlar</b> (" . count(AdminRepo::all()) . " ta)";
    }

    public static function adminsKeyboard(): array
    {
        $kb = [];
        foreach (AdminRepo::allRows() as $r) {
            if ((int)$r['is_main']) continue;
            $kb[] = [
                ['text' => "👤 {$r['user_id']}", 'callback_data' => 'noop'],
                ['text' => "❌ O'chirish", 'callback_data' => 'adel:' . $r['user_id']],
            ];
        }
        $kb[] = [['text' => "➕ Admin qo'shish", 'callback_data' => 'admin_add']];
        return $kb;
    }

    private static function settings(int $cid): void
    {
        $base = ChannelRepo::base();
        $main = ChannelRepo::main();
        showMenu($cid,
            "⚙️ <b>Sozlamalar</b>\n\n" .
            "🤖 Bot: @" . Telegram::username() . "\n" .
            "👤 Bosh admin: <code>" . Config::get('admin.main') . "</code>\n" .
            "🔐 Adminlar: " . count(AdminRepo::all()) . " ta\n" .
            "🗄 Baza: " . ($base ? '@' . $base['username'] : '—') . "\n" .
            "📣 Kanal: " . ($main ? '@' . $main['username'] : '—') . "\n" .
            "🔒 Majburiy: " . count(ChannelRepo::required()) . " ta\n" .
            "🎬 Filmlar: " . FilmRepo::countFilms() . " ta\n" .
            "🔢 Oxirgi kod: " . FilmRepo::lastCode() . "\n" .
            "👥 Userlar: " . UserRepo::count() . " ta"
        );
    }
}
