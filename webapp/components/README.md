# components/

Bu yerdagi HTML fayllar — **reference shablonlar** (manba haqiqati `js/app.js` da).
Komponentlar ishlash vaqtida JS orqali render qilinadi (DRY: bitta joyda),
shu fayllar esa markup tuzilishini hujjatlashtiradi.

- `bottom-nav.html` — pastki tab bar (`App.buildTabbar` chiqaradi)
- `movie-card.html` — poster kartochka (`App.posterCard` chiqaradi)

Yangi komponent qo'shganda: shablonni shu yerga, render funksiyasini `app.js` ga qo'shing.
