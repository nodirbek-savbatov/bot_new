<?php
/**
 * NanoRepo — Nano Coin ichki valyutasi (AI uchun to'lov birligi).
 *
 * XAVFSIZLIK:
 *  - Manfiy balans yo'q: debet bitta himoyalangan UPDATE (WHERE nano_balance >= cost).
 *  - Double-spend yo'q: shu UPDATE atomik — bir vaqtda ikki marta yechilmaydi.
 *  - Atomiklik: har harakat Database::transaction() ichida (balans + jurnal birga).
 *  - Audit: har bir coin harakati nano_transactions ga balance_after bilan yoziladi.
 *  - SQL injection yo'q: barcha so'rovlar prepared.
 *
 * Sozlamalar (register/daily/ai narxi) avval SettingRepo (admin tahriri), keyin Config default.
 */
final class NanoRepo
{
    // ---- Sozlamalar (admin SettingRepo orqali o'zgartiradi; aks holda config default) ----

    public static function cfg(string $key, int $default): int
    {
        $override = SettingRepo::get('nano_' . $key, null);
        if ($override !== null && $override !== '') {
            return (int)$override;
        }
        return (int)Config::get('nano.' . $key, $default);
    }

    public static function setCfg(string $key, int $value): void
    {
        SettingRepo::set('nano_' . $key, (string)max(0, $value));
    }

    // ---- O'qish ----

    public static function balance(int $uid): int
    {
        return (int)Database::value("SELECT nano_balance FROM users WHERE id = ?", [$uid]);
    }

    // ---- Asosiy ledger operatsiyalari (atomik) ----

    /** Balansga qo'shadi va jurnalga yozadi. Yangi balansni qaytaradi. */
    public static function credit(int $uid, int $amount, string $reason, string $note = ''): int
    {
        if ($amount <= 0) {
            return self::balance($uid);
        }
        return (int)Database::transaction(static function () use ($uid, $amount, $reason, $note): int {
            Database::execute("UPDATE users SET nano_balance = nano_balance + ? WHERE id = ?", [$amount, $uid]);
            $balance = (int)Database::value("SELECT nano_balance FROM users WHERE id = ?", [$uid]);
            self::log($uid, $amount, $balance, $reason, $note);
            return $balance;
        });
    }

    /**
     * Balansdan yechadi. Yetarli bo'lsa true (yechildi + jurnal), aks holda false.
     * Atomik: WHERE nano_balance >= cost — manfiy balans / double-spend imkonsiz.
     */
    public static function debit(int $uid, int $cost, string $reason, string $note = ''): bool
    {
        if ($cost <= 0) {
            return true;
        }
        return (bool)Database::transaction(static function () use ($uid, $cost, $reason, $note): bool {
            $affected = Database::execute(
                "UPDATE users SET nano_balance = nano_balance - ? WHERE id = ? AND nano_balance >= ?",
                [$cost, $uid, $cost]
            );
            if ($affected !== 1) {
                return false; // balans yetarli emas (yoki user yo'q)
            }
            $balance = (int)Database::value("SELECT nano_balance FROM users WHERE id = ?", [$uid]);
            self::log($uid, -$cost, $balance, $reason, $note);
            return true;
        });
    }

    // ---- Bonuslar ----

    /** Ro'yxatdan o'tish bonusi — har user uchun BIR MARTA (reason='register' mavjudligi tekshiriladi). */
    public static function grantRegisterBonus(int $uid): bool
    {
        $bonus = self::cfg('register_bonus', 100);
        if ($bonus <= 0) {
            return false;
        }
        return (bool)Database::transaction(static function () use ($uid, $bonus): bool {
            $already = Database::value(
                "SELECT 1 FROM nano_transactions WHERE user_id = ? AND reason = 'register' LIMIT 1",
                [$uid]
            );
            if ($already !== null) {
                return false; // allaqachon berilgan
            }
            Database::execute("UPDATE users SET nano_balance = nano_balance + ? WHERE id = ?", [$bonus, $uid]);
            $balance = (int)Database::value("SELECT nano_balance FROM users WHERE id = ?", [$uid]);
            self::log($uid, $bonus, $balance, 'register', "Ro'yxatdan o'tish bonusi");
            return true;
        });
    }

