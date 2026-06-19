<?php
/**
 * FilmRepo — filmlar, seriallar, reaktsiyalar, qidiruv, sahifalash.
 * Barcha hisoblagichlar atomik UPDATE bilan (race-condition yo'q).
 */
final class FilmRepo
{
    // ---- Yaratish ----

    /** Serial nomini series jadvaliga qo'shadi (yoki mavjudini topadi). */
    public static function seriesId(string $title): int
    {
        Database::execute("INSERT IGNORE INTO series (title) VALUES (?)", [$title]);
        return (int)Database::value("SELECT id FROM series WHERE title = ?", [$title]);
    }

    public static function createFilm(int $msgId, string $title, string $desc): int
    {
        return Database::insert(
            "INSERT INTO films (msg_id, title, description, type, created_at)
             VALUES (?, ?, ?, 'film', NOW())",
            [$msgId, mb_substr($title, 0, 255), $desc]
        );
    }

    public static function createSerial(int $msgId, string $title, string $desc, int $season, int $episode): int
    {
        $sid = self::seriesId(mb_substr($title, 0, 255));
        return Database::insert(
            "INSERT INTO films (msg_id, title, description, type, series_id, season, episode, created_at)
             VALUES (?, ?, ?, 'serial', ?, ?, ?, NOW())",
            [$msgId, mb_substr($title, 0, 255), $desc, $sid, $season, $episode]
        );
    }

    // ---- O'qish ----

    public static function get(int $code): ?array
    {
        return Database::fetch("SELECT * FROM films WHERE code = ?", [$code]);
    }

