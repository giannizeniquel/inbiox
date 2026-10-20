// Phase 5a-bis follow-up, run after apply-bis.js on the renamed tree:
// 1) templates: `field.member` where `field` is a component field holding a class (inject(X) or typed X)
//    and `member` was renamed in X;
// 2) specs: method names passed as strings to jasmine spyOn/createSpyObj (array items and object keys).
// Usage (from the repo root): node odd/tasks/limpieza-frontend-fase-5/fix-bis.js apps/web [--write]
const path = require('path'), fs = require('fs');
const web = path.resolve(process.argv[2]);
const write = process.argv.includes('--write');
const ts = require(web + '/node_modules/typescript');
const { rename } = JSON.parse(fs.readFileSync(__dirname + '/map-bis.json', 'utf8'));
const app = web + '/src/app';

(async () => {
  const ng = await import(web + '/node_modules/@angular/compiler/fesm2022/compiler.mjs');
  const walk = (d, o = []) => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p, o) : o.push(p); } return o; };
  const tsFiles = walk(app).filter(f => f.endsWith('.ts'));
  const parse = f => ts.createSourceFile(f, fs.readFileSync(f, 'utf8'), ts.ScriptTarget.Latest, true);
  // class name -> member names
  const classMembers = new Map();
  for (const f of tsFiles) for (const st of parse(f).statements)
    if (ts.isClassDeclaration(st) && st.name) classMembers.set(st.name.text, new Set(st.members.filter(m => m.name).map(m => m.name.getText())));
  const out = new Map(); let n = 0; const problems = [];
  const edit = (f, s, old, text) => { const m = out.get(f) || new Map(); out.set(f, m); m.set(s, { old, text }); n++; };

  for (const f of tsFiles.filter(f => !f.endsWith('.spec.ts'))) {
    const sf = parse(f);
    for (const st of sf.statements) {
      if (!ts.isClassDeclaration(st)) continue;
      const dec = (ts.getDecorators(st) || []).find(d => ts.isCallExpression(d.expression) && d.expression.expression.getText(sf) === 'Component');
      if (!dec) continue;
      const fieldType = new Map();
      for (const m of st.members) {
        if (!ts.isPropertyDeclaration(m) || !m.name) continue;
        const init = m.initializer && m.initializer.getText(sf);
        const inj = init && init.match(/^inject\((\w+)\)/);
        const t = inj ? inj[1] : (m.type && m.type.getText(sf));
        if (t && classMembers.has(t)) fieldType.set(m.name.getText(sf), t);
      }
      // constructor parameter properties
      for (const m of st.members) if (ts.isConstructorDeclaration(m)) for (const p of m.parameters)
        if (p.modifiers && p.type && classMembers.has(p.type.getText(sf))) fieldType.set(p.name.getText(sf), p.type.getText(sf));
      const obj = dec.expression.arguments[0];
      const tu = obj.properties.find(p => p.name && p.name.getText(sf) === 'templateUrl');
      const tpl = obj.properties.find(p => p.name && p.name.getText(sf) === 'template');
      let file, text, off;
      if (tu) { file = path.resolve(path.dirname(f), tu.initializer.text); text = fs.readFileSync(file, 'utf8'); off = 0; }
      else if (tpl) { file = f; text = tpl.initializer.text; off = tpl.initializer.getStart(sf) + 1; }
      else continue;
      const r = ng.parseTemplate(text, file, { preserveWhitespaces: true });
      const seen = new WeakSet();
      (function w(o, depth) {
        if (!o || typeof o !== 'object' || seen.has(o) || depth > 80) return; seen.add(o);
        const k = o.constructor && o.constructor.name;
        if ((k === 'PropertyRead' || k === 'SafePropertyRead') && o.receiver && o.receiver.constructor.name === 'PropertyRead'
          && /ImplicitReceiver|ThisReceiver/.test(o.receiver.receiver.constructor.name)) {
          const cls = fieldType.get(o.receiver.name);
          const nw = rename[o.name];
          if (cls && nw && !classMembers.get(cls).has(o.name) && classMembers.get(cls).has(nw)) edit(file, off + o.nameSpan.start, o.name, nw);
        }
        for (const key of Object.keys(o)) if (key !== 'parent' && key !== 'sourceSpan') w(o[key], depth + 1);
      })(r.nodes, 0);
    }
  }

  for (const f of tsFiles.filter(f => f.endsWith('.spec.ts'))) {
    const sf = parse(f);
    const v = node => {
      if (ts.isCallExpression(node)) {
        const callee = node.expression.getText(sf);
        let lits = [];
        if (/(^|\.)createSpyObj$/.test(callee)) for (const a of node.arguments) {
          if (ts.isArrayLiteralExpression(a)) lits.push(...a.elements.filter(ts.isStringLiteral));
          if (ts.isObjectLiteralExpression(a)) lits.push(...a.properties.filter(p => p.name && (ts.isIdentifier(p.name) || ts.isStringLiteral(p.name))).map(p => p.name));
        }
        if (/(^|\.)(spyOn|spyOnProperty)$/.test(callee) && node.arguments[1] && ts.isStringLiteral(node.arguments[1])) lits.push(node.arguments[1]);
        for (const l of lits) {
          const old = l.text, nw = rename[old];
          if (!nw) continue;
          const s = ts.isStringLiteral(l) ? l.getStart(sf) + 1 : l.getStart(sf);
          edit(f, s, old, nw);
        }
      }
      ts.forEachChild(node, v);
    };
    v(sf);
  }

  console.log(`edits: ${n} in ${out.size} files`);
  for (const [f, m] of out) {
    let t = fs.readFileSync(f, 'utf8');
    for (const [s, e] of [...m.entries()].sort((a, b) => b[0] - a[0])) {
      if (t.slice(s, s + e.old.length) !== e.old) { problems.push(`span mismatch ${path.relative(app, f)}:${s}`); continue; }
      t = t.slice(0, s) + e.text + t.slice(s + e.old.length);
    }
    if (write) fs.writeFileSync(f, t);
  }
  if (problems.length) console.log('PROBLEMS:\n' + problems.join('\n'));
  console.log(write ? 'written' : 'dry run');
})();
