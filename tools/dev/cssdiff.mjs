// Computed-style diff between two builds of the stylesheet, in headless Edge
// over the DevTools protocol. Proof for CSS refactors: "nothing renders
// differently".
//
//   node tools/dev/cssdiff.mjs --old OLD.css --new NEW.css [--port 8099]
//        [--root .dev/site/public] [--pages /,/about] [--quick]
//
// Needs the local site running (bash tools/dev/serve.sh, PORT=...). Both sheets
// are copied to <root>/style.old.css and style.new.css (the router serves
// /public/*) and swapped into each loaded page, so the DOM is identical.
//
// Checked: every page x theme (light/dark, from prefers-color-scheme, which
// theme.js follows) x viewport (desktop 1280, phone 390): the computed style of
// every element and of ::before/::after with content; then :hover and
// :focus-visible forced (CSS.forcePseudoState) on a sample of the interactive
// elements, comparing the element and its descendants; then print media on a
// few pages. Prefers-reduced-motion is on so transitions cannot leave a
// half-way style behind.
// Canary: a sheet with one changed property per kind of check must show up as a
// difference in each of them, or the run fails.
//
// Cannot see: states needing real input (:active, open <select>, :visited),
// JS-driven classes that no page load reaches, pages not in the list, and
// hover on an ancestor of the sampled element.
import { spawn } from 'node:child_process';
import { join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { rmSync, copyFileSync, readFileSync, writeFileSync } from 'node:fs';

const arg = (name, def) => { const i = process.argv.indexOf('--' + name); return i < 0 ? def : (process.argv[i + 1] ?? true); };
const PORT = Number(arg('port', process.env.PORT || 8099));
const BASE = `http://127.0.0.1:${PORT}`;
const CDP = PORT + 1000;
const ROOT = resolve(arg('root', '.dev/site/public'));
const OLD = arg('old'), NEW = arg('new');
if (!OLD || !NEW) { console.error('usage: node tools/dev/cssdiff.mjs --old OLD.css --new NEW.css [--port N]'); process.exit(2); }
const QUICK = process.argv.includes('--quick');

let pages = ['/', '/openings', '/openings/sicilian-defense', '/openings/zaire-defense', '/openings/letter/s',
  '/eco', '/eco/B90', '/gambits', '/popular-openings', '/best-openings-for-white', '/rankings',
  '/opening-trends', '/search', '/search?q=najdorf', '/search?moves=1.e4%20c5%202.Nf3',
  '/play/sicilian-defense', '/train', '/train/sicilian-defense', '/repertoire', '/about', '/no-such-page'];
if (arg('pages')) pages = String(arg('pages')).split(',');
const printPages = ['/', '/openings/sicilian-defense', '/openings/zaire-defense', '/play/sicilian-defense', '/train/sicilian-defense', '/search', '/about'];
const modes = [];
for (const scheme of ['light', 'dark']) {
  modes.push({ name: `desktop-${scheme}`, w: 1280, h: 900, mobile: false, scheme });
  modes.push({ name: `phone-${scheme}`, w: 390, h: 800, mobile: true, scheme });
}

copyFileSync(OLD, join(ROOT, 'style.old.css'));
copyFileSync(NEW, join(ROOT, 'style.new.css'));
// One changed property per kind of check; each must be reported as a difference.
writeFileSync(join(ROOT, 'style.canary.css'), readFileSync(NEW, 'utf8') +
  '\nbody{letter-spacing:3px}\na:hover,button:hover{outline:3px solid red}\n:focus-visible{outline-offset:9px}\n' +
  '@media print{.site-footer,footer{color:red}}\n');

const profile = join(tmpdir(), `cssdiff-profile-${PORT}`);
const edge = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ['--headless=new', `--remote-debugging-port=${CDP}`, `--user-data-dir=${profile}`, '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws;
for (let i = 0; i < 40 && !ws; i++) {
  await sleep(250);
  try { const p = (await (await fetch(`http://127.0.0.1:${CDP}/json`)).json()).find((t) => t.type === 'page'); if (p) ws = new WebSocket(p.webSocketDebuggerUrl); } catch {}
}
await new Promise((r) => ws.addEventListener('open', r));
let id = 0; const waiting = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } });
const send = (method, params = {}) => new Promise((r) => { const i = ++id; waiting.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true })).result.result.value;

