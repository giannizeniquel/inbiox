// Contract stage 2: fix template reads of renamed wire properties at the positions the compiler reports.
// Reads `ng build` output on stdin; rewrites only TS2339/TS2551 "Property 'x' does not exist on type 'T'" errors
// in .html files and inline templates whose old name is in the unit map and whose type is one of the given types.
// Usage (from apps/web): ng build ... 2>&1 | node <dir>/fix-templates.js <unit-template-map.json> [--write]
const fs = require('fs'), path = require('path');
const { types, rename } = JSON.parse(fs.readFileSync(path.resolve(process.argv[2]), 'utf8'));
const write = process.argv.includes('--write');
const out = fs.readFileSync(0, 'utf8').replace(/\x1b\[[0-9;]*m/g, '');
const re = /TS2(?:339|551): Property '(\w+)' does not exist on type '([^']+)'[^\n]*\n(?:[^\n]*\n)*?\s+(src\/[^\s:]+):(\d+):(\d+):/g;
const edits = new Map(); let m, skipped = [];
while ((m = re.exec(out))) {
  const [, old, type, file, line, col] = m;
  const nw = rename[old];
  if (!nw || !types.some(t => type === t || type.startsWith(t))) { skipped.push(`${file}:${line}:${col} ${old} on ${type}`); continue; }
  const key = `${file}:${line}:${col}`;
  edits.set(key, { file, line: +line, col: +col, old, nw });
}
const byFile = new Map();
for (const e of edits.values()) (byFile.get(e.file) || byFile.set(e.file, []).get(e.file)).push(e);
let n = 0; const problems = [];
for (const [file, es] of byFile) {
  const lines = fs.readFileSync(file, 'utf8').split('\n');
  for (const e of es.sort((a, b) => b.line - a.line || b.col - a.col)) {
    const l = lines[e.line - 1];
    if (l.slice(e.col, e.col + e.old.length) !== e.old) { problems.push(`mismatch ${file}:${e.line}:${e.col} ${e.old}`); continue; }
    lines[e.line - 1] = l.slice(0, e.col) + e.nw + l.slice(e.col + e.old.length); n++;
  }
  if (write) fs.writeFileSync(file, lines.join('\n'));
}
console.log(`edits: ${n} in ${byFile.size} files`);
if (skipped.length) console.log('SKIPPED:\n' + skipped.join('\n'));
if (problems.length) console.log('PROBLEMS:\n' + problems.join('\n'));
console.log(write ? 'written' : 'dry run');
