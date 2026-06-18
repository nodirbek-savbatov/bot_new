<?php
/**
 * AiService — AI so'rovini orkestrlash (provider'dan mustaqil):
 *   1) Baza konteksti + system prompt (AiPrompt)
 *   2) Javob keshini tekshirish (bir xil savol → API chaqirilmaydi)
 *   3) Provider (Gemini) chaqirish
 *   4) Javobni keshlash
 *
 * Coin yechish/tekshirish bu yerda EMAS — u AiHandler'da (bot integratsiyasi).
 */
final class AiService
{
    /**
     * @param array $history [['role'=>'user'|'model','content'=>string], ...] (joriy savoldan oldingi)
     * @return array{ok:bool, text:string, cached?:bool, error?:string, dbCount?:int}
     */
    public static function ask(int $uid, string $message, array $history): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['ok' => false, 'error' => 'empty', 'text' => ''];
        }

        $dbFilms = AiPrompt::dbFilms($message);
        $system  = AiPrompt::system($uid, $dbFilms);
        $ttl     = (int)Config::get('ai.cache_ttl', 86400);

        // Kesh kaliti: model + savol + baza imzosi + qisqa kontekst digesti.
        // Kontekstni ham kalitga qo'shamiz — shunda "bir xil savol + bir xil kontekst" qayta ishlatiladi,
        // ammo boshqacha suhbat oqimida noto'g'ri kesh javob qaytmaydi.
        $ctxDigest = '';
        if ($history) {
            $tail = array_slice($history, -2);
            $ctxDigest = sha1(json_encode($tail, JSON_UNESCAPED_UNICODE));
        }
        $key = hash('sha256', implode('|', [
            GeminiClient::model(),
            mb_strtolower($message, 'UTF-8'),
            AiPrompt::signature($dbFilms),
            $ctxDigest,
        ]));

        // Bazada nechta moslik topildi (AiHandler "admindan so'rash" tugmasini shu asosda ko'rsatadi).
        $dbCount = count($dbFilms);

        $cached = AiRepo::cacheGet($key, $ttl);
        if ($cached !== null) {
            return ['ok' => true, 'text' => $cached, 'cached' => true, 'dbCount' => $dbCount];
        }

        // Tarix + joriy savol.
        $contents = $history;
        $contents[] = ['role' => 'user', 'content' => $message];

        $res = GeminiClient::generate($contents, $system);
        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'unknown'), 'text' => '', 'dbCount' => $dbCount];
        }

        $text = (string)$res['text'];
        AiRepo::cacheSet($key, $text);

        return ['ok' => true, 'text' => $text, 'cached' => false, 'dbCount' => $dbCount];
    }
}
