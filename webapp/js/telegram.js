/* =====================================================================
   telegram.js — Telegram WebApp SDK ustidan yupqa o'ram (TG).
   Mavzu, BackButton, HapticFeedback, sendData, link ochish.
   Brauzerda (SDK yo'q) ham buzilmasligi uchun stub bilan.
   ===================================================================== */
(function () {
    const wa = (window.Telegram && window.Telegram.WebApp) ? window.Telegram.WebApp : null;

    /**
     * Auth uchun xom initData. Ba'zi ochilish usullarida `wa.initData` bo'sh qoladi,
     * lekin Telegram uni URL hash'iga (`tgWebAppData`) qo'yadi — shu yerdan tiklaymiz.
     * Backend kutadigan format bilan bir xil ("key=val&...&hash=...").
     */
    function readInitData() {
        if (wa && wa.initData) return wa.initData;
        try {
            const fromHash = new URLSearchParams((window.location.hash || '').replace(/^#/, '')).get('tgWebAppData');
            if (fromHash) return fromHash;
            const fromQuery = new URLSearchParams(window.location.search).get('tgWebAppData');
            if (fromQuery) return fromQuery;
        } catch (e) { /* ignore */ }
        return '';
    }

    function applyTheme() {
        if (!wa || !wa.themeParams) return;
        const root = document.documentElement;
        const p = wa.themeParams;
        const map = {
            'bg_color': '--tg-theme-bg-color',
            'secondary_bg_color': '--tg-theme-secondary-bg-color',
            'section_bg_color': '--tg-theme-section-bg-color',
            'text_color': '--tg-theme-text-color',
            'hint_color': '--tg-theme-hint-color',
            'link_color': '--tg-theme-link-color',
            'button_color': '--tg-theme-button-color',
            'button_text_color': '--tg-theme-button-text-color',
            'header_bg_color': '--tg-theme-header-bg-color',
        };
        for (const k in map) {
            if (p[k]) root.style.setProperty(map[k], p[k]);
        }
        if (wa.colorScheme) root.style.colorScheme = wa.colorScheme;
    }

    const TG = {
        wa,
        available: !!wa,
        // Auth uchun xom initData (backendga yuboriladi). Getter — har murojaatda
        // qaytadan o'qiladi (SDK kech to'ldirsa yoki hash'da bo'lsa ham ishlaydi).
        get initData() { return readInitData(); },
        user: wa && wa.initDataUnsafe ? wa.initDataUnsafe.user : null,

        ready() {
            if (!wa) return;
            try {
                wa.ready();
                wa.expand();
                if (wa.setHeaderColor) wa.setHeaderColor('secondary_bg_color');
                if (wa.disableVerticalSwipes) wa.disableVerticalSwipes();
                applyTheme();
                wa.onEvent('themeChanged', applyTheme);
            } catch (e) { /* ignore */ }
        },

        haptic(kind) {
            if (!wa || !wa.HapticFeedback) return;
            try {
                if (kind === 'success' || kind === 'error' || kind === 'warning') {
                    wa.HapticFeedback.notificationOccurred(kind);
                } else if (kind === 'select') {
                    wa.HapticFeedback.selectionChanged();
                } else {
                    wa.HapticFeedback.impactOccurred(kind || 'light');
                }
            } catch (e) { /* ignore */ }
        },

        back: {
            show(cb) {
                if (!wa || !wa.BackButton) { return; }
                wa.BackButton.show();
                wa.BackButton.onClick(cb);
            },
            hide() {
                if (wa && wa.BackButton) wa.BackButton.hide();
            }
        },

        // Telegram.WebApp.sendData — app YOPILADI, ma'lumot botga `web_app_data` bo'lib boradi.
        sendData(obj) {
            if (!wa) return false;
            try { wa.sendData(JSON.stringify(obj)); return true; }
            catch (e) { return false; }
        },

        openTelegramLink(url) {
            if (wa && wa.openTelegramLink) wa.openTelegramLink(url);
            else window.open(url, '_blank');
        },

        close() { if (wa) wa.close(); }
    };

    window.TG = TG;
})();
