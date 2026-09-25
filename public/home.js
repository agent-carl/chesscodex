/**
 * Homepage "Pick up where you left off": the openings this browser viewed
 * last, from localStorage "codex-recent" (written by app.js on every opening
 * page). A script of its own because the homepage doesn't load app.js — the
 * section used to stay hidden forever.
 *
 * Loaded without defer right after the section, so it's filled in before
 * the content below is painted and doesn't push it down afterwards.
 */
(function () {
    const KEY = 'codex-recent';
    const sec = document.getElementById('home-recent');
    if (!sec) return;
    const ul = document.getElementById('home-recent-list');
    const clearBtn = document.getElementById('home-recent-clear');
    const base = (document.currentScript && document.currentScript.dataset.base) || '';

    function load() {
        try {
            const data = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(data) ? data.filter((x) => x && x.slug) : [];
        } catch (e) { return []; }
    }

    function render() {
        const items = load();
        ul.textContent = '';
        if (items.length === 0) { sec.hidden = true; return; }
        sec.hidden = false;
        items.forEach((it) => {
            const li = document.createElement('li');
            const a  = document.createElement('a');
            a.href = base + '/openings/' + encodeURIComponent(it.slug);
            const tag = document.createElement('span');
            tag.className = 'eco-tag';
            tag.textContent = it.eco || '?';
            a.appendChild(tag);
            const name = document.createElement('span');
            name.className = 'home-recent-name';
            name.textContent = it.name || it.slug;
            a.appendChild(name);
            li.appendChild(a);
            ul.appendChild(li);
        });
    }

    render();
    if (clearBtn) clearBtn.addEventListener('click', () => {
        try { localStorage.removeItem(KEY); } catch (e) {}
        render();
    });
})();
