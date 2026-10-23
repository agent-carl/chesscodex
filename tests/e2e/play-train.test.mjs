// Trainer (/train/<slug>) and the play page (/play/<slug>), which loads Stockfish in a worker.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../vendor/chess.min.js';
import { launch, newPage, go, expectBoard, movePiece, sleep } from './helpers.mjs';

let browser;
before(async () => { browser = await launch(); });
after(async () => { await browser.close(); });

const status = (page) => page.$eval('#train-status', (e) => e.textContent);

test('trainer: a correct move is accepted, a wrong one is rejected', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/train/sicilian-defense-najdorf-variation');
    const cfg = await page.$eval('#train-data', (e) => JSON.parse(e.textContent));
    const line = new Chess();
    line.load_pgn(cfg.lines[0].pgn, { sloppy: true });
    const sans = line.history();
    const mine = (i) => (i % 2 === 0) === (cfg.color === 'white');
    const first = sans.findIndex((_, i) => mine(i));
    assert.ok(first >= 0 && first < sans.length - 1, 'the line has a move for the user with another after it');

    // The opponent's moves before the user's first one are played for them.
    const game = new Chess();
    sans.slice(0, first).forEach((s) => game.move(s));
    await expectBoard(page, '#train-board', game);
    assert.match(await status(page), /^You play /);

    // A wrong move: any legal move but the line's own.
    const wrong = game.moves({ verbose: true }).find((m) => m.san.replace(/[+#]/g, '') !== sans[first].replace(/[+#]/g, ''));
    await movePiece(page, '#train-board', wrong.from, wrong.to);
    await page.waitForFunction(() => /not this line/.test(document.getElementById('train-status').textContent));
    await expectBoard(page, '#train-board', game);

    // The right one.
    const right = game.move(sans[first]);
    await movePiece(page, '#train-board', right.from, right.to);
    await page.waitForFunction(() => document.getElementById('train-status').textContent === 'Right.');
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});

test('trainer: "Show move" names the next move of the line', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/train/sicilian-defense');
    await page.waitForFunction(() => /^You play/.test(document.getElementById('train-status').textContent));
    await sleep(600); // the opponent's first move is played after 350 ms
    await page.click('#train-hint');
    await page.waitForFunction(() => /^The move is /.test(document.getElementById('train-status').textContent));
  } finally { await page.close(); }
});

test('play: a move on the board shows in the game list and the Lichess link', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/play/sicilian-defense');
    await page.waitForSelector('.play-engine-state[data-state="ready"]', { timeout: 20000 });
    // The Sicilian is Black's opening: the page puts the user on Black and the engine moves first.
    assert.ok(await page.$eval('#play-board', (e) => e.classList.contains('orientation-black')));
    await page.waitForFunction(() => document.querySelectorAll('#play-moves li:last-child span:not(.move-no)').length >= 1
      && document.querySelectorAll('#play-moves .move-book').length === 2
      && document.querySelectorAll('#play-moves li').length >= 2);
    const before = await page.$$eval('#play-moves li span:not(.move-no)', (s) => s.map((x) => x.textContent));
    assert.deepEqual(before.slice(0, 2), ['e4', 'c5']);
    assert.equal(before.length, 3, 'one engine move after the book line');

    await movePiece(page, '#play-board', 'd7', 'd6');
    await page.waitForFunction(() => [...document.querySelectorAll('#play-moves li span:not(.move-no)')].some((s) => s.textContent === 'd6'));
    const after = await page.$$eval('#play-moves li span:not(.move-no)', (s) => s.map((x) => x.textContent));
    assert.equal(after[3], 'd6');
    assert.ok((await page.$eval('#play-lichess', (a) => a.href)).includes('d6'));

    await page.click('#play-new');
    await page.waitForFunction(() => document.querySelectorAll('#play-moves li span:not(.move-no)').length <= 3);
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});
