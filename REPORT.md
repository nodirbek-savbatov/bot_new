# 📋 HISOBOT — Kino Bot refaktoring (Eski kod / Muammo / Yangi kod / Sababi)

Eski loyiha: bitta `bot.php` (1533 qator), JSON storage, `step/*.txt` holatlar.
Yangi loyiha: **MySQL**, modulli arxitektura, production hardening.
Eski versiya `bot.legacy.php` da saqlangan.

Quyida har bir aniqlangan muammo so'ralgan formatda tushuntirilgan.

---

## 🔴 1. Webhook secret yo'q — spoofing (eng kritik xavfsizlik)

**Eski kod** (`bot.legacy.php`):
```php
$update = json_decode(file_get_contents('php://input'));
// ... callback'da:
if (str_starts_with($data, 'post_') && isAdmin($callfrid)) { ... }
```
Webhook hech qanday autentifikatsiyasiz qabul qilinardi.

**Muammo:** Istalgan odam URL ga (`.../bot.php`) soxta JSON POST qilib,
`callback_query.from.id = ADMIN_ID` qo'yib, **admin amallarini** (film o'chirish,
broadcast, admin qo'shish) bajara olardi. `isAdmin()` faqat `from.id` ga ishonardi,
u esa soxta bo'lishi mumkin edi.

**Yangi kod** (`bot.php` + `database/install.php`):
```php
// install: setWebhook'ga tasodifiy secret
Telegram::call('setWebhook', ['url'=>$url, 'secret_token'=>$secret, ...]);

// bot.php: har so'rovda header tekshiriladi
$got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($expected, (string)$got)) {
    Logger::warning('Webhook secret mos kelmadi — rad etildi');
    http_response_code(403); exit('forbidden');
}
```

**Sababi:** Telegram har webhook so'rovida `X-Telegram-Bot-Api-Secret-Token` header
yuboradi. Faqat Telegram bu secret'ni biladi. `hash_equals` (timing-safe) bilan
solishtiramiz — endi soxta update'lar kira olmaydi. Admin amallari xavfsiz.

---

## 🔴 2. Race condition — JSON faylni buzilishi / hisoblagich yo'qolishi

**Eski kod:**
```php
function addView(int $code): void {
    $films = json_decode(file_get_contents($file), true);
    $films[(string)$code]['views']++;
    file_put_contents($file, json_encode($films, ...)); // lock yo'q
}
```

**Muammo:** Bir vaqtda 2 ta foydalanuvchi film ochsa, ikkalasi ham faylni o'qiydi,
bittasi yozadi, ikkinchisi ustiga yozadi → **ko'rishlar yo'qoladi**, yomon holatda
butun `films.json` **buziladi** (yarim yozilgan JSON). `likes/dislikes/users` ham xuddi shunday.

**Yangi kod** (`database/FilmRepo.php`):
```php
public static function addView(int $code): void {
    Database::execute("UPDATE films SET views = views + 1 WHERE code = ?", [$code]);
}
```

**Sababi:** MySQL'da `views = views + 1` **atomik** — server darajasida qulflanadi,
bir vaqtda kelgan so'rovlar to'g'ri jamlanadi. Reaktsiyalar `transaction()` ichida.
Hech qachon read-modify-write race yo'q.

---

## 🟠 3. HTML injection / parse_mode buzilishi

**Eski kod:**
```php
$cap = "$icon <b>{$film['title']}</b>\n";   // title xom
if (!empty($film['desc'])) $cap .= "\n{$film['desc']}\n";
```

