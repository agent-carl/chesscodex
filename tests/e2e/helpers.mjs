// Shared by tests/e2e/*.test.mjs: the base URL (local servers only), the
// installed Microsoft Edge driven through puppeteer-core, and small page helpers.
import { existsSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

export const BASE = (process.env.SMOKE_BASE || 'http://127.0.0.1:8099').replace(/\/$/, '');
const host = new URL(BASE).hostname;
if (host !== '127.0.0.1' && host !== 'localhost') {
  throw new Error(`tests/e2e drive a real browser and write to localStorage: SMOKE_BASE must be 127.0.0.1 or localhost, got ${host}`);
}

const EDGE_PATHS = [
  process.env.EDGE_PATH,
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/microsoft-edge',
  '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
].filter(Boolean);

export function launch() {
  const executablePath = EDGE_PATHS.find((p) => existsSync(p));
  if (!executablePath) throw new Error('Microsoft Edge not found; set EDGE_PATH');
  return puppeteer.launch({ executablePath, headless: true, args: ['--disable-gpu'] });
}

// A fresh browser context (own localStorage, light system theme) with a page that records console
// errors, uncaught exceptions and failed or >= 400 same-origin responses.
export async function newPage(browser, { width = 1280, height = 900 } = {}) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width, height });
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
  page.problems = [];
  page.on('console', (m) => { if (m.type() === 'error') page.problems.push('console: ' + m.text()); });
  page.on('pageerror', (e) => page.problems.push('exception: ' + e.message));
  page.on('requestfailed', (r) => {
    if (r.url().startsWith(BASE) && r.failure()?.errorText !== 'net::ERR_ABORTED') page.problems.push(`request failed: ${r.url()} ${r.failure()?.errorText}`);
  });
  page.on('response', (r) => { if (r.url().startsWith(BASE) && r.status() >= 400) page.problems.push(`HTTP ${r.status()}: ${r.url()}`); });
  page.close = ((close) => async () => { await close(); await context.close(); })(page.close.bind(page));
  return page;
}

export const go = (page, path) => page.goto(BASE + path, { waitUntil: 'load' });

// Pieces of a chessground board as { e2: 'white pawn', ... }.
export const pieces = (page, boardSel) => page.$eval(boardSel, (el) => {
  const out = {};
  el.querySelectorAll('cg-board piece').forEach((p) => { if (p.cgKey) out[p.cgKey] = p.className.replace(/\b(dragging|anim|fading|ghost)\b/g, '').trim(); });
  return out;
});

// Click a square of a chessground board (click to select, click to move).
export async function clickSquare(page, boardSel, square) {
  await page.waitForSelector(`${boardSel} cg-board piece`);
  const { x, y, w, h, black } = await page.$eval(boardSel, (el) => {
    const r = el.querySelector('cg-board').getBoundingClientRect();
    return { x: r.x, y: r.y, w: r.width, h: r.height, black: el.classList.contains('orientation-black') };
  });
  const file = 'abcdefgh'.indexOf(square[0]);
  const rank = Number(square[1]) - 1;
  const col = black ? 7 - file : file;
  const row = black ? rank : 7 - rank;
  await page.mouse.click(x + (col + 0.5) * w / 8, y + (row + 0.5) * h / 8);
}

export async function movePiece(page, boardSel, from, to) {
  await clickSquare(page, boardSel, from);
  await clickSquare(page, boardSel, to);
}

// Piece placement of a chess.js game in the same shape as pieces().
const NAMES = { p: 'pawn', n: 'knight', b: 'bishop', r: 'rook', q: 'queen', k: 'king' };
export const expectedPieces = (chess) => Object.fromEntries(
  chess.board().flat().filter(Boolean).map((s) => [s.square, `${s.color === 'w' ? 'white' : 'black'} ${NAMES[s.type]}`]));

// Wait until the board shows exactly that placement (animations take a moment).
export async function expectBoard(page, boardSel, chess, timeout = 5000) {
  const want = expectedPieces(chess);
  const deadline = Date.now() + timeout;
  let got;
  do {
    got = await pieces(page, boardSel);
    if (JSON.stringify(Object.entries(got).sort()) === JSON.stringify(Object.entries(want).sort())) return;
    await sleep(100);
  } while (Date.now() < deadline);
  throw new Error(`board ${boardSel} shows ${JSON.stringify(got)}, expected ${JSON.stringify(want)}`);
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
