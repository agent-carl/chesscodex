import { Chess } from '../vendor/chess.js';
import { Chessground } from '../vendor/chessground.min.js';

// ----------------------------------------------------------------------
// Recently viewed openings — passive browser-history-style log of the
// last N openings the user opened. Pure localStorage, no auth, no server.
// Auto-records on every opening-page load, rendered into the strip on
// /openings/<slug> (excluding the current one). The homepage's "Pick up
// where you left off" reads the same list from public/home.js.
// ----------------------------------------------------------------------
const RecentHistory = (function () {
    const KEY = 'codex-recent';
    const MAX = 8;
    function load() {
        try {
            const raw = localStorage.getItem(KEY);
            if (!raw) return [];
            const data = JSON.parse(raw);
            return Array.isArray(data) ? data.filter((x) => x && x.slug) : [];
        } catch (e) { return []; }
    }
    function save(list) {
        try { localStorage.setItem(KEY, JSON.stringify(list.slice(0, MAX))); } catch (e) {}
    }
    function record(slug, name, eco) {
        if (!slug) return;
        const list = load().filter((x) => x.slug !== slug);
        list.unshift({ slug, name, eco, ts: Date.now() });
        save(list);
    }
    return { load, record };
})();

// Hook 1: on an opening page, record the visit.
(function () {
    const meta = document.querySelector('meta[name="codex-current-opening"]');
    if (!meta) return;
    RecentHistory.record(meta.dataset.slug, meta.dataset.name, meta.dataset.eco);
})();

// Hook 2: render the "Recently viewed" strip in the opening header.
// Excludes the current opening; only shows when there's at least one other.
(function () {
    const strip = document.getElementById('recent-strip');
    if (!strip) return;
    const list = document.getElementById('recent-strip-list');
    const meta = document.querySelector('meta[name="codex-current-opening"]');
    const currentSlug = meta ? meta.dataset.slug : '';
    const items = RecentHistory.load().filter((x) => x.slug !== currentSlug).slice(0, 4);
    if (items.length === 0) return;
    items.forEach((it) => {
        const li = document.createElement('li');
        const a  = document.createElement('a');
        a.href = '/openings/' + encodeURIComponent(it.slug);
        a.title = it.name + ' (' + it.eco + ')';
        const tag = document.createElement('span');
        tag.className = 'eco-tag';
        tag.textContent = it.eco || '?';
        a.appendChild(tag);
        const span = document.createElement('span');
        span.className = 'recent-strip-name';
        span.textContent = it.name || it.slug;
        a.appendChild(span);
        li.appendChild(a);
        list.appendChild(li);
    });
    strip.hidden = false;
})();

// ----------------------------------------------------------------------
// Toast notification helper. Replaces the older "inline button text flash"
// feedback for copy actions — more elegant and frees the button to stay
// labelled with its actual purpose.
//
// Usage:  Toast.show('PGN copied');
//         Toast.show('Network error', 'error');
// ----------------------------------------------------------------------
const Toast = (function () {
    const region = () => document.getElementById('toast-region');
    function show(message, variant) {
        const r = region();
        if (!r) return;
        const el = document.createElement('div');
        el.className = 'toast' + (variant ? ' toast-' + variant : '');
        el.setAttribute('role', variant === 'error' ? 'alert' : 'status');
        el.textContent = message;
        r.appendChild(el);
        // Trigger CSS transition by deferring the "in" class one frame.
        requestAnimationFrame(() => el.classList.add('is-in'));
        setTimeout(() => {
            el.classList.remove('is-in');
            el.addEventListener('transitionend', () => el.remove(), { once: true });
            // Safety: if transitionend never fires, force-remove after 600 ms.
            setTimeout(() => el.remove(), 600);
        }, 2200);
    }
    return { show };
})();

// Sync aria-expanded with native <details> open state. Screen readers
// understand the attribute even when the underlying element is HTML5
// <details>, which by default doesn't expose expansion state via ARIA.
(function () {
    document.querySelectorAll('details').forEach((d) => {
        const summary = d.querySelector('summary');
        if (!summary) return;
        const sync = () => summary.setAttribute('aria-expanded', d.open ? 'true' : 'false');
        sync();
        d.addEventListener('toggle', sync);
    });
})();

