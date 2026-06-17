/* =====================================================================
   movie.js — kino detal sahifasi.
   "Ko'rish" → App.watch(code) → video bot orqali yuboriladi (sendData EMAS,
   chunki app ochiq qolib "Botga yuborildi" toast + "Botga o'tish" chiqishi kerak).
   ===================================================================== */
(function () {
    const code = parseInt(App.param('code'), 10);
    const root = document.getElementById('movie');

    // Orqaga — Telegram BackButton
    TG.back.show(() => history.length > 1 ? history.back() : (location.href = 'index.html'));

    if (!code) { App.empty(root, '😕', 'Noto\'g\'ri havola', 'Kino kodi ko\'rsatilmagan'); return; }

    function render(f) {
        App.setBot(f.bot);
        document.getElementById('nav-title').textContent = f.title;

        const seasonLine = (f.type === 'serial' && f.season)
            ? `<span>📌 ${f.season}-fasl${f.episode ? ', ' + f.episode + '-qism' : ''}</span>` : '';

        let episodesHtml = '';
        if (f.episodes && f.episodes.length > 1) {
            episodesHtml =
                `<div class="section-title" style="font-size:18px">📺 Qismlar</div>` +
                `<div class="list">` +
                f.episodes.map(e =>
                    `<div class="list__row pressable" onclick="location.href='movie.html?code=${e.code}'">` +
                    `<div class="list__row-title">${e.episode}-qism</div>` +
                    `<div class="list__row-value">#${e.code}</div><div class="list__chevron">›</div></div>`
                ).join('') + `</div>`;
        }

        root.innerHTML =
            `<div class="detail-hero" style="background:${App.gradient(f.code)}">` +
                `<div class="detail-hero__big">${App.esc(App.initials(f.title))}</div>` +
                `<div class="detail-hero__content">` +
                    `<div class="detail-hero__title">${App.esc(f.title)}</div>` +
                    `<div class="detail-meta">` +
                        `<span>${f.type === 'serial' ? '📺 Serial' : '🎬 Film'}</span>` +
                        seasonLine +
                        `<span>👁 ${App.fmtNum(f.views)}</span>` +
                        `<span>👍 ${App.fmtNum(f.likes)}</span>` +
                        `<span>#${f.code}</span>` +
                    `</div>` +
                `</div>` +
            `</div>` +
            (f.description ? `<p class="detail-desc">${App.esc(f.description)}</p>` : '<p class="detail-desc muted">Tavsif kiritilmagan.</p>') +
            episodesHtml;

        // Pastki amal paneli
        const bar = document.getElementById('action-bar');
        bar.innerHTML =
            `<button class="icon-btn ${f.is_favorite ? 'on' : ''}" id="fav">${f.is_favorite ? '❤️' : '🤍'}</button>` +
            `<button class="btn btn--lg" id="watch">▶️ Ko'rish</button>`;

        let fav = !!f.is_favorite;
        const favBtn = document.getElementById('fav');
        favBtn.onclick = async () => {
            const target = !fav;
            const ok = await App.toggleFavorite(f.code, target, favBtn);
            if (ok) fav = target;
        };
        document.getElementById('watch').onclick = (e) => App.watch(f.code, e.currentTarget);
    }

    (async () => {
        root.innerHTML = '<div class="spinner spinner--center"></div>';
        const res = await API.movie(code);
        if (!res.ok) {
            App.empty(root, '😕', 'Kino topilmadi', 'Bu kino o\'chirilgan bo\'lishi mumkin');
            return;
        }
        render(res.data);
    })();
})();
