# 🎬 Kino App — Telegram Web App (Mini App)

Mavjud bot bilan **bitta tizim**: bir xil MySQL bazasi, bir xil backend mantiq,
bir xil video yetkazish mexanizmi. Yangi backend yo'q — `webapp/api.php` mavjud
`bootstrap.php` + repos + `Telegram` orqali ishlaydi.

## Tuzilma
```
webapp/
├── *.html              6 sahifa (index, search, movie, categories, favorites, profile)
├── api.php             backend kirish nuqtasi (initData auth → WebAppHandler::process)
├── css/                variables, animations, main, ios, components
├── js/                 telegram, api, app, search, movie, profile, favorites
├── assets/             icons, images, fonts (hozircha tizim shrifti + gradient posterlar)
└── components/         reference shablonlar (runtime render — js/app.js)
```

## Arxitektura — nega HTTP API (sendData emas)?
`Telegram.WebApp.sendData()` chaqirilganda **app yopiladi**. Lekin bizga app ochiq
qolishi kerak: ichki **Toast** (Success/Error/Warning), sevimlilarni qo'shganda app
qolishi, va "Ko'rish"dan keyin **"✅ Botga yuborildi" toast + "Botga o'tish"** tugmasi.
Shu sababli asosiy integratsiya **HTTP API** orqali:

1. Frontend `api.php` ga `POST {action, ...}` + `X-Telegram-Init-Data` header yuboradi.
2. `WebApp::validate()` initData HMAC imzosini tekshiradi (soxta so'rov rad etiladi).
3. `WebAppHandler::process()` — bot bilan **bir xil** mantiq, bir xil DB.
4. JSON qaytadi → app ochiq qoladi → toast ko'rsatiladi.

`sendData()` ham **to'liq qo'llab-quvvatlanadi**: bot tomonda `web_app_data` handler
(`Router` → `WebAppHandler::handleData`) bor. Reply-keyboarddagi `🎬 Kino App` tugmasi
orqali ochilsa, `TG.sendData({action,movie_id})` ham ishlaydi.

## Video logikasi
Video Web App ichida **saqlanmaydi / strim qilinmaydi / yuklanmaydi**. "Ko'rish"da
backend mavjud `deliverFilm()` (baza kanaldan `copyMessage`, ya'ni `file_id`) orqali
videoni foydalanuvchi chatiga yuboradi.

## Bot ↔ Web App bildirishnomalari (anti-spam bilan)
| Hodisa | Bot xabari |
|--------|-----------|
| App ochildi (`init`) | 📱 Web App ochildi. |
| Kino tanlandi (`watch`) | 🎬 Siz kino tanladingiz: `<title>` |
| Tayyorlanmoqda | ⏳ Kino tayyorlanmoqda... |
| Yuborildi | ✅ Kino muvaffaqiyatli yuborildi. / 📝 Tarixga qo'shildi |
| Sevimliga qo'shildi | ⭐ Sevimlilarga qo'shildi. |
| Kino yo'q | ❌ Kino topilmadi. Admin bilan bog'laning. |
| Video ishlamasa | ❌ Video vaqtinchalik mavjud emas. |

Anti-spam: `notify_log` jadvali — bir xil action `webapp.notify_ttl` (5s) ichida
takror bo'lsa, bot xabari **qayta yuborilmaydi** (`WebAppRepo::shouldNotify`).

## O'rnatish / deploy
1. `config/config.php` → `webapp.url` ni Web App'ning HTTPS manziliga qo'ying
   (masalan `https://SIZNING-DOMEN/webapp/`).
2. Yangi jadvallarni qo'llang (idempotent):
   ```bash
   php database/install.php https://SIZNING-DOMEN/bot.php
   ```
   Bu `favorites`, `watch_history`, `notify_log` jadvallarini yaratadi va
   `setChatMenuButton` orqali "🎬 Kino App" menyu tugmasini o'rnatadi.
3. `webapp/` papkasi bot bilan bir domenda HTTPS orqali ochiladigan bo'lsin.
4. Botda `🎬 Kino App` (pastki tugma yoki menyu tugmasi) bosib oching.

## Sinash (dev)
`config.debug = true` bo'lsa, `api.php` initData bo'lmagan so'rovni bosh admin sifatida
qabul qiladi (brauzerda sinash uchun). **Production'da `debug = false`!**