// "Back to top" floating button — appears after the user scrolls ~600 px
// down. Useful on long opening pages where the subtree expands to thousands
// of pixels and there's no quick way back to the board.
(function () {
    const btn = document.getElementById('back-to-top');
    if (!btn) return;
    const SHOW_AFTER_PX = 600;
    function onScroll() {
        if (window.scrollY > SHOW_AFTER_PX) btn.hidden = false;
        else btn.hidden = true;
    }
    // passive listener — we don't preventDefault, so let the browser optimise.
    window.addEventListener('scroll', onScroll, { passive: true });
    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    onScroll();
})();

// Global keyboard shortcuts:
//   /  or  Cmd/Ctrl+K    → focus the page's main search input (name autocomplete
//                            on /search, otherwise navigate to /search and focus there)
//   Esc                  → blur the focused input
// Inspired by GitHub / Lichess. Skips when the user is already typing in a
// form field — except for Cmd/Ctrl+K which always wins (per macOS convention).
(function () {
    function focusSearch(forceNav) {
        const input = document.getElementById('search-name-input');
        if (input && !forceNav) {
            input.focus();
            input.select();
            return true;
        }
        // No on-page search input — go to /search and focus the field there.
        const base = document.querySelector('link[rel="canonical"]');
        // Use a relative path; if locale prefix matters the layout's nav link
        // would have it, but the absolute /search works for the default EN.
        window.location.href = '/search';
        return true;
    }
    document.addEventListener('keydown', (e) => {
        const target = e.target;
        const inField = target && target.matches && target.matches('input, textarea, select, [contenteditable]');

        // Cmd/Ctrl+K — always intercepts, works from any context.
        if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            focusSearch(false);
            return;
        }
        if (inField) return;
        // Plain "/" — focus search.
        if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            e.preventDefault();
            focusSearch(false);
            return;
        }
    });
})();

// Generic clipboard helper used by share + tools buttons.
async function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    // execCommand fallback for older browsers / non-HTTPS dev contexts.
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
}

// Share "Copy link" button — uses the Toast helper for feedback instead of
// mutating its own label. Cleaner, screen-reader friendly.
(function () {
    const btn = document.querySelector('[data-share-copy]');
    if (!btn) return;
    const container = btn.closest('.opening-share');
    const url = container && container.dataset.shareUrl ? container.dataset.shareUrl : location.href;
    btn.addEventListener('click', async () => {
        try {
            await copyToClipboard(url);
            Toast.show('Link copied');
        } catch (e) {
            Toast.show('Copy failed — your browser blocked clipboard access', 'error');
        }
    });
})();

// Suggest-form anchor — when the Overview's "suggest one yourself" link is
// clicked (href="#suggest-form-details"), the targeted <details> needs to be
// programmatically opened, since browsers don't auto-open closed details
// even when the URL hash matches their id.
(function () {
    function openTargetedDetails() {
        if (location.hash !== '#suggest-form-details') return;
        const el = document.getElementById('suggest-form-details');
        if (!el) return;
        el.open = true;
        // Slight delay lets the browser finish its own anchor scroll first.
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    }
    window.addEventListener('hashchange', openTargetedDetails);
    if (location.hash === '#suggest-form-details') openTargetedDetails();
})();

// Variations toggle — independent of board init, runs on every opening page.
(function () {
    const toggle = document.querySelector('.children-toggle');
    if (!toggle) return;
    const section = toggle.closest('.opening-children');
    toggle.addEventListener('click', () => {
        const wasCollapsed = section.dataset.childrenCollapsed === '1';
        const willHide = !wasCollapsed; // toggle the state
        section.querySelectorAll('.child-list li.is-overflow').forEach((li) => {
            li.hidden = willHide;
        });
        section.dataset.childrenCollapsed = willHide ? '1' : '0';
        toggle.textContent = willHide ? toggle.dataset.showText : toggle.dataset.hideText;
    });
})();