**Muammo:** Film nomi yoki tavsifida `<`, `>`, `&` bo'lsa, `parse_mode=html` buziladi
(xabar umuman yuborilmaydi yoki noto'g'ri ko'rinadi). Admin nomida `<b>` yozsa — formatni buzadi.

**Yangi kod** (`functions/helpers.php`):
```php
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// caption'da:
$cap = "$icon <b>" . e($f['title']) . "</b>\n";
```

**Sababi:** Foydalanuvchi/admin kiritgan barcha matn HTML kontekstga qo'yilishdan
oldin `htmlspecialchars` bilan escape qilinadi. Maxsus belgilar endi xavfsiz.

---

## 🟠 4. callback_data 64 bayt limiti — serial tugmalari ishlamasligi

**Eski kod:**
```php
$keyboard[] = [['text' => "📺 {$season}-fasl",
    'callback_data' => "season_" . urlencode($title) . "_$season"]];
```

**Muammo:** Telegram `callback_data` ≤ **64 bayt** bo'lishini talab qiladi. Serial nomi
uzun bo'lsa (`urlencode` bilan yana uzayadi), 64 baytdan oshadi → Telegram tugmani
**jim rad etadi** yoki butun klaviatura yuborilmaydi. Foydalanuvchi uchun "tugma ishlamaydi".

**Yangi kod** (`series` jadvali + `keyboards/Keyboard.php`, `CallbackHandler.php`):
```php
// Serial alohida `series` jadvalida (id, title). Callback'da faqat raqamli id:
'callback_data' => "srl:{$s['id']}"          // seriallar ro'yxati
'callback_data' => "ssn:$seriesId:{$season}" // fasllar
'callback_data' => "ep:{$ep['code']}"        // qismlar
```

**Sababi:** Uzun nom o'rniga qisqa `series_id` (butun son) ishlatiladi — callback_data
har doim ~10 baytdan kam. Limitdan oshmaydi, tugmalar ishonchli ishlaydi.

---

## 🟠 5. Reply-keyboard (pastki tugmalar) yo'qolib ketishi

**Eski kod:**
```php
$r = bot('sendMessage', [..., 'reply_markup' => mainMenu()]); // reply keyboard
if ($r && $r->ok) savePrevMenu($cid, $r->result->message_id);  // o'chiriladiganlar ro'yxatiga
// keyingi amalda:
deletePrevMenu($cid); // ← reply keyboardli xabar o'chadi → tugmalar yo'qoladi
```

**Muammo:** Reply keyboard uni yuborgan **xabarga bog'langan**. O'sha xabar
`deletePrevMenu` bilan o'chsa, pastki tugmalar ham yo'qoladi. Eski kod ba'zi joylarda
reply-keyboardli xabarni `savePrevMenu`/`deletePrevMenu` siklига qo'shgan (masalan
"Yordam", qidiruv natijasi) → tugmalar tasodifan yo'qolardi.

**Yangi kod** (`functions/helpers.php` + `State` da `menu_msg_id` / `kbd_msg_id`):
```php
// Navigatsiya menyulari (inline) — o'chiriladi:
function showMenu(...) { deletePrevMenu($chatId); ... State::setMenu(...); }

// Reply-keyboard — FAQAT rejim almashganda, alohida kuzatiladi:
function showKeyboard(...) {
    $old = State::kbdId($chatId);
    $r = Telegram::send(...);              // avval yangisi
    State::setKbd($chatId, $newId);
    if ($old) Telegram::delete($chatId, $old); // keyin eskisi
}
```

**Sababi:** Ikki xil xabar **qat'iy ajratildi**: inline navigatsiya menyulari
(`menu_msg_id`, har safar o'chadi) va reply-keyboard tashuvchi (`kbd_msg_id`, faqat
admin↔user almashganda yangilanadi). Reply-keyboardli xabar endi hech qachon
navigatsiya o'chirish siklida bo'lmaydi → tugmalar yo'qolmaydi.

---

## 🟠 6. Inline qidiruv — tugmasiz, ishonchsiz

**Eski kod:**
```php
$results[] = [
    'type' => 'article', 'id' => (string)$f['code'],
    'title' => "$icon {$f['title']}",
    'input_message_content' => ['message_text' => "/start {$f['code']}"],
];
bot('answerInlineQuery', ['... 'cache_time' => 10]);
```

**Muammo:** (a) Natija tanlansa chatga shunchaki `"/start 123"` **matni** yuboriladi —
bot bo'lmagan guruh/chatda hech narsa qilmaydi. (b) Tugma yo'q — natijadan keyin
hech qanday tugma qolmaydi. (c) `cache_time=10` — yangilanishlar kechikadi.

**Yangi kod** (`inline/InlineHandler.php`):
```php
'input_message_content' => [
    'message_text' => "$icon <b>".e($f['title'])."</b>\n\n▶️ ... kod: <code>{$f['code']}</code>",
    'parse_mode' => 'HTML',
],
'reply_markup' => ['inline_keyboard' => [[
    ['text' => '▶️ Filmni olish', 'url' => "https://t.me/$botUser?start={$f['code']}"],
]]],
// answerInline: cache_time=5, is_personal=true
```

