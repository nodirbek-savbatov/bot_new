# Kino Bot — Refaktoring jarayoni (DAVOM ETISH UCHUN)

> Bu fayl API limit tugaganda **qayerdan davom etishni** ko'rsatadi.
> Reja fayli: `C:\Users\Nodirbek Savbatov\.claude\plans\merry-prancing-sunrise.md`

## Umumiy maqsad
Eski yagona-fayl `bot.php` (1533 qator, JSON storage) → **MySQL asosida modulli,
xavfsiz, production** bot. Hosting: Oracle VPS. Ma'lumot noldan. Webhook URL o'zgarmaydi
(`bot.php` front controller bo'lib qoladi). Eski versiya `bot.legacy.php` da saqlangan.

## ✅ BAJARILGAN fayllar (yozib bo'lingan)
- `config/config.php`, `config/config.sample.php` — token, secret, DB, webhook_url
- `.gitignore`
- `bootstrap.php` — barcha modul yuklash, error/exception handler, RateLimiter::init
- `classes/Config.php` — dot-notation config
- `classes/Logger.php` — kunlik log + rotate()
- `classes/Database.php` — PDO MySQL singleton + helperlar (query/fetch/value/insert/transaction)
- `classes/Telegram.php` — API wrapper (retry, 429, CURLFile, send/edit/copy/answerCb/answerInline, botId, username keshi)
- `classes/State.php` — FSM (step+data, timeout, menu/kbd msg tracking, cleanup)
- `classes/RateLimiter.php` — fayl asosidagi flood control
- `database/schema.sql` — barcha jadvallar + indekslar (utf8mb4)
- `database/install.php` — jadval yaratish, secret gen, setWebhook, default kanal seed
- `database/SettingRepo.php`, `AdminRepo.php`, `UserRepo.php`, `StatRepo.php`,
  `ChannelRepo.php`, `BroadcastRepo.php`, `FilmRepo.php`
- `functions/helpers.php` — e(), truncate(), filmCaption(), UI lifecycle
  (showMenu/showKeyboard/deletePrevMenu), deliverFilm(), postToChannel(), exportUsersTxt()
- `keyboards/Keyboard.php` — barcha klaviaturalar (callback ':' formatida)
- `admin/ChannelManager.php` — validate(), checkSubscription(), panel, apply()
- `handlers/StartHandler.php` — /start + deep-link + obuna
- `handlers/MessageHandler.php` — user menyular, qidiruv, kod
- `handlers/AdminHandler.php` — admin tugmalari + barcha steplar

## ⏳ QOLGAN ishlar (SHU YERDAN DAVOM ET)
1. **`handlers/CallbackHandler.php`** — barcha callback_query (eng muhim keyingi qadam).
   Callback sxemasi `action:arg1:arg2` (':' bilan). Kerakli actionlar:
   - `cancel`, `noop`, `check_sub`
   - `like:CODE`, `dislike:CODE` → FilmRepo::react($fromId, code, type) + editMarkup(Keyboard::reactions)
   - `watch:CODE`, `ep:CODE` → deliverFilm
   - `srl:ID` → fasllar (editMarkup, ssn:ID:SEASON), `ssn:ID:SEASON` → qismlar (ep:CODE) + 🔙 srl:ID
   - `fpage:N` → FilmRepo::latestFilms paginate (editMarkup)
   - `users:N` → UserRepo::paginate (editText, AdminHandler::usersText) [admin]
   - `export_users` → exportUsersTxt + sendDocument(CURLFile) [admin]
   - `post:CODE` → postToChannel + editText [admin]; `nopost:CODE` → delete [admin]
   - `etitle:CODE` → State::set 'edit_title' {edit_code}; `edesc:CODE` → 'edit_desc' [admin]
   - `del:CODE` → FilmRepo::delete + editText [admin]
   - `bc_all` → State::set 'broadcast_all' + prompt; `bc_one` → 'broadcast_one' [admin]
   - `admin_add` → State::set 'admin_add' [main]; `adel:ID` → AdminRepo::remove + refresh [main]
   - `ch_panel` → ChannelManager::refreshPanel; `ch_base`/`ch_main`/`ch_req` → set step + prompt;
     `chdel:ID` → ChannelRepo::remove + refreshPanel [admin]
   - Admin-gated actionlarda `AdminRepo::isAdmin($fromId)` / main uchun `isMain`.
   - Boshida `Telegram::answerCb($cb->id)`.
2. **`inline/InlineHandler.php`** — inline_query. Bo'sh so'rovda FilmRepo::top(15),
   aks holda searchByName. Har natija: article + input_message_content(HTML) +
   reply_markup URL tugma `t.me/<bot>?start=CODE`. answerInline cache_time=5, is_personal.
3. **`classes/Router.php`** — dispatch(update): inline/callback/message ajratish.
   message(): ctx yig'ish (cid,mid,text,video,document,from,name,username,step),
   UserRepo::touch, blok tekshiruv, `/bekor` global, /start→StartHandler,
   timeout tekshiruv (State::isExpired→clear+xabar), step=State::step,
   admin bo'lsa AdminHandler::handle (true qaytmasa) → MessageHandler::handle.
4. **`bot.php`** (front controller) — require bootstrap; webhook secret tekshiruv
   (`HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN` vs Config bot.secret, hash_equals, 403);
   php://input o'qish; rate-limit (admin emas bo'lsa); try/catch Router::dispatch;
   har doim 200 qaytarish.
5. **`cron/broadcast_worker.php`** — BroadcastRepo::nextBatch(config broadcast.batch),
   har biriga Telegram::copy(target, from_chat, message_id), 403→UserRepo::setBlocked + markFailed,
   sleep(broadcast.sleep_ms), markSent. CLI.
6. **`cron/cleanup.php`** — State::cleanup(timeout), Logger::rotate(), RateLimiter::cleanup(),
   BroadcastRepo::purgeDone(). CLI.
7. **`REPORT.md`** — har bug uchun *Eski kod / Muammo / Yangi kod / Sababi* (talab #15) + jadval.
8. **Lint** — iloji bo'lsa `php -l` har faylga (PHP mahalliy yo'q; VPS'da).
9. **README.md** (ixtiyoriy) — o'rnatish bo'yicha qo'llanma + cron qatorlari.

## Muhim texnik eslatmalar
- Callback sxema: `:` ajratuvchi (eski `_` ambiguity yo'q). Series uchun qisqa `series_id`.
- UI lifecycle: `showMenu` (menu_msg_id, o'chiriladi) vs `showKeyboard` (kbd_msg_id, rejim almashganda).
  Reply-keyboardli xabar HECH QACHON menu o'chirish siklida emas (bug #5 yechimi).
- `deliverFilm`/`postToChannel` baza/asosiy kanalni `ChannelRepo` dan oladi (chat_id yoki @username).
- Film upload oqimi: video→title→desc→INSERT→kod KO'RSATILADI (eski "kod oldin" o'rniga).
- Telegram::call() **massiv** qaytaradi (eski kod object ishlatardi).
- DB ulanish lazy; admin/rate-limit DB'ga tegadi → bot.php'da try ichida.

## Deploy bosqichlari (yakunida foydalanuvchiga aytiladigan)
1. MySQL DB + user yaratish, `config/config.php` to'ldirish.
2. `php database/install.php https://domen/bot.php`
3. Botga kanallarni admin qilish (baza/asosiy/majburiy).
4. Cron: `* * * * * php .../cron/broadcast_worker.php` va `30 3 * * * php .../cron/cleanup.php`
5. `database/install.php` ni o'chirish.
