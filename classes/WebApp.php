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
        if ($hash === '') return null;
        // `hash` va (yangi) Ed25519 `signature` data_check_string'ga kirmaydi.
        unset($data['hash'], $data['signature']);

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
            return null; // imzo mos kelmadi — soxta yoki buzilgan
        }

        // 4) Eskirgan initData'ni rad etamiz (qayta o'ynatishdan himoya).
        $authDate = (int)($data['auth_date'] ?? 0);
        if ($authTtl > 0 && $authDate > 0 && (time() - $authDate) > $authTtl) {
            return null;
        }

        // 5) Foydalanuvchi JSON'ini ajratamiz.
        $user = json_decode($data['user'] ?? '', true);
        if (!is_array($user) || empty($user['id'])) {
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
