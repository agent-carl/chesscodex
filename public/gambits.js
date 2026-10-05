// /gambits: filter the A–Z list of gambits by name, and fold the long list
// of openings above it on phones. Without JS the full list simply shows.
(() => {
    const box   = document.querySelector('.gambits-filter');
    const input = document.getElementById('gambits-q');
    if (!box || !input) return;

    const nav   = document.getElementById('gambits-contents');
    const empty = document.querySelector('.gambits-filter-empty');
    const count = box.querySelector('.gambits-filter-count');

    // "Grünfeld Defense: Lutikov Gambit (D80)" → "grunfeld defense lutikov gambit d80"
    const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, ' ').trim();

    const groups = [...document.querySelectorAll('#all-gambits .openings-family')].map((g) => ({
        el: g,
        count: g.querySelector('h3 .openings-letter-count'),
        items: [...g.querySelectorAll('li')].map((li) => ({
            el: li,
            text: norm(li.querySelector('a')?.title || li.textContent),
        })),
    }));
    const total = groups.reduce((n, g) => n + g.items.length, 0);
    for (const g of groups) g.label = g.count ? g.count.textContent : '';
    const fmt = (n) => n.toLocaleString('en');

    const apply = () => {
        const words = norm(input.value).split(' ').filter(Boolean);
        let shown = 0;
        for (const g of groups) {
            let n = 0;
            for (const it of g.items) {
                const ok = words.every((w) => it.text.includes(w));
                it.el.hidden = !ok;
                if (ok) n++;
            }
            g.el.hidden = n === 0;
            if (g.count) g.count.textContent = words.length ? `${n} of ${g.items.length}` : g.label;
            shown += n;
        }
        if (nav) nav.hidden = words.length > 0;
        count.textContent = words.length ? `${fmt(shown)} of ${fmt(total)}` : '';
        if (empty) empty.hidden = shown > 0;
    };

    input.addEventListener('input', apply);
    box.hidden = false;

    // On phones the list of openings is longer than a screen: show the first
    // dozen and a button for the rest (the CSS only folds it on small screens).
    const links = nav ? nav.querySelectorAll('a') : [];
    if (links.length > 15) {
        nav.classList.add('is-folded');
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'gambits-more';
        more.textContent = `Show all ${links.length} openings`;
        more.addEventListener('click', () => {
            nav.classList.remove('is-folded');
            more.remove();
        });
        nav.append(more);
    }
})();