**Sababi:** Har natijaga **URL tugma** (`t.me/bot?start=CODE`) qo'shildi — bu istalgan
chatda ishlaydi va natija yuborilgandan **keyin ham tugma qoladi**. `is_personal` har
foydalanuvchiga moslab, kichik `cache_time` esa yangiликни tez ko'rsatadi.

---

## 🟡 7. getMe / getChat har so'rovda chaqirilishi

**Eski kod:**
```php
$botUser = bot('getMe')->result->username ?? 'bot'; // har joyda qayta-qayta
```

**Muammo:** Bot username va kanal ma'lumoti **har webhook'da** API'dan olinardi —
ortiqcha tarmoq so'rovlari, sekinlik, Telegram limitiga yaqinlashish.

**Yangi kod** (`classes/Telegram.php` + `settings` jadvali):
```php
public static function username(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = SettingRepo::get('bot_username', '');  // DB kesh
    if ($cached === '') { /* bir marta getMe, keyin saqlash */ }
    return $cached;
}
```

**Sababi:** Bot username `settings` jadvalida keshlanadi (install paytida yoziladi).
So'rov ichida ham static kesh. Ortiqcha API chaqiruvlari yo'qoldi.

---

## 🟠 8. Broadcast sinxron — timeout, ikki marta yuborish

**Eski kod:**
```php
foreach ($users as $u) {
    $res = bot('copyMessage', ['chat_id' => $uid, ...]); // hammasi bitta webhook ichida
}
```

**Muammo:** Minglab foydalanuvchiga **bitta webhook so'rovi ichida** ketma-ket
yuborish → 15+ soniya → PHP/Telegram **timeout** → Telegram webhook'ni **qayta
yuboradi** → xabar **2 marta** ketadi. Flood limitiga ham uriladi.

**Yangi kod** (`broadcast_queue` jadvali + `cron/broadcast_worker.php`):
```php
// Admin "Barchaga" bosganda — faqat navbatga yoziladi (tez):
BroadcastRepo::enqueueAll($cid, $mid);
// Cron har daqiqada partiyalab yuboradi, rate-limit + retry + 403'da blok:
$rows = BroadcastRepo::nextBatch($batch);
foreach ($rows as $row) { Telegram::copy(...); usleep($sleepMs*1000); }
```

**Sababi:** Yuborish webhook'dan **ajratildi** (async navbat). Webhook bir zumda
tugaydi (timeout yo'q, ikki marta yuborish yo'q). Cron worker flood-control bilan
asta yuboradi, bloklagan userlarni belgilaydi, xatoda qayta uradi.

---

## 🟡 9. Cheksiz like/dislike

**Eski kod:**
```php
function addReaction(int $code, string $type): void {
    $films[(string)$code][$type]++; // kim bosgani saqlanmaydi
}
```

**Muammo:** Bitta foydalanuvchi tugmani **cheksiz** bosib hisoblagichni "shishira"
olardi. Reaktsiya kim tomonidan bosilgani saqlanmasdi.

**Yangi kod** (`reactions` jadvali, `FilmRepo::react`):
```php
// PK(user_id, film_code) — bitta user, bitta reaktsiya. Toggle:
//   yo'q -> qo'shadi; bir xil -> olib tashlaydi; boshqa -> almashtiradi
```

**Sababi:** `reactions` jadvali (user+film unikal) har foydalanuvchiga **bitta**
reaktsiya beradi va qayta bosish toggle qiladi (👍 ni olib tashlash mumkin). Halol hisob.

---

## 🟡 10. Caption uzunligi (1024) limiti

**Eski kod:** `copyMessage`'ga uzun `caption` to'g'ridan-to'g'ri uzatilardi.

**Muammo:** Telegram caption ≤ **1024** belgi. Uzun tavsifli filmda API xato beradi,
film **umuman yuborilmaydi**.

**Yangi kod** (`classes/Telegram.php`):
```php
private static function clip(string $text, int $limit): string {
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
}
// copy(): caption 1024 ga, send(): text 4096 ga avtomatik qisqartiriladi
```

