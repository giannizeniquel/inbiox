// Phase 5a-bis: rename functions, methods, signals and variables with map-bis.json.
// Usage (from the repo root): node odd/tasks/limpieza-frontend-fase-5/apply-bis.js apps/web <inventory.json> [--write]
// 1) TS language-service renames from each declaration in the inventory whose name is in the map
//    (locals and params keep object keys via prefix/suffix text: `{ agentId }` -> `{ agentId: agenteId }`).
// 2) Templates (.html and inline) parsed with @angular/compiler: reads of renamed component members,
//    and [input]/(output)/attribute names on elements whose selector belongs to a component with renamed inputs/outputs.
const path = require('path'), fs = require('fs');
const web = path.resolve(process.argv[2]);
const inv = JSON.parse(fs.readFileSync(path.resolve(process.argv[3]), 'utf8'));
const write = process.argv.includes('--write');
const ts = require(web + '/node_modules/typescript');
const { rename, perSite = {} } = JSON.parse(fs.readFileSync(__dirname + '/map-bis.json', 'utf8'));
const newName = (rel, line, name) => perSite[`${rel}:${line}`] || rename[name];
const app = web + '/src/app';

(async () => {
  const ng = await import(web + '/node_modules/@angular/compiler/fesm2022/compiler.mjs');
  const walk = (d, o = []) => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p, o) : o.push(p); } return o; };
  const all = walk(web + '/src');
  const tsFiles = all.filter(f => f.endsWith('.ts'));
  const contents = new Map(tsFiles.map(f => [f, fs.readFileSync(f, 'utf8')]));
  const problems = [], warnings = [];

  // ---- 1. TS renames
  const cfg = ts.readConfigFile(web + '/tsconfig.json', ts.sys.readFile).config;
  const opts = ts.parseJsonConfigFileContent(cfg, ts.sys, web).options;
  const ls = ts.createLanguageService({
    getScriptFileNames: () => tsFiles, getScriptVersion: () => '1',
    getScriptSnapshot: f => contents.has(f) ? ts.ScriptSnapshot.fromString(contents.get(f))
      : (fs.existsSync(f) ? ts.ScriptSnapshot.fromString(fs.readFileSync(f, 'utf8')) : undefined),
    getCurrentDirectory: () => web, getCompilationSettings: () => opts,
    getDefaultLibFileName: o => ts.getDefaultLibFilePath(o),
    fileExists: ts.sys.fileExists, readFile: ts.sys.readFile, readDirectory: ts.sys.readDirectory,
    directoryExists: ts.sys.directoryExists, getDirectories: ts.sys.getDirectories,
  });
  const program = ls.getProgram();
  const edits = new Map(); // file -> Map(start -> {len, text, old})
  const declNameNode = (sf, line, name) => {
    let found = null;
    const v = n => {
      if (found) return;
      const nm = n.name;
      if (nm && ts.isIdentifier(nm) && nm.text === name && (ts.isMethodDeclaration(n) || ts.isPropertyDeclaration(n)
        || ts.isGetAccessor(n) || ts.isSetAccessor(n) || ts.isFunctionDeclaration(n) || ts.isClassDeclaration(n) || ts.isVariableDeclaration(n) || ts.isParameter(n))
        && sf.getLineAndCharacterOfPosition(nm.getStart(sf)).line + 1 === line) found = nm;
      ts.forEachChild(n, v);
    };
    v(sf);
    return found;
  };
  let locCount = 0, declCount = 0;
  for (const d of inv.decl) {
    const nw = newName(d.rel, d.line, d.name);
    if (!nw) continue;
    const file = path.join(app, d.rel);
    const sf = program.getSourceFile(file);
    const node = sf && declNameNode(sf, d.line, d.name);
    if (!node) { problems.push(`declaration not found: ${d.rel}:${d.line} ${d.name}`); continue; }
    const affix = d.kind === 'local' || d.kind === 'param';
    const locs = ls.findRenameLocations(file, node.getStart(sf), false, false, { providePrefixAndSuffixTextForRename: affix });
    if (!locs) { problems.push(`no rename locations: ${d.rel}:${d.line} ${d.name}`); continue; }
    declCount++;
    for (const l of locs) {
      const lsf = program.getSourceFile(l.fileName);
      const tok = ts.getTokenAtPosition ? ts.getTokenAtPosition(lsf, l.textSpan.start) : null;
      const parent = tok && tok.parent;
      if (parent && ts.isPropertySignature(parent)) { problems.push(`touches a type property (possible JSON field): ${path.relative(app, l.fileName)} ${d.name}`); continue; }
      if (parent && ts.isPropertyAssignment(parent) && parent.name === tok) warnings.push(`object key renamed: ${path.relative(app, l.fileName)}:${lsf.getLineAndCharacterOfPosition(l.textSpan.start).line + 1} ${d.name}`);
      const text = (l.prefixText || '') + nw + (l.suffixText || '');
      const m = edits.get(l.fileName) || new Map(); edits.set(l.fileName, m);
      const prev = m.get(l.textSpan.start);
      if (prev && prev.text !== text) problems.push(`conflict at ${path.relative(app, l.fileName)}:${l.textSpan.start} ${prev.text} vs ${text}`);
      m.set(l.textSpan.start, { len: l.textSpan.length, text, old: d.name });
      locCount++;
    }
  }

  // ---- 2. Templates (computed on the original sources, before applying TS edits)
  const components = []; // {file, cls, selector, tplFile, tplText, tplOffset, members: Map(old->new), io: Map(old->new)}
  for (const f of tsFiles.filter(f => f.startsWith(app) && !f.endsWith('.spec.ts'))) {
    const sf = program.getSourceFile(f);
    for (const st of sf.statements) {
      if (!ts.isClassDeclaration(st) || !st.name) continue;
      const dec = (ts.getDecorators(st) || []).find(dd => ts.isCallExpression(dd.expression) && dd.expression.expression.getText(sf) === 'Component');
      if (!dec) continue;
      const obj = dec.expression.arguments[0];
      const prop = n => obj.properties.find(p => p.name && p.name.getText(sf) === n);
      const sel = prop('selector'), tu = prop('templateUrl'), tpl = prop('template'), host = prop('host');
      const members = new Map(), io = new Map();
      for (const mem of st.members) {
        if (!mem.name || !ts.isIdentifier(mem.name)) continue;
        const nw = newName(path.relative(app, f), sf.getLineAndCharacterOfPosition(mem.name.getStart(sf)).line + 1, mem.name.text);
        if (!nw) continue;
        members.set(mem.name.text, nw);
        const init = ts.isPropertyDeclaration(mem) && mem.initializer ? mem.initializer.getText(sf) : '';
        const decs = (ts.getDecorators(mem) || []).map(x => x.getText(sf)).join(' ');
        if (/^(input|model|output)(\.required)?[<(]/.test(init) || /@(Input|Output)/.test(decs)) io.set(mem.name.text, nw);
      }
      const c = { file: f, cls: st.name.text, selector: sel && sel.initializer.text, members, io };
      if (tu) { c.tplFile = path.resolve(path.dirname(f), tu.initializer.text); c.tplText = fs.readFileSync(c.tplFile, 'utf8'); c.tplOffset = 0; }
      else if (tpl) { c.tplFile = f; c.tplText = tpl.initializer.text; c.tplOffset = tpl.initializer.getStart(sf) + 1; }
      if (host && members.size) warnings.push(`host bindings in ${path.relative(app, f)} may reference renamed members: check by hand`);
      components.push(c);
    }
  }
  const bySelector = new Map(components.filter(c => c.selector && c.io.size).map(c => [c.selector, c]));
  const tplEdits = new Map(); // file -> Map(absStart -> {len, text, old})
  const addTpl = (file, start, old, text) => {
    const m = tplEdits.get(file) || new Map(); tplEdits.set(file, m);
    m.set(start, { len: old.length, text, old });
  };
  let tplCount = 0;
  for (const c of components) {
    if (!c.tplFile) continue;
    const r = ng.parseTemplate(c.tplText, c.tplFile, { preserveWhitespaces: true });
    if (r.errors && r.errors.length) { problems.push(`template parse errors in ${path.relative(app, c.tplFile)}`); continue; }
    const reads = [], locals = new Set(), seen = new WeakSet();
    (function w(o, depth) {
      if (!o || typeof o !== 'object' || seen.has(o) || depth > 80) return; seen.add(o);
      const k = o.constructor && o.constructor.name;
      if ((k === 'PropertyRead' || k === 'SafePropertyRead') && /ImplicitReceiver|ThisReceiver/.test(o.receiver.constructor.name)) reads.push(o);
      if (k === 'Variable' || k === 'Reference' || k === 'LetDeclaration') locals.add(o.name);
      if (k === 'Element' && bySelector.has(o.name)) {
        const child = bySelector.get(o.name);
        for (const a of [...o.inputs, ...o.outputs, ...o.attributes]) {
          const nw = child.io.get(a.name);
          if (nw && a.keySpan) { addTpl(c.tplFile, c.tplOffset + a.keySpan.start.offset, a.name, nw); tplCount++; }
        }
      }
      for (const key of Object.keys(o)) if (key !== 'parent' && key !== 'sourceSpan') w(o[key], depth + 1);
    })(r.nodes, 0);
    for (const rd of reads) {
      const nw = c.members.get(rd.name);
      if (!nw) continue;
      if (locals.has(rd.name)) { problems.push(`template local shadows member ${rd.name} in ${path.relative(app, c.tplFile)}`); continue; }
      addTpl(c.tplFile, c.tplOffset + rd.nameSpan.start, rd.name, nw); tplCount++;
    }
  }

  // ---- apply
  const texts = new Map();
  const get = f => texts.has(f) ? texts.get(f) : (contents.has(f) ? contents.get(f) : fs.readFileSync(f, 'utf8'));
  const merged = new Map();
  for (const src of [edits, tplEdits]) for (const [f, m] of src) {
    const mm = merged.get(f) || new Map(); merged.set(f, mm);
    for (const [s, e] of m) { const p = mm.get(s); if (p && p.text !== e.text) problems.push(`template/ts conflict ${f}:${s}`); mm.set(s, e); }
  }
  for (const [f, m] of merged) {
    let t = get(f);
    for (const [s, e] of [...m.entries()].sort((a, b) => b[0] - a[0])) {
      if (t.slice(s, s + e.len) !== e.old) { problems.push(`span mismatch ${path.relative(app, f)}:${s} '${t.slice(s, s + e.len)}' != '${e.old}'`); continue; }
      t = t.slice(0, s) + e.text + t.slice(s + e.len);
    }
    texts.set(f, t);
  }
  console.log(`declarations renamed: ${declCount}, ts locations: ${locCount}, template locations: ${tplCount}, files: ${texts.size}`);
  if (warnings.length) console.log('WARNINGS:\n' + [...new Set(warnings)].join('\n'));
  if (problems.length) console.log('PROBLEMS:\n' + [...new Set(problems)].join('\n'));
  if (!write) { console.log('dry run'); process.exit(problems.length ? 1 : 0); }
  if (problems.length) process.exit(1);
  for (const [f, t] of texts) fs.writeFileSync(f, t);
  console.log('written');
})();
