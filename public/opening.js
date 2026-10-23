/**
 * Opening page extras that don't need the board module (app.js): the
 * lazy-loaded sub-variation tree and the Stockfish prefetch. Kept out of the
 * HTML so the Content-Security-Policy can forbid inline scripts.
 */
import { movesLabel, shortName, fold } from './lib/helpers.js';

// Lazy-load the descendant subtree on first <details> open. Only fires when
// the section has a data-lazy="N" attribute (set by the PHP template when
// the subtree exceeds the inline-render threshold).
(function () {
    const details = document.querySelector('.opening-subtree details[data-lazy]');
    if (!details) return;
    let loaded = false;
    details.addEventListener('toggle', async () => {
        if (!details.open || loaded) return;
        loaded = true;
        const id = details.dataset.lazy;
        const parentDepth = Number(details.dataset.parentDepth || 0);
        const ul = details.querySelector('.opening-subtree-list');
        const status = details.querySelector('.opening-subtree-status');
        const statusText = status && status.querySelector('.opening-subtree-status-text');
        try {
            const res = await fetch(details.dataset.api + encodeURIComponent(id), { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const rows = data.subtree || [];
            const frag = document.createDocumentFragment();
            rows.forEach((d) => {
                const li = document.createElement('li');
                li.style.setProperty('--depth-indent', Math.max(0, d.depth - parentDepth - 1));
                const a = document.createElement('a');
                a.href = details.dataset.href + encodeURIComponent(d.slug);
                a.title = d.name;
                const tag = document.createElement('span');
                tag.className = 'eco-tag';
                tag.textContent = d.eco;
                a.appendChild(tag);
                const name = document.createElement('span');
                name.className = 'opening-subtree-name';
                name.textContent = shortName(d.name, d.parent_name);
                a.appendChild(name);
                const plies = document.createElement('span');
                plies.className = 'opening-subtree-plies';
                plies.textContent = movesLabel(d.move_count);
                a.appendChild(plies);
                li.appendChild(a);
                frag.appendChild(li);
            });
            ul.appendChild(frag);
            ul.hidden = false;
            ul.setAttribute('aria-busy', 'false');
            if (status) status.hidden = true;
        } catch {
            loaded = false; // allow retry on next open
            if (statusText) statusText.textContent = 'Could not load sub-variations. Try again.';
            if (status) status.dataset.state = 'error';
        }
    });
})();

// Filter the sub-variation list by name or ECO code (the title holds the
// whole name, the tag the code), for trees of hundreds of lines.
(function () {
    const input = document.querySelector('.opening-subtree-filter');
    if (!input) return;
    const none = document.querySelector('.opening-subtree-none');
    input.addEventListener('input', () => {
        const words = fold(input.value).split(/\s+/).filter(Boolean);
        let shown = 0;
        document.querySelectorAll('.opening-subtree-list li').forEach((li) => {
            const a = li.querySelector('a');
            const text = fold((a.title || '') + ' ' + (li.querySelector('.eco-tag') || {}).textContent);
            const hit = words.every((w) => text.includes(w));
            li.hidden = !hit;
            if (hit) shown++;
        });
        none.hidden = shown > 0;
    });
})();

// Lazy-prefetch Stockfish only if the user signals intent to play (hovers or
// focuses the "Play vs Stockfish" CTA, or touches it). Saves ~560 KB of
// background traffic for the 90 % of visitors who only read.
(function () {
    const cta = document.querySelector('.board-cta[data-prefetch]');
    if (!cta) return;
    let loaded = false;
    function preload() {
        if (loaded) return;
        loaded = true;
        cta.dataset.prefetch.split(' ').forEach(function (href) {
            const l = document.createElement('link');
            l.rel = 'prefetch';
            l.href = href;
            if (href.endsWith('.wasm')) { l.as = 'fetch'; l.crossOrigin = 'anonymous'; }
            else { l.as = 'script'; }
            document.head.appendChild(l);
        });
    }
    cta.addEventListener('mouseenter', preload, { once: true });
    cta.addEventListener('focus', preload, { once: true });
    cta.addEventListener('touchstart', preload, { once: true, passive: true });
})();
