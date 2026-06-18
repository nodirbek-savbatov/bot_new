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

        // Xom javob (||FILMS|| markeri bilan) keshlanadi — shunda kesh-hit'da ham
        // bazani qaytadan tekshira olamiz (baza o'zgargan bo'lishi mumkin).
        $raw    = AiRepo::cacheGet($key, $ttl);
        $cached = $raw !== null;

        if (!$cached) {
            $contents   = $history;
            $contents[] = ['role' => 'user', 'content' => $message];

            $res = GeminiClient::generate($contents, $system);
            if (!($res['ok'] ?? false)) {
                return ['ok' => false, 'error' => (string)($res['error'] ?? 'unknown'), 'text' => '', 'dbCount' => count($dbFilms)];
            }
            $raw = (string)$res['text'];
            AiRepo::cacheSet($key, $raw);
        }

        // Marker'ni o'qib, tavsiya qilingan nomlarni bazadan qaytadan qidiramiz
        // (AI nomni bilsa-yu xom qidiruv topa olmagan holatni yopadi).
        $resolved = self::resolveDbMatches($raw);
        $text     = $resolved['text'];
        $films    = $resolved['films'];

        if ($films) {
            $text .= "\n\n🎬 **Botda mavjud:**";
            foreach ($films as $f) {
                $icon  = ($f['type'] === 'serial') ? '📺' : '🎬';
                $text .= "\n$icon «{$f['title']}» — kod `{$f['code']}`";
            }
        }

        return [
            'ok'          => true,
            'text'        => $text,
            'cached'      => $cached,
            'recommended' => $resolved['titles'] !== [], // AI aniq kino tavsiya qildimi
            'dbCount'     => count($films),               // shulardan nechtasi bazada bor
        ];
    }

    /**
     * AI javobidagi `||FILMS|| Nom1; Nom2` markerini o'qiydi:
     *   - markerni (va undan keyingi qismni) matndan olib tashlaydi (foydalanuvchiga ko'rsatilmaydi);
     *   - har bir nomni bazadan qidiradi va ishonchli mosini (substring yoki yuqori relevance) oladi.
     * @return array{text:string, films:array<int,array>, titles:array<string>}
     */
    private static function resolveDbMatches(string $raw): array
    {
        $titles = [];
        $clean  = trim($raw);

        if (preg_match('/\|\|\s*FILMS\s*\|\|\s*(.*)$/su', $raw, $m)) {
            $pos = mb_strpos($raw, $m[0]);
            if ($pos !== false) {
                // Marker oldidagi ortiqcha markdown belgilarini ham tozalaymiz (AI uni **bilan** o'rasa).
                $clean = rtrim(trim(mb_substr($raw, 0, $pos)), " \t\r\n*_|");
            }
            foreach (preg_split('/[;\n]+/u', (string)$m[1]) as $t) {
                $t = trim($t, " \t\r\n\"'«»·•—-");
                if ($t !== '' && mb_strlen($t, 'UTF-8') >= 2) {
                    $titles[] = $t;
                }
                if (count($titles) >= 5) break; // ko'pi bilan 5 ta
            }
        }

        $films = [];
        foreach ($titles as $t) {
            $tl = mb_strtolower($t, 'UTF-8');
            foreach (FilmRepo::searchFuzzy($t, 3) as $f) {
                $ft = mb_strtolower((string)$f['title'], 'UTF-8');
                // Ishonchli moslik: nom o'zaro substring bo'lsa yoki relevance yuqori bo'lsa.
                $strong = (mb_strpos($ft, $tl) !== false)
                       || (mb_strpos($tl, $ft) !== false)
                       || ((int)($f['relevance'] ?? 0) >= 240);
                if ($strong) {
                    $films[(int)$f['code']] = $f; // kod bo'yicha noyob
                    break;
                }
            }
        }

        return ['text' => $clean, 'films' => array_values($films), 'titles' => $titles];
    }
}
