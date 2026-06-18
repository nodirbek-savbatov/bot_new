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

## 🤖 AI Kino Yordamchisi + 🪙 Nano Coin

AI moduli (Google Gemini) — alohida, modular service. Mavjud funksiyalarga ta'sir qilmaydi:
faqat **AI session aktiv** userlar uchun ishlaydi; qolganlar eski oqimda davom etadi.

**Modullar:** `ai/GeminiClient.php` (HTTP klient), `ai/AiPrompt.php` (system prompt + baza
konteksti), `ai/AiService.php` (orkestrlash + javob keshi), `ai/AiHandler.php` (bot integratsiyasi),
`database/NanoRepo.php` (coin ledger), `database/AiRepo.php` (session/xotira/kesh),
`admin/NanoAdmin.php` (admin paneli), `handlers/ProfileHandler.php` (bot profil).

**Sozlash** (`config/config.php`):
```php
'ai'   => ['gemini' => ['api_key' => 'SIZNING_GEMINI_KEY', 'model' => 'gemini-2.5-flash'], ...],
'nano' => ['register_bonus' => 100, 'daily_bonus' => 10, 'ai_cost' => 10],
```
- **Gemini kaliti:** https://aistudio.google.com/apikey dan oling → `ai.gemini.api_key` ga qo'ying.
- Register/kunlik bonus va AI narxi adminda (`👑 Admin panel → 🪙 Nano Coin`) ham o'zgartiriladi
  (qiymatlar `settings` jadvalida saqlanadi, config faqat default).

**Migratsiya:** mavjud bazaga `php database/install.php` ni qayta yuriting — u `users` jadvaliga
`nano_balance`/`last_daily` ustunlarini va yangi jadvallarni (`nano_transactions`, `ai_sessions`,
`ai_messages`, `ai_cache`) idempotent qo'shadi.

**Ishlash tartibi:** AI har so'rov uchun `ai_cost` Nano Coin yechadi (atomik — manfiy balans/double-spend
yo'q, har harakat `nano_transactions` ga loglanadi). Yangi user 100, har 24 soatda +10 (👤 Profil →
🎁 Kunlik bonus) oladi. AI avval bot bazasini tekshiradi (fuzzy qidiruv), topilsa kodini tavsiya qiladi.

> Eski yagona-fayl versiyasi: `bot.legacy.php` (zaxira).
