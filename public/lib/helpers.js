// Pure helpers of the page scripts in public/: no DOM, no state, so tests/js can import
// them directly. tools/build.mjs bundles them into each page's .min.js.

// "Sep 25, 2026" from the cache's "2026-09-25 19:55:16".
export function updatedLabel(at) {
    const d = at ? new Date(String(at).replace(' ', 'T')) : null;
    return d && !isNaN(d) ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

// Text alternative for a white/draw/black bar (same wording as the PHP side).
export function barLabel(w, d, b) {
    return `White ${w.toFixed(1)}%, Draw ${d.toFixed(1)}%, Black ${b.toFixed(1)}%`;
}

// 209,140,943 → "209.1M" (same thresholds as Rankings::compact in PHP).
export function compactCount(n) {
    const one = (x) => String(Number(x.toFixed(1)));
    if (n >= 999950000) return one(n / 1e9) + 'B';
    if (n >= 999500) return one(n / 1e6) + 'M';
    if (n >= 10000) return Math.round(n / 1000) + 'K';
    return n.toLocaleString('en-US');
}

// The move number before a move: "3. " for White, "3… " for Black (ply counts from 0).
export const moveNoOf = (ply) => Math.floor(ply / 2) + 1 + (ply % 2 === 0 ? '. ' : '… ');

// The moves of a game as numbered text: "1. e4 e5 2. Nf3".
export const gamePgn = (sans) => sans.map((san, i) => (i % 2 === 0 ? (i / 2 + 1) + '. ' : '') + san).join(' ');

export const plain = (san) => san.replace(/[+#?!]/g, '');
export const label = (ply, san) => (Math.floor(ply / 2) + 1) + (ply % 2 === 0 ? '. ' : '…') + san;

// A line's length in full moves (move_count counts plies: one side's move).
export const movesLabel = (plies) => { const n = Math.ceil(plies / 2); return n === 1 ? '1 move' : n + ' moves'; };

// Same delta-name logic as the PHP $opening_short_name helper.
export function shortName(name, parentName) {
    if (!parentName) return name;
    if (name === parentName) return name.split(/: |, /).pop();
    for (const sep of [': ', ', ']) {
        const prefix = parentName + sep;
        if (name.indexOf(prefix) === 0) return name.slice(prefix.length);
    }
    return name;
}

// Name for filtering: accents and apostrophes gone, lower case.
export const fold = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/['’]/g, '').toLowerCase();

// "Grünfeld Defense: Lutikov Gambit (D80)" → "grunfeld defense lutikov gambit d80"
export const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, ' ').trim();

// SAN moves, optionally numbered: the whole query has to be made of them.
const MOVE = '(?:\\d+\\.+\\s*)?(?:O-O(?:-O)?|0-0(?:-0)?|[KQRBNkqrn]?[a-h]?[1-8]?x?[a-h][1-8](?:=[QRBNqrbn])?)[+#]?';
const MOVES_RE = new RegExp('^\\s*' + MOVE + '(?:\\s+' + MOVE + ')*\\s*$');
export const looksLikeMoves = function (q) { return /\d|^[a-h][1-8]\b/.test(q) && MOVES_RE.test(q); };

// Russian move text: Cyrillic letters that look like Latin files and
// signs ("е4", "с5", "exd5" with "х", "О-О"), and the Russian piece
// letters at the start of a move — Кр king, К knight, Ф queen, Л rook,
// С bishop ("Кf3" is Nf3).
const LOOKALIKES = { 'а': 'a', 'е': 'e', 'с': 'c', 'х': 'x', 'О': 'O' };
const RU_PIECES = [[/^Кр/, 'K'], [/^К/, 'N'], [/^Ф/, 'Q'], [/^Л/, 'R'], [/^С/, 'B']];

// Move text or a PGN → its moves: headers, comments, variations, move
// numbers, NAGs, annotations and the result go.
export function sanTokens(text) {
    let t = text
        .replace(/\[[^\]]*\]/g, ' ')
        .replace(/\{[^}]*\}/g, ' ')
        .replace(/;[^\n]*/g, ' ')
        .replace(/\$\d+/g, ' ');
    let prev;
    do { prev = t; t = t.replace(/\([^()]*\)/g, ' '); } while (t !== prev);
    return t.split(/\s+/)
        .map((tok) => tok.replace(/^\d+\.+/, ''))
        .map((tok) => RU_PIECES.reduce((m, [re, to]) => m.replace(re, to), tok).replace(/[аесхО]/g, (ch) => LOOKALIKES[ch]))
        .map((tok) => tok.replace(/[!?]+$/, '').replace(/^0-0(-0)?/, (m) => m.replace(/0/g, 'O')))
        .filter((tok) => tok !== '' && !/^(1-0|0-1|1\/2-1\/2|½-½|\*)$/.test(tok));
}

// One move, also in lower case ("nf3", "qxd4"). "b…" is tried as a pawn
// move first, then as a bishop move.
export function tryMove(c, tok) {
    const m = c.move(tok, { sloppy: true });
    if (m || !/^[nbrqk]/.test(tok)) return m;
    return c.move(tok[0].toUpperCase() + tok.slice(1), { sloppy: true });
}

// Castling rights for a bare piece placement: a side keeps them while its
// king and that rook stand on their first squares — true in almost every
// opening position, where the named lines are stored with them.
export function guessCastling(placement) {
    const rows = placement.split('/').map((r) => r.replace(/\d/g, (n) => '.'.repeat(Number(n))));
    const [top, bottom] = [rows[0], rows[7]];
    let c = '';
    if (bottom[4] === 'K' && bottom[7] === 'R') c += 'K';
    if (bottom[4] === 'K' && bottom[0] === 'R') c += 'Q';
    if (top[4] === 'k' && top[7] === 'r') c += 'k';
    if (top[4] === 'k' && top[0] === 'r') c += 'q';
    return c || '-';
}
