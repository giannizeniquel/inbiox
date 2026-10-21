const path=require('path'),fs=require('fs');
const web=path.resolve(process.argv[2]);const ts=require(web+'/node_modules/typescript');
const app=web+'/src/app';
const walk=(d,o=[])=>{for(const f of fs.readdirSync(d)){const p=path.join(d,f);fs.statSync(p).isDirectory()?walk(p,o):o.push(p);}return o;};
const files=walk(app).filter(f=>f.endsWith('.ts'));
const BIZ=new Set(('conversation conversations message messages contact contacts agent agents user users team teams workspace workspaces assign assigned assignment assignments assignee unassign unassigned shift shifts tag tags media attachment attachments rule rules inbox window availability available override autoreply auto reply template templates resolve resolved resolving reopen reopened status direction sender owner priority customer timeline closure audit read unread import imported load capacity participation episode attribution keyword settings profile notification notifications preference preferences link unlink linked deactivate detail details filter filters pagination metadata scope realtime provider inbound outbound expiry expired tagging logout session upload download phone recipient workflow export heatmap recurring delay trend volume counts performance leaderboard report reports send sent retry dashboard').split(' '));
const toks=n=>n.replace(/([a-z0-9])([A-Z])/g,'$1 $2').replace(/_/g,' ').toLowerCase().split(/\s+/);
const json=new Set();const decl=[];
for(const f of files){const sf=ts.createSourceFile(f,fs.readFileSync(f,'utf8'),ts.ScriptTarget.Latest,true);const rel=path.relative(app,f);const spec=rel.endsWith('.spec.ts');
 const v=(n,cls)=>{
  if((ts.isPropertySignature(n))&&n.name&&!spec) json.add(n.name.getText(sf));
  let kind=null,name=null;
  if(ts.isMethodDeclaration(n)&&ts.isClassLike(n.parent)){kind='method';name=n.name.getText(sf);}
  else if(ts.isGetAccessor(n)||ts.isSetAccessor(n)){kind='accessor';name=n.name.getText(sf);}
  else if(ts.isPropertyDeclaration(n)){name=n.name.getText(sf);const init=n.initializer?n.initializer.getText(sf):'';const dec=(ts.getDecorators?ts.getDecorators(n)||[]:[]).map(d=>d.getText(sf)).join(' ');
    kind=/^(input|model)(\.required)?[<(]/.test(init)||/@Input/.test(dec)?'input':/^output[<(]/.test(init)||/@Output/.test(dec)?'output':/^(signal|computed|linkedSignal)[<(]|toSignal\(/.test(init)?'signal':/^(viewChild|contentChild|viewChildren)/.test(init)?'query':'field';}
  else if(ts.isFunctionDeclaration(n)&&n.name){kind='function';name=n.name.text;}
  else if(ts.isVariableDeclaration(n)&&ts.isIdentifier(n.name)){kind=ts.isSourceFile(n.parent.parent.parent)?'const':'local';name=n.name.text;}
  else if(ts.isParameter(n)&&ts.isIdentifier(n.name)){kind='param';name=n.name.text;}
  if(kind&&name&&toks(name).some(t=>BIZ.has(t))) decl.push({rel,kind,name,spec,line:sf.getLineAndCharacterOfPosition(n.getStart(sf)).line+1});
  ts.forEachChild(n,v);};
 v(sf);}
const by={};for(const d of decl){const k=d.kind+(d.spec?'(spec)':'');by[k]=by[k]||{decls:0,names:new Set(),json:new Set()};by[k].decls++;by[k].names.add(d.name);if(json.has(d.name))by[k].json.add(d.name);}
for(const [k,v2] of Object.entries(by)) console.log(k.padEnd(14),'decls',v2.decls,'distinct',v2.names.size,'json-colliding',v2.json.size);
fs.writeFileSync(__dirname+'/inventory-bis.json',JSON.stringify({decl,json:[...json]},null,0));
