<?php
/**
 * WebAppHandler — Web App (Mini App) bilan botni bog'lovchi yagona mantiq.
 *
 * Ikki kirish nuqtasi bir xil `process()` yadrosini ishlatadi:
 *   1) HTTP API  — webapp/api.php (initData bilan auth, JSON qaytaradi → app ochiq qoladi,
 *      shu sababli toast'lar ishlaydi). Asosiy yo'l.
 *   2) sendData  — Telegram.WebApp.sendData() → bot `web_app_data` xabari (handleData).
 *      App yopiladi; faqat botdagi xabarlar ko'rinadi.
 *
 * Video HECH QACHON Web App ichida saqlanmaydi/strim qilinmaydi — mavjud `deliverFilm()`
 * (baza kanaldan copyMessage) orqali foydalanuvchining chatiga yuboriladi.
 */
final class WebAppHandler
{
    // ---------- sendData (web_app_data) yo'li ----------

    public static function handleData(int $userId, string $json): void
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            Logger::warning('WebApp sendData: yaroqsiz JSON', ['raw' => mb_substr($json, 0, 200)]);
            return;
        }
        // App yopilgan — natija JSON kerak emas; process() o'zi kerakli bot xabarlarini yuboradi.
        self::process($userId, $payload);
    }

    // ---------- Yagona action yadrosi (API ham, sendData ham shuni chaqiradi) ----------

    /** @return array JSON-ga mos natija (API uchun). */
    public static function process(int $userId, array $payload): array
    {
        $action = (string)($payload['action'] ?? '');
        $code   = (int)($payload['movie_id'] ?? ($payload['code'] ?? 0));

        switch ($action) {
            case 'init':            return self::aInit($userId);
            case 'home':            return self::aHome($userId);
            case 'search':          return self::aSearch($userId, (string)($payload['q'] ?? ''));
            case 'movie':           return self::aMovie($userId, $code);
            case 'categories':      return self::aCategories();
            case 'favorites':       return self::aFavorites($userId);
            case 'history':         return self::aHistory($userId);
            case 'profile':
            case 'open_profile':    return self::aProfile($userId);
            case 'add_favorite':    return self::aAddFavorite($userId, $code);
            case 'remove_favorite': return self::aRemoveFavorite($userId, $code);
            case 'watch_movie':     return self::aWatch($userId, $code);
            default:
                return ['ok' => false, 'error' => 'unknown_action'];
        }
    }

    // =================== ACTION'lar ===================

    private static function aInit(int $userId): array
    {
        // Web App ochilganda bir marta xabar (anti-spam ttl ichida takrorlanmaydi).
        self::notify($userId, 'open_app', "📱 Web App ochildi.");
        return [
            'ok'   => true,
            'data' => [
                'bot'     => Telegram::username(),
                'profile' => self::profileData($userId),
                'home'    => self::homeData($userId),
            ],
        ];
    }

    private static function aHome(int $userId): array
    {
        return ['ok' => true, 'data' => self::homeData($userId)];
    }

    private static function aSearch(int $userId, string $q): array
    {
        $q = trim($q);
        if ($q === '') return ['ok' => true, 'data' => ['query' => '', 'results' => []]];
        $favs = WebAppRepo::favoriteCodes($userId);
        $rows = FilmRepo::searchFuzzy($q, 30);
        StatRepo::inc('searches');
        return [
            'ok'   => true,
            'data' => [
                'query'   => $q,
                'results' => array_map(fn($f) => self::dto($f, $favs), $rows),
            ],
        ];
    }

    private static function aMovie(int $userId, int $code): array
    {
        $film = FilmRepo::get($code);
        if (!$film) return ['ok' => false, 'error' => 'not_found'];
        $dto = self::dto($film, WebAppRepo::favoriteCodes($userId));

        // Serial bo'lsa — o'sha serialning boshqa qismlari
        $episodes = [];
        if ($film['type'] === 'serial' && $film['series_id']) {
            $rows = FilmRepo::episodes((int)$film['series_id'], (int)$film['season']);
            $episodes = array_map(fn($e) => [
                'code'    => (int)$e['code'],
                'episode' => (int)$e['episode'],
                'title'   => (string)$e['title'],
            ], $rows);
        }
        $dto['episodes'] = $episodes;
        $dto['bot'] = Telegram::username();
        return ['ok' => true, 'data' => $dto];
    }

    private static function aCategories(): array
    {
        $series = FilmRepo::seriesList();
        return [
            'ok'   => true,
            'data' => [
                'films_count'  => FilmRepo::countFilms(),
                'series_count' => count($series),
                'series'       => array_map(fn($s) => [
                    'id'    => (int)$s['id'],
                    'title' => (string)$s['title'],
                ], $series),
                'top' => array_map(fn($f) => self::dto($f, []), FilmRepo::top(10)),
            ],
        ];
    }

    private static function aFavorites(int $userId): array
    {
        $favs = WebAppRepo::favoriteCodes($userId);
        $rows = WebAppRepo::favorites($userId);
        return [
            'ok'   => true,
            'data' => ['items' => array_map(fn($f) => self::dto($f, $favs), $rows)],
        ];
    }

    private static function aHistory(int $userId): array
    {
        $favs = WebAppRepo::favoriteCodes($userId);
        $rows = WebAppRepo::history($userId);
        return [
            'ok'   => true,
            'data' => ['items' => array_map(fn($f) => self::dto($f, $favs), $rows)],
        ];
    }

    private static function aProfile(int $userId): array
    {
        return ['ok' => true, 'data' => self::profileData($userId)];
    }

    private static function aAddFavorite(int $userId, int $code): array
    {
        if (!FilmRepo::get($code)) return ['ok' => false, 'error' => 'not_found'];
        $added = WebAppRepo::addFavorite($userId, $code);
        if ($added) {
            self::notify($userId, "fav_add:$code", "⭐ Sevimlilarga qo'shildi.");
        }
        return ['ok' => true, 'is_favorite' => true, 'added' => $added];
    }

    private static function aRemoveFavorite(int $userId, int $code): array
    {
        WebAppRepo::removeFavorite($userId, $code);
        // O'chirishda bot xabari yuborilmaydi — Web App toasti yetarli (spam kamaytirish).
        return ['ok' => true, 'is_favorite' => false];
    }

    /**
     * "Ko'rish" — videoni botga (foydalanuvchi chatiga) yuborish.
     * Bildirishnomalar: tanlandi → tayyorlanmoqda → [video] → muvaffaqiyatli + tarix.
     */
    private static function aWatch(int $userId, int $code): array
    {
        $film = FilmRepo::get($code);
        if (!$film) {
            self::notify($userId, "watch_missing:$code", "❌ Kino topilmadi.\nAdmin bilan bog'laning.");
            return ['ok' => false, 'error' => 'not_found'];
        }

        // Majburiy obuna (botdagi kabi) — obuna bo'lmasa bot obuna so'rovini ko'rsatadi.
        if (!ChannelManager::checkSubscription($userId)) {
            return ['ok' => false, 'error' => 'subscribe', 'bot' => Telegram::username()];
        }

        $ttl = (int)Config::get('webapp.notify_ttl', 5);
        // Ketma-ket bir xil "Ko'rish" — videoni ikki marta yubormaymiz.
        if (!WebAppRepo::shouldNotify($userId, "watch:$code", $ttl)) {
            return ['ok' => true, 'delivered' => false, 'duplicate' => true, 'bot' => Telegram::username()];
        }

        Telegram::send($userId, "🎬 Siz kino tanladingiz:\n<b>" . e((string)$film['title']) . "</b>");
        Telegram::send($userId, "⏳ Kino tayyorlanmoqda...");

        $ok = deliverFilm($userId, $code); // mavjud mexanizm: baza kanaldan copyMessage (file_id)
        if (!$ok) {
            Telegram::send($userId, "❌ Video vaqtinchalik mavjud emas.");
            return ['ok' => false, 'error' => 'unavailable', 'bot' => Telegram::username()];
        }

        WebAppRepo::addHistory($userId, $code);
        Telegram::send($userId, "✅ Kino muvaffaqiyatli yuborildi.\n📝 Ko'rilganlar tarixiga qo'shildi.");

        return ['ok' => true, 'delivered' => true, 'bot' => Telegram::username()];
    }

    // =================== Yordamchilar ===================

    /** Bildirishnomani anti-spam bilan yuboradi. */
    private static function notify(int $userId, string $signature, string $text): void
    {
        $ttl = (int)Config::get('webapp.notify_ttl', 5);
        if (WebAppRepo::shouldNotify($userId, $signature, $ttl)) {
            Telegram::send($userId, $text);
        }
    }

    /** Film qatorini Web App uchun toza obyektga aylantiradi. */
    private static function dto(array $f, array $favCodes): array
    {
        $code = (int)$f['code'];
        return [
            'code'        => $code,
            'title'       => (string)$f['title'],
            'description' => (string)($f['description'] ?? ''),
            'type'        => (string)$f['type'],
            'season'      => (int)$f['season'],
            'episode'     => (int)$f['episode'],
            'views'       => (int)$f['views'],
            'likes'       => (int)$f['likes'],
            'dislikes'    => (int)$f['dislikes'],
            'is_favorite' => in_array($code, $favCodes, true),
        ];
    }

    private static function homeData(int $userId): array
    {
        $favs = WebAppRepo::favoriteCodes($userId);
        $latest = FilmRepo::latestFilms(1, 12);
        return [
            'latest'     => array_map(fn($f) => self::dto($f, $favs), $latest['rows']),
            'top'        => array_map(fn($f) => self::dto($f, $favs), FilmRepo::top(10)),
            'films_total' => $latest['total'],
        ];
    }

    private static function profileData(int $userId): array
    {
        return [
            'id'            => $userId,
            'bot'           => Telegram::username(),
            'favorites'     => WebAppRepo::favCount($userId),
            'history'       => WebAppRepo::historyCount($userId),
            'history_items' => array_map(
                fn($f) => self::dto($f, []),
                WebAppRepo::history($userId, 20)
            ),
        ];
    }
}