const STYLE_FN = `function styleOf(el) { const out = []; const one = (e, pseudo) => { const cs = getComputedStyle(e, pseudo); let s = '';
    for (let i = 0; i < cs.length; i++) { const p = cs[i]; s += p + ':' + cs.getPropertyValue(p) + ';'; } return s; };
  const lab = (e) => (e.tagName + '.' + (e.getAttribute('class') || '')).slice(0, 80);
  out.push(lab(el) + '|' + one(el));
  for (const ps of ['::before', '::after']) { const c = getComputedStyle(el, ps).content; if (c && c !== 'none' && c !== 'normal') out.push(lab(el) + ps + '|' + one(el, ps)); }
  return out; }`;
const COLLECT = `(() => { ${STYLE_FN} const out = []; for (const el of document.querySelectorAll('body *')) out.push(...styleOf(el)); return out; })()`;
const SEL = 'a[href], button, input, select, textarea, summary, [tabindex], [role=button], label';
// Indices of up to 2 visible elements per tag+class signature, at most 70.
const SAMPLE = `(() => { const seen = {}; const idx = []; document.querySelectorAll('${SEL}').forEach((el, i) => {
  const r = el.getBoundingClientRect(); if (!r.width || !r.height) return;
  const k = el.tagName + '.' + el.className + '.' + (el.getAttribute('type') || ''); seen[k] = (seen[k] || 0) + 1;
  if (seen[k] <= 2 && idx.length < 70) idx.push(i); }); return idx; })()`;
const SUBTREE = `function () { ${STYLE_FN} const out = styleOf(this); let n = 0; for (const d of this.querySelectorAll('*')) { if (n++ > 60) break; out.push(...styleOf(d)); } return out; }`;
const swap = (file) => `new Promise((res) => { const l = document.querySelector('link[href*="style.min.css"],link[data-swapped]');
  l.setAttribute('data-swapped', '1'); l.onload = () => setTimeout(res, 300); l.href = '/public/${file}?' + Date.now(); })`;

async function stateStyles(nodeId, pseudo) {
  await send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [pseudo] });
  const r = await send('DOM.resolveNode', { nodeId });
  let v = [];
  if (r.result?.object) {
    const o = await send('Runtime.callFunctionOn', { objectId: r.result.object.objectId, functionDeclaration: SUBTREE, returnByValue: true });
    v = o.result?.result?.value ?? [];
    await send('Runtime.releaseObject', { objectId: r.result.object.objectId });
  }
  await send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] });
  return v;
}

// Everything checked for the page as loaded: base styles, then forced states.
async function collect(nodeIds, withStates) {
  const res = { base: await evalJs(COLLECT) };
  if (withStates) {
    for (const pseudo of ['hover', 'focus-visible']) {
      res[pseudo] = [];
      for (const n of nodeIds) res[pseudo].push(...await stateStyles(n, pseudo));
    }
  }
  return res;
}

function compare(a, b) {
  const out = {};
  for (const k of Object.keys(a)) {
    let diff = 0; const samples = [];
    if (a[k].length !== b[k].length) { diff = Math.abs(a[k].length - b[k].length); samples.push(`count ${a[k].length} vs ${b[k].length}`); }
    for (let i = 0; i < Math.min(a[k].length, b[k].length); i++) if (a[k][i] !== b[k][i]) {
      diff++;
      if (samples.length < 3) {
        const [lab, x] = a[k][i].split('|'); const y = b[k][i].split('|')[1];
        const xs = x.split(';'), ys = y.split(';');
        const props = xs.filter((p, j) => p !== ys[j]).slice(0, 3).map((p, j) => p + ' -> ' + ys[xs.indexOf(p)]);
        samples.push(lab + ' ' + props.join(' '));
      }
    }
    out[k] = { diff, n: a[k].length, samples };
  }
  return out;
}

