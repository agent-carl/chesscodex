// The minified files the pages load, built with esbuild:
//   public/<name>.min.js   from public/<name>.js
//   public/style.min.css   from public/css/NN-*.css in file-name order (the order is the cascade)
//   public/fonts/*.woff2   the web fonts 00-fonts.css imports, names hashed (cached as immutable)
//   vendor/chess.min.js    from vendor/chess.js, its licence header kept
//
//   npm run build
//
// The board modules import ../vendor/*.js; those imports get ?v=<md5 of the
// file>, the version $asset() in templates/layout.php gives the modulepreload
// links, so a page loads each module once and a changed vendor file gets a
// new URL.
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const pub = `${root}public`;
const vendor = `${root}vendor`;
const version = (file) => createHash('md5').update(readFileSync(file)).digest('hex').slice(0, 8);

const chess = readFileSync(`${vendor}/chess.js`, 'utf8');
await build({
    entryPoints: [`${vendor}/chess.js`],
    outfile: `${vendor}/chess.min.js`,
    format: 'esm',
    minify: true,
    legalComments: 'none',
    banner: { js: chess.slice(0, chess.indexOf('*/') + 2) },
});

// Everything but theme.js (applies the saved theme before first paint, so it must block, and
// reads document.currentScript), home.js (blocking on purpose, same) and sw.js (a service worker).
const modules = ['app', 'play', 'search', 'train', 'repertoire', 'opening', 'gambits', 'site', 'charts', 'admin'];
await build({
    entryPoints: modules.map((name) => `${pub}/${name}.js`),
    outdir: pub,
    entryNames: '[name].min',
    bundle: true,
    format: 'esm',
    minify: true,
    plugins: [{
        name: 'versioned-vendor',
        setup(b) {
            b.onResolve({ filter: /^\.\.\/vendor\// }, (args) => ({
                path: `${args.path}?v=${version(`${vendor}/${args.path.slice('../vendor/'.length)}`)}`,
                external: true,
            }));
        },
    }],
});

const scripts = readdirSync(pub).filter((f) => f.endsWith('.js') && !f.endsWith('.min.js') && !modules.includes(f.slice(0, -3)));
await build({
    entryPoints: scripts.map((f) => `${pub}/${f}`),
    outdir: pub,
    entryNames: '[name].min',
    minify: true,
});

const css = readdirSync(`${pub}/css`).filter((f) => f.endsWith('.css')).sort();
await build({
    stdin: { contents: css.map((f) => `@import "./css/${f}";`).join('\n'), resolveDir: pub, loader: 'css' },
    outfile: `${pub}/style.min.css`,
    bundle: true,
    minify: true,
    loader: { '.woff2': 'file' },
    assetNames: 'fonts/[name]-[hash]',
});

console.log(`built ${modules.length + scripts.length} scripts, chess.min.js and style.min.css (${css.length} files)`);
