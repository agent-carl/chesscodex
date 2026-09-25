<?php
declare(strict_types=1);

/**
 * Minimal chess engine in PHP. Designed to apply known-good PGN sequences
 * (from the Lichess opening dataset) and produce FEN / UCI output.
 *
 * Scope (what it does correctly):
 *   - All standard moves: pawn pushes, captures, en passant, promotion
 *   - Knight, bishop, rook, queen, king
 *   - Castling (O-O, O-O-O, with castling-rights tracking)
 *   - SAN parsing including file/rank disambiguation (Nbd2, R1e2, etc.)
 *   - FEN serialization (full 6-field standard FEN)
 *   - UCI move notation (e2e4, e7e8q, etc.)
 *
 * Out of scope (input PGN is trusted to be legal):
 *   - Pin / discovered-check filtering (won't reject illegal-looking moves)
 *   - Check, mate, stalemate detection
 *   - Threefold repetition / 50-move rule
 *
 * Square indexing: 0..63, with index 0 = a8 (top-left from White's POV)
 * and 63 = h1. file = idx % 8 (0=a, 7=h), rank = 8 - intdiv(idx, 8).
 */
final class ChessEngine
{
    private const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    /** @var string[] 64 entries; '' empty, uppercase = white, lowercase = black */
    private array $board;
    private string $turn = 'w';
    private string $castling = 'KQkq';
    private ?int $epSquare = null;
    private int $halfmove = 0;
    private int $fullmove = 1;
    /** @var string[] UCI tokens of moves applied so far (e.g. ['e2e4', 'c7c5']) */
    private array $uciHistory = [];

    public function __construct(?string $fen = null)
    {
        $this->loadFen($fen ?? self::START_FEN);
    }

    /** Convenience: build engine from a complete PGN string. */
    public static function fromPgn(string $pgn): self
    {
        $e = new self();
        $e->applyPgn($pgn);
        return $e;
    }

    public function fen(): string
    {
        $ranks = [];
        for ($r = 0; $r < 8; $r++) {
            $rankStr = '';
            $empty = 0;
            for ($f = 0; $f < 8; $f++) {
                $p = $this->board[$r * 8 + $f];
                if ($p === '') { $empty++; continue; }
                if ($empty > 0) { $rankStr .= $empty; $empty = 0; }
                $rankStr .= $p;
            }
            if ($empty > 0) $rankStr .= $empty;
            $ranks[] = $rankStr;
        }
        $ep = $this->epSquare === null ? '-' : self::idxToName($this->epSquare);
        $castling = $this->castling === '' ? '-' : $this->castling;
        return implode('/', $ranks) . " {$this->turn} $castling $ep {$this->halfmove} {$this->fullmove}";
    }

    /** @return string[] */
    public function uciHistory(): array { return $this->uciHistory; }

    public function applyPgn(string $pgn): void
    {
        // Strip move numbers like "1." or "12..." then split by whitespace.
        $clean = preg_replace('/\d+\.(\.\.)?/', ' ', $pgn);
        $clean = preg_replace('/\s+/', ' ', trim($clean));
        if ($clean === '') return;
        foreach (explode(' ', $clean) as $san) {
            if ($san === '' || $san === '*' || $san === '1-0' || $san === '0-1' || $san === '1/2-1/2') continue;
            $this->applySan($san);
        }
    }

    public function applySan(string $san): void
    {
        $san = preg_replace('/[+#!?]/', '', $san);
        if ($san === '') throw new RuntimeException('Empty SAN');

        // --- Castling -------------------------------------------------------
        if ($san === 'O-O' || $san === '0-0')   { $this->doCastle(true);  return; }
        if ($san === 'O-O-O' || $san === '0-0-0') { $this->doCastle(false); return; }

        // --- Promotion (e8=Q, e7e8=Q-style) ---------------------------------
        $promotion = null;
        if (preg_match('/=([QRBN])$/', $san, $m)) {
            $promotion = $m[1];
            $san = preg_replace('/=[QRBN]$/', '', $san);
        }

        // --- Piece type (default pawn) --------------------------------------
        $piece = 'P';
        $i = 0;
        if (in_array($san[0], ['K', 'Q', 'R', 'B', 'N'], true)) {
            $piece = $san[0];
            $i = 1;
        }

        // --- Capture flag ---------------------------------------------------
        $capture = strpos($san, 'x') !== false;
        $san = str_replace('x', '', $san);

        // After stripping piece letter and 'x', remainder is [disambig][dest]
        // where dest is the last 2 chars. Disambiguation is 0-2 chars
        // (file, rank, or both) between piece and dest.
        $remainder = substr($san, $i);
        if (strlen($remainder) < 2) throw new RuntimeException("SAN too short: $san");
        $dest = substr($remainder, -2);
        $disambig = substr($remainder, 0, -2);
        $destIdx = self::nameToIdx($dest);

        $candidates = $this->findCandidates($piece, $destIdx, $disambig);
        if (count($candidates) !== 1) {
            throw new RuntimeException(sprintf(
                'SAN "%s%s%s%s" matches %d candidate(s) — expected 1. FEN: %s',
                $piece === 'P' ? '' : $piece, $disambig, $capture ? 'x' : '', $dest,
                count($candidates), $this->fen()
            ));
        }
        $this->doMove($candidates[0], $destIdx, $promotion);
    }

