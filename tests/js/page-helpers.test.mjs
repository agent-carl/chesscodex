import { test } from 'node:test';
import assert from 'node:assert/strict';
import { helper } from './extract.mjs';

test('search.js guessCastling: rights follow king and rook on their first squares', () => {
  const guessCastling = helper('search.js', 'guessCastling');
  assert.equal(guessCastling('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'), 'KQkq');
  assert.equal(guessCastling('r3k2r/8/8/8/8/8/8/R3K2R'), 'KQkq');
  assert.equal(guessCastling('r3k3/8/8/8/8/8/8/4K2R'), 'Kq');
  assert.equal(guessCastling('4k3/8/8/8/8/8/8/4K3'), '-');
  assert.equal(guessCastling('rnbqkbnr/pppppppp/8/8/4P3/5N2/PPPP1PPP/RNBQKB1R'), 'KQkq');
  assert.equal(guessCastling('rnbq1bnr/pppkpppp/8/8/8/8/PPPPPPPP/RNBQKBNR'), 'KQ', 'king moved off e8');
});

const LOOK = ['LOOKALIKES', 'RU_PIECES'];

test('search.js sanTokens: PGN furniture is removed, only moves stay', () => {
  const sanTokens = helper('search.js', 'sanTokens', LOOK);
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

test('search.js sanTokens: zero castling and Russian notation', () => {
  const sanTokens = helper('search.js', 'sanTokens', LOOK);
  assert.deepEqual(sanTokens('0-0 0-0-0'), ['O-O', 'O-O-O']);
  assert.deepEqual(sanTokens('0-0+'), ['O-O+']);
  // Cyrillic lookalikes for files and "x"; Russian piece letters at the start of a move.
  assert.deepEqual(sanTokens('1. е4 е5 2. Кf3 Кс6'), ['e4', 'e5', 'Nf3', 'Nc6']);
  assert.deepEqual(sanTokens('3. Сb5 Фd7 Лe1 Крf1'), ['Bb5', 'Qd7', 'Re1', 'Kf1']);
  assert.deepEqual(sanTokens('4. exd5 ехd5'), ['exd5', 'exd5']);
  assert.deepEqual(sanTokens('О-О'), ['O-O']);
});

test('opening.js shortName: the part of the name after the parent\'s name', () => {
  const shortName = helper('opening.js', 'shortName');
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

test('opening.js movesLabel: plies to full moves, same as Opening::movesLabel in PHP', () => {
  const movesLabel = helper('opening.js', 'movesLabel');
  assert.equal(movesLabel(0), '0 moves');
  assert.equal(movesLabel(1), '1 move');
  assert.equal(movesLabel(2), '1 move');
  assert.equal(movesLabel(3), '2 moves');
  assert.equal(movesLabel(20), '10 moves');
});

test('opening.js fold: accents and apostrophes gone, lower case', () => {
  const fold = helper('opening.js', 'fold');
  assert.equal(fold("Queen's Gambit"), 'queens gambit');
  assert.equal(fold('Queen’s Gambit'), 'queens gambit');
  assert.equal(fold('Grünfeld Défense'), 'grunfeld defense');
  assert.equal(fold(''), '');
});

test('gambits.js norm: lower case, accents folded, punctuation to single spaces', () => {
  const norm = helper('gambits.js', 'norm');
  assert.equal(norm('Grünfeld Defense: Lutikov Gambit (D80)'), 'grunfeld defense lutikov gambit d80');
  assert.equal(norm("  King's   Gambit  "), 'king s gambit');
  assert.equal(norm('---'), '');
  assert.equal(norm(''), '');
});

test('play.js gamePgn: move numbers before White\'s moves only', () => {
  const gamePgn = helper('play.js', 'gamePgn');
  assert.equal(gamePgn([]), '');
  assert.equal(gamePgn(['e4']), '1. e4');
  assert.equal(gamePgn(['e4', 'e5', 'Nf3']), '1. e4 e5 2. Nf3');
  assert.equal(gamePgn(['e4', 'e5', 'Nf3', 'Nc6']), '1. e4 e5 2. Nf3 Nc6');
});

test('train.js plain and label', () => {
  const plain = helper('train.js', 'plain');
  const label = helper('train.js', 'label');
  assert.equal(plain('Nf3+'), 'Nf3');
  assert.equal(plain('Qxf7#'), 'Qxf7');
  assert.equal(plain('e4!?'), 'e4');
  assert.equal(plain('O-O'), 'O-O');
  assert.equal(label(0, 'e4'), '1. e4');
  assert.equal(label(1, 'e5'), '1…e5');
  assert.equal(label(4, 'Bc4'), '3. Bc4');
  assert.equal(label(5, 'Bc5'), '3…Bc5');
});

test('app.js moveNoOf: "3. " for White, "3… " for Black', () => {
  const moveNoOf = helper('app.js', 'moveNoOf');
  assert.equal(moveNoOf(0), '1. ');
  assert.equal(moveNoOf(1), '1… ');
  assert.equal(moveNoOf(4), '3. ');
  assert.equal(moveNoOf(5), '3… ');
});
