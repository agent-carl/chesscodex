// Site-wide and list pages: theme toggle, service worker, repertoire, gambits filter, year charts.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { BASE, launch, newPage, go } from './helpers.mjs';

let browser;
before(async () => { browser = await launch(); });
after(async () => { await browser.close(); });

const theme = (page) => page.evaluate(() => document.documentElement.dataset.theme);
const pressed = (page) => page.$eval('#theme-toggle', (b) => b.getAttribute('aria-pressed'));

test('theme toggle switches and the choice persists across a reload', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/about');
    assert.equal(await theme(page), 'light');
    assert.equal(await pressed(page), 'false');
    await page.click('#theme-toggle');
    assert.equal(await theme(page), 'dark');
    assert.equal(await pressed(page), 'true');
    await page.reload({ waitUntil: 'load' });
    assert.equal(await theme(page), 'dark');
    assert.equal(await pressed(page), 'true');
    await go(page, '/openings');
    assert.equal(await theme(page), 'dark');
    // Picking the system's own theme again means "follow the system": nothing is stored.
    await page.click('#theme-toggle');
    assert.equal(await theme(page), 'light');
    assert.equal(await page.evaluate(() => localStorage.getItem('codex-theme')), null);
  } finally { await page.close(); }
});

test('the saved theme is applied before the first paint', async () => {
  const page = await newPage(browser);
  try {
    await page.evaluateOnNewDocument(() => { try { localStorage.setItem('codex-theme', 'dark'); } catch {} });
    await page.goto(BASE + '/about', { waitUntil: 'domcontentloaded' });
    assert.equal(await theme(page), 'dark');
  } finally { await page.close(); }
});

test('the service worker registers without error', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/');
    const reg = await page.evaluate(async () => {
      const r = await navigator.serviceWorker.ready;
      return { scope: r.scope, script: (r.active || r.installing || r.waiting).scriptURL };
    });
    assert.ok(reg.script.endsWith('/public/sw.js'), reg.script);
    assert.ok(reg.scope.endsWith('/'));
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});

test('repertoire: add a line on an opening page, see it, remove it', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/openings/sicilian-defense');
    const btn = '.repertoire-toggle [data-repertoire-side="black"]';
    assert.match(await page.$eval(btn, (b) => b.textContent), /^\+ Black/);
    await page.click(btn);
    assert.match(await page.$eval(btn, (b) => b.textContent), /^✓ Black/);
    assert.equal(await page.$eval(btn, (b) => b.getAttribute('aria-pressed')), 'true');

    await go(page, '/repertoire');
    const list = '.repertoire-side[data-side="black"]';
    assert.equal(await page.$$eval(`${list} .repertoire-list li`, (l) => l.length), 1);
    assert.match(await page.$eval(`${list} .repertoire-list`, (l) => l.textContent), /Sicilian Defense/);
    assert.equal(await page.$eval(`${list} .repertoire-count`, (e) => e.textContent), '(1)');
    assert.match(await page.$eval(`${list} [data-repertoire-train]`, (a) => a.href), /\/train\/sicilian-defense\?from=repertoire&side=black$/);

    await page.click(`${list} .repertoire-remove`);
    assert.equal(await page.$$eval(`${list} .repertoire-list li`, (l) => l.length), 0);
    assert.equal(await page.$eval(`${list} .repertoire-empty`, (e) => e.hidden), false);
    assert.equal(await page.$eval(`${list} .repertoire-actions`, (e) => e.hidden), true);
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});

test('gambits: the filter narrows the list and says how many remain', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/gambits');
    assert.equal(await page.$eval('.gambits-filter', (b) => b.hidden), false);
    const visible = () => page.$$eval('#all-gambits li:not([hidden])', (l) => l.length);
    const all = await visible();
    assert.ok(all > 20);
    await page.type('#gambits-q', 'gambit declined');
    const some = await visible();
    assert.ok(some > 0 && some < all, `${some} of ${all}`);
    assert.match(await page.$eval('.gambits-filter-count', (e) => e.textContent), new RegExp(`^${some} of ${all.toLocaleString('en')}$`));
    await page.$eval('#gambits-q', (i) => { i.value = ''; });
    await page.type('#gambits-q', 'zzzzqq');
    assert.equal(await visible(), 0);
    assert.equal(await page.$eval('.gambits-filter-empty', (e) => e.hidden), false);
    await page.$eval('#gambits-q', (i) => { i.value = ''; i.dispatchEvent(new Event('input')); });
    assert.equal(await visible(), all);
  } finally { await page.close(); }
});

test('year charts: hovering a chart shows its tooltip', async () => {
  const page = await newPage(browser);
  try {
    await go(page, '/opening-trends');
    await page.hover('.trend .trend-plot');
    await page.waitForFunction(() => { const t = document.querySelector('.trend .trend-tip'); return t && !t.hidden && t.textContent.trim() !== ''; });
    assert.deepEqual(page.problems, []);
  } finally { await page.close(); }
});