    /**
     * Kunlik bonus — har 24 soatda bir marta (atomik UPDATE bilan double-claim oldi olinadi).
     * @return array{ok:bool, amount?:int, balance:int, wait?:int}
     */
    public static function claimDaily(int $uid): array
    {
        $bonus = self::cfg('daily_bonus', 10);
        return (array)Database::transaction(static function () use ($uid, $bonus): array {
            $affected = Database::execute(
                "UPDATE users SET nano_balance = nano_balance + ?, last_daily = NOW()
                 WHERE id = ? AND (last_daily IS NULL OR last_daily <= NOW() - INTERVAL 24 HOUR)",
                [$bonus, $uid]
            );
            if ($affected !== 1) {
                return ['ok' => false, 'wait' => self::nextDailyWait($uid), 'balance' => self::balance($uid)];
            }
            $balance = (int)Database::value("SELECT nano_balance FROM users WHERE id = ?", [$uid]);
            self::log($uid, $bonus, $balance, 'daily', 'Kunlik bonus');
            return ['ok' => true, 'amount' => $bonus, 'balance' => $balance];
        });
    }

    /** Keyingi kunlik bonusgacha qolgan soniya (0 = hozir mavjud). */
    public static function nextDailyWait(int $uid): int
    {
        $sec = Database::value(
            "SELECT GREATEST(0, 86400 - TIMESTAMPDIFF(SECOND, last_daily, NOW()))
             FROM users WHERE id = ?",
            [$uid]
        );
        return $sec === null ? 0 : (int)$sec;
    }

    public static function dailyAvailable(int $uid): bool
    {
        return self::nextDailyWait($uid) <= 0;
    }

    // ---- Admin boshqaruvi ----

    /**
     * Admin tomonidan +/- (delta>0 berish, delta<0 yechish).
     * @return array{ok:bool, balance:int}
     */
    public static function adminAdjust(int $uid, int $delta, int $byAdmin): array
    {
        if ($delta === 0) {
            return ['ok' => false, 'balance' => self::balance($uid)];
        }
        if ($delta > 0) {
            $bal = self::credit($uid, $delta, 'admin_add', 'admin:' . $byAdmin);
            return ['ok' => true, 'balance' => $bal];
        }
        $ok = self::debit($uid, -$delta, 'admin_sub', 'admin:' . $byAdmin);
        return ['ok' => $ok, 'balance' => self::balance($uid)];
    }

    /** Eng boy foydalanuvchilar. */
    public static function topRichest(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return Database::fetchAll(
            "SELECT id, name, username, nano_balance FROM users
             WHERE nano_balance > 0 ORDER BY nano_balance DESC, id ASC LIMIT $limit"
        );
    }

    /** Barcha tranzaksiyalar (sahifalangan, admin uchun). */
    public static function transactionsAll(int $page = 1, int $perPage = 10): array
    {
        $total  = (int)Database::value("SELECT COUNT(*) FROM nano_transactions");
        $pages  = max(1, (int)ceil($total / $perPage));
        $page   = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT t.id, t.user_id, t.amount, t.balance_after, t.reason, t.created_at, u.name
             FROM nano_transactions t
             LEFT JOIN users u ON u.id = t.user_id
             ORDER BY t.id DESC LIMIT $perPage OFFSET $offset"
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** Bitta userning oxirgi tranzaksiyalari. */
    public static function userTransactions(int $uid, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return Database::fetchAll(
            "SELECT amount, balance_after, reason, created_at
             FROM nano_transactions WHERE user_id = ? ORDER BY id DESC LIMIT $limit",
            [$uid]
        );
    }

    // ---- Ichki ----

    private static function log(int $uid, int $amount, int $balanceAfter, string $reason, string $note): void
    {
        Database::execute(
            "INSERT INTO nano_transactions (user_id, amount, balance_after, reason, note, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$uid, $amount, $balanceAfter, $reason, mb_substr($note, 0, 255)]
        );
    }
}
