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
