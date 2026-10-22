// Pulls named helpers out of the browser scripts in public/, which export nothing and
// need a DOM to run. Nothing in public/ is changed: the source text of the helper is cut
// out and evaluated on its own.
//   function name(...) { ... }      the closing "}" must stand in the same column as "function"
//   const name = ...;               one or more lines, the last one ending in ";"
// `uses` lists other "const X = ...;" definitions the helper needs (e.g. lookup tables).
import { readFileSync } from 'node:fs';

function cut(lines, name) {
  const fn = new RegExp(String.raw`^(\s*)(?:async )?function ${name}\(`);
  const cn = new RegExp(String.raw`^\s*const ${name} = `);
  for (let i = 0; i < lines.length; i++) {
    const f = fn.exec(lines[i]);
    if (f) {
      const end = lines.findIndex((l, j) => j > i && l === `${f[1]}}`);
      if (end < 0) throw new Error(`${name}: no closing brace in column ${f[1].length}`);
      return lines.slice(i, end + 1).join('\n');
    }
    if (cn.test(lines[i])) {
      let end = i;
      while (!lines[end].trimEnd().endsWith(';')) end++;
      return lines.slice(i, end + 1).join('\n');
    }
  }
  throw new Error(`no function or const named ${name}`);
}

export function helper(file, name, uses = []) {
  const lines = readFileSync(new URL(`../../public/${file}`, import.meta.url), 'utf8').split('\n');
  const prelude = uses.map((u) => cut(lines, u)).join('\n');
  const body = cut(lines, name).trim();
  const asValue = body.startsWith('const ') ? body : `const ${name} = ${body};`;
  return new Function(`${prelude}\n${asValue}\nreturn ${name};`)();
}
