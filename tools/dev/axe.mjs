// axe-core (WCAG 2.1 A + AA) over the local site in headless Edge, DevTools protocol.
//
//   AXE=/path/to/axe.min.js node tools/dev/axe.mjs [--port 8099] [--pages /,/about]
//        [--out result.json] [--modes desktop-light,phone-dark]
//
// axe-core is not a dependency of this repo: install it anywhere (npm i axe-core)
// and point AXE at its axe.min.js. Needs the local site (bash tools/dev/serve.sh).
// Every page x mode (desktop 1280 / phone 390 x light / dark, from
// prefers-color-scheme) is loaded and checked; prints one line per violation
// (impact, rule, node count, first selector) and a count by impact. Cannot see:
// states behind input (open menus, board moves), anything axe has no rule for.
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { readFileSync, writeFileSync } from 'node:fs';

const arg = (name, def) => { const i = process.argv.indexOf('--' + name); return i < 0 ? def : (process.argv[i + 1] ?? true); };
const PORT = Number(arg('port', process.env.PORT || 8099));
const BASE = `http://127.0.0.1:${PORT}`;
const CDP = PORT + 2000;
if (!process.env.AXE) { console.error('set AXE=/path/to/axe.min.js'); process.exit(2); }
const AXE_SRC = readFileSync(process.env.AXE, 'utf8');

let pages = ['/', '/openings', '/openings/letter/s', '/openings/sicilian-defense', '/openings/zaire-defense',
  '/eco', '/eco/B90', '/gambits', '/popular-openings', '/best-openings-for-white', '/opening-trends', '/rankings',
  '/how-to-play-against', '/search', '/search?q=najdorf', '/play/sicilian-defense', '/train/sicilian-defense',
  '/repertoire', '/about', '/no-such-page', '/admin/login'];
if (arg('pages')) pages = String(arg('pages')).split(',');
const all = [];
for (const scheme of ['light', 'dark']) {
  all.push({ name: `desktop-${scheme}`, w: 1280, h: 900, mobile: false, scheme });
  all.push({ name: `phone-${scheme}`, w: 390, h: 800, mobile: true, scheme });
}
const want = arg('modes') ? String(arg('modes')).split(',') : null;
const modes = all.filter((m) => !want || want.includes(m.name));

const edge = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ['--headless=new', `--remote-debugging-port=${CDP}`, `--user-data-dir=${join(tmpdir(), `axe-profile-${PORT}`)}`, '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
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

const RUN = `axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } })
  .then((r) => r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help,
    nodes: v.nodes.map((n) => ({ target: n.target.join(' '), summary: (n.any.concat(n.all, n.none)[0] || {}).message || '' })) })))`;

const results = {};
for (const m of modes) {
  await send('Emulation.setDeviceMetricsOverride', { width: m.w, height: m.h, deviceScaleFactor: 1, mobile: m.mobile });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: m.scheme }, { name: 'prefers-reduced-motion', value: 'reduce' }] });
  for (const p of pages) {
    await send('Page.navigate', { url: BASE + p }); await sleep(2200);
    await evalJs(AXE_SRC);
    results[`${p} ${m.name}`] = await evalJs(RUN);
  }
}
const counts = {};
for (const [k, vs] of Object.entries(results)) for (const v of vs) {
  counts[v.impact] = (counts[v.impact] || 0) + 1;
  console.log(`${v.impact.padEnd(8)} ${k.padEnd(50)} ${v.id} x${v.nodes.length}  ${v.nodes[0].target}`);
}
console.log('violations by impact (rule x page x mode):', JSON.stringify(counts));
if (arg('out')) writeFileSync(arg('out'), JSON.stringify(results, null, 1));
ws.close(); edge.kill(); process.exit(0);
