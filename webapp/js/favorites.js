/* =====================================================================
   favorites.js — sevimlilar sahifasi.
   ===================================================================== */
(function () {
    const root = document.getElementById('fav-list');

    (async () => {
        root.className = 'grid';
        root.appendChild(App.skeletonGrid(6));
        const res = await API.favorites();
        if (!res.ok) { App.Toast.error('Yuklab bo\'lmadi'); return; }
        const items = res.data.items || [];
        if (!items.length) {
            root.className = '';
            App.empty(root, '🤍', 'Sevimlilar bo\'sh', 'Kino sahifasida ❤️ bosib sevimlilarga qo\'shing');
            return;
        }
        root.className = 'grid stagger';
        root.innerHTML = '';
        items.forEach(f => root.appendChild(App.posterCard(f)));
    })();
})();
