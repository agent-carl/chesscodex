import { Chess } from '../vendor/chess.js';
import { Chessground } from '../vendor/chessground.min.js';

const dataNode = document.getElementById('search-data');
if (dataNode) {
    const { searchApiUrl, openingPathFmt } = JSON.parse(dataNode.textContent);

    // -----------------------------------------------------------------------
    // Search by name (autocomplete). Independent of the board — runs whenever
    // the user types in the #search-name-input, debounced 150 ms. Hits
    // /api/search?name=... which returns up to 10 best matches by popularity.
    // -----------------------------------------------------------------------
    (function () {
        const input = document.getElementById('search-name-input');
        const list  = document.getElementById('search-name-results');
        if (!input || !list) return;

        let timer = null;
        let abortCtrl = null;

        function clearResults() {
            list.innerHTML = '';
            list.hidden = true;
        }

        async function lookup(q) {
            // Cancel any inflight fetch — the user's keystroke supersedes it.
            // Saves a tiny amount of bandwidth + makes results never arrive
            // out-of-order on slow networks.
            if (abortCtrl) abortCtrl.abort();
            abortCtrl = new AbortController();
            try {
                const url = searchApiUrl + '?name=' + encodeURIComponent(q);
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: abortCtrl.signal,
                });
                if (!res.ok) return clearResults();
                const data = await res.json();
                renderResults(data.matches || []);
            } catch (e) {
                // AbortError is expected when we cancel; ignore. Anything else
                // is a real failure → clear the dropdown.
                if (e.name !== 'AbortError') clearResults();
            }
        }

        function renderResults(matches) {
            list.innerHTML = '';
            if (matches.length === 0) {
                const li = document.createElement('li');
                li.className = 'search-by-name-empty';
                li.textContent = 'No openings match that name.';
                list.appendChild(li);
                list.hidden = false;
                return;
            }
            matches.forEach((m) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                const a = document.createElement('a');
                a.href = openingPathFmt.replace('{slug}', encodeURIComponent(m.slug));
                const tag = document.createElement('span');
                tag.className = 'eco-tag';
                tag.textContent = m.eco;
                a.appendChild(tag);
                const name = document.createElement('span');
                name.className = 'search-by-name-result-name';
                name.textContent = m.name;
                a.appendChild(name);
                const plies = document.createElement('span');
                plies.className = 'search-by-name-result-plies';
                plies.textContent = m.plies + '-ply';
                a.appendChild(plies);
                li.appendChild(a);
                list.appendChild(li);
            });
            list.hidden = false;
        }

        // Index of the currently keyboard-highlighted result row. -1 = none.
        let cursor = -1;

        function updateCursor(delta) {
            const items = list.querySelectorAll('li[role="option"]');
            if (items.length === 0) return;
            cursor = (cursor + delta + items.length) % items.length;
            items.forEach((li, i) => li.classList.toggle('is-active', i === cursor));
            const active = items[cursor];
            if (active) active.scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearTimeout(timer);
            cursor = -1;
            if (q.length < 2) { clearResults(); return; }
            timer = setTimeout(() => lookup(q), 150);
        });
        input.addEventListener('focus', () => {
            if (list.children.length > 0) list.hidden = false;
        });
        // Hide dropdown on outside click.
        document.addEventListener('click', (e) => {
            if (!list.contains(e.target) && e.target !== input) list.hidden = true;
        });
        // Keyboard: ESC closes, ↑/↓ navigates, Enter follows the highlighted link.
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { clearResults(); input.blur(); return; }
            if (list.hidden || list.children.length === 0) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); updateCursor(+1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); updateCursor(-1); }
            else if (e.key === 'Enter' && cursor >= 0) {
                const active = list.querySelector('li.is-active a');
                if (active) { e.preventDefault(); window.location.href = active.href; }
            }
        });
    })();

    const chess = new Chess();
    const boardEl = document.getElementById('search-board');
    const undoBtn = document.getElementById('search-undo');
    const resetBtn = document.getElementById('search-reset');
    const playedListEl = document.getElementById('search-played-list');
    const resultEl = document.getElementById('search-result');
    const continuationsEl = document.getElementById('search-continuations');
    // The FEN search relabels this list "Other transpositions"; move lookups
    // put the original heading back.
    const continuationsTitle = continuationsEl.querySelector('h2').textContent;

    const turnColor = (c) => (c.turn() === 'w' ? 'white' : 'black');

    // 64 algebraic squares — generated once, used to ask chess.js for
    // legal moves per origin so chessground knows which squares are draggable.
    const ALL_SQUARES = (() => {
        const out = [];
        const files = 'abcdefgh';
        for (let r = 8; r >= 1; r--) for (const f of files) out.push(f + r);
        return out;
    })();

    function legalDests(c) {
        const dests = new Map();
        ALL_SQUARES.forEach((sq) => {
            const moves = c.moves({ square: sq, verbose: true });
            if (moves.length > 0) {
                dests.set(sq, moves.map((m) => m.to));
            }
        });
        return dests;
    }

    const board = Chessground(boardEl, {
        fen: chess.fen(),
        coordinates: true,
        movable: {
            free: false,
            color: 'white',
            dests: legalDests(chess),
            showDests: true,
            events: {
                after: (orig, dest) => {
                    // chess.js handles validation. Default promotion to queen
                    // — the explorer doesn't usually care about underpromotion
                    // and most opening lines never reach the 8th rank anyway.
                    const move = chess.move({ from: orig, to: dest, promotion: 'q' });
                    if (!move) {
                        // Shouldn't happen — chessground only allowed legal dests.
                        board.set({ fen: chess.fen() });
                        return;
                    }
                    syncBoardAndQuery();
                },
            },
        },
        draggable: { showGhost: true },
    });

    function syncBoardAndQuery() {
        board.set({
            fen: chess.fen(),
            turnColor: turnColor(chess),
            movable: { color: turnColor(chess), dests: legalDests(chess) },
            lastMove: chess.history({ verbose: true }).slice(-1).map((m) => [m.from, m.to])[0],
        });
        renderPlayed();
        fetchMatch();
    }

    function renderPlayed() {
        const sans = chess.history();
        playedListEl.innerHTML = '';
        sans.forEach((san) => {
            const li = document.createElement('li');
            const span = document.createElement('span');
            span.textContent = san;
            li.appendChild(span);
            playedListEl.appendChild(li);
        });
    }

    function canonOf(sans) {
        // Strip annotations (+, #, !, ?) so the server's canonical form matches.
        return sans.map((s) => s.replace(/[+#!?]/g, '')).join(' ');
    }

    let inflight = 0;
    async function fetchMatch() {
        const sans = chess.history();
        if (sans.length === 0) {
            renderEmpty();
            return;
        }
        const myTurn = ++inflight;
        const url = searchApiUrl + '?moves=' + encodeURIComponent(canonOf(sans));
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (myTurn !== inflight) return; // stale response, user moved on
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            renderResult(data);
        } catch (err) {
            console.error('search fetch failed', err);
            renderError();
        }
    }

    function renderEmpty() {
        resultEl.dataset.state = 'empty';
        resultEl.querySelector('.search-empty').hidden = false;
        resultEl.querySelector('.search-match').hidden = true;
        continuationsEl.hidden = true;
    }

    function renderError() {
        resultEl.dataset.state = 'error';
        resultEl.querySelector('.search-empty').textContent = 'Search failed. Try again.';
        resultEl.querySelector('.search-empty').hidden = false;
        resultEl.querySelector('.search-match').hidden = true;
    }

    function renderResult(data) {
        const empty = resultEl.querySelector('.search-empty');
        const matchEl = resultEl.querySelector('.search-match');
        const playedPlies = chess.history().length;

        if (data.match) {
            resultEl.dataset.state = data.match.exact ? 'exact' : 'ancestor';
            empty.hidden = true;
            matchEl.hidden = false;
            const link = matchEl.querySelector('.search-match-link');
            link.href = openingPathFmt.replace('{slug}', encodeURIComponent(data.match.slug));
            link.querySelector('.eco-tag').textContent = data.match.eco;
            link.querySelector('.search-match-name').textContent = data.match.name;
            const meta = matchEl.querySelector('.search-match-meta');
            if (data.match.exact) {
                meta.textContent = `Exact match — you're playing this opening.`;
            } else {
                const extra = playedPlies - data.match.plies;
                // extra counts half-moves, hence "plies" (as on opening pages).
                meta.textContent = `Closest known opening, ${extra} ${extra === 1 ? 'ply' : 'plies'} past documented theory.`;
            }
        } else {
            resultEl.dataset.state = 'unknown';
            empty.textContent = 'No matching opening found in our index.';
            empty.hidden = false;
            matchEl.hidden = true;
        }

        continuationsEl.querySelector('h2').textContent = continuationsTitle;
        const ulEl = continuationsEl.querySelector('ul');
        ulEl.innerHTML = '';
        if (data.continuations && data.continuations.length > 0) {
            data.continuations.forEach((c) => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = openingPathFmt.replace('{slug}', encodeURIComponent(c.slug));
                const tag = document.createElement('span');
                tag.className = 'eco-tag';
                tag.textContent = c.eco;
                a.appendChild(tag);
                a.appendChild(document.createTextNode(c.name));
                li.appendChild(a);
                ulEl.appendChild(li);
            });
            continuationsEl.hidden = false;
        } else {
            continuationsEl.hidden = true;
        }
    }

    undoBtn.addEventListener('click', () => {
        if (chess.history().length === 0) return;
        chess.undo();
        syncBoardAndQuery();
    });

    resetBtn.addEventListener('click', () => {
        chess.reset();
        syncBoardAndQuery();
    });

    // Keyboard shortcut: Backspace = undo (when not focused in an input).
    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea, [contenteditable]')) return;
        if (e.key === 'Backspace' && chess.history().length > 0) {
            e.preventDefault();
            chess.undo();
            syncBoardAndQuery();
        }
    });

    renderEmpty();

    // ---- Paste moves / PGN ---------------------------------------------------
    // Reads move text or a whole PGN (headers, comments and move numbers are
    // fine) and replays it on the board, so the lookup runs exactly as for
    // moves played by hand. The deepest named line is 36 plies, so a full game
    // is cut to its first MAX_PLIES.
    const MAX_PLIES = 40;
    const pasteForm = document.getElementById('search-paste-form');
    const pasteInput = document.getElementById('search-paste-input');
    const pasteStatus = document.getElementById('search-paste-status');

    function showPasteStatus(text) {
        pasteStatus.textContent = text;
        pasteStatus.hidden = text === '';
    }

    function loadMoves(text) {
        const parsed = new Chess();
        if (!parsed.load_pgn(text, { sloppy: true }) || parsed.history().length === 0) {
            showPasteStatus('Couldn’t read those moves. Use standard notation, e.g. 1. e4 c5 2. Nf3.');
            return;
        }
        if (parsed.header().FEN) {
            showPasteStatus('Only games from the standard starting position can be identified.');
            return;
        }
        const moves = parsed.history({ verbose: true });
        chess.reset();
        moves.slice(0, MAX_PLIES).forEach((m) => chess.move({ from: m.from, to: m.to, promotion: m.promotion }));
        showPasteStatus(moves.length > MAX_PLIES
            ? `Showing the first ${MAX_PLIES / 2} moves — no named opening goes deeper.`
            : '');
        syncBoardAndQuery();
    }

    if (pasteForm && pasteInput && pasteStatus) {
        pasteForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const text = pasteInput.value.trim();
            if (text) loadMoves(text);
        });
        // Enter identifies; Shift+Enter starts a new line.
        pasteInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                pasteForm.requestSubmit();
            }
        });
        // /search?moves=1.e4+c5 opens with those moves filled in and looked up.
        const preset = new URLSearchParams(location.search).get('moves');
        if (preset) {
            pasteInput.value = preset;
            loadMoves(preset);
        }
    }

    // ---- FEN search form (transposition lookup) -----------------------------
    const fenForm = document.getElementById('search-fen-form');
    if (fenForm) {
        fenForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('search-fen-input');
            const fen = input.value.trim();
            if (!fen) return;
            const url = searchApiUrl + '?fen=' + encodeURIComponent(fen);
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                renderFenResults(data);
            } catch (err) {
                console.error('fen search failed', err);
                renderError();
            }
        });
    }

    function renderFenResults(data) {
        const empty = resultEl.querySelector('.search-empty');
        const matchEl = resultEl.querySelector('.search-match');
        empty.hidden = true;
        matchEl.hidden = true;

        const ulEl = continuationsEl.querySelector('ul');
        ulEl.innerHTML = '';
        const list = data.matches || [];
        if (list.length === 0) {
            resultEl.dataset.state = 'unknown';
            empty.textContent = 'No opening matches that exact FEN.';
            empty.hidden = false;
            continuationsEl.hidden = true;
            return;
        }

        // Show first match in the main slot, the rest as alternatives below.
        const head = list[0];
        resultEl.dataset.state = 'exact';
        matchEl.hidden = false;
        const link = matchEl.querySelector('.search-match-link');
        link.href = openingPathFmt.replace('{slug}', encodeURIComponent(head.slug));
        link.querySelector('.eco-tag').textContent = head.eco;
        link.querySelector('.search-match-name').textContent = head.name;
        const meta = matchEl.querySelector('.search-match-meta');
        meta.textContent = list.length === 1
            ? 'One opening reaches this exact position.'
            : `${list.length} openings reach this position (transpositions).`;

        list.slice(1).forEach((c) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = openingPathFmt.replace('{slug}', encodeURIComponent(c.slug));
            const tag = document.createElement('span');
            tag.className = 'eco-tag';
            tag.textContent = c.eco;
            a.appendChild(tag);
            a.appendChild(document.createTextNode(c.name));
            li.appendChild(a);
            ulEl.appendChild(li);
        });
        continuationsEl.querySelector('h2').textContent = 'Other transpositions';
        continuationsEl.hidden = list.length <= 1;
    }
}