// Suggest-description form — AJAX submit so we can show inline status
// instead of dumping JSON onto the page.
(function () {
    const form = document.getElementById('suggest-form');
    if (!form) return;
    const status = form.querySelector('.suggest-status');
    const button = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        status.textContent = 'Sending…';
        status.dataset.state = 'sending';
        button.disabled = true;
        try {
            const fd = new FormData(form);
            const res = await fetch(form.action, { method: 'POST', body: fd });
            const data = await res.json();
            if (res.ok && data.ok) {
                form.reset();
                status.textContent = 'Thanks! Your suggestion will be reviewed.';
                status.dataset.state = 'ok';
                // Collapse the form so the page doesn't look "open" forever.
                const details = form.closest('details');
                if (details) details.open = false;
            } else {
                status.textContent = (data && data.error) || 'Something went wrong.';
                status.dataset.state = 'error';
            }
        } catch (err) {
            status.textContent = 'Network error — please try again.';
            status.dataset.state = 'error';
        } finally {
            button.disabled = false;
        }
    });
})();

const dataNode = document.getElementById('opening-data');
if (!dataNode) {
    // Not on an opening page — nothing to do.
} else {
    const { id, pgn, statsApiUrl } = JSON.parse(dataNode.textContent);

    // Replay the PGN through chess.js to get the FEN at every ply,
    // plus the from/to squares of the move that produced each position
    // (needed for chessground highlighting and movable.dests config).
    const chess = new Chess();
    if (!chess.load_pgn(pgn, { sloppy: true })) {
        console.error('chess.js failed to load PGN', pgn);
    }
    const history = chess.history({ verbose: true });
    const startFen = new Chess().fen();
    const positions = [{ san: null, fen: startFen, label: 'start', from: null, to: null }];
    const replay = new Chess();
    history.forEach((mv) => {
        replay.move({ from: mv.from, to: mv.to, promotion: mv.promotion });
        positions.push({
            san: mv.san,
            fen: replay.fen(),
            label: mv.san,
            from: mv.from,
            to: mv.to,
        });
    });

    const boardEl = document.getElementById('board');
    const moveListEl = document.getElementById('move-list');
    const resetBtn = document.getElementById('board-reset');

    let currentIdx = positions.length - 1;

    const turnColor = (fen) => (fen.split(' ')[1] === 'w' ? 'white' : 'black');

    function movableForPosition(i) {
        if (i >= positions.length - 1) {
            return { color: undefined, dests: new Map() };
        }
        const next = positions[i + 1];
        const dests = new Map([[next.from, [next.to]]]);
        return { color: turnColor(positions[i].fen), dests };
    }

    const board = Chessground(boardEl, {
        fen: positions[currentIdx].fen,
        coordinates: true,
        movable: {
            free: false,
            showDests: true,
            color: undefined,
            dests: new Map(),
            events: {
                // Chessground already animated the canonical move; sync our
                // state to the next position. The fen we set will match what
                // chessground rendered, so no flicker.
                after: () => setPosition(currentIdx + 1),
            },
        },
        draggable: { showGhost: true },
    });

    moveListEl.innerHTML = '';
    for (let i = 1; i < positions.length; i++) {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = positions[i].label;
        btn.dataset.idx = String(i);
        btn.addEventListener('click', () => setPosition(i));
        li.appendChild(btn);
        moveListEl.appendChild(li);
    }

    function setPosition(i) {
        currentIdx = i;
        const m = movableForPosition(i);
        board.set({
            fen: positions[i].fen,
            turnColor: turnColor(positions[i].fen),
            lastMove: positions[i].from ? [positions[i].from, positions[i].to] : undefined,
            movable: { color: m.color, dests: m.dests },
        });
        moveListEl.querySelectorAll('button').forEach((b) => {
            b.classList.toggle('is-active', Number(b.dataset.idx) === i);
        });
    }

    resetBtn.addEventListener('click', () => setPosition(0));

    // Flip-board button — toggles between white-bottom and black-bottom view.
    // Persisted per-browser in localStorage so the preference sticks across
    // page navigations (useful when you're studying a black-defence repertoire
    // and want every opening to render black-down).
    (function () {
        const flipBtn = document.getElementById('board-flip');
        if (!flipBtn) return;
        const FLIP_KEY = 'codex-board-orientation';
        let orientation = 'white';
        try {
            const saved = localStorage.getItem(FLIP_KEY);
            if (saved === 'black') orientation = 'black';
        } catch (e) {}
        if (orientation === 'black') {
            board.set({ orientation: 'black' });
        }
        flipBtn.addEventListener('click', () => {
            orientation = orientation === 'white' ? 'black' : 'white';
            board.set({ orientation: orientation });
            try { localStorage.setItem(FLIP_KEY, orientation); } catch (e) {}
        });
    })();

    // ----------------------------------------------------------------
    // Tools row: Copy PGN, Copy FEN, Open in Lichess, Embed snippet.
    // Wired here because we have the computed final FEN handy in
    // positions[positions.length-1].fen.
    // ----------------------------------------------------------------
    (function () {
        const tools = document.querySelector('[data-opening-tools]');
        if (!tools) return;
        const finalFen = positions[positions.length - 1].fen;
        const pgn      = tools.dataset.pgn || '';

        // "Open in Lichess analysis" — direct deep link with FEN preloaded.
        const lichessLink = tools.querySelector('[data-tool-lichess]');
        if (lichessLink) {
            lichessLink.href = 'https://lichess.org/analysis/standard/' + encodeURIComponent(finalFen);
        }

        // Enable Copy FEN now that we have it computed.
        const fenBtn = tools.querySelector('[data-tool-copy-fen]');
        if (fenBtn) {
            fenBtn.disabled = false;
            fenBtn.title = 'Copy: ' + finalFen;
        }

        async function copyAndToast(text, label) {
            try {
                await copyToClipboard(text);
                Toast.show(label + ' copied');
            } catch (e) {
                Toast.show('Copy failed', 'error');
            }
        }
        const pgnBtn = tools.querySelector('[data-tool-copy-pgn]');
        if (pgnBtn) pgnBtn.addEventListener('click', () => copyAndToast(pgn, 'PGN'));
        if (fenBtn) fenBtn.addEventListener('click', () => copyAndToast(finalFen, 'FEN'));

    })();

    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, [contenteditable]')) return;
        if (e.key === 'ArrowLeft' && currentIdx > 0) {
            e.preventDefault();
            setPosition(currentIdx - 1);
        } else if (e.key === 'ArrowRight' && currentIdx < positions.length - 1) {
            e.preventDefault();
            setPosition(currentIdx + 1);
        }
    });

    // Land on the final position by default — that's the canonical "result"
    // of the opening; users navigate backward via Reset / arrow keys / move buttons.
    setPosition(currentIdx);

    // ----------------------------------------------------------------
    // Stats panel: fetch Lichess Explorer aggregates for the FINAL canonical
    // position only. This is one HTTP round-trip per page; the server-side
    // cache absorbs >99% of repeat traffic.
    // ----------------------------------------------------------------
    const statsEl = document.getElementById('opening-stats');
    if (statsEl && statsApiUrl) {
        // The server prints cached numbers into the page (data-stats="fresh"
        // or "stale"); fetch only when there are none yet or they're more
        // than a week old. A failed refresh keeps the printed numbers.
        const shown = statsEl.dataset.stats || 'none';
        if (history.length > 0 && shown !== 'fresh') {
            loadStats(statsEl, statsApiUrl, id).catch((err) => {
                console.error('stats fetch failed', err);
                if (shown === 'none') renderStatsError(statsEl, 'Statistics could not be loaded.');
            });
        } else if (history.length === 0) {
            renderStatsError(statsEl, 'No moves to query.');
        }
    }
}

