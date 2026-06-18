<?php
/**
 * CallbackHandler — barcha inline tugma bosishlari (callback_query).
 * Sxema: "action:arg1:arg2" (':' ajratuvchi — eski '_' ambiguity yo'q, 64 bayt limitidan past).
 */
final class CallbackHandler
{
    public static function handle(object $cb): void
    {
        $data   = (string)($cb->data ?? '');
        $chatId = (int)($cb->message->chat->id ?? 0);
        $msgId  = (int)($cb->message->message_id ?? 0);
        $fromId = (int)($cb->from->id ?? 0);

        Telegram::answerCb((string)$cb->id);

        if ($data === '' || $data === 'noop') return;

        $parts  = explode(':', $data);
        $action = $parts[0];
        $a1     = $parts[1] ?? null;
        $a2     = $parts[2] ?? null;

        $isAdmin = AdminRepo::isAdmin($fromId);
        $isMain  = AdminRepo::isMain($fromId);

        switch ($action) {
            // ---- Umumiy ----
            case 'cancel':
                State::clear($chatId);
                Telegram::editText($chatId, $msgId, "❌ Bekor qilindi.");
                State::setMenu($chatId, null);
                return;

            case 'check_sub':
                if (ChannelManager::checkSubscription($chatId)) {
                    Telegram::delete($chatId, $msgId);
                    State::setMenu($chatId, null);
                    showKeyboard($chatId, "✅ Rahmat! Endi botdan foydalanishingiz mumkin.",
                        $isAdmin ? Keyboard::admin() : Keyboard::main(false));
                }
                return;

            // ---- Kunlik bonus (profildan, har bir foydalanuvchi uchun) ----
            case 'nano_daily':
                ProfileHandler::claim($chatId, $msgId);
                return;

            // ---- AI bazada topa olmadi → adminga xabar rejimiga o'tish ----
            case 'ask_admin':
                AiRepo::stop($chatId);
                AiRepo::clearMessages($chatId);
                Telegram::editMarkup($chatId, $msgId, []); // tugmani olib tashlaymiz
                ContactHandler::enter($chatId);
                return;

            // ---- Admin: foydalanuvchiga javob berish ----
            case 'reply':
                if ($isAdmin) {
                    $target = (int)$a1;
                    State::set($chatId, 'contact_reply', ['reply_target' => $target]);
                    showMenu($chatId,
                        "✍️ <code>$target</code> ga javobingizni yuboring (matn/rasm/video):",
                        Keyboard::cancel()
                    );
                }
                return;

            // ---- Reaktsiya ----
            case 'like':
            case 'dislike':
                $film = FilmRepo::react($fromId, (int)$a1, $action);
                if ($film) {
                    Telegram::editMarkup($chatId, $msgId, Keyboard::reactions($film));
                }
                return;

            // ---- Film/qism ko'rish (menyu qoladi) ----
            case 'watch':
            case 'ep':
                deliverFilm($chatId, (int)$a1);
                return;

            // ---- Serial navigatsiyasi ----
            case 'srl':
                self::seasons($chatId, $msgId, (int)$a1);
                return;

            case 'ssn':
                self::episodes($chatId, $msgId, (int)$a1, (int)$a2);
                return;

            // ---- Yangi filmlar paginatsiya ----
            case 'fpage':
                self::filmsPage($chatId, $msgId, (int)$a1);
                return;

            // ================= ADMIN =================
            case 'users':
                if ($isAdmin) self::usersPage($chatId, $msgId, (int)$a1);
                return;

            case 'export_users':
                if ($isAdmin) self::exportUsers($chatId);
                return;

            case 'post':
                if ($isAdmin) {
                    $ok = postToChannel((int)$a1);
                    Telegram::editText($chatId, $msgId, $ok ? "✅ Kanalga yuborildi!" : "❌ Yuborib bo'lmadi (kanal sozlamalarini tekshiring).");
                }
                return;

            case 'nopost':
                if ($isAdmin) {
                    Telegram::delete($chatId, $msgId);
                    State::setMenu($chatId, null);
                }
                return;

            case 'etitle':
                if ($isAdmin) {
                    State::set($chatId, 'edit_title', ['edit_code' => (int)$a1]);
                    Telegram::editText($chatId, $msgId, "✏️ Yangi <b>nomni</b> yuboring:");
                    State::setMenu($chatId, $msgId);
                }
                return;

            case 'edesc':
                if ($isAdmin) {
                    State::set($chatId, 'edit_desc', ['edit_code' => (int)$a1]);
                    Telegram::editText($chatId, $msgId, "📝 Yangi <b>tavsifni</b> yuboring (yoki <code>-</code>):");
                    State::setMenu($chatId, $msgId);
                }
                return;

            case 'del':
                if ($isAdmin) {
                    FilmRepo::delete((int)$a1);
                    Telegram::editText($chatId, $msgId, "✅ Film o'chirildi. Kod: <code>" . (int)$a1 . "</code>");
                    State::setMenu($chatId, null);
                }
                return;

            case 'bc_all':
                if ($isAdmin) {
                    State::set($chatId, 'broadcast_all');
                    showMenu($chatId, "📢 Barchaga yuboriladigan xabarni yuboring (matn/rasm/video):", Keyboard::cancel());
                }
                return;

            case 'bc_one':
                if ($isAdmin) {
                    State::set($chatId, 'broadcast_one');
                    showMenu($chatId, "👤 Foydalanuvchi <b>ID</b> sini yuboring:", Keyboard::cancel());
                }
                return;

            case 'admin_add':
                if ($isMain) {
                    State::set($chatId, 'admin_add');
                    showMenu($chatId, "➕ Yangi admin <b>Telegram ID</b> sini yuboring:", Keyboard::cancel());
                }
                return;

            case 'adel':
                if ($isMain) {
                    AdminRepo::remove((int)$a1);
                    Telegram::editText($chatId, $msgId, AdminHandler::adminsText(), [
                        'reply_markup' => json_encode(['inline_keyboard' => AdminHandler::adminsKeyboard()]),
                    ]);
                }
                return;

            // ---- Kanal boshqaruvi ----
            case 'ch_panel':
                if ($isAdmin) ChannelManager::refreshPanel($chatId, $msgId);
                return;

            case 'ch_base':
            case 'ch_main':
            case 'ch_req':
                if ($isAdmin) self::channelPrompt($chatId, $action);
                return;

            case 'chdel':
                if ($isAdmin) {
                    ChannelRepo::remove((int)$a1);
                    ChannelManager::refreshPanel($chatId, $msgId);
                }
                return;

            // ---- Nano Coin admin paneli ----
            case 'nano_panel':
            case 'nano_give':
            case 'nano_take':
            case 'nano_view':
            case 'nano_top':
            case 'nano_txns':
            case 'nano_cfg_reg':
            case 'nano_cfg_daily':
            case 'nano_cfg_cost':
                if ($isAdmin) NanoAdmin::callback($chatId, $msgId, $action, $a1);
                return;
        }
    }

