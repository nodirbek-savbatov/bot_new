/* =====================================================================
   api.js — Web App backend (webapp/api.php) bilan aloqa.
   Har so'rovga initData (auth) qo'shiladi. Mavjud bot DB/mantig'idan
   foydalanadi — yangi backend yo'q.
   ===================================================================== */
(function () {
    // api.php shu papkada — index.html ham, movie.html ham bir xil ildizdan ishlaydi.
    const ENDPOINT = 'api.php';

    async function call(action, data) {
        const payload = Object.assign({ action, initData: (window.TG ? TG.initData : '') }, data || {});
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Telegram-Init-Data': window.TG ? TG.initData : ''
                },
                body: JSON.stringify(payload)
            });
            const json = await res.json().catch(() => ({ ok: false, error: 'bad_json' }));
            if (!res.ok && json.ok === undefined) {
                return { ok: false, error: 'http_' + res.status };
            }
            return json;
        } catch (e) {
            return { ok: false, error: 'network' };
        }
    }

    window.API = {
        call,
        init:        ()        => call('init'),
        home:        ()        => call('home'),
        search:      (q)       => call('search', { q }),
        movie:       (code)    => call('movie', { movie_id: code }),
        categories:  ()        => call('categories'),
        favorites:   ()        => call('favorites'),
        history:     ()        => call('history'),
        profile:     ()        => call('open_profile'),
        watch:       (code)    => call('watch_movie', { movie_id: code }),
        addFavorite:    (code) => call('add_favorite', { movie_id: code }),
        removeFavorite: (code) => call('remove_favorite', { movie_id: code })
    };
})();