    // ------------------------------------------------------------------
    // Move generation & disambiguation
    // ------------------------------------------------------------------

    /** @return int[] origin squares matching the SAN constraints */
    private function findCandidates(string $piece, int $destIdx, string $disambig): array
    {
        $needle = $this->turn === 'w' ? $piece : strtolower($piece);
        $out = [];
        for ($idx = 0; $idx < 64; $idx++) {
            if ($this->board[$idx] !== $needle) continue;
            if (!$this->canReach($idx, $destIdx, $piece)) continue;
            if (!self::matchDisambig($idx, $disambig)) continue;
            $out[] = $idx;
        }
        // If two pieces could geometrically reach the destination, the SAN is
        // unambiguous only because one of them is pinned (moving it would
        // expose the king to check). Trial-apply each candidate and discard
        // the ones that leave the king in check.
        if (count($out) > 1) {
            $out = $this->filterPinned($out, $destIdx);
        }
        return $out;
    }

    /** Trial each candidate; keep only those whose move doesn't leave own king in check. */
    private function filterPinned(array $candidates, int $destIdx): array
    {
        $kingChar = $this->turn === 'w' ? 'K' : 'k';
        $enemyWhite = $this->turn !== 'w';

        $safe = [];
        foreach ($candidates as $from) {
            // Save the two squares we're about to mutate.
            $origFrom = $this->board[$from];
            $origTo   = $this->board[$destIdx];
            // Apply the move on the board.
            $this->board[$destIdx] = $origFrom;
            $this->board[$from] = '';

            // Find own king (could be the piece we just moved on a king move).
            $kingIdx = -1;
            for ($i = 0; $i < 64; $i++) {
                if ($this->board[$i] === $kingChar) { $kingIdx = $i; break; }
            }
            $isAttacked = $kingIdx >= 0 && $this->isSquareAttacked($kingIdx, $enemyWhite);

            // Restore exactly.
            $this->board[$from]    = $origFrom;
            $this->board[$destIdx] = $origTo;

            if (!$isAttacked) $safe[] = $from;
        }
        return $safe;
    }

    /** True if any piece of the given colour attacks the target square. */
    private function isSquareAttacked(int $sq, bool $byWhite): bool
    {
        for ($i = 0; $i < 64; $i++) {
            $p = $this->board[$i];
            if ($p === '') continue;
            if (ctype_upper($p) !== $byWhite) continue;
            if ($this->pieceAttacks($i, $sq)) return true;
        }
        return false;
    }

    /**
     * Does the piece at $from attack square $to? Like canReach, but pawn
     * attacks ONLY diagonally (no forward push) and target's occupant is
     * irrelevant — we're computing attack maps, not legal moves.
     */
    private function pieceAttacks(int $from, int $to): bool
    {
        if ($from === $to) return false;
        $p = $this->board[$from];
        if ($p === '') return false;
        $ff = $from % 8; $fr = intdiv($from, 8);
        $tf = $to % 8;   $tr = intdiv($to, 8);
        $df = $tf - $ff; $dr = $tr - $fr;
        $adf = abs($df); $adr = abs($dr);
        $piece = strtoupper($p);
        switch ($piece) {
            case 'N': return ($adf === 1 && $adr === 2) || ($adf === 2 && $adr === 1);
            case 'B': return $adf === $adr && $this->pathClear($from, $to);
            case 'R': return ($df === 0 || $dr === 0) && $this->pathClear($from, $to);
            case 'Q': return (($adf === $adr) || $df === 0 || $dr === 0) && $this->pathClear($from, $to);
            case 'K': return $adf <= 1 && $adr <= 1;
            case 'P':
                $isWhite = ctype_upper($p);
                $dir = $isWhite ? -1 : 1; // white pawns attack toward smaller rank index
                return $adf === 1 && $dr === $dir;
        }
        return false;
    }

