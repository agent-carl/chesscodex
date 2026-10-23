// Every kind of page loads without console errors, exceptions or failed
// same-origin requests. Needs the local site: SMOKE_BASE=http://127.0.0.1:8099
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { launch, newPage, go, sleep } from './helpers.mjs';

// The pages tests/SmokeTest.php visits, plus one of each other kind.
const PAGES = [
  '/', '/about', '/openings', '/openings/letter/s', '/best-openings-for-white', '/gambits', '/popular-openings',
  '/rankings', '/opening-trends', '/eco', '/eco/B90', '/openings/sicilian-defense', '/openings/zaire-defense',
  '/search', '/play/sicilian-defense', '/train', '/train/sicilian-defense', '/repertoire',
  '/admin/login',
];

let browser;
before(async () => { browser = await launch(); });
after(async () => { await browser.close(); });

for (const path of PAGES) {
  test(`${path}: loads without console errors or failed requests`, async () => {
    const page = await newPage(browser);
    try {
      const res = await go(page, path);
      assert.equal(res.status(), 200);
      await page.waitForSelector('main, form');
      await sleep(path.startsWith('/play') ? 2500 : 600);
      assert.deepEqual(page.problems, []);
    } finally {
      await page.close();
    }
  });
}

test('a 404 page still has the theme toggle (site-wide scripts run)', async () => {
  const page = await newPage(browser);
  try {
    const res = await go(page, '/no-such-page');
    assert.equal(res.status(), 404);
    assert.equal(await page.$eval('#theme-toggle', (b) => b.getAttribute('aria-pressed')), 'false');
  } finally {
    await page.close();
  }
});