    public static function countFilms(): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM films WHERE type = 'film'");
    }

    public static function countAll(): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM films");
    }

    public static function lastCode(): int
    {
        return (int)Database::value("SELECT COALESCE(MAX(code),0) FROM films");
    }

    /** Nom bo'yicha qidiruv (substring, case-insensitive). */
    public static function searchByName(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $limit = max(1, min($limit, 50));
        return Database::fetchAll(
            "SELECT * FROM films WHERE title LIKE ? ORDER BY views DESC, code DESC LIMIT $limit",
            [$like]
        );
    }

    /**
     * Qidiruv "shovqin" so'zlari — so'rovni tozalashda tashlanadi (o'zbek + ruscha + inglizcha,
     * AI/suhbat konteksti uchun). Bularning hammasi tashlansa va hech narsa qolmasa,
     * asl so'zlar (stop-so'zsiz) qaytariladi — bo'sh natija bo'lmasligi uchun.
     */
    private const STOP_WORDS = [
        'kino', 'film', 'filmi', 'filmni', 'serial', 'seriali', 'serialni', 'multik',
        'haqida', 'haqidagi', 'nomli', 'degan', 'bilan', 'uchun', 'kabi', 'yoki',
        'menga', 'iltimos', 'tavsiya', 'qil', 'qila', 'qiling', 'qilib',
        'topib', 'ber', 'bering', 'boring', 'kerak', 'istayman', 'xohlayman',
        'korsat', "ko'rsat", 'kormoqchiman', 'qidir', 'qidiryapman', 'qaysi', 'qanaqa', 'qanday',
        'bormi', 'yangi', 'eng', 'zor', 'yaxshi', 'mashhur', 'janr', 'janri', 'yilgi',
        'про', 'фильм', 'кино', 'сериал', 'about', 'movie', 'the', 'and', 'with',
    ];

    /** So'rovni qidiruv so'zlariga ajratadi (stop-so'zlarsiz, >=2 belgi). */
    private static function searchWords(string $q): array
    {
        $all = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($q, 'UTF-8')) ?: [],
            static fn($w) => mb_strlen($w, 'UTF-8') >= 2
        ));
        // Stop-so'zlarni olib tashlaymiz.
        $words = array_values(array_filter(
            $all,
            static fn($w) => !in_array($w, self::STOP_WORDS, true)
        ));
        // Hammasi stop-so'z bo'lib chiqsa — asl so'zlarni qaytaramiz (bo'sh qolmasin).
        if (!$words) $words = $all;
        if (!$words) $words = [mb_strtolower(trim($q), 'UTF-8')];
        return $words;
    }

    /**
     * Fuzzy qidiruv — nom (title) VA tavsif (description) bo'yicha, relevance tartibida.
     *  - So'rov stop-so'zlardan tozalanib so'zlarga ajratiladi; har so'z alohida LIKE shart.
     *  - Qator topiladi agar KAMIDA bitta so'z title YOKI description'da uchrasa (OR),
     *    shu bilan "tez gazabli" → "Tez va G'azabli", "kosmos haqida" → tavsifida kosmos
     *    bo'lgan kinolar, hatto aktyor/qahramon ismi tavsifda bo'lsa — topiladi.
     *  - Tartib: aniq tenglik > boshlanishi mos > to'liq ibora > title'dagi mos so'zlar >
     *    tavsifdagi mos so'zlar (title ancha og'irroq baholanadi).
     */
    public static function searchFuzzy(string $q, int $limit = 20): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if ($q === '') return [];
        $limit = max(1, min($limit, 50));

        $words = self::searchWords($q);

        $esc = static fn(string $s): string =>
            str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);

        $ql    = mb_strtolower($q, 'UTF-8');
        $whole = '%' . $esc($ql) . '%';

        // Placeholderlar SQL ichida SELECT (relevance), keyin WHERE tartibida keladi —
        // shuning uchun parametrlarni ham aynan shu tartibda alohida yig'amiz.
        $score = ["(CASE WHEN LOWER(title) = ?    THEN 1000 ELSE 0 END)",  // aniq tenglik (title)
                  "(CASE WHEN LOWER(title) LIKE ? THEN 500  ELSE 0 END)",  // boshlanishi mos (title)
                  "(CASE WHEN LOWER(title) LIKE ? THEN 300  ELSE 0 END)",  // to'liq ibora (title)
                  "(CASE WHEN LOWER(COALESCE(description,'')) LIKE ? THEN 120 ELSE 0 END)"]; // to'liq ibora (tavsif)
        $scoreParams = [$ql, $esc($ql) . '%', $whole, $whole];

        $conds       = [];
        $whereParams = [];
        foreach ($words as $w) {
            $like          = '%' . $esc($w) . '%';
            // Title'dagi mos so'z — yuqori vazn; tavsifdagi — pastroq.
            $score[]       = "(CASE WHEN LOWER(title) LIKE ? THEN 60 ELSE 0 END)";
            $scoreParams[] = $like;
            $score[]       = "(CASE WHEN LOWER(COALESCE(description,'')) LIKE ? THEN 15 ELSE 0 END)";
            $scoreParams[] = $like;
            // Qator topilishi uchun: so'z title YOKI description'da bo'lsa kifoya.
            $conds[]       = "(LOWER(title) LIKE ? OR LOWER(COALESCE(description,'')) LIKE ?)";
            $whereParams[] = $like;
            $whereParams[] = $like;
        }

        $scoreSql = implode(' + ', $score);
        $where    = implode(' OR ', $conds);

        $sql = "SELECT *, ($scoreSql) AS relevance
                FROM films
                WHERE $where
                ORDER BY relevance DESC, views DESC, code DESC
                LIMIT $limit";

        return Database::fetchAll($sql, array_merge($scoreParams, $whereParams));
    }

    /** Eng so'nggi filmlar (sahifalangan). */
    public static function latestFilms(int $page, int $perPage = 8): array
    {
        $total  = self::countFilms();
        $pages  = max(1, (int)ceil($total / $perPage));
        $page   = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT * FROM films WHERE type = 'film' ORDER BY code DESC LIMIT $perPage OFFSET $offset"
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** Top filmlar (ko'rishlar bo'yicha, film + serial). */
    public static function top(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return Database::fetchAll("SELECT * FROM films ORDER BY views DESC, code DESC LIMIT $limit");
    }

    // ---- Seriallar ----

    /** Serial guruhlari: [['id','title'], ...]. */
    public static function seriesList(): array
    {
        return Database::fetchAll(
            "SELECT s.id, s.title
             FROM series s
             JOIN films f ON f.series_id = s.id AND f.type = 'serial'
             GROUP BY s.id, s.title
             ORDER BY s.title"
        );
    }

    public static function seriesTitle(int $seriesId): string
    {
        return (string)Database::value("SELECT title FROM series WHERE id = ?", [$seriesId]);
    }

    /** Serialni id bo'yicha olish (navigatsiya / yuklash uchun). */
    public static function seriesById(int $seriesId): ?array
    {
        return Database::fetch("SELECT id, title FROM series WHERE id = ?", [$seriesId]);
    }

    /**
     * Serial nomini fuzzy qidiradi (faqat qismi bor seriallar).
     * searchFuzzy bilan bir xil relevance mantig'i, lekin `series` jadvali bo'yicha guruhlangan.
     * @return array<array{id:int,title:string,episodes:int}>
     */
    public static function searchSeries(string $q, int $limit = 30): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if ($q === '') return [];
        $limit = max(1, min($limit, 50));

        $words = array_values(array_filter(
            explode(' ', mb_strtolower($q, 'UTF-8')),
            static fn($w) => mb_strlen($w, 'UTF-8') >= 2
        ));
        if (!$words) $words = [mb_strtolower($q, 'UTF-8')];

        $esc = static fn(string $s): string =>
            str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);

        $ql    = mb_strtolower($q, 'UTF-8');
        $whole = '%' . $esc($ql) . '%';

        $score = ["(CASE WHEN LOWER(s.title) = ?    THEN 1000 ELSE 0 END)",
                  "(CASE WHEN LOWER(s.title) LIKE ? THEN 500  ELSE 0 END)",
                  "(CASE WHEN LOWER(s.title) LIKE ? THEN 300  ELSE 0 END)"];
        $scoreParams = [$ql, $esc($ql) . '%', $whole];

        $conds       = [];
        $whereParams = [];
        foreach ($words as $w) {
            $like          = '%' . $esc($w) . '%';
            $score[]       = "(CASE WHEN LOWER(s.title) LIKE ? THEN 50 ELSE 0 END)";
            $scoreParams[] = $like;
            $conds[]       = "LOWER(s.title) LIKE ?";
            $whereParams[] = $like;
        }

        $scoreSql = implode(' + ', $score);
        $where    = implode(' OR ', $conds);

        $sql = "SELECT s.id, s.title, COUNT(f.code) AS episodes, ($scoreSql) AS relevance
                FROM series s
                JOIN films f ON f.series_id = s.id AND f.type = 'serial'
                WHERE $where
                GROUP BY s.id, s.title
                ORDER BY relevance DESC, s.title ASC
                LIMIT $limit";

        return Database::fetchAll($sql, array_merge($scoreParams, $whereParams));
    }

    /** Serialdagi fasllar: [['season','cnt'], ...]. */
    public static function seasons(int $seriesId): array
    {
        return Database::fetchAll(
            "SELECT season, COUNT(*) AS cnt FROM films
             WHERE series_id = ? AND type = 'serial'
             GROUP BY season ORDER BY season",
            [$seriesId]
        );
    }

    /** Fasldagi qismlar. */
    public static function episodes(int $seriesId, int $season): array
    {
        return Database::fetchAll(
            "SELECT * FROM films
             WHERE series_id = ? AND season = ? AND type = 'serial'
             ORDER BY episode",
            [$seriesId, $season]
        );
    }

    /** "Barcha kinolar" uchun: filmlar ro'yxati + seriallar guruhi. */
    public static function catalog(): array
    {
        $films = Database::fetchAll(
            "SELECT code, title FROM films WHERE type = 'film' ORDER BY code DESC"
        );
        $rows = Database::fetchAll(
            "SELECT s.title AS series_title, f.season, f.episode, f.code
             FROM films f JOIN series s ON s.id = f.series_id
             WHERE f.type = 'serial'
             ORDER BY s.title, f.season, f.episode"
        );
        $serials = [];
        foreach ($rows as $r) {
            $serials[$r['series_title']][(int)$r['season']][] = $r;
        }
        return ['films' => $films, 'serials' => $serials];
    }

    // ---- O'zgartirish ----

    /** Faqat ruxsat etilgan maydonlarni yangilaydi. */
    public static function update(int $code, array $fields): void
    {
        $allowed = ['title', 'description'];
        $set = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $set[] = "$k = ?";
                $params[] = $v;
            }
        }
        if (!$set) return;
        $params[] = $code;
        Database::execute("UPDATE films SET " . implode(', ', $set) . " WHERE code = ?", $params);
    }

    /** Film posterini o'rnatadi (faqat type='film' uchun ishlatiladi). */
    public static function setFilmPoster(int $code, string $file): void
    {
        Database::execute("UPDATE films SET poster = ? WHERE code = ?", [$file, $code]);
    }

    /** Serial posterini o'rnatadi (bitta serialga bitta — barcha qismlarga umumiy). */
    public static function setSeriesPoster(int $seriesId, string $file): void
    {
        Database::execute("UPDATE series SET poster = ? WHERE id = ?", [$file, $seriesId]);
    }

    /**
     * Serial posteri (fayl nomi yoki ''). So'rov davomida keshlanadi —
     * ro'yxatlarda bir serialning bir nechta qismi bo'lsa ham N+1 so'rov bo'lmaydi.
     */
    public static function seriesPoster(int $seriesId): string
    {
        static $cache = [];
        if (!array_key_exists($seriesId, $cache)) {
            $cache[$seriesId] = (string)Database::value("SELECT poster FROM series WHERE id = ?", [$seriesId]);
        }
        return $cache[$seriesId];
    }

    public static function delete(int $code): void
    {
        Database::transaction(static function () use ($code): void {
            Database::execute("DELETE FROM reactions WHERE film_code = ?", [$code]);
            Database::execute("DELETE FROM films WHERE code = ?", [$code]);
        });
    }

    /** Ko'rish hisoblagichi (atomik). */
    public static function addView(int $code): void
    {
        Database::execute("UPDATE films SET views = views + 1 WHERE code = ?", [$code]);
    }

    /**
     * Reaktsiya: bitta user — bitta reaktsiya. Toggle:
     *  yo'q -> qo'shadi; bir xil -> olib tashlaydi; boshqa -> almashtiradi.
     * Yangilangan film qatorini qaytaradi.
     */
    public static function react(int $userId, int $code, string $type): ?array
    {
        if (!in_array($type, ['like', 'dislike'], true)) return null;

        return Database::transaction(static function () use ($userId, $code, $type): ?array {
            $existing = Database::value(
                "SELECT type FROM reactions WHERE user_id = ? AND film_code = ?",
                [$userId, $code]
            );

            if ($existing === null) {
                Database::execute(
                    "INSERT INTO reactions (user_id, film_code, type, created_at) VALUES (?, ?, ?, NOW())",
                    [$userId, $code, $type]
                );
                Database::execute("UPDATE films SET {$type}s = {$type}s + 1 WHERE code = ?", [$code]);
            } elseif ($existing === $type) {
                Database::execute("DELETE FROM reactions WHERE user_id = ? AND film_code = ?", [$userId, $code]);
                Database::execute("UPDATE films SET {$type}s = GREATEST({$type}s - 1, 0) WHERE code = ?", [$code]);
            } else {
                $old = $existing; // 'like' yoki 'dislike'
                Database::execute(
                    "UPDATE reactions SET type = ?, created_at = NOW() WHERE user_id = ? AND film_code = ?",
                    [$type, $userId, $code]
                );
                Database::execute(
                    "UPDATE films SET {$type}s = {$type}s + 1, {$old}s = GREATEST({$old}s - 1, 0) WHERE code = ?",
                    [$code]
                );
            }
            return self::get($code);
        });
    }
}
