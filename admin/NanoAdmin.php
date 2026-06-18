<?php
/**
 * NanoAdmin — admin uchun Nano Coin boshqaruvi (ChannelManager uslubida).
 *
 * Imkoniyatlar: coin berish, yechish, balans ko'rish, eng boylar reytingi,
 * tranzaksiyalar tarixi, register/kunlik bonus va AI so'rovi narxini tahrirlash.
 *
 * AdminHandler reply-tugma va steplarni shu klassga yo'naltiradi; CallbackHandler
 * inline tugmalarni callback() ga uzatadi. Barcha amallar admin-gated bo'lishi kerak
 * (chaqiruvchi tomonda AdminRepo::isAdmin tekshiriladi).
 */
final class NanoAdmin
{
    // ---- Panel ----

    public static function showPanel(int $cid): void
    {
        showMenu($cid, self::panelText(), Keyboard::adminNano());
    }

    public static function refreshPanel(int $cid, int $msgId): void
    {
        Telegram::editText($cid, $msgId, self::panelText(), [
            'reply_markup' => json_encode(['inline_keyboard' => Keyboard::adminNano()]),
        ]);
        State::setMenu($cid, $msgId);
    }

    private static function panelText(): string
    {
        return "🪙 <b>Nano Coin boshqaruvi</b>\n\n" .
            "🎁 Register bonus: <b>" . NanoRepo::cfg('register_bonus', 100) . "</b>\n" .
            "📅 Kunlik bonus: <b>" . NanoRepo::cfg('daily_bonus', 10) . "</b>\n" .
            "🤖 AI so'rovi narxi: <b>" . NanoRepo::cfg('ai_cost', 10) . "</b>\n\n" .
            "Quyidagi amallardan birini tanlang:";
    }

    // ---- Inline tugmalar (CallbackHandler → NanoAdmin::callback) ----

    public static function callback(int $cid, int $msgId, string $action, ?string $a1): void
    {
        switch ($action) {
            case 'nano_panel':
                self::refreshPanel($cid, $msgId);
                return;

            case 'nano_give':
                self::prompt($cid, 'nano_give_id', "💰 Coin <b>beriladigan</b> foydalanuvchi ID sini yuboring:");
                return;

            case 'nano_take':
                self::prompt($cid, 'nano_take_id', "💸 Coin <b>yechiladigan</b> foydalanuvchi ID sini yuboring:");
                return;

            case 'nano_view':
                self::prompt($cid, 'nano_view_id', "🔍 Balansi ko'riladigan foydalanuvchi ID sini yuboring:");
                return;

            case 'nano_cfg_reg':
                self::prompt($cid, 'nano_set_register', "✏️ Yangi <b>register bonus</b> miqdorini yuboring (raqam):");
                return;

            case 'nano_cfg_daily':
                self::prompt($cid, 'nano_set_daily', "✏️ Yangi <b>kunlik bonus</b> miqdorini yuboring (raqam):");
                return;

            case 'nano_cfg_cost':
                self::prompt($cid, 'nano_set_cost', "✏️ Yangi <b>AI so'rovi narxi</b>ni yuboring (raqam):");
                return;

            case 'nano_top':
                Telegram::editText($cid, $msgId, self::topText(), [
                    'reply_markup' => json_encode(['inline_keyboard' => [[
                        ['text' => '🔙 Panel', 'callback_data' => 'nano_panel'],
                    ]]]),
                ]);
                State::setMenu($cid, $msgId);
                return;

            case 'nano_txns':
                $page = max(1, (int)($a1 ?? 1));
                $p = NanoRepo::transactionsAll($page);
                $kb = [];
                $nav = Keyboard::nav($p['page'], $p['pages'], 'nano_txns');
                if ($nav) $kb[] = $nav;
                $kb[] = [['text' => '🔙 Panel', 'callback_data' => 'nano_panel']];
                Telegram::editText($cid, $msgId, self::txnsText($p), [
                    'reply_markup' => json_encode(['inline_keyboard' => $kb]),
                ]);
                State::setMenu($cid, $msgId);
                return;
        }
    }

    // ---- Matn steplari (AdminHandler → NanoAdmin::step) ----