// The server derives the moves from the opening id. It fetches from Lichess
// one request at a time and answers 503 + Retry-After while another one is
// in flight, so retry a couple of times before giving up.
async function loadStats(rootEl, apiUrl, id, attempt = 1) {
    const url = apiUrl + '?id=' + encodeURIComponent(id);
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const retryAfter = Number(res.headers.get('Retry-After'));
    if (res.status === 503 && retryAfter > 0 && retryAfter <= 5 && attempt < 4) {
        await new Promise((resolve) => setTimeout(resolve, retryAfter * 1000));
        return loadStats(rootEl, apiUrl, id, attempt + 1);
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.error) {
        renderStatsError(rootEl, data.error);
        return;
    }
    renderStats(rootEl, data);
}

function renderStats(rootEl, data) {
    // Mark the section as no-longer-busy so screen readers stop announcing it.
    rootEl.setAttribute('aria-busy', 'false');
    const total = data.white + data.black + data.draws;
    const status = rootEl.querySelector('.stats-status');
    if (total === 0) {
        status.textContent = 'No games found in Lichess for this exact position.';
        // Switch off the loading state so the spinner-via-CSS goes away.
        status.dataset.state = 'empty';
        return;
    }
    status.dataset.state = 'done';
    status.hidden = true;

    const pct = (n) => (total > 0 ? (n * 100 / total) : 0);
    const wPct = pct(data.white), dPct = pct(data.draws), bPct = pct(data.black);

    const bar = rootEl.querySelector('.stats-bar');
    bar.setAttribute('aria-label', barLabel(wPct, dPct, bPct));
    bar.querySelector('.stats-bar-w').style.width = wPct.toFixed(1) + '%';
    bar.querySelector('.stats-bar-d').style.width = dPct.toFixed(1) + '%';
    bar.querySelector('.stats-bar-b').style.width = bPct.toFixed(1) + '%';
    bar.hidden = false;

    const totals = rootEl.querySelector('.stats-totals');
    totals.textContent = `${total.toLocaleString('en-US')} games · White ${wPct.toFixed(1)}% / Draw ${dPct.toFixed(1)}% / Black ${bPct.toFixed(1)}%`;
    totals.hidden = false;

    const tbody = rootEl.querySelector('.stats-moves tbody');
    tbody.innerHTML = '';
    data.top_moves.forEach((m) => {
        const moveTotal = m.white + m.black + m.draws;
        if (moveTotal === 0) return;
        const tr = document.createElement('tr');
        const td1 = document.createElement('td'); td1.textContent = m.san; tr.appendChild(td1);
        const td2 = document.createElement('td'); td2.textContent = moveTotal.toLocaleString('en-US'); tr.appendChild(td2);
        const td3 = document.createElement('td');
        const inner = document.createElement('div');
        inner.className = 'stats-bar inline';
        inner.setAttribute('role', 'img');
        inner.setAttribute('aria-label', barLabel(m.white * 100 / moveTotal, m.draws * 100 / moveTotal, m.black * 100 / moveTotal));
        inner.innerHTML =
            `<span class="stats-bar-w" style="width:${(m.white * 100 / moveTotal).toFixed(1)}%"></span>` +
            `<span class="stats-bar-d" style="width:${(m.draws * 100 / moveTotal).toFixed(1)}%"></span>` +
            `<span class="stats-bar-b" style="width:${(m.black * 100 / moveTotal).toFixed(1)}%"></span>`;
        td3.appendChild(inner);
        tr.appendChild(td3);
        tbody.appendChild(tr);
    });
    if (tbody.children.length > 0) {
        rootEl.querySelector('.stats-moves').hidden = false;
    }

    const attribution = rootEl.querySelector('.stats-attribution');
    rootEl.querySelector('.stats-cached-at').textContent = data.cached_at || '';
    attribution.hidden = false;
}

// Text alternative for a white/draw/black bar (same wording as the PHP side).
function barLabel(w, d, b) {
    return `White ${w.toFixed(1)}% · Draw ${d.toFixed(1)}% · Black ${b.toFixed(1)}%`;
}

function renderStatsError(rootEl, message) {
    rootEl.setAttribute('aria-busy', 'false');
    const status = rootEl.querySelector('.stats-status');
    status.textContent = message;
    status.dataset.state = 'error';
}
