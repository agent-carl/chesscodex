import { Chess } from '../vendor/chess.js';
import { Chessground } from '../vendor/chessground.min.js';

const dataNode = document.getElementById('play-data');
if (dataNode) {
    const cfg = JSON.parse(dataNode.textContent);

    // Replay the opening's PGN to get the starting FEN of the play session.
    const opening = new Chess();
    opening.load_pgn(cfg.pgn, { sloppy: true });
    const startFen = opening.fen();
    const startSide = startFen.split(' ')[1] === 'w' ? 'white' : 'black';

    // By default the user plays the side whose opening it is — the one that
    // made its last move (White in the Italian, Black in the Najdorf), the
    // same side "+ Repertoire" suggests; Stockfish replies first. "Play as"
    // and Flip switch sides.
    const ownSide = opening.history().length === 0 || startSide === 'black' ? 'white' : 'black';
    let userColor = ownSide;

    const chess = new Chess();
    chess.load(startFen);

    // ---------------- Game persistence (localStorage) ------------------------
    // Key per-opening so different /play/<slug> pages don't clobber each other.
    // We save the current PGN + chosen colour after every move, and try to
    // restore them on page load so a refresh / accidental close doesn't lose
    // the game-in-progress. Cleared when the user starts a new game.
    const SAVE_KEY = 'codex-play-' + (cfg.slug || location.pathname);
    function saveGame() {
        try {
            localStorage.setItem(SAVE_KEY, JSON.stringify({
                pgn:   chess.pgn(),
                color: userColor,
                level: skillLevel,
                mt:    moveTimeMs,
                novice: noviceMode ? 1 : 0,
                ts:    Date.now(),
            }));
        } catch (e) { /* quota / privacy mode — silent failure is fine */ }
    }
    function clearGame() { try { localStorage.removeItem(SAVE_KEY); } catch (e) {} }
    function loadGame() {
        try {
            const raw = localStorage.getItem(SAVE_KEY);
            if (!raw) return null;
            const data = JSON.parse(raw);
            if (!data || typeof data.pgn !== 'string') return null;
            // Stale games (older than 30 days) are not restored — feels weird
            // to re-open something you started a month ago.
            if (data.ts && Date.now() - data.ts > 30 * 86400 * 1000) return null;
            return data;
        } catch (e) { return null; }
    }
    const savedGame = loadGame();
    if (savedGame) {
        const restored = new Chess();
        if (restored.load_pgn(savedGame.pgn, { sloppy: true })) {
            // Only restore if the saved game's first moves match this opening's
            // PGN — otherwise it's a stale entry from a different deploy.
            const openingHistory = new Chess();
            openingHistory.load_pgn(cfg.pgn, { sloppy: true });
            const openingPly = openingHistory.history().length;
            const restoredHistory = restored.history();
            if (restoredHistory.length >= openingPly) {
                chess.reset();
                restoredHistory.forEach((san) => chess.move(san, { sloppy: true }));
                userColor = savedGame.color || userColor;
            }
        }
    }

    // ---------------- Stockfish worker bootstrap -----------------------------
    // The bundled stockfish.js parses self.location.hash to find the wasm.
    // Pass our renamed file via #stockfish.wasm — the wasm path is resolved
    // relative to the JS file itself, not the document URL.
    let engine = null;
    let engineReady = false;
    let engineThinking = false;
    let pendingFen = null; // FEN we last asked the engine to think about

    // Difficulty: skill 0..20, movetime in ms. Default Intermediate.
    // noviceMode = true at the lowest level — plays random legal moves part
    // of the time so the effective Elo dips below what Stockfish alone gives.
    let skillLevel = 10;
    let moveTimeMs = 1000;
    let noviceMode = false;
    const NOVICE_RANDOM_PROB = 0.6;
    try {
        const saved = localStorage.getItem('codex-engine-level');
        if (saved !== null) {
            const parts = saved.split(':').map(Number);
            const [s, mt, nv] = parts;
            if (Number.isFinite(s) && Number.isFinite(mt)) {
                skillLevel = s;
                moveTimeMs = mt;
                noviceMode = nv === 1;
            }
        }
    } catch (e) {}

    function createEngine() {
        const w = new Worker(cfg.stockfishJsUrl + '#stockfish.wasm');
        w.onmessage = (e) => onEngineMessage(typeof e.data === 'string' ? e.data : '');
        w.onerror = (e) => {
            console.error('stockfish worker error', e);
            setEngineState('error', 'Stockfish failed to load.');
        };
        w.postMessage('uci');
        return w;
    }

    function onEngineMessage(line) {
        if (!line) return;
        if (line === 'uciok') {
            engine.postMessage('isready');
            return;
        }
        if (line === 'readyok') {
            if (!engineReady) {
                engineReady = true;
                setEngineState('ready', 'Stockfish ready.');
                tickGame();
            }
            return;
        }
        if (line.startsWith('info ')) {
            // info depth 12 ... score cp 35 ... pv e2e4 ...
            const m = line.match(/score (cp|mate) (-?\d+)/);
            if (m) renderEval(m[1], parseInt(m[2], 10));
            return;
        }
        if (line.startsWith('bestmove ')) {
            engineThinking = false;
            // Discard if the position changed (user clicked New game/Undo/etc.
            // while the engine was thinking).
            if (chess.fen() !== pendingFen) return;
            const parts = line.split(/\s+/);
            const uci = parts[1];
            if (!uci || uci === '(none)') {
                renderResult(); // game ended (mate/stalemate/etc.)
                return;
            }
            const promotion = uci.length === 5 ? uci[4] : undefined;
            const move = chess.move({
                from: uci.slice(0, 2),
                to: uci.slice(2, 4),
                promotion,
            });
            if (move) {
                syncBoard();
                renderMoveList();
                tickGame();
            }
        }
    }

    function stopEngine() {
        if (engine && engineThinking) engine.postMessage('stop');
        pendingFen = null;
    }

    function tickGame() {
        if (chess.game_over()) {
            renderResult();
            return;
        }
        const sideToMove = chess.turn() === 'w' ? 'white' : 'black';
        renderTurn(sideToMove);
        if (sideToMove === userColor) {
            // Wait for user to play; chessground's movable.dests guides them.
            return;
        }
        // Engine to move.
        if (!engineReady || engineThinking) return;

        if (noviceMode && Math.random() < NOVICE_RANDOM_PROB) {
            playRandomMove();
            return;
        }

        engineThinking = true;
        setEngineState('thinking', 'Thinking…');
        pendingFen = chess.fen();
        engine.postMessage('setoption name Skill Level value ' + skillLevel);
        engine.postMessage('position fen ' + pendingFen);
        engine.postMessage('go movetime ' + moveTimeMs);
    }

    function playRandomMove() {
        // Mimic engine "thinking" briefly so the move doesn't feel instant.
        engineThinking = true;
        setEngineState('thinking', 'Thinking…');
        pendingFen = chess.fen();
        setTimeout(() => {
            engineThinking = false;
            if (chess.fen() !== pendingFen) return; // user changed game state
            const legal = chess.moves();
            if (legal.length === 0) { renderResult(); return; }
            const pick = legal[Math.floor(Math.random() * legal.length)];
            chess.move(pick);
            setEngineState('ready', 'Stockfish ready.');
            syncBoard();
            renderMoveList();
            tickGame();
        }, 350);
    }

    // ---------------- Board ---------------------------------------------------
    const boardEl = document.getElementById('play-board');
    const ALL_SQUARES = (() => {
        const out = [];
        for (let r = 8; r >= 1; r--) for (const f of 'abcdefgh') out.push(f + r);
        return out;
    })();

    function legalDests() {
        const dests = new Map();
        ALL_SQUARES.forEach((sq) => {
            const moves = chess.moves({ square: sq, verbose: true });
            if (moves.length > 0) dests.set(sq, moves.map((m) => m.to));
        });
        return dests;
    }

    const board = Chessground(boardEl, {
        fen: chess.fen(),
        orientation: userColor,
        coordinates: true,
        movable: {
            free: false,
            color: userColor,
            dests: legalDests(),
            showDests: true,
            events: {
                after: (orig, dest) => {
                    const move = chess.move({ from: orig, to: dest, promotion: 'q' });
                    if (!move) {
                        board.set({ fen: chess.fen() });
                        return;
                    }
                    syncBoard();
                    renderMoveList();
                    tickGame();
                },
            },
        },
        draggable: { showGhost: true },
    });
    // Defensive: some chessground versions only honour `orientation` in the
    // constructor when the option is "white" — for "black" the board ends up
    // un-flipped. Calling set() explicitly after init forces the flip and
    // matches what the user's "Play as: Black" pick should produce.
    board.set({ orientation: userColor });

    function syncBoard() {
        const sideToMove = chess.turn() === 'w' ? 'white' : 'black';
        const last = chess.history({ verbose: true }).slice(-1)[0];
        board.set({
            fen: chess.fen(),
            turnColor: sideToMove,
            lastMove: last ? [last.from, last.to] : undefined,
            movable: {
                color: sideToMove === userColor ? userColor : undefined,
                dests: sideToMove === userColor ? legalDests() : new Map(),
            },
        });
        // Persist after every position change so a refresh restores us
        // exactly where we left off. clearGame() runs on New game / Resign.
        if (chess.history().length > 0) saveGame();
    }

    // ---------------- UI rendering -------------------------------------------
    const statusEl = document.getElementById('play-status');
    const stateEl = statusEl.querySelector('.play-engine-state');
    const evalEl = document.querySelector('#play-game .play-eval');
    const turnEl = document.querySelector('.play-turn');
    const resultEl = document.querySelector('.play-result');
    const movesEl = document.getElementById('play-moves');

    function setEngineState(state, text) {
        stateEl.dataset.state = state;
        stateEl.textContent = text;
    }

    function renderEval(kind, value) {
        // Stockfish reports eval from the side-to-move's perspective. Flip to
        // a "white-positive" view for consistency.
        const fromWhite = chess.turn() === 'w' ? value : -value;
        if (kind === 'mate') {
            evalEl.textContent = 'Engine sees mate in ' + Math.abs(fromWhite)
                + (fromWhite > 0 ? ' for White' : ' for Black');
        } else {
            const pawns = (fromWhite / 100).toFixed(2);
            const sign = fromWhite > 0 ? '+' : '';
            evalEl.textContent = `Eval: ${sign}${pawns} ${fromWhite >= 0 ? '(White)' : '(Black)'}`;
        }
        evalEl.hidden = false;
    }

    function renderTurn(side) {
        const name = side === 'white' ? 'White' : 'Black';
        turnEl.textContent = side === userColor
            ? 'Your move (' + name + ').'
            : 'Stockfish to move (' + name + ').';
    }

    function renderMoveList() {
        const sans = chess.history();
        movesEl.innerHTML = '';
        sans.forEach((san) => {
            const li = document.createElement('li');
            const span = document.createElement('span');
            span.textContent = san;
            li.appendChild(span);
            movesEl.appendChild(li);
        });
        movesEl.scrollTop = movesEl.scrollHeight;
    }

    function renderResult(message) {
        let text = message;
        if (!text) {
            if (chess.in_checkmate()) {
                const winner = chess.turn() === 'w' ? 'Black' : 'White';
                text = winner + ' wins by checkmate.';
            } else if (chess.in_stalemate()) {
                text = 'Stalemate. Draw.';
            } else if (chess.in_threefold_repetition()) {
                text = 'Draw by threefold repetition.';
            } else if (chess.insufficient_material()) {
                text = 'Draw by insufficient material.';
            } else if (chess.in_draw()) {
                text = 'Draw.';
            } else {
                text = 'Game ended.';
            }
        }
        resultEl.textContent = text;
        resultEl.hidden = false;
        turnEl.textContent = '';
        board.set({ movable: { color: undefined, dests: new Map() } });
    }

    // ---------------- Controls -----------------------------------------------
    document.getElementById('play-new').addEventListener('click', () => {
        stopEngine();
        chess.load(startFen);
        clearGame();
        resultEl.hidden = true;
        evalEl.hidden = true;
        renderMoveList();
        syncBoard();
        tickGame();
    });

    document.getElementById('play-undo').addEventListener('click', () => {
        if (chess.history().length === 0) return;
        stopEngine();
        // Undo BOTH plies (engine's reply + your move) so it's still your turn.
        chess.undo();
        if (chess.history().length > 0 && chess.turn() !== (userColor === 'white' ? 'w' : 'b')) {
            chess.undo();
        }
        resultEl.hidden = true;
        renderMoveList();
        syncBoard();
        tickGame();
    });

    document.getElementById('play-flip').addEventListener('click', () => {
        stopEngine();
        userColor = userColor === 'white' ? 'black' : 'white';
        board.set({ orientation: userColor });
        syncBoard();
        tickGame();
        syncColorPickUi();
    });

    // Explicit "Play as: White / Black" picker. Same effect as the Flip
    // button — switching colour also resets the game so the user starts
    // from the opening's final position playing the chosen side.
    function syncColorPickUi() {
        document.querySelectorAll('[data-play-color]').forEach((btn) => {
            const on = btn.dataset.playColor === userColor;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    document.querySelectorAll('[data-play-color]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = btn.dataset.playColor;
            // No-op when re-clicking the active colour — but still re-run
            // the orientation set so the board picks up a fresh flip if the
            // initial render didn't honour the constructor-level setting.
            if (next === userColor) {
                board.set({ orientation: userColor });
                return;
            }
            stopEngine();
            // Reset to the opening's final position so the new side gets a
            // fresh game, not the middle of the previous one.
            chess.reset();
            chess.load(startFen);
            userColor = next;
            // Set orientation BEFORE syncBoard so subsequent set() calls
            // can't clobber it.
            board.set({ orientation: userColor });
            resultEl.hidden = true;
            renderMoveList();
            syncBoard();
            // Re-set orientation one more time AFTER syncBoard, just in case
            // movable/turnColor changes triggered a re-render that reset it.
            board.set({ orientation: userColor });
            tickGame();
            syncColorPickUi();
        });
    });
    syncColorPickUi();

    document.getElementById('play-resign').addEventListener('click', () => {
        stopEngine();
        const winner = userColor === 'white' ? 'Black' : 'White';
        renderResult('You resigned. ' + winner + ' wins.');
        clearGame();
    });

    // Difficulty selector — radio-style buttons that persist choice and
    // visibly update the indicator + description on every switch.
    const diffEl = document.getElementById('play-difficulty');
    const currentLevelEl = document.getElementById('play-current-level');
    const currentLevelName = currentLevelEl.querySelector('.play-current-level-name');
    const currentLevelElo = currentLevelEl.querySelector('.play-current-level-elo');
    const descEl = document.getElementById('play-difficulty-desc');

    function syncLevelUi() {
        // Two buttons share data-level=0 (Novice and Beginner) — disambiguate
        // by also matching movetime and the novice flag.
        let activeBtn = null;
        diffEl.querySelectorAll('button').forEach((b) => {
            const btnIsNovice = b.dataset.novice === '1';
            const isActive = Number(b.dataset.level) === skillLevel
                && Number(b.dataset.mt) === moveTimeMs
                && btnIsNovice === noviceMode;
            b.classList.toggle('is-active', isActive);
            b.setAttribute('aria-checked', isActive ? 'true' : 'false');
            b.setAttribute('role', 'radio');
            if (isActive) activeBtn = b;
        });
        if (activeBtn) {
            currentLevelName.textContent = activeBtn.dataset.name;
            currentLevelElo.textContent = activeBtn.dataset.elo + ' Elo';
            descEl.textContent = activeBtn.dataset.desc;
        }
    }

    function flashIndicator() {
        // Re-trigger the CSS animation by toggling the class.
        currentLevelEl.classList.remove('is-changed');
        // Force reflow so the next class re-application restarts the animation.
        void currentLevelEl.offsetWidth;
        currentLevelEl.classList.add('is-changed');
    }

    diffEl.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-level]');
        if (!btn) return;
        const newLevel = Number(btn.dataset.level);
        const newMt = Number(btn.dataset.mt);
        const newNovice = btn.dataset.novice === '1';
        if (newLevel === skillLevel && newMt === moveTimeMs && newNovice === noviceMode) return;
        skillLevel = newLevel;
        moveTimeMs = newMt;
        noviceMode = newNovice;
        try {
            localStorage.setItem('codex-engine-level',
                skillLevel + ':' + moveTimeMs + ':' + (noviceMode ? '1' : '0'));
        } catch (_) {}
        syncLevelUi();
        flashIndicator();
        // If engine is mid-think, restart with new strength so the change
        // takes effect immediately rather than after the current ply.
        if (engineThinking && chess.turn() !== (userColor === 'white' ? 'w' : 'b')) {
            stopEngine();
            tickGame();
        }
    });
    syncLevelUi();

    // ---------------- Boot ----------------------------------------------------
    renderMoveList();
    syncBoard();
    engine = createEngine();
}