**Sababi:** Barcha caption/matn yuborishdan oldin xavfsiz uzunlikka kesiladi.
API xatosi bo'lmaydi, film doim yuboriladi.

---

## 🟡 11. arsort() bilan noto'g'ri saralash

**Eski kod:**
```php
$films = array_filter($films, fn($f) => $f['type'] === 'film');
arsort($films); // massivlardan iborat massivni "saralaydi"
```

**Muammo:** `arsort` qiymatlar (massivlar) bo'yicha saralaydi — bu PHP'da elementma-element
taqqoslash, semantik **noto'g'ri**; "yangi filmlar" tartibi tasodifan to'g'ri chiqardi.

**Yangi kod** (`FilmRepo::latestFilms`):
```php
"SELECT * FROM films WHERE type = 'film' ORDER BY code DESC LIMIT ? OFFSET ?"
```

**Sababi:** Saralash **DB darajasida**, aniq `ORDER BY code DESC` (yangi → eski).
Indeks bilan tez va to'g'ri.

---

## 🟠 12. Loglash / exception handling yo'q

**Eski kod:** faqat `if ($err) error_log("cURL: $err");` — API xatolari jim yutilardi.

**Muammo:** Nimadir ishlamasa **sabab noma'lum** qolardi (API rad etdimi, DB xatosimi).
Debug qilish imkonsiz.

**Yangi kod** (`classes/Logger.php` + `bootstrap.php` global handlerlar):
```php
set_exception_handler(fn($e) => Logger::error('Tutilmagan exception: '.$e->getMessage(), [...]));
// Telegram::call() har 'ok=false' javobni loglaydi; bot.php Router'ni try/catch'da
```

**Sababi:** Markaziy log (`logs/bot-YYYY-MM-DD.log`), global exception/error/shutdown
handler, har API xatosi yoziladi. Endi har muammo izlanadi. Production'da xato ekranga
chiqmaydi (faqat logga).

---

## 🟠 13. Kanallar konstanta — bot ichidan boshqarib bo'lmasligi (talab #11)

**Eski kod:**
```php
define('KANAL', '@Kino_vaqti_Premyeralar');
define('BAZA_ID', '@k1no_vaqti_uz');
define('CHANNELS', serialize(['Kino_vaqti_Premyeralar']));
```

**Muammo:** Kanalni o'zgartirish uchun **kodga kirib** tahrirlash kerak edi. Admin
bot ichidan boshqara olmasdi.

**Yangi kod** (`channels` jadvali + `admin/ChannelManager.php` + admin panel):
```php
// "📢 Kanallar" tugmasi → panel: baza/asosiy/majburiy CRUD
ChannelManager::apply('ch_base', $username); // getChat validatsiya + saqlash
ChannelRepo::addRequired($data); ChannelRepo::remove($id);
```

**Sababi:** Barcha kanallar (main/base/required) DB'da. Admin bot ichidan o'zgartiradi:
asosiy kanal, baza kanal, majburiy kanal qo'shish/o'chirish. Qo'shishda `getChat` bilan
mavjudligi va bot adminligi tekshiriladi.

---

## 🟡 14. Rate limiting yo'q

**Eski kod:** har update cheklovsiz ishlov olardi.

**Muammo:** Foydalanuvchi tez-tez bosaversa (flood/spam) — bot zo'riqadi, API limitiga uriladi.

**Yangi kod** (`classes/RateLimiter.php` + `bot.php`):
```php
if (!RateLimiter::allow($fromId, 20, 10)) { http_response_code(200); exit; }
```

**Sababi:** Fayl asosidagi (flock) fixed-window limiter: 10 soniyada 20 xabardan ortig'i
e'tiborsiz qoldiriladi. Adminlar ozod. DB'ga yuk solmaydi.

---

## 🟡 15. State timeout / bekor qilish yo'q (talab #8 — Ask Question)

**Eski kod:**
```php
function setStep(int $cid, string $s): void { file_put_contents("step/$cid.txt", $s); }
// timeout yo'q, bekor qilib bo'lmaydi
```

**Muammo:** Admin film yuklashni yarim qoldirsa, `step` **abadiy** qolardi — keyingi
oddiy xabari ham step sifatida talqin qilinardi. Bekor qilish tugmasi yo'q.

