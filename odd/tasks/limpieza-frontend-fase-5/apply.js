// Usage: node apply.js <apps/web dir> [--write]
// 1) symbol renames via the TS language service, 2) selector strings, 3) file moves + relative specifier fixes.
const path = require('path'), fs = require('fs'), cp = require('child_process');
const web = path.resolve(process.argv[2]);
const write = process.argv.includes('--write');
const ts = require(web + '/node_modules/typescript');
const map = JSON.parse(fs.readFileSync(__dirname + '/map.json', 'utf8'));
const { move } = require('./moves.js');
const app = web + '/src/app';

function walk(d, out = []) {
  for (const f of fs.readdirSync(d)) {
    const p = path.join(d, f);
    if (fs.statSync(p).isDirectory()) walk(p, out); else out.push(p);
  }
  return out;
}
const all = walk(web + '/src');
const tsFiles = all.filter(f => f.endsWith('.ts'));
const contents = new Map(tsFiles.map(f => [f, fs.readFileSync(f, 'utf8')]));

// ---- 1. symbols
const cfg = ts.readConfigFile(web + '/tsconfig.json', ts.sys.readFile).config;
const opts = ts.parseJsonConfigFileContent(cfg, ts.sys, web).options;
const host = {
  getScriptFileNames: () => tsFiles,
  getScriptVersion: () => '1',
  getScriptSnapshot: f => contents.has(f) ? ts.ScriptSnapshot.fromString(contents.get(f))
    : (fs.existsSync(f) ? ts.ScriptSnapshot.fromString(fs.readFileSync(f, 'utf8')) : undefined),
  getCurrentDirectory: () => web,
  getCompilationSettings: () => opts,
  getDefaultLibFileName: o => ts.getDefaultLibFilePath(o),
  fileExists: ts.sys.fileExists, readFile: ts.sys.readFile, readDirectory: ts.sys.readDirectory,
  directoryExists: ts.sys.directoryExists, getDirectories: ts.sys.getDirectories,
};
const ls = ts.createLanguageService(host);
const edits = new Map(); // file -> Map(start -> {len, text, old})
let problems = [];
function addLocs(file, pos, oldName, newName) {
  const locs = ls.findRenameLocations(file, pos, false, false, { providePrefixAndSuffixTextForRename: false });
  if (!locs) { problems.push(`no rename locations: ${oldName} in ${file}`); return 0; }
  for (const l of locs) {
    const m = edits.get(l.fileName) || new Map(); edits.set(l.fileName, m);
    const prev = m.get(l.textSpan.start);
    if (prev && prev.text !== newName) problems.push(`conflict at ${l.fileName}:${l.textSpan.start} ${prev.text} vs ${newName}`);
    m.set(l.textSpan.start, { len: l.textSpan.length, text: newName, old: oldName });
  }
  return locs.length;
}
const program = ls.getProgram();
let count = 0;
for (const [rel, oldName, newName] of map.symbols) {
  const file = path.join(app, rel);
  const sf = program.getSourceFile(file);
  if (!sf) { problems.push(`missing file ${rel}`); continue; }
  const decl = sf.statements.find(s => s.name && s.name.text === oldName);
  if (!decl) { problems.push(`missing decl ${oldName} in ${rel}`); continue; }
  count += addLocs(file, decl.name.getStart(sf), oldName, newName);
}
for (const [rel, enumName, oldName, newName] of map.members) {
  const file = path.join(app, rel);
  const sf = program.getSourceFile(file);
  const en = sf.statements.find(s => ts.isEnumDeclaration(s) && s.name.text === enumName);
  const mem = en.members.find(m => m.name.getText(sf) === oldName);
  count += addLocs(file, mem.name.getStart(sf), oldName, newName);
}
for (const [file, m] of edits) {
  let text = contents.get(file);
  if (text === undefined) { problems.push(`edit outside src ts: ${file}`); continue; }
  for (const [start, e] of [...m.entries()].sort((a, b) => b[0] - a[0])) {
    const cur = text.slice(start, start + e.len);
    if (cur !== e.old) problems.push(`span mismatch ${file}:${start} '${cur}' != '${e.old}'`);
    text = text.slice(0, start) + e.text + text.slice(start + e.len);
  }
  contents.set(file, text);
}
console.log(`symbols: ${map.symbols.length + map.members.length} renamed, ${count} locations in ${edits.size} files`);

