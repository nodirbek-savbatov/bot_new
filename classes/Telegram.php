<?php
/**
 * Telegram — Bot API o'rami (eski global bot() o'rnida).
 * Xususiyatlari: retry, 429 (flood) retry_after hurmati, har xato javobni log,
 * CURLFile (fayl yuborish), matn/caption uzunligini xavfsiz cheklash.
 * call() har doim dekod qilingan massiv (yoki null) qaytaradi.
 */
final class Telegram
{
    private static string $token = '';
    private static string $api   = 'https://api.telegram.org';

    private const TEXT_LIMIT    = 4096;
    private const CAPTION_LIMIT = 1024;

    public static function init(string $token, string $api = 'https://api.telegram.org'): void
    {
        self::$token = $token;
        self::$api   = rtrim($api, '/');
    }

    /** Asosiy API chaqiruvi. */
    public static function call(string $method, array $params = [], int $retries = 2): ?array
    {
        $url = self::$api . '/bot' . self::$token . '/' . $method;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => $params,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $raw      = curl_exec($ch);
            $errno    = curl_errno($ch);
            $err      = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                Logger::warning("cURL xato ($method): $err", ['attempt' => $attempt]);
                usleep(300000);
                continue;
            }

            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                Logger::error("JSON parse xato ($method)", ['raw' => substr((string)$raw, 0, 300)]);
                return null;
            }

            if (($data['ok'] ?? false) === true) {
                return $data;
            }

            $code = (int)($data['error_code'] ?? $httpCode);
            $desc = $data['description'] ?? 'nomalum';

            if ($code === 429) {
                $retryAfter = (int)($data['parameters']['retry_after'] ?? 1);
                Logger::warning("429 flood ($method), retry_after=$retryAfter");
                sleep(min($retryAfter, 5));
                continue;
            }

            Logger::warning("API xato ($method): [$code] $desc", ['params' => self::safeParams($params)]);
            return $data; // ok=false — chaqiruvchi o'zi tekshiradi
        }
        return null;
    }

    // ---- Qulay metodlar ----

    public static function send(int|string $chatId, string $text, array $opts = []): ?array
    {
        return self::call('sendMessage', array_merge([
            'chat_id'                  => $chatId,
            'text'                     => self::clip($text, self::TEXT_LIMIT),
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ], $opts));
    }

    public static function editText(int|string $chatId, int $msgId, string $text, array $opts = []): ?array
    {
        return self::call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $msgId,
            'text'       => self::clip($text, self::TEXT_LIMIT),
            'parse_mode' => 'HTML',
        ], $opts));
    }

    public static function editMarkup(int|string $chatId, int $msgId, array $inlineKeyboard): ?array
    {
        return self::call('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }

    public static function delete(int|string $chatId, int $msgId): ?array
    {
        return self::call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
    }

    public static function copy(int|string $chatId, int|string $fromChatId, int $msgId, array $opts = []): ?array
    {
        if (isset($opts['caption'])) {
            $opts['caption'] = self::clip($opts['caption'], self::CAPTION_LIMIT);
        }
        return self::call('copyMessage', array_merge([
            'chat_id'      => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id'   => $msgId,
        ], $opts));
    }

    public static function answerCb(string $cbId, array $opts = []): ?array
    {
        return self::call('answerCallbackQuery', array_merge(['callback_query_id' => $cbId], $opts));
    }

    public static function answerInline(string $inlineId, array $results, array $opts = []): ?array
    {
        return self::call('answerInlineQuery', array_merge([
            'inline_query_id' => $inlineId,
            'results'         => json_encode($results, JSON_UNESCAPED_UNICODE),
        ], $opts));
    }

    public static function getChat(int|string $chatId): ?array
    {
        return self::call('getChat', ['chat_id' => $chatId]);
    }

    public static function getChatMember(int|string $chatId, int $userId): ?array
    {
        return self::call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /** Bot ID (tokendan olinadi: "<id>:<hash>"). */
    public static function botId(): int
    {
        $parts = explode(':', self::$token, 2);
        return (int)($parts[0] ?? 0);
    }

    /** Bot username (keshlanadi). */
    public static function username(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $cached = (string)SettingRepo::get('bot_username', '');
        if ($cached === '') {
            $me = self::call('getMe');
            $cached = $me['result']['username'] ?? '';
            if ($cached !== '') SettingRepo::set('bot_username', $cached);
        }
        return $cached;
    }

    // ---- Ichki ----

    private static function clip(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }

    /** Log uchun: fayl/maxfiy maydonlarni yashirish. */
    private static function safeParams(array $params): array
    {
        foreach ($params as $k => $v) {
            if ($v instanceof CURLFile) {
                $params[$k] = '[file]';
            } elseif (is_string($v) && mb_strlen($v) > 200) {
                $params[$k] = mb_substr($v, 0, 200) . '…';
            }
        }
        return $params;
    }
}