async function load(path) {
  await send('Page.navigate', { url: BASE + path }); await sleep(2200);
}
// Compare sheet A against sheet B: each gets a fresh load, so page scripts and
// forced states leave both with the same history.
async function runPage(path, fileA, fileB, withStates) {
  let idx = null;
  await load(path); // warm-up: the first load of a page can measure text in a fallback font
  const one = async (file) => {
    await load(path);
    await evalJs(swap(file));
    await evalJs('document.fonts.ready.then(() => new Promise((r) => setTimeout(r, 500))).then(() => document.fonts.ready)');
    let nodeIds = [];
    if (withStates) {
      const sampled = await evalJs(SAMPLE); idx ??= sampled;
      const doc = await send('DOM.getDocument', { depth: 0 });
      const q = await send('DOM.querySelectorAll', { nodeId: doc.result.root.nodeId, selector: SEL });
      nodeIds = idx.map((i) => q.result.nodeIds[i]).filter(Boolean);
    }
    return collect(nodeIds, withStates);
  };
  const a = await one(fileA);
  const b = await one(fileB);
  return compare(a, b);
}

await send('Page.enable'); await send('Network.enable'); await send('DOM.enable'); await send('CSS.enable');
await send('Network.setBlockedURLs', { urls: ['*/api/view*', '*/api/stats*', '*lichess*', '*cloudflareinsights*'] });
await send('Network.setBypassServiceWorker', { bypass: true });
await send('Network.setCacheDisabled', { cacheDisabled: true });
const media = (scheme, type = '') => send('Emulation.setEmulatedMedia', { media: type, features: [{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: 'reduce' }] });

let total = 0, checks = 0;
const report = (label, r) => {
  const bits = Object.entries(r).map(([k, v]) => { total += v.diff; checks += v.n; return `${k} ${v.n}/${v.diff}`; });
  const samples = Object.values(r).flatMap((v) => v.samples).slice(0, 4);
  console.log(`${label}: ${bits.join(' ')}${samples.length ? ' DIFF ' + samples.join(' ; ') : ''}`);
};
console.log('format: kind elements/differing');
for (const m of modes) {
  await send('Emulation.setDeviceMetricsOverride', { width: m.w, height: m.h, deviceScaleFactor: 1, mobile: m.mobile });
  await media(m.scheme);
  for (const path of pages) report(`${m.name} ${path}`, await runPage(path, 'style.old.css', 'style.new.css', !QUICK));
}
let printRuns = 0;
for (const scheme of ['light', 'dark']) {
  await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  await media(scheme, 'print');
  for (const path of printPages) { printRuns++; report(`print-${scheme} ${path}`, await runPage(path, 'style.old.css', 'style.new.css', false)); }
}

// Canary: new vs canary sheet must differ in every kind of check.
await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
await media('light');
const c = await runPage('/', 'style.new.css', 'style.canary.css', true);
await media('light', 'print');
const cp = await runPage('/', 'style.new.css', 'style.canary.css', false);
const canaryOk = c.base.diff > 0 && c.hover.diff > 0 && c['focus-visible'].diff > 0 && cp.base.diff > 0;
console.log(`canary: base ${c.base.diff} hover ${c.hover.diff} focus-visible ${c['focus-visible'].diff} print ${cp.base.diff} (each must be > 0): ${canaryOk ? 'detected' : 'NOT DETECTED'}`);
console.log(`pages ${pages.length} x themes 2 x viewports 2 = ${pages.length * 4} page runs${QUICK ? '' : ' with hover + focus-visible'}, print ${printRuns} runs, ${checks} element styles compared`);
console.log(`TOTAL differing: ${total}${canaryOk ? '' : ' (CANARY FAILED)'}`);
ws.close(); edge.kill(); await sleep(500);
try { rmSync(profile, { recursive: true, force: true }); } catch {}
process.exit(total === 0 && canaryOk ? 0 : 1);
