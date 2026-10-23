import { test } from 'node:test';
import assert from 'node:assert/strict';
import { guessCastling, sanTokens, tryMove, looksLikeMoves, shortName, movesLabel, fold, norm, gamePgn, plain, label, moveNoOf } from '../../public/lib/helpers.js';

test('guessCastling: rights follow king and rook on their first squares', () => {
  assert.equal(guessCastling('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'), 'KQkq');
  assert.equal(guessCastling('r3k2r/8/8/8/8/8/8/R3K2R'), 'KQkq');
  assert.equal(guessCastling('r3k3/8/8/8/8/8/8/4K2R'), 'Kq');
  assert.equal(guessCastling('4k3/8/8/8/8/8/8/4K3'), '-');
  assert.equal(guessCastling('rnbqkbnr/pppppppp/8/8/4P3/5N2/PPPP1PPP/RNBQKB1R'), 'KQkq');
  assert.equal(guessCastling('rnbq1bnr/pppkpppp/8/8/8/8/PPPPPPPP/RNBQKBNR'), 'KQ', 'king moved off e8');
});


test('sanTokens: PGN furniture is removed, only moves stay', () => {
  assert.deepEqual(sanTokens('1. e4 e5 2. Nf3 Nc6'), ['e4', 'e5', 'Nf3', 'Nc6']);
  assert.deepEqual(sanTokens('1.e4 e5 2.Nf3'), ['e4', 'e5', 'Nf3']);
  assert.deepEqual(sanTokens('1. e4 {best by test} e5 (1... c5 (1... e6) 2. Nf3) 2. Nf3 $1 *'), ['e4', 'e5', 'Nf3']);
  assert.deepEqual(sanTokens('[Event "X"]\n[Result "1-0"]\n\n1. d4 d5 ; a comment\n2. c4 1-0'), ['d4', 'd5', 'c4']);
  assert.deepEqual(sanTokens('1. e4! e5?! 2. Nf3?? Nc6!!'), ['e4', 'e5', 'Nf3', 'Nc6']);
  assert.deepEqual(sanTokens('1... e5 2. Nf3'), ['e5', 'Nf3']);
  assert.deepEqual(sanTokens('0-1'), []);
  assert.deepEqual(sanTokens('1/2-1/2'), []);
  assert.deepEqual(sanTokens('½-½'), []);
  assert.deepEqual(sanTokens(''), []);
  assert.deepEqual(sanTokens('   \n  '), []);
});

test('sanTokens: zero castling and Russian notation', () => {
  assert.deepEqual(sanTokens('0-0 0-0-0'), ['O-O', 'O-O-O']);
  assert.deepEqual(sanTokens('0-0+'), ['O-O+']);
  // Cyrillic lookalikes for files and "x"; Russian piece letters at the start of a move.
  assert.deepEqual(sanTokens('1. е4 е5 2. Кf3 Кс6'), ['e4', 'e5', 'Nf3', 'Nc6']);
  assert.deepEqual(sanTokens('3. Сb5 Фd7 Лe1 Крf1'), ['Bb5', 'Qd7', 'Re1', 'Kf1']);
  assert.deepEqual(sanTokens('4. exd5 ехd5'), ['exd5', 'exd5']);
  assert.deepEqual(sanTokens('О-О'), ['O-O']);
});

test('shortName: the part of the name after the parent\'s name', () => {
  assert.equal(shortName('Sicilian Defense: Najdorf Variation', 'Sicilian Defense'), 'Najdorf Variation');
  assert.equal(shortName('Sicilian Defense: Najdorf Variation, English Attack', 'Sicilian Defense: Najdorf Variation'), 'English Attack');
  assert.equal(shortName('Vienna Gambit, with Max Lange Defense', 'Vienna Gambit'), 'with Max Lange Defense');
  assert.equal(shortName('Caro-Kann Defense', 'Caro-Kann Defense'), 'Caro-Kann Defense', 'same name, no separator');
  assert.equal(shortName('Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Najdorf Variation'), 'Najdorf Variation', 'same name: the last part');
  assert.equal(shortName('Italian Game', 'Ruy Lopez'), 'Italian Game', 'unrelated parent');
  assert.equal(shortName('Italian Game', ''), 'Italian Game');
  assert.equal(shortName('Italian Game', null), 'Italian Game');
  assert.equal(shortName('Sicilian Defense Extra: X', 'Sicilian Defense'), 'Sicilian Defense Extra: X', 'a longer word is not the parent');
});

