/* =====================================================================
   app.js — umumiy yadro: Toast, UI render, tab bar, navigatsiya,
   "Ko'rish"/sevimli umumiy mantiq, Home & Categories sahifa kontrollerlari.
   ===================================================================== */
(function () {
    // ---------- Yordamchilar ----------
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    const GRADIENTS = [
        ['#FF6B6B', '#C73866'], ['#4FACFE', '#00F2FE'], ['#43E97B', '#38F9D7'],
        ['#FA709A', '#FEE140'], ['#7367F0', '#CE9FFC'], ['#F093FB', '#F5576C'],
        ['#30CFD0', '#330867'], ['#FF9A9E', '#FAD0C4'], ['#A18CD1', '#FBC2EB'],
        ['#0BA360', '#3CBA92'], ['#FBAB7E', '#F7CE68'], ['#5EE7DF', '#B490CA']
    ];
    function gradient(code) {
        const g = GRADIENTS[Math.abs(code | 0) % GRADIENTS.length];
        return `linear-gradient(140deg, ${g[0]}, ${g[1]})`;
    }
    function initials(title) {
        const t = (title || '?').trim();
        const parts = t.split(/\s+/).slice(0, 2);
        return parts.map(p => p[0]).join('').toUpperCase();
    }
    function fmtNum(n) {
        n = n | 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0', '') + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'K';
        return String(n);
    }
    function param(name) {
        return new URLSearchParams(location.search).get(name);
    }
    function typeBadge(f) {
        if (f.type === 'serial') {
            return f.season ? `S${f.season}` : '📺';
        }
        return '🎬';
    }

    // ---------- Toast (Web App ichidagi bildirishnoma) ----------
    function host() {
        let h = document.getElementById('toast-host');
        if (!h) { h = document.createElement('div'); h.id = 'toast-host'; document.body.appendChild(h); }
        return h;
    }
    const ICONS = { success: '✓', error: '✕', warning: '!' };
    const TITLES = { success: 'Muvaffaqiyatli', error: 'Xatolik', warning: 'Diqqat' };
    function toast(type, msg, opts) {
        opts = opts || {};
        TG.haptic(type === 'success' ? 'success' : type === 'error' ? 'error' : 'warning');
        const el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.innerHTML =
            `<div class="toast__icon">${ICONS[type] || 'i'}</div>` +
            `<div class="toast__body">` +
                `<div class="toast__title">${esc(opts.title || TITLES[type])}</div>` +
                `<div class="toast__msg">${esc(msg)}</div>` +
            `</div>`;
        if (opts.action) {
            const a = document.createElement('button');
            a.className = 'toast__action';
            a.textContent = opts.action.label;
            a.onclick = () => { opts.action.onClick(); dismiss(el); };
            el.appendChild(a);
        }
        host().appendChild(el);
        const ttl = opts.duration || (opts.action ? 6000 : 3000);
        const timer = setTimeout(() => dismiss(el), ttl);
        el.addEventListener('click', (e) => {
            if (e.target.classList.contains('toast__action')) return;
            clearTimeout(timer); dismiss(el);
        });
        return el;
    }
    function dismiss(el) {
        if (!el || el.classList.contains('hide')) return;
        el.classList.add('hide');
        setTimeout(() => el.remove(), 220);
    }
    const Toast = {
        success: (m, o) => toast('success', m, o),
        error:   (m, o) => toast('error', m, o),
        warning: (m, o) => toast('warning', m, o)
    };

    // ---------- Render: poster kartochka ----------
    function posterCard(f) {
        const el = document.createElement('div');
        el.className = 'card pressable';
        el.innerHTML =
            `<div class="poster" style="background:${gradient(f.code)}">` +
                `<span class="poster__badge">${esc(typeBadge(f))}</span>` +
                (f.is_favorite ? `<span class="poster__fav on">❤️</span>` : '') +
                `<span class="poster__letter">${esc(initials(f.title))}</span>` +
                `<span class="poster__meta">👁 ${fmtNum(f.views)} · 👍 ${fmtNum(f.likes)}</span>` +
            `</div>` +
            `<div class="card__title">${esc(f.title)}</div>`;
        el.onclick = () => goMovie(f.code);
        return el;
    }

    // ---------- Render: ro'yxat qatori ----------
    function listRow(f, rank) {
        const el = document.createElement('div');
        el.className = 'mrow pressable';
        el.innerHTML =
            (rank != null ? `<div class="mrow__rank">${rank}</div>` : '') +
            `<div class="mrow__poster" style="background:${gradient(f.code)}">${esc(initials(f.title))}</div>` +
            `<div class="mrow__body">` +
                `<div class="mrow__title">${esc(f.title)}</div>` +
                `<div class="mrow__sub">${f.type === 'serial' ? '📺 Serial' : '🎬 Film'} · 👁 ${fmtNum(f.views)} · #${f.code}</div>` +
            `</div>` +
            `<div class="list__chevron">›</div>`;
        el.onclick = () => goMovie(f.code);
        return el;
    }

    function skeletonGrid(n) {
        const frag = document.createDocumentFragment();
        for (let i = 0; i < n; i++) {
            const c = document.createElement('div');
            c.className = 'card';
            c.innerHTML = `<div class="skeleton skeleton--poster"></div><div class="skeleton skeleton--line"></div><div class="skeleton skeleton--line short"></div>`;
            frag.appendChild(c);
        }
        return frag;
    }

    function empty(host, icon, title, text) {
        host.innerHTML =
            `<div class="empty"><div class="empty__icon">${icon}</div>` +
            `<div class="empty__title">${esc(title)}</div>` +
            `<div class="empty__text">${esc(text)}</div></div>`;
    }

    // ---------- Navigatsiya ----------
    function goMovie(code) { TG.haptic('light'); location.href = 'movie.html?code=' + code; }

    const TABS = [
        { id: 'home', href: 'index.html', label: 'Asosiy', icon: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/>' },
        { id: 'search', href: 'search.html', label: 'Qidiruv', icon: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>' },
        { id: 'categories', href: 'categories.html', label: 'Bo\'limlar', icon: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>' },
        { id: 'favorites', href: 'favorites.html', label: 'Sevimli', icon: '<path d="M12 20s-7-4.5-9.5-9A5 5 0 0 1 12 6a5 5 0 0 1 9.5 5c-2.5 4.5-9.5 9-9.5 9Z"/>' },
        { id: 'profile', href: 'profile.html', label: 'Profil', icon: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/>' }
    ];
    function buildTabbar(active) {
        const nav = document.getElementById('tabbar');
        if (!nav) return;
        nav.className = 'tabbar';
        nav.innerHTML = TABS.map(t =>
            `<a class="tabbar__item ${t.id === active ? 'active' : ''}" href="${t.href}">` +
            `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${t.icon}</svg>` +
            `<span>${t.label}</span></a>`
        ).join('');
    }

    // ---------- Umumiy: sevimli toggle ----------
    let bot = '';
    async function toggleFavorite(code, makeOn, iconEl) {
        const res = makeOn ? await API.addFavorite(code) : await API.removeFavorite(code);
        if (!res.ok) { Toast.error('Amalni bajarib bo\'lmadi'); return false; }
        if (iconEl) { iconEl.classList.toggle('on', makeOn); iconEl.textContent = makeOn ? '❤️' : '🤍'; }
        if (makeOn) Toast.success('Sevimlilarga qo\'shildi', { title: 'Sevimli' });
        else Toast.warning('Sevimlilardan olib tashlandi');
        return true;
    }

    // ---------- Umumiy: "Ko'rish" (video botga yuboriladi) ----------
    async function watch(code, btn) {
        if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px"></span> Yuborilmoqda...'; }
        const res = await API.watch(code);
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || '▶️ Ko\'rish'; }
        if (res.bot) bot = res.bot;

        if (res.ok && (res.delivered || res.duplicate)) {
            const botUser = res.bot || bot;
            Toast.success('Kino Telegram botga yuborildi', {
                title: '✅ Tayyor',
                action: botUser ? { label: 'Botga o\'tish', onClick: () => TG.openTelegramLink('https://t.me/' + botUser) } : null
            });
            return true;
        }
        if (res.error === 'subscribe') {
            const botUser = res.bot || bot;
            Toast.warning('Avval majburiy kanalga obuna bo\'ling', {
                action: botUser ? { label: 'Botga o\'tish', onClick: () => TG.openTelegramLink('https://t.me/' + botUser) } : null
            });
            return false;
        }
        if (res.error === 'not_found') Toast.error('Kino topilmadi. Admin bilan bog\'laning');
        else if (res.error === 'unavailable') Toast.error('Video vaqtinchalik mavjud emas');
        else Toast.error('Xatolik yuz berdi. Qayta urinib ko\'ring');
        return false;
    }

    // ---------- Sahifa kontrollerlari: HOME ----------
    async function pageHome() {
        const latestEl = document.getElementById('latest');
        const topEl = document.getElementById('top');
        const heroEl = document.getElementById('hero');
        if (latestEl) latestEl.appendChild(skeletonGrid(6));

        const res = await API.init();
        if (!res.ok) {
            if (res.error === 'auth') { Toast.error('Telegram orqali oching'); }
            else Toast.error('Ma\'lumotni yuklab bo\'lmadi');
            if (latestEl) empty(latestEl, '😕', 'Yuklanmadi', 'Internet aloqasini tekshiring');
            return;
        }
        bot = (res.data && res.data.bot) || '';
        const home = res.data.home;

        // Hero — eng ko'p ko'rilgan film
        if (heroEl && home.top && home.top.length) {
            const f = home.top[0];
            heroEl.style.background = gradient(f.code);
            heroEl.innerHTML =
                `<div class="hero__content">` +
                `<div class="hero__kicker">⭐ Eng mashhur</div>` +
                `<div class="hero__title">${esc(f.title)}</div>` +
                `<button class="btn" style="width:auto;padding:10px 20px">▶️ Ko\'rish</button>` +
                `</div>`;
            heroEl.querySelector('button').onclick = (e) => { e.stopPropagation(); watch(f.code, e.currentTarget); };
            heroEl.onclick = () => goMovie(f.code);
            heroEl.classList.add('pressable');
        }

        if (latestEl) {
            latestEl.innerHTML = '';
            if (!home.latest.length) empty(latestEl, '🎬', 'Hozircha film yo\'q', 'Tez orada qo\'shiladi');
            else home.latest.forEach(f => latestEl.appendChild(posterCard(f)));
        }
        if (topEl) {
            topEl.innerHTML = '';
            home.top.forEach((f, i) => topEl.appendChild(listRow(f, i + 1)));
        }
    }

    // ---------- Sahifa kontrollerlari: CATEGORIES ----------
    async function pageCategories() {
        const seriesEl = document.getElementById('series');
        const topEl = document.getElementById('cat-top');
        const countsEl = document.getElementById('counts');
        if (seriesEl) seriesEl.innerHTML = '<div class="spinner spinner--center"></div>';

        const res = await API.categories();
        if (!res.ok) { Toast.error('Yuklab bo\'lmadi'); return; }
        const d = res.data;

        if (countsEl) {
            countsEl.innerHTML =
                `<div class="stat"><div class="stat__num">${d.films_count}</div><div class="stat__label">🎬 Filmlar</div></div>` +
                `<div class="stat"><div class="stat__num">${d.series_count}</div><div class="stat__label">📺 Seriallar</div></div>`;
        }
        if (seriesEl) {
            if (!d.series.length) { empty(seriesEl, '📺', 'Serial yo\'q', 'Hozircha seriallar qo\'shilmagan'); }
            else {
                seriesEl.className = 'list';
                seriesEl.innerHTML = '';
                d.series.forEach(s => {
                    const row = document.createElement('div');
                    row.className = 'list__row pressable';
                    row.innerHTML =
                        `<div class="mrow__poster" style="width:36px;height:36px;border-radius:8px;flex:0 0 36px;background:${gradient(s.id * 7)}">📺</div>` +
                        `<div class="list__row-title">${esc(s.title)}</div>` +
                        `<div class="list__chevron">›</div>`;
                    row.onclick = () => goSearch(s.title);
                    seriesEl.appendChild(row);
                });
            }
        }
        if (topEl) { topEl.innerHTML = ''; d.top.forEach((f, i) => topEl.appendChild(listRow(f, i + 1))); }
    }

    function goSearch(q) { location.href = 'search.html?q=' + encodeURIComponent(q); }

    // ---------- Boot ----------
    function boot(activeTab) {
        TG.ready();
        host();
        buildTabbar(activeTab);
    }

    window.App = {
        esc, gradient, initials, fmtNum, param, typeBadge,
        Toast, posterCard, listRow, skeletonGrid, empty,
        goMovie, goSearch, toggleFavorite, watch, boot,
        pageHome, pageCategories,
        get bot() { return bot; }, setBot(b) { if (b) bot = b; }
    };
})();