    private function canReach(int $from, int $to, string $piece): bool
    {
        if ($from === $to) return false;
        $ff = $from % 8; $fr = intdiv($from, 8);
        $tf = $to % 8;   $tr = intdiv($to, 8);
        $df = $tf - $ff; $dr = $tr - $fr;
        $adf = abs($df); $adr = abs($dr);
        $target = $this->board[$to];
        $sameColour = $target !== '' && (ctype_upper($target) === ($this->turn === 'w'));
        if ($sameColour) return false;

        switch ($piece) {
            case 'N':
                return ($adf === 1 && $adr === 2) || ($adf === 2 && $adr === 1);

            case 'B':
                return $adf === $adr && $this->pathClear($from, $to);

            case 'R':
                return ($df === 0 || $dr === 0) && $this->pathClear($from, $to);

            case 'Q':
                return (($adf === $adr) || ($df === 0) || ($dr === 0)) && $this->pathClear($from, $to);

            case 'K':
                // Non-castling king move (castling handled separately).
                return $adf <= 1 && $adr <= 1;

            case 'P':
                // White pawns move toward smaller rank index (up the board);
                // black pawns toward larger rank index.
                $dir = $this->turn === 'w' ? -1 : 1;
                if ($df === 0 && $dr === $dir && $target === '') {
                    return true; // single push
                }
                $startRank = $this->turn === 'w' ? 6 : 1; // index-rank from top
                if ($df === 0 && $dr === 2 * $dir && $fr === $startRank
                    && $target === '' && $this->board[$from + 8 * $dir] === '') {
                    return true; // double push
                }
                if ($adf === 1 && $dr === $dir) {
                    if ($target !== '') return true; // capture
                    if ($this->epSquare === $to) return true; // en passant
                }
                return false;
        }
        return false;
    }

    private function pathClear(int $from, int $to): bool
    {
        $ff = $from % 8; $fr = intdiv($from, 8);
        $tf = $to % 8;   $tr = intdiv($to, 8);
        $sf = $tf <=> $ff;
        $sr = $tr <=> $fr;
        $f = $ff + $sf; $r = $fr + $sr;
        while ($f !== $tf || $r !== $tr) {
            if ($this->board[$r * 8 + $f] !== '') return false;
            $f += $sf; $r += $sr;
        }
        return true;
    }

    private static function matchDisambig(int $idx, string $disambig): bool
    {
        if ($disambig === '') return true;
        $file = $idx % 8;
        $rank = 8 - intdiv($idx, 8); // 1..8
        if (strlen($disambig) === 1) {
            $c = $disambig[0];
            if (ctype_alpha($c)) return $file === ord($c) - ord('a');
            if (ctype_digit($c)) return $rank === (int) $c;
            return false;
        }
        // Two-char disambig: file+rank → exact origin square
        return self::nameToIdx($disambig) === $idx;
    }

    // ------------------------------------------------------------------
    // Move application
    // ------------------------------------------------------------------

    private function doMove(int $from, int $to, ?string $promotion): void
    {
        $piece = $this->board[$from];
        $captured = $this->board[$to];
        $isPawn = strtoupper($piece) === 'P';
        $isCapture = $captured !== '';

        // En passant capture: target square is empty but we still capture
        // the pawn one rank back.
        $epCapture = $isPawn && $to === $this->epSquare && !$isCapture;
        if ($epCapture) {
            $epPawnIdx = $to + ($this->turn === 'w' ? 8 : -8);
            $captured = $this->board[$epPawnIdx];
            $this->board[$epPawnIdx] = '';
            $isCapture = true;
        }

        // Move the piece (with optional promotion).
        $this->board[$to] = $promotion !== null
            ? ($this->turn === 'w' ? $promotion : strtolower($promotion))
            : $piece;
        $this->board[$from] = '';

        // Update castling rights — any king or rook leaving its home square
        // forfeits the relevant rights. Same for a rook being captured on
        // its home square.
        $this->updateCastlingRights($from, $to, $piece, $captured);

        // En passant target: set only on a pawn double-push, cleared otherwise.
        if ($isPawn && abs($to - $from) === 16) {
            $this->epSquare = ($from + $to) >> 1;
        } else {
            $this->epSquare = null;
        }

        // Halfmove clock: resets on capture or pawn move, increments otherwise.
        if ($isCapture || $isPawn) $this->halfmove = 0;
        else $this->halfmove++;

        if ($this->turn === 'b') $this->fullmove++;
        $this->turn = $this->turn === 'w' ? 'b' : 'w';

        // Record UCI.
        $uci = self::idxToName($from) . self::idxToName($to);
        if ($promotion !== null) $uci .= strtolower($promotion);
        $this->uciHistory[] = $uci;
    }

