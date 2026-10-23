// Opening page: board, move controls, keyboard, move list, stats, sub-variation tree.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../vendor/chess.min.js';
import { launch, newPage, go, expectBoard } from './helpers.mjs';

let browser;
before(async () => { browser = await launch(); });
after(async () => { await browser.close(); });

// Sicilian Defense is 1.e4 c5: three positions (start, after e4, after c5).
const at = (...sans) => { const c = new Chess(); sans.forEach((s) => c.move(s)); return c; };
const active = (page) => page.$$eval('#move-list button.is-active', (bs) => bs.map((b) => b.textContent));

test('the board renders the final position and the move list marks the last move', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    await expectBoard(page, '#board', at('e4', 'c5'));
    assert.deepEqual(await page.$$eval('#move-list button', (bs) => bs.map((b) => b.textContent)), ['e4', 'c5']);
    assert.deepEqual(await active(page), ['c5']);
    assert.equal(await page.$eval('#board-next', (b) => b.disabled), true);
    assert.equal(await page.$eval('#board-prev', (b) => b.disabled), false);
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});

test('previous, next, first and the move list change the position', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    await page.click('#board-prev');
    await expectBoard(page, '#board', at('e4'));
    assert.deepEqual(await active(page), ['e4']);
    assert.equal(await page.$eval('#board-next', (b) => b.disabled), false);

    await page.click('#board-next');
    await expectBoard(page, '#board', at('e4', 'c5'));
    assert.deepEqual(await active(page), ['c5']);

    await page.click('#board-reset');
    await expectBoard(page, '#board', new Chess());
    assert.deepEqual(await active(page), []);
    assert.equal(await page.$eval('#board-prev', (b) => b.disabled), true);

    await page.click('#move-list button[data-idx="2"]');
    await expectBoard(page, '#board', at('e4', 'c5'));
    await page.click('#move-list button[data-idx="1"]');
    await expectBoard(page, '#board', at('e4'));
    assert.deepEqual(await active(page), ['e4']);
  } finally { await page.close(); }
});

test('the left and right arrow keys step through the line', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    await page.keyboard.press('ArrowLeft');
    await expectBoard(page, '#board', at('e4'));
    await page.keyboard.press('ArrowLeft');
    await expectBoard(page, '#board', new Chess());
    await page.keyboard.press('ArrowRight');
    await expectBoard(page, '#board', at('e4'));
  } finally { await page.close(); }
});

test('the statistics and answers blocks are on the page', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    assert.ok(await page.$('#opening-stats .stats-bar'));
    await page.waitForFunction(() => document.querySelectorAll('#opening-stats .stats-moves tbody tr').length > 0);
    assert.ok(await page.$('#opening-answers-title'));
    assert.ok(await page.$('#the-numbers'));
  } finally { await page.close(); }
});

test('a move in the statistics table is shown on the board', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    await page.waitForSelector('#opening-stats .stats-move-btn');
    const san = await page.$eval('#opening-stats .stats-move-btn', (b) => b.dataset.san);
    await page.click('#opening-stats .stats-move-btn');
    await expectBoard(page, '#board', at('e4', 'c5', san));
  } finally { await page.close(); }
});

test('the sub-variation tree loads on first open and its filter narrows it', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    assert.ok(await page.$('.opening-subtree details[data-lazy]'), 'sicilian-defense has a lazy subtree');
    await page.$eval('.opening-subtree details[data-lazy] summary', (s) => s.click());
    await page.waitForFunction(() => document.querySelectorAll('.opening-subtree-list li').length > 5);
    const all = await page.$$eval('.opening-subtree-list li', (l) => l.length);
    await page.type('.opening-subtree-filter', 'najdorf');
    const shown = await page.$$eval('.opening-subtree-list li:not([hidden])', (l) => l.length);
    assert.ok(shown > 0 && shown < all, `${shown} of ${all}`);
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});

test('an opened opening appears under "pick up where you left off" on the home page', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/');
    assert.equal(await page.$eval('#home-recent', (s) => s.hidden), true);
    await go(page, '/openings/sicilian-defense');
    await go(page, '/');
    assert.equal(await page.$eval('#home-recent', (s) => s.hidden), false);
    assert.match(await page.$eval('#home-recent-list', (l) => l.textContent), /Sicilian Defense/);
    await page.click('#home-recent-clear');
    assert.equal(await page.$eval('#home-recent', (s) => s.hidden), true);
  } finally { await page.close(); }
});
