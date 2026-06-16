# 🎬 Kino Bot (Telegram) — MySQL, modulli, production

Film/serial tarqatuvchi Telegram bot. Webhook asosida, MySQL bilan ishlaydi.

## Talablar
- PHP **8.1+** (`pdo_mysql`, `curl`, `mbstring`)
- MySQL / MariaDB
- HTTPS domen (webhook uchun) — Oracle VPS, nginx/apache + php-fpm

## Struktura
```
bot.php              # webhook kirish nuqtasi (front controller)
bootstrap.php        # modul yuklash + infratuzilma
config/              # config.php (maxfiy), config.sample.php
classes/             # Config, Database, Logger, Telegram, State, RateLimiter, Router
database/            # schema.sql, install.php, *Repo.php (ma'lumot qatlami)
functions/           # helpers.php
keyboards/           # Keyboard.php
handlers/            # Start, Message, Admin, Callback
inline/              # InlineHandler.php
admin/               # ChannelManager.php
cron/                # broadcast_worker.php, cleanup.php
logs/                # runtime loglar
```

## O'rnatish
1. **DB yarating:**
   ```sql
   CREATE DATABASE movie_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'movie_bot'@'localhost' IDENTIFIED BY 'PAROL';
   GRANT ALL ON movie_bot.* TO 'movie_bot'@'localhost';
   ```
2. **Config:** `cp config/config.sample.php config/config.php` va to'ldiring
   (`bot.token`, `admin.main`, `db.*`, `bot.webhook_url`).
3. **O'rnatuvchi:**
   ```bash
   php database/install.php https://SIZNING-DOMEN/bot.php
   ```
   Bu: jadvallarni yaratadi, `secret` generatsiya qiladi, webhook o'rnatadi,
   bot username keshlaydi, default kanallarni seed qiladi.
4. **Kanallar:** botni baza/asosiy/majburiy kanallarga **admin** qiling.
   Botda: `Admin panel → 📢 Kanallar` orqali sozlang.
5. **Cron** (`crontab -e`):
   ```
   * * * * * /usr/bin/php /path/movie_bot/cron/broadcast_worker.php >> /path/movie_bot/logs/cron.log 2>&1
   30 3 * * * /usr/bin/php /path/movie_bot/cron/cleanup.php          >> /path/movie_bot/logs/cron.log 2>&1
   ```
6. **Xavfsizlik:** `database/install.php` ni o'chiring.

## Foydalanish
- **User:** `/start`, kino kodi, 🔍 qidirish, 📺 seriallar, 🆕 yangi, ⭐ top, inline `@bot Avatar`.
- **Admin:** film/serial yuklash, tahrirlash, o'chirish, broadcast, statistika,
  foydalanuvchilar, kanallar, adminlar boshqaruvi.

## Xavfsizlik xususiyatlari
- Webhook `secret_token` (spoofing himoyasi)
- PDO prepared statements (SQL injection yo'q)
- HTML escape (injection yo'q)
- Rate limiting (flood himoyasi)
- Markaziy loglash + exception handling

> Eski yagona-fayl versiyasi: `bot.legacy.php` (zaxira).
