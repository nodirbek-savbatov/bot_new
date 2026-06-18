/* =====================================================================
   profile.js — profil sahifasi: foydalanuvchi, statistika, tarix, "Botga o'tish".
   ===================================================================== */
(function () {
    const headEl = document.getElementById('profile-head');
    const statsEl = document.getElementById('profile-stats');
    const nanoEl = document.getElementById('nano');
    const histEl = document.getElementById('history');

    // Nano Coin kartasi + kunlik bonus
    function renderNano(d) {
        const avail = !!d.daily_available;
        nanoEl.innerHTML =
            `<div class="card" style="padding:16px;display:flex;align-items:center;justify-content:space-between;gap:12px">` +
                `<div>` +
                    `<div class="muted" style="font-size:13px">🪙 Nano Coin</div>` +
                    `<div style="font-size:26px;font-weight:800">${App.fmtNum(d.nano_balance || 0)}</div>` +
                `</div>` +
                `<button class="btn ${avail ? '' : 'btn--ghost'}" id="daily-btn" ` +
                    `style="width:auto;padding:10px 16px"${avail ? '' : ' disabled'}>` +
                    `${avail ? '🎁 Kunlik bonus' : '⏳ Olingan'}</button>` +
            `</div>`;
        const btn = document.getElementById('daily-btn');
        if (btn && avail) {
            btn.onclick = async () => {
                btn.disabled = true;
                const r = await API.claimDaily();
                if (r.ok && r.claimed) {
                    App.Toast.success('Kunlik bonus: +' + r.amount + ' 🪙', { title: '🎁 Bonus' });
                    d.nano_balance = r.balance;
                } else {
                    App.Toast.warning('Bugun allaqachon olingan');
                }
                d.daily_available = false;
                renderNano(d);
            };
        }
    }

    // Telegram foydalanuvchisi (initDataUnsafe) — darhol ko'rsatamiz
    const u = TG.user || {};
    const name = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'Foydalanuvchi';
    const uname = u.username ? '@' + u.username : 'Telegram orqali';

    headEl.innerHTML =
        `<div class="avatar" style="background:${App.gradient((u.id || 7) % 97)}">${App.esc(App.initials(name))}</div>` +
        `<div style="margin-top:12px;font-size:22px;font-weight:700">${App.esc(name)}</div>` +
        `<div class="muted">${App.esc(uname)}</div>`;

    (async () => {
        const res = await API.profile();
        if (!res.ok) { App.Toast.error('Profilni yuklab bo\'lmadi'); return; }
        const d = res.data;
        App.setBot(d.bot);

        statsEl.innerHTML =
            `<div class="stat"><div class="stat__num">${d.favorites}</div><div class="stat__label">⭐ Sevimli</div></div>` +
            `<div class="stat"><div class="stat__num">${d.history}</div><div class="stat__label">📝 Ko'rilgan</div></div>`;

        renderNano(d);

        const items = d.history_items || [];
        if (!items.length) {
            App.empty(histEl, '📭', 'Tarix bo\'sh', 'Ko\'rgan kinolaringiz shu yerda chiqadi');
        } else {
            histEl.innerHTML = '';
            items.forEach(f => histEl.appendChild(App.listRow(f)));
        }
    })();

    // "Botga o'tish" tugmasi
    document.getElementById('open-bot').onclick = () => {
        const b = App.bot;
        if (b) TG.openTelegramLink('https://t.me/' + b);
        else App.Toast.warning('Bot manzili aniqlanmadi');
    };
})();
