/* =====================================================================
   search.js — qidiruv sahifasi (fuzzy qidiruv backend orqali).
   ===================================================================== */
(function () {
    const input = document.getElementById('q');
    const clearBtn = document.getElementById('clear');
    const results = document.getElementById('results');
    let timer = null, lastQuery = '';

    function setLoading() {
        results.className = 'grid';
        results.innerHTML = '';
        results.appendChild(App.skeletonGrid(6));
    }

    async function run(q) {
        q = q.trim();
        lastQuery = q;
        clearBtn.style.visibility = q ? 'visible' : 'hidden';
        if (q.length < 1) {
            results.className = '';
            App.empty(results, '🔍', 'Kino qidiring', 'Nomini yozing: Avatar, John Wick, Qasoskorlar...');
            return;
        }
        setLoading();
        const res = await API.search(q);
        if (lastQuery !== q) return; // eskirgan javob — e'tiborsiz

        if (!res.ok) { App.Toast.error('Qidiruvda xatolik'); return; }
        const items = res.data.results || [];
        if (!items.length) {
            results.className = '';
            App.empty(results, '🤷', 'Hech narsa topilmadi', 'Boshqa nom bilan urinib ko\'ring');
            return;
        }
        results.className = 'grid stagger';
        results.innerHTML = '';
        items.forEach(f => results.appendChild(App.posterCard(f)));
    }

    function onInput() {
        clearTimeout(timer);
        timer = setTimeout(() => run(input.value), 300);
    }

    input.addEventListener('input', onInput);
    clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); run(''); });

    // ?q= bilan kelgan bo'lsa (masalan "Bo'limlar"dan) — to'ldiramiz
    const pre = App.param('q');
    if (pre) { input.value = pre; run(pre); }
    else { run(''); input.focus(); }
})();