**Yangi kod** (`classes/State.php` — professional FSM):
```php
// updated_at bilan timeout:
if (State::isExpired($cid)) { State::clear($cid); showMenu($cid, "⏰ Vaqt tugadi..."); }
// har stepда "❌ Bekor qilish" inline tugma + /bekor buyrug'i
// noto'g'ri input → qayta so'raydi (clear emas), validatsiya bilan
```

**Sababi:** Holat `states` jadvalida (`updated_at` bilan). 10 daqiqadan eski step
avtomatik bekor + ogohlantirish. Har qadamda "❌ Bekor qilish" va `/bekor`.
Har input validatsiyadan o'tadi (raqam kerak bo'lsa raqam tekshiriladi). `cron/cleanup`
eskirgan holatlarni tozalaydi.

---

## 🟠 16. Token kodda ochiq

**Eski kod:** `'token' => '8762...'` to'g'ridan-to'g'ri `bot.php` da (git'ga tushishi mumkin).

**Yangi kod:** `config/config.php` (`.gitignore` da) + `config/config.sample.php` (namuna).

**Sababi:** Maxfiy ma'lumot (token, secret, DB parol) kod omboridan ajratildi.
Repozitoriyaga faqat `config.sample.php` tushadi.

---

## 🟢 17. "🔍 Kino qidirish" — qidiruv rejimi va fuzzy qidiruv

**Eski kod** (`MessageHandler.php`, `FilmRepo.php`):
```php
// Tugma: inline maslahatli yordam matni chiqardi
case '🔍 Kino qidirish':
    State::set($cid, 'search');
    showMenu($cid, "🔍 Kino kodini yoki nomini yuboring:\n\nInline: @bot Avatar", Keyboard::cancel());

// Qidiruv: faqat bitta substring LIKE
public static function searchByName(string $q, int $limit = 20): array {
    $like = '%' . ... . '%';
    return Database::fetchAll("SELECT * FROM films WHERE title LIKE ? ORDER BY views DESC ...", [$like]);
}
```

**Muammo:**
1. Tugma bosilganda asosan **inline maslahat** ko'rsatilardi — foydalanuvchi inline yozishni
   o'ylab chalkashardi; oddiy matn rejimi aniq emas edi.
2. Qidiruv **yagona substring LIKE** edi: ko'p so'zli so'rov (`"tez gazabli"`),
   so'z tartibi o'zgargan yoki qisman nom **topilmasdi**.
3. Qidiruv rejimida **alohida timeout yo'q** edi (global 10 daqiqaga bog'liq).

**Yangi kod:**
```php
// 1) Aniq qidiruv rejimi — to'g'ridan-to'g'ri matn so'raydi (State::SEARCH_MOVIE)
case '🔍 Kino qidirish':
    State::set($cid, State::SEARCH_MOVIE);
    showMenu($cid, "🔎 <b>Kino nomini yuboring.</b>\n\nMasalan:\n• Qasoskorlar\n"
        . "• Tez va G'azabli\n• John Wick\n\n❌ Bekor qilish uchun /cancel");

// 2) Fuzzy qidiruv — so'zlarga ajratish + relevance ranking (case-insensitive)
FilmRepo::searchFuzzy($q);   // har so'z LIKE (OR), aniqlik bo'yicha tartib

// 3) Qidiruv uchun 5 daqiqalik alohida timeout
//    State::isExpired() endi step === SEARCH_MOVIE bo'lsa state.search_timeout (300s) ishlatadi
```

**Sababi:**
- **UX:** Tugma bosilishi bilan foydalanuvchi avtomatik qidiruv rejimiga o'tadi va keyingi
  yozgan matni kino nomi sifatida ishlanadi — inline chalkashlik yo'q.
- **Topish darajasi:** `searchFuzzy` so'rovni so'zlarga bo'lib, kamida bitta so'z mos kelsa
  natija qaytaradi va `relevance` (aniq tenglik > prefiks > to'liq ibora > mos so'z soni)
  bo'yicha tartiblaydi → ko'p so'zli/qisman/tartibsiz so'rovlar ham topiladi.
- **Xavfsizlik/barqarorlik:** Qidiruv bir martalik — natija chiqishi bilan `State::clear`,
  5 daqiqada avtomatik eskirish (memory leak yo'q, holat DB'da + cron cleanup).
