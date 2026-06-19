<?php
/**
 * WebApp — Telegram Mini App'dan kelgan `initData` ni tekshiradi (autentifikatsiya).
 *
 * Telegram har Web App so'rovida `initData` query-string yuboradi. U bot token bilan
 * imzolangan (HMAC-SHA256). Backend uni quyidagicha tekshiradi:
 *   secret_key = HMAC_SHA256(key="WebAppData", data=bot_token)
 *   hash       = HMAC_SHA256(key=secret_key, data=data_check_string)
 * data_check_string — `hash`dan boshqa barcha (kalit bo'yicha saralangan) "k=v" juftliklar,
 * '\n' bilan ulangan. Mos kelsa — so'rov haqiqatan Telegram'dan, soxta emas.
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
final class WebApp
{
    /**
     * initData ni tekshiradi. To'g'ri bo'lsa foydalanuvchi massivini, aks holda null qaytaradi.
     *
     * @return array{id:int,first_name:string,last_name:string,username:string,language_code:string}|null
     */
    public static function validate(string $initData, string $botToken, int $authTtl = 86400): ?array
    {
        if ($initData === '' || $botToken === '') {
            // Eng ko'p uchraydigan sabab: initData umuman kelmayapti (SDK/transport)
            // yoki config'da bot.token bo'sh. Diagnostika uchun aniq yozamiz.
            Logger::warning('WebApp auth rad etildi: ' . ($initData === '' ? 'no_initdata' : 'no_token'), [
                'initdata_len' => mb_strlen($initData),
                'has_token'    => $botToken !== '',
            ]);
            return null;
        }

        // 1) Query-string'ni qo'lda parse qilamiz (parse_str kalitlarni buzishi mumkin).
        $data = [];
        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') continue;
            $kv = explode('=', $pair, 2);
            $key = urldecode($kv[0]);
            $data[$key] = isset($kv[1]) ? urldecode($kv[1]) : '';
        }

        $hash = $data['hash'] ?? '';
        if ($hash === '') {
            Logger::warning('WebApp auth rad etildi: no_hash', ['keys' => implode(',', array_keys($data))]);
            return null;
        }
        // Faqat `hash` data_check_string'dan chiqariladi; `signature` esa QOLADI.
        unset($data['hash']); // FIX: signature data_check_string'DA QOLADI — Telegram hash'ni faqat 'hash'siz hisoblaydi

        // 2) data_check_string — saralangan "k=v" lar '\n' bilan.
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $checkString = implode("\n", $pairs);

        // 3) HMAC zanjiri.
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calcHash  = hash_hmac('sha256', $checkString, $secretKey);

        if (!hash_equals($calcHash, $hash)) {
            // Imzo mos kelmadi — odatda config'dagi bot.token Mini App egasi bo'lgan
            // botnikiga to'g'ri kelmaganda yuz beradi (yoki initData buzilgan/soxta).
            Logger::warning('WebApp auth rad etildi: hash_mismatch', [
                'auth_date' => $data['auth_date'] ?? '',
                'keys'      => implode(',', array_keys($data)),
            ]);
            return null;
        }

        // 4) Eskirgan initData'ni rad etamiz (qayta o'ynatishdan himoya).
        $authDate = (int)($data['auth_date'] ?? 0);
        if ($authTtl > 0 && $authDate > 0 && (time() - $authDate) > $authTtl) {
            Logger::warning('WebApp auth rad etildi: expired', [
                'age_sec' => time() - $authDate,
                'ttl'     => $authTtl,
            ]);
            return null;
        }

        // 5) Foydalanuvchi JSON'ini ajratamiz.
        $user = json_decode($data['user'] ?? '', true);
        if (!is_array($user) || empty($user['id'])) {
            Logger::warning('WebApp auth rad etildi: no_user');
            return null;
        }

        return [
            'id'            => (int)$user['id'],
            'first_name'    => (string)($user['first_name'] ?? ''),
            'last_name'     => (string)($user['last_name'] ?? ''),
            'username'      => (string)($user['username'] ?? ''),
            'language_code' => (string)($user['language_code'] ?? ''),
        ];
    }
}