test('movesLabel: plies to full moves, same as Opening::movesLabel in PHP', () => {
  assert.equal(movesLabel(0), '0 moves');
  assert.equal(movesLabel(1), '1 move');
  assert.equal(movesLabel(2), '1 move');
  assert.equal(movesLabel(3), '2 moves');
  assert.equal(movesLabel(20), '10 moves');
});

test('fold: accents and apostrophes gone, lower case', () => {
  assert.equal(fold("Queen's Gambit"), 'queens gambit');
  assert.equal(fold('Queen’s Gambit'), 'queens gambit');
  assert.equal(fold('Grünfeld Défense'), 'grunfeld defense');
  assert.equal(fold(''), '');
});

test('norm: lower case, accents folded, punctuation to single spaces', () => {
  assert.equal(norm('Grünfeld Defense: Lutikov Gambit (D80)'), 'grunfeld defense lutikov gambit d80');
  assert.equal(norm("  King's   Gambit  "), 'king s gambit');
  assert.equal(norm('---'), '');
  assert.equal(norm(''), '');
});

test('gamePgn: move numbers before White\'s moves only', () => {
  assert.equal(gamePgn([]), '');
  assert.equal(gamePgn(['e4']), '1. e4');
  assert.equal(gamePgn(['e4', 'e5', 'Nf3']), '1. e4 e5 2. Nf3');
  assert.equal(gamePgn(['e4', 'e5', 'Nf3', 'Nc6']), '1. e4 e5 2. Nf3 Nc6');
});

test('plain and label', () => {
  assert.equal(plain('Nf3+'), 'Nf3');
  assert.equal(plain('Qxf7#'), 'Qxf7');
  assert.equal(plain('e4!?'), 'e4');
  assert.equal(plain('O-O'), 'O-O');
  assert.equal(label(0, 'e4'), '1. e4');
  assert.equal(label(1, 'e5'), '1…e5');
  assert.equal(label(4, 'Bc4'), '3. Bc4');
  assert.equal(label(5, 'Bc5'), '3…Bc5');
});

test('moveNoOf: "3. " for White, "3… " for Black', () => {
  assert.equal(moveNoOf(0), '1. ');
  assert.equal(moveNoOf(1), '1… ');
  assert.equal(moveNoOf(4), '3. ');
  assert.equal(moveNoOf(5), '3… ');
});

test('looksLikeMoves: only whole queries made of SAN moves count', () => {
  assert.equal(looksLikeMoves('e4 c5 Nf3 d6'), true);
  assert.equal(looksLikeMoves('1.e4 e5 2.Nf3'), true);
  assert.equal(looksLikeMoves('e4'), true);
  assert.equal(looksLikeMoves('1. e4'), true);
  assert.equal(looksLikeMoves('O-O-O'), false, 'no digit or square to start from');
  assert.equal(looksLikeMoves('najdorf'), false);
  assert.equal(looksLikeMoves('sicilian 1.e4'), false);
  assert.equal(looksLikeMoves('b4'), true);
});

test('tryMove: lower-case piece letters work, "b" first as a pawn move', async () => {
  const { Chess } = await import('../../vendor/chess.min.js');
  const c = new Chess();
  assert.equal(tryMove(c, 'e4').san, 'e4');
  assert.equal(tryMove(c, 'e5').san, 'e5');
  assert.equal(tryMove(c, 'nf3').san, 'Nf3');
  assert.equal(tryMove(c, 'nc6').san, 'Nc6');
  assert.equal(tryMove(c, 'bb5').san, 'Bb5');
  assert.equal(tryMove(c, 'Ke4'), null);
});