    // ---- Yordamchilar ----

    private static function seasons(int $chatId, int $msgId, int $seriesId): void
    {
        $seasons = FilmRepo::seasons($seriesId);
        if (!$seasons) return;
        $kb = [];
        foreach ($seasons as $s) {
            $kb[] = [['text' => "📺 {$s['season']}-fasl ({$s['cnt']} qism)", 'callback_data' => "ssn:$seriesId:{$s['season']}"]];
        }
        Telegram::editMarkup($chatId, $msgId, $kb);
    }

    private static function episodes(int $chatId, int $msgId, int $seriesId, int $season): void
    {
        $eps = FilmRepo::episodes($seriesId, $season);
        $kb = [];
        foreach ($eps as $ep) {
            $kb[] = [['text' => "▶️ {$ep['episode']}-qism", 'callback_data' => "ep:{$ep['code']}"]];
        }
        $kb[] = [['text' => '🔙 Orqaga', 'callback_data' => "srl:$seriesId"]];
        Telegram::editMarkup($chatId, $msgId, $kb);
    }

    private static function filmsPage(int $chatId, int $msgId, int $page): void
    {
        $p = FilmRepo::latestFilms($page);
        $kb = [];
        foreach ($p['rows'] as $f) {
            $kb[] = [['text' => "🎬 {$f['title']} (#{$f['code']})", 'callback_data' => "watch:{$f['code']}"]];
        }
        $nav = Keyboard::nav($p['page'], $p['pages'], 'fpage');
        if ($nav) $kb[] = $nav;
        Telegram::editMarkup($chatId, $msgId, $kb);
    }

    private static function usersPage(int $chatId, int $msgId, int $page): void
    {
        $p = UserRepo::paginate($page);
        $kb = [];
        $nav = Keyboard::nav($p['page'], $p['pages'], 'users');
        if ($nav) $kb[] = $nav;
        $kb[] = [['text' => '📥 TXT yuklab olish', 'callback_data' => 'export_users']];
        Telegram::editText($chatId, $msgId, AdminHandler::usersText($p), [
            'reply_markup' => json_encode(['inline_keyboard' => $kb]),
        ]);
    }

    private static function exportUsers(int $chatId): void
    {
        $path = exportUsersTxt();
        Telegram::call('sendDocument', [
            'chat_id'  => $chatId,
            'document' => new CURLFile($path),
            'caption'  => "👥 Foydalanuvchilar ro'yxati",
        ]);
    }

    private static function channelPrompt(int $chatId, string $action): void
    {
        $labels = ['ch_base' => 'Baza', 'ch_main' => 'Asosiy', 'ch_req' => 'Majburiy'];
        State::set($chatId, $action);
        showMenu($chatId,
            "📢 <b>{$labels[$action]} kanal</b>\n\n" .
            "Kanal username (<code>@kanal</code>) yoki ID sini yuboring.\n" .
            "<i>Bot o'sha kanalda admin bo'lishi shart.</i>",
            Keyboard::cancel()
        );
    }
}
