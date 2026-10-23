// Search: by name (suggestions, site-wide), by moves, by pasted PGN, by FEN, and the board.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../vendor/chess.min.js';
import { BASE, launch, newPage, go, expectBoard, movePiece } from './helpers.mjs';

let browser;
before(async () => { browser = await launch(); });
after(async () => { await browser.close(); });

const api = async (query) => (await fetch(`${BASE}/api/search?${query}`)).json();
const matchHref = (page) => page.$eval('#search-result .search-match-link', (a) => new URL(a.href).pathname);
const waitForMatch = (page) => page.waitForFunction(() => !document.querySelector('#search-result .search-match').hidden);

test('by name: typing "najdorf" lists suggestions that link to Najdorf openings', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await page.type('#search-name-input', 'najdorf');
    await page.waitForSelector('#search-name-results a[role="option"]');
    const hrefs = await page.$$eval('#search-name-results a[role="option"]', (as) => as.map((a) => new URL(a.href).pathname));
    assert.ok(hrefs.length > 0);
    assert.ok(hrefs.every((h) => h.startsWith('/openings/') && h.includes('najdorf')), hrefs.join(' '));
    // Enter follows the first suggestion.
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    assert.equal(new URL(page.url()).pathname, hrefs[0]);
  } finally { await page.close(); }
});

test('by name on the home page: suggestions, and "/" focuses the field', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/');
    await page.keyboard.press('/');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'hero-search-input');
    await page.keyboard.type('sicilian');
    await page.waitForSelector('#hero-search-results a[role="option"]');
    assert.ok(await page.$eval('#hero-search-results', (l) => !l.hidden));
    assert.equal(await page.$eval('#hero-search-input', (i) => i.getAttribute('aria-expanded')), 'true');
    await page.keyboard.press('Escape');
    assert.ok(await page.$eval('#hero-search-results', (l) => l.hidden));
  } finally { await page.close(); }
});

test('moves typed in the name field go to the identifier on /search', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/');
    await page.type('#hero-search-input', 'e4 c5 Nf3 d6');
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    const url = new URL(page.url());
    assert.equal(url.pathname, '/search');
    assert.equal(url.searchParams.get('moves'), 'e4 c5 Nf3 d6');
    await waitForMatch(page);
  } finally { await page.close(); }
});

test('by moves: "e4 c5 Nf3 d6" pasted and submitted finds the opening it names', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await page.type('#search-paste-input', 'e4 c5 Nf3 d6');
    await page.keyboard.press('Enter');
    await waitForMatch(page);
    const want = (await api('moves=' + encodeURIComponent('e4 c5 Nf3 d6'))).match;
    assert.ok(want, 'the API knows a match');
    assert.equal(await matchHref(page), '/openings/' + want.slug);
    assert.match(await page.$eval('#search-result .search-match-name', (e) => e.textContent), /Sicilian/);
    assert.equal(await page.$eval('#search-played-list', (e) => e.textContent), '1. e4 c5 2. Nf3 d6');
    await expectBoard(page, '#search-board', (() => { const c = new Chess(); ['e4', 'c5', 'Nf3', 'd6'].forEach((s) => c.move(s)); return c; })());
  } finally { await page.close(); }
});

test('by PGN: headers, numbers and comments are ignored', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await page.$eval('#search-paste-input', (t) => {
      t.value = '[Event "x"]\n\n1. e4 {best by test} e5 2. Nf3 Nc6 3. Bb5 a6 1-0';
    });
    await page.$eval('#search-paste-form', (f) => f.requestSubmit());
    await waitForMatch(page);
    assert.equal(await page.$eval('#search-played-list', (e) => e.textContent), '1. e4 e5 2. Nf3 Nc6 3. Bb5 a6');
    assert.match(await page.$eval('#search-result .search-match-name', (e) => e.textContent), /Ruy Lopez/);
  } finally { await page.close(); }
});

test('by FEN: the position finds the openings that reach it', async () => {
  const fen = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1';
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await page.click('.identify-fen summary');
    await page.type('#search-fen-input', fen);
    await page.$eval('#search-fen-form', (f) => f.requestSubmit());
    await waitForMatch(page);
    const want = (await api('fen=' + encodeURIComponent(fen))).matches[0];
    assert.equal(await matchHref(page), '/openings/' + want.slug);
    const c = new Chess(); c.move('e4');
    await expectBoard(page, '#search-board', c);
  } finally { await page.close(); }
});

test('a bad FEN shows an error instead of a result', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await page.click('.identify-fen summary');
    await page.type('#search-fen-input', 'not a fen');
    await page.$eval('#search-fen-form', (f) => f.requestSubmit());
    await page.waitForFunction(() => document.getElementById('search-result').dataset.state === 'error');
    assert.match(await page.$eval('#search-result .search-empty', (e) => e.textContent), /isn.t a valid FEN/);
  } finally { await page.close(); }
});

test('a move on the board is looked up; undo and reset step back', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/search');
    await movePiece(page, '#search-board', 'e2', 'e4');
    await page.waitForFunction(() => document.getElementById('search-result').dataset.state === 'exact');
    assert.equal(await page.$eval('#search-played-list', (e) => e.textContent), '1. e4');
    await page.click('#search-undo');
    await expectBoard(page, '#search-board', new Chess());
    assert.equal(await page.$eval('#search-played-list', (e) => e.hidden), true);
    await movePiece(page, '#search-board', 'd2', 'd4');
    await page.click('#search-reset');
    await expectBoard(page, '#search-board', new Chess());
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});