    /** @return bool true = step iste'mol qilindi. */
    public static function step(array $ctx, string $step): bool
    {
        $cid  = (int)$ctx['cid'];
        $text = trim((string)$ctx['text']);

        switch ($step) {
            case 'nano_give_id':
            case 'nano_take_id':
            case 'nano_view_id':
                if (!is_digits($text)) {
                    showMenu($cid, "❌ Faqat raqam (foydalanuvchi ID) yuboring.", Keyboard::cancel());
                    return true;
                }
                $target = (int)$text;
                if ($step === 'nano_view_id') {
                    State::clear($cid);
                    self::showUser($cid, $target);
                    return true;
                }
                $next = ($step === 'nano_give_id') ? 'nano_give_amount' : 'nano_take_amount';
                State::set($cid, $next, ['target' => $target]);
                $verb = ($step === 'nano_give_id') ? 'beriladigan' : 'yechiladigan';
                showMenu($cid, "👤 ID: <code>$target</code>\n\n🪙 $verb <b>miqdorni</b> yuboring (raqam):", Keyboard::cancel());
                return true;

            case 'nano_give_amount':
            case 'nano_take_amount':
                if (!is_digits($text) || (int)$text <= 0) {
                    showMenu($cid, "❌ Musbat raqam yuboring.", Keyboard::cancel());
                    return true;
                }
                $d = State::data($cid);
                $target = (int)($d['target'] ?? 0);
                $amount = (int)$text;
                $delta  = ($step === 'nano_give_amount') ? $amount : -$amount;
                State::clear($cid);

                $r = NanoRepo::adminAdjust($target, $delta, $cid);
                if (!$r['ok'] && $delta < 0) {
                    showMenu($cid,
                        "❌ Yechib bo'lmadi — balans yetarli emas.\n" .
                        "👤 <code>$target</code> joriy balansi: <b>{$r['balance']}</b> 🪙"
                    );
                    return true;
                }
                $sign = $delta > 0 ? '➕' : '➖';
                showMenu($cid,
                    "✅ $sign <b>$amount</b> Nano Coin.\n" .
                    "👤 ID: <code>$target</code>\n" .
                    "🪙 Yangi balans: <b>{$r['balance']}</b>"
                );
                // Foydalanuvchiga xabar
                Telegram::send($target, $delta > 0
                    ? "🎁 Hisobingizga <b>$amount</b> Nano Coin qo'shildi!\n🪙 Balans: <b>{$r['balance']}</b>"
                    : "ℹ️ Hisobingizdan <b>$amount</b> Nano Coin yechildi.\n🪙 Balans: <b>{$r['balance']}</b>"
                );
                return true;

            case 'nano_set_register':
            case 'nano_set_daily':
            case 'nano_set_cost':
                if (!is_digits($text)) {
                    showMenu($cid, "❌ Faqat raqam yuboring.", Keyboard::cancel());
                    return true;
                }
                $map = [
                    'nano_set_register' => ['register_bonus', 'Register bonus'],
                    'nano_set_daily'    => ['daily_bonus', 'Kunlik bonus'],
                    'nano_set_cost'     => ['ai_cost', "AI so'rovi narxi"],
                ];
                [$key, $label] = $map[$step];
                NanoRepo::setCfg($key, (int)$text);
                State::clear($cid);
                showMenu($cid, "✅ <b>$label</b> yangilandi: <b>" . (int)$text . "</b>", [[
                    ['text' => '🔙 Nano panel', 'callback_data' => 'nano_panel'],
                ]]);
                return true;
        }

        return false;
    }

    // ---- Yordamchilar ----

    private static function prompt(int $cid, string $step, string $text): void
    {
        State::set($cid, $step);
        showMenu($cid, $text, Keyboard::cancel());
    }

    private static function showUser(int $cid, int $target): void
    {
        $balance = NanoRepo::balance($target);
        $txns = NanoRepo::userTransactions($target, 8);
        $t = "👤 <b>Foydalanuvchi</b> <code>$target</code>\n🪙 Balans: <b>$balance</b>\n\n";
        if (!$txns) {
            $t .= "📭 Tranzaksiyalar yo'q.";
        } else {
            $t .= "📜 <b>Oxirgi harakatlar:</b>\n";
            foreach ($txns as $x) {
                $amt = (int)$x['amount'];
                $sign = $amt >= 0 ? '➕' : '➖';
                $t .= "$sign " . abs($amt) . " · " . self::reasonLabel($x['reason']) .
                      " · 🪙{$x['balance_after']} · " . substr((string)$x['created_at'], 0, 16) . "\n";
            }
        }
        showMenu($cid, $t, [[['text' => '🔙 Nano panel', 'callback_data' => 'nano_panel']]]);
    }

    public static function topText(): string
    {
        $rows = NanoRepo::topRichest(10);
        if (!$rows) {
            return "🏆 <b>Eng boylar</b>\n\n📭 Hozircha balansi bor foydalanuvchi yo'q.";
        }
        $t = "🏆 <b>Eng boy foydalanuvchilar</b>\n\n";
        $i = 1;
        foreach ($rows as $u) {
            $medal = [1 => '🥇', 2 => '🥈', 3 => '🥉'][$i] ?? "$i.";
            $uname = $u['username'] ? "@{$u['username']}" : '—';
            $t .= "$medal " . e((string)$u['name']) . " ($uname) — 🪙 <b>{$u['nano_balance']}</b>\n";
            $i++;
        }
        return $t;
    }

    public static function txnsText(array $p): string
    {
        $t = "📜 <b>Tranzaksiyalar</b> ({$p['total']} ta) — {$p['page']}/{$p['pages']}\n\n";
        if (!$p['rows']) {
            return $t . "📭 Bo'sh.";
        }
        foreach ($p['rows'] as $x) {
            $amt  = (int)$x['amount'];
            $sign = $amt >= 0 ? '➕' : '➖';
            $name = $x['name'] ? e((string)$x['name']) : "ID {$x['user_id']}";
            $t .= "$sign <b>" . abs($amt) . "</b> · " . self::reasonLabel($x['reason']) .
                  " · $name · 🪙{$x['balance_after']}\n";
        }
        return $t;
    }

    private static function reasonLabel(string $reason): string
    {
        return [
            'register'   => '🎉 register',
            'daily'      => '📅 kunlik',
            'ai_request' => '🤖 AI',
            'refund'     => '↩️ qaytarish',
            'admin_add'  => '👑 admin+',
            'admin_sub'  => '👑 admin−',
        ][$reason] ?? $reason;
    }
}