    private function doCastle(bool $kingside): void
    {
        $rank = $this->turn === 'w' ? 7 : 0; // 7 = rank 1, 0 = rank 8
        $kingFrom = $rank * 8 + 4;
        $kingTo   = $kingside ? $rank * 8 + 6 : $rank * 8 + 2;
        $rookFrom = $kingside ? $rank * 8 + 7 : $rank * 8 + 0;
        $rookTo   = $kingside ? $rank * 8 + 5 : $rank * 8 + 3;

        $king = $this->board[$kingFrom];
        $rook = $this->board[$rookFrom];
        $this->board[$kingFrom] = $this->board[$rookFrom] = '';
        $this->board[$kingTo] = $king;
        $this->board[$rookTo] = $rook;

        // Drop both castling rights for the side that just castled.
        $this->castling = preg_replace(
            $this->turn === 'w' ? '/[KQ]/' : '/[kq]/',
            '', $this->castling
        );

        $this->epSquare = null;
        $this->halfmove++;
        if ($this->turn === 'b') $this->fullmove++;
        $this->turn = $this->turn === 'w' ? 'b' : 'w';

        $this->uciHistory[] = self::idxToName($kingFrom) . self::idxToName($kingTo);
    }

    private function updateCastlingRights(int $from, int $to, string $piece, string $captured): void
    {
        // King moved → drop both rights for that colour.
        if ($piece === 'K') $this->castling = str_replace(['K', 'Q'], '', $this->castling);
        if ($piece === 'k') $this->castling = str_replace(['k', 'q'], '', $this->castling);

        // Rook moved off its home square → drop the matching side's right.
        $homeMap = [56 => 'Q', 63 => 'K', 0 => 'q', 7 => 'k'];
        if (isset($homeMap[$from]) && strtoupper($piece) === 'R') {
            $this->castling = str_replace($homeMap[$from], '', $this->castling);
        }
        // A rook captured on its home square also forfeits that right.
        if (isset($homeMap[$to]) && $captured !== '' && strtoupper($captured) === 'R') {
            $this->castling = str_replace($homeMap[$to], '', $this->castling);
        }
    }

    // ------------------------------------------------------------------
    // FEN load / square name helpers
    // ------------------------------------------------------------------

    private function loadFen(string $fen): void
    {
        $parts = explode(' ', trim($fen));
        if (count($parts) < 4) throw new RuntimeException("Invalid FEN: $fen");
        [$placement, $turn, $castling, $ep] = $parts;
        $half = $parts[4] ?? '0';
        $full = $parts[5] ?? '1';

        $this->board = array_fill(0, 64, '');
        $idx = 0;
        foreach (explode('/', $placement) as $rank) {
            foreach (str_split($rank) as $c) {
                if (ctype_digit($c)) $idx += (int) $c;
                else $this->board[$idx++] = $c;
            }
        }
        $this->turn = $turn;
        $this->castling = $castling === '-' ? '' : $castling;
        $this->epSquare = $ep === '-' ? null : self::nameToIdx($ep);
        $this->halfmove = (int) $half;
        $this->fullmove = (int) $full;
        $this->uciHistory = [];
    }

    private static function nameToIdx(string $name): int
    {
        $f = ord($name[0]) - ord('a');
        $r = (int) $name[1];
        return (8 - $r) * 8 + $f;
    }

    private static function idxToName(int $idx): string
    {
        $f = $idx % 8;
        $r = 8 - intdiv($idx, 8);
        return chr(ord('a') + $f) . $r;
    }
}
