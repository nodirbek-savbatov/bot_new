-- =====================================================================
-- Kino Bot — MySQL sxema (utf8mb4, InnoDB)
-- install.php avtomatik bajaradi. Qo'lda: mysql movie_bot < schema.sql
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Foydalanuvchilar ----
CREATE TABLE IF NOT EXISTS users (
    id        BIGINT       NOT NULL,
    name      VARCHAR(128) NOT NULL DEFAULT '',
    username  VARCHAR(64)  NOT NULL DEFAULT '',
    joined    DATETIME     NOT NULL,
    last_seen DATETIME     NOT NULL,
    blocked   TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_blocked (blocked),
    KEY idx_joined (joined)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Adminlar ----
CREATE TABLE IF NOT EXISTS admins (
    user_id  BIGINT     NOT NULL,
    is_main  TINYINT(1) NOT NULL DEFAULT 0,
    added_at DATETIME   NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Seriallar (guruhlash — callback_data qisqa bo'lishi uchun) ----
CREATE TABLE IF NOT EXISTS series (
    id    INT          NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Filmlar / serial qismlari ----
CREATE TABLE IF NOT EXISTS films (
    code        INT          NOT NULL AUTO_INCREMENT,
    msg_id      BIGINT       NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT         NULL,
    type        ENUM('film','serial') NOT NULL DEFAULT 'film',
    series_id   INT          NULL,
    season      INT          NOT NULL DEFAULT 0,
    episode     INT          NOT NULL DEFAULT 0,
    views       INT          NOT NULL DEFAULT 0,
    likes       INT          NOT NULL DEFAULT 0,
    dislikes    INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (code),
    KEY idx_type (type),
    KEY idx_views (views),
    KEY idx_series (series_id, season, episode),
    KEY idx_created (created_at),
    FULLTEXT KEY ft_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Reaktsiyalar (takror bosishni bloklaydi) ----
CREATE TABLE IF NOT EXISTS reactions (
    user_id   BIGINT NOT NULL,
    film_code INT    NOT NULL,
    type      ENUM('like','dislike') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, film_code),
    KEY idx_film (film_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Kanallar (main / base / required) ----
CREATE TABLE IF NOT EXISTS channels (
    id       INT          NOT NULL AUTO_INCREMENT,
    username VARCHAR(64)  NOT NULL DEFAULT '',
    chat_id  BIGINT       NULL,
    title    VARCHAR(255) NOT NULL DEFAULT '',
    type     ENUM('main','base','required') NOT NULL DEFAULT 'required',
    sort     INT          NOT NULL DEFAULT 0,
    active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_type_active (type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Sozlamalar (key/value) ----
CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) NOT NULL,
    v TEXT        NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Holatlar (FSM: step + data + menyu/keyboard kuzatuvi) ----
CREATE TABLE IF NOT EXISTS states (
    user_id     BIGINT      NOT NULL,
    step        VARCHAR(64) NOT NULL DEFAULT '',
    data        JSON        NULL,
    menu_msg_id BIGINT      NULL,
    kbd_msg_id  BIGINT      NULL,
    updated_at  DATETIME    NOT NULL,
    PRIMARY KEY (user_id),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Statistika (kunlik metrikalar) ----
CREATE TABLE IF NOT EXISTS stats (
    day    DATE        NOT NULL,
    metric VARCHAR(32) NOT NULL,
    cnt    INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (day, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Broadcast navbati (async yuborish) ----
CREATE TABLE IF NOT EXISTS broadcast_queue (
    id           BIGINT NOT NULL AUTO_INCREMENT,
    target_id    BIGINT NOT NULL,
    from_chat_id BIGINT NOT NULL,
    message_id   BIGINT NOT NULL,
    status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts     INT    NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
