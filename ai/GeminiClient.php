<?php
/**
 * GeminiClient — Google Gemini API ning sof HTTP klienti (alohida, almashtiriladigan provider).
 *
 * Telegram::call() uslubidagi cURL: retry, 429/5xx hurmati, xato logi.
 * Kelajakda OpenAI yoki boshqa provider qo'shish uchun shu interfeysga (generate) mos yangi
 * klass yoziladi va AiService provider'ni config('ai.provider') bo'yicha tanlaydi.
 *
 * @see https://ai.google.dev/api/generate-content
 */
final class GeminiClient
{
    /**
     * @param array $contents [['role'=>'user'|'model','content'=>string], ...] (eskidan yangiga)
     * @return array{ok:bool, text:string, error?:string, model?:string, reason?:string}
     */
    public static function generate(array $contents, string $systemPrompt): array
    {
        $apiKey = (string)Config::get('ai.gemini.api_key', '');
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'no_key', 'text' => ''];
        }

        $model  = self::model();
        $base   = rtrim((string)Config::get('ai.gemini.api', 'https://generativelanguage.googleapis.com'), '/');
        $maxOut = max(128, (int)Config::get('ai.max_output', 800));

        // Bot ichki formatini Gemini "contents" ga aylantiramiz.
        $geminiContents = [];
        foreach ($contents as $m) {
            $role = (($m['role'] ?? 'user') === 'model') ? 'model' : 'user';
            $text = trim((string)($m['content'] ?? ''));
            if ($text === '') continue;
            $geminiContents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        if (!$geminiContents) {
            return ['ok' => false, 'error' => 'empty', 'text' => ''];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => $geminiContents,
            'generationConfig'   => [
                'temperature'     => 0.7,
                'topP'            => 0.95,
                'maxOutputTokens' => $maxOut,
            ],
            // Kino suhbati (jangari, qo'rqinchli janrlar) keraksiz bloklanmasligi uchun.
            'safetySettings' => array_map(
                static fn($c) => ['category' => $c, 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH',
                 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT']
            ),
        ];

        $url  = "$base/v1beta/models/$model:generateContent?key=" . urlencode($apiKey);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $raw   = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                Logger::warning("Gemini cURL xato: $err", ['attempt' => $attempt]);
                usleep(400000);
                continue;
            }

            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                Logger::error('Gemini JSON parse xato', ['raw' => substr((string)$raw, 0, 300)]);
                return ['ok' => false, 'error' => 'bad_json', 'text' => ''];
            }

            // Vaqtinchalik xatolar — qayta urinamiz.
            if ($http === 429 || $http >= 500) {
                Logger::warning("Gemini HTTP $http — qayta urinish", ['attempt' => $attempt]);
                sleep(1);
                continue;
            }

            if ($http !== 200 || isset($data['error'])) {
                $msg = $data['error']['message'] ?? "HTTP $http";
                Logger::warning('Gemini API xato: ' . $msg);
                return ['ok' => false, 'error' => 'api', 'text' => ''];
            }

            $text = self::extractText($data);
            if ($text === '') {
                $reason = $data['candidates'][0]['finishReason']
                    ?? ($data['promptFeedback']['blockReason'] ?? 'empty');
                Logger::info("Gemini bo'sh javob: $reason");
                return ['ok' => false, 'error' => 'empty', 'text' => '', 'reason' => (string)$reason];
            }

            return ['ok' => true, 'text' => $text, 'model' => $model];
        }

        return ['ok' => false, 'error' => 'network', 'text' => ''];
    }

    /** Joriy model (admin SettingRepo orqali o'zgartirishi mumkin). */
    public static function model(): string
    {
        $override = SettingRepo::get('ai_model', null);
        if ($override !== null && $override !== '') {
            return $override;
        }
        return (string)Config::get('ai.gemini.model', 'gemini-2.5-flash');
    }

    private static function extractText(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out = '';
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $out .= $p['text'];
            }
        }
        return trim($out);
    }
}