// ---- 2. selectors (.ts, .html, .scss)
const textFiles = all.filter(f => /\.(ts|html|scss)$/.test(f));
for (const f of textFiles) if (!contents.has(f)) contents.set(f, fs.readFileSync(f, 'utf8'));
let selHits = 0;
for (const f of textFiles) {
  let t = contents.get(f);
  for (const [a, z] of map.selectors) {
    const re = new RegExp(`(?<![\\w-])${a}(?![\\w-])`, 'g');
    t = t.replace(re, () => { selHits++; return z; });
  }
  contents.set(f, t);
}
console.log(`selectors: ${selHits} replacements`);

// ---- 3. moves + relative specifiers
const relOf = f => path.relative(app, f).split(path.sep).join('/');
const newPathOf = f => f.startsWith(app + '/') ? path.join(app, move(relOf(f))) : f;
const moved = all.filter(f => newPathOf(f) !== f);
console.log(`moves: ${moved.length} files`);
function newSpec(fromOld, spec) {
  const base = path.resolve(path.dirname(fromOld), spec);
  const kind = [[base, 'exact'], [base + '.ts', 'ts'], [base + '/index.ts', 'index']]
    .find(([c]) => fs.existsSync(c) && fs.statSync(c).isFile());
  if (!kind) { problems.push(`unresolved ${spec} in ${relOf(fromOld)}`); return spec; }
  const [target, how] = kind;
  const fromNew = newPathOf(fromOld), targetNew = newPathOf(target);
  if (fromNew === fromOld && targetNew === target) return spec;
  const tn = how === 'exact' ? targetNew : how === 'ts' ? targetNew.slice(0, -3) : path.dirname(targetNew);
  let r = path.relative(path.dirname(fromNew), tn).split(path.sep).join('/');
  if (!r.startsWith('.')) r = './' + r;
  return r;
}
let specFixes = 0;
for (const f of tsFiles) {
  const text = contents.get(f);
  const sf = ts.createSourceFile(f, text, ts.ScriptTarget.Latest, true);
  const reps = [];
  const visit = n => {
    if (ts.isStringLiteral(n) && n.text.startsWith('.')) {
      const p = n.parent;
      const isImport = (ts.isImportDeclaration(p) || ts.isExportDeclaration(p)) && p.moduleSpecifier === n;
      const isDyn = ts.isCallExpression(p) && p.expression.kind === ts.SyntaxKind.ImportKeyword;
      const isUrl = (ts.isPropertyAssignment(p) && /^(templateUrl|styleUrl)$/.test(p.name.getText(sf)))
        || (ts.isArrayLiteralExpression(p) && ts.isPropertyAssignment(p.parent) && p.parent.name.getText(sf) === 'styleUrls');
      if (isImport || isDyn || isUrl) {
        const ns = newSpec(f, n.text);
        if (ns !== n.text) reps.push([n.getStart(sf) + 1, n.text.length, ns]);
      }
    }
    ts.forEachChild(n, visit);
  };
  visit(sf);
  let t = text;
  for (const [s, l, ns] of reps.sort((a, b) => b[0] - a[0])) t = t.slice(0, s) + ns + t.slice(s + l);
  specFixes += reps.length;
  contents.set(f, t);
}
console.log(`specifier fixes: ${specFixes}`);
if (problems.length) { console.log('PROBLEMS:\n' + problems.join('\n')); }
if (!write) { console.log('dry run'); process.exit(problems.length ? 1 : 0); }
if (problems.length) process.exit(1);
for (const [f, t] of contents) if (fs.readFileSync(f, 'utf8') !== t) fs.writeFileSync(f, t);
for (const f of moved) {
  const to = newPathOf(f);
  fs.mkdirSync(path.dirname(to), { recursive: true });
  cp.execFileSync('git', ['mv', f, to], { cwd: web });
}
console.log('written');