- Har bir foydalanuvchi holati `user_id` bo'yicha alohida — bir kishi qidiruvda bo'lsa,
  boshqalarning holatiga ta'sir qilmaydi. `/cancel` va `/start` Router'da qidiruvdan
  oldin ushlanadi — buyruqlar holatni buzmaydi.

---

## 🟢 18. Telegram Web App (Mini App) integratsiyasi

**Eski holat:** Web App yo'q edi — faqat bot tugmalari/inline.

**Talab:** Mavjud bot va bazani buzmasdan iOS uslubidagi Mini App qo'shish;
videoni Web App ichida emas, botga yuborib `deliverFilm` orqali yetkazish;
toast + bot bildirishnomalarini sinxronlash (anti-spam bilan).

**Yondashuv (muhim qaror — `sendData()` vs HTTP API):**
`Telegram.WebApp.sendData()` app'ni **yopadi**, lekin talablar (ichki Toast, sevimli
qo'shganda app ochiq qolishi, "Ko'rish"dan keyin "Botga o'tish" toasti) app **ochiq**
qolishini talab qiladi. Shuning uchun:
```php
// Asosiy yo'l — HTTP API (app ochiq qoladi → toast ishlaydi)
webapp/api.php → WebApp::validate(initData)  // HMAC, soxta so'rov rad etiladi
              → WebAppHandler::process()      // bot bilan BIR XIL mantiq, BIR XIL DB
              → JSON

// sendData ham qo'llab-quvvatlanadi (explicit talab)
Router::message → isset($m->web_app_data) → WebAppHandler::handleData()
```

**Video:** Web App'da saqlanmaydi/strim qilinmaydi. "Ko'rish" → `WebAppHandler::aWatch`
→ mavjud `deliverFilm()` (baza kanaldan `copyMessage` = file_id) → chatga video.

**Anti-spam:** `notify_log` jadvali + `WebAppRepo::shouldNotify()` — bir xil action
`notify_ttl` (5s) ichida takror bo'lsa, bot xabari qayta yuborilmaydi.

**Xavfsizlik:** har so'rov `initData` HMAC-SHA256 (bot token) bilan tekshiriladi
(`classes/WebApp.php`); blok va rate-limit bot bilan bir xil qoidalarda.

**Sababi:** Foydalanuvchi Web App va botni bitta tizim sifatida his qiladi —
toast (Web App) va bildirishnoma (bot) birga ishlaydi, ma'lumot bir bazada.

---

## ✳️ Qo'shimcha yaxshilanishlar

| Joy | Yaxshilanish |
|-----|--------------|
| `database/schema.sql` | Barcha jadvalga mos **indekslar** (type, views, series, joined, status) + FULLTEXT(title) |
| `FilmRepo::delete` | Film bilan birga reaktsiyalari ham tranzaksiyada o'chadi (yetim yozuv yo'q) |
| `bot.php` | Telegram'ga **har doim 200** — webhook qayta urilish stormini to'xtatadi |
| `Telegram::call` | **Retry** (cURL xato) + **429 retry_after** hurmati |
| Film yuklash oqimi | Kod endi saqlangandan **keyin** ko'rsatiladi (reservation/draft kerak emas) |
| SQL | Hammasi **prepared statement** — SQL injection imkonsiz |
| Broadcast | 403/400 javobda user **avtomatik blok** belgilanadi — ro'yxat toza qoladi |

---

## 🚀 Deploy (Oracle VPS)

1. MySQL DB va foydalanuvchi yarating, `config/config.php` ni to'ldiring.
2. O'rnatish: `php database/install.php https://SIZNING-DOMEN/bot.php`
3. Botni baza/asosiy/majburiy kanallarga **admin** qiling, panel orqali sozlang.
4. Cron qatorlari (`crontab -e`):
   ```
   * * * * * /usr/bin/php /path/movie_bot/cron/broadcast_worker.php >> /path/movie_bot/logs/cron.log 2>&1
   30 3 * * * /usr/bin/php /path/movie_bot/cron/cleanup.php          >> /path/movie_bot/logs/cron.log 2>&1
   ```
5. Xavfsizlik uchun `database/install.php` ni o'chiring.
