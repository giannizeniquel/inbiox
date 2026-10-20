// Contract stage 2, WU2 (realtime + conversacion): rename wire properties, enum members and payload types
// with the TS language service. Values, URLs, templates and string keys are edited by hand (contrato-map.md A.6, A.10, B.11).
// Usage (from the repo root): node odd/tasks/limpieza-frontend-fase-5/apply-wu2.js apps/web [--write]
const path = require('path'), fs = require('fs');
const web = path.resolve(process.argv[2]);
const write = process.argv.includes('--write');
const ts = require(web + '/node_modules/typescript');
const app = web + '/src/app';

const RT = 'core/tiempo-real/evento-en-tiempo-real.model.ts';
const MM = 'data-access/conversacion/mensaje.model.ts';
// [file, container, dotted member path ('' renames the container itself), new name]
const RENAMES = [
  [RT, 'EventoEnTiempoReal', 'eventId', 'eventoId'],
  [RT, 'EventoEnTiempoReal', 'timestamp', 'ocurridoAt'],
  [RT, 'EventoEnTiempoReal', 'eventType', 'tipoDeEvento'],
  [RT, 'MessageAddedPayload', 'messageId', 'mensajeId'],
  [RT, 'MessageAddedPayload', 'content', 'contenido'],
  [RT, 'MessageAddedPayload', 'direction', 'direccion'],
  [RT, 'MessageAddedPayload', 'senderName', 'remitenteNombre'],
  [RT, 'MessageAddedPayload', 'createdAt', 'creadoAt'],
  [RT, 'MessageAddedPayload', 'media.filename', 'nombreDeArchivo'],
  [RT, 'MessageAddedPayload', 'media.size', 'tamanio'],
  [RT, 'MessageAddedPayload', 'media.kind', 'tipo'],
  [RT, 'MessageAddedPayload', 'media.caption', 'leyenda'],
  [RT, 'MessageAddedPayload', 'media', 'adjunto'],
  [RT, 'TicketCreatedPayload', 'contactId', 'contactoId'],
  [RT, 'TicketCreatedPayload', 'conversationId', 'conversacionId'],
  [RT, 'TicketCreatedPayload', 'status', 'estado'],
  [RT, 'TicketCreatedPayload', 'priority', 'prioridad'],
  [RT, 'TicketCreatedPayload', 'createdAt', 'creadoAt'],
  [RT, 'TicketStatusChangedPayload', 'previousStatus', 'estadoAnterior'],
  [RT, 'TicketStatusChangedPayload', 'newStatus', 'estadoNuevo'],
  [RT, 'TicketStatusChangedPayload', 'changedAt', 'cambiadoAt'],
  [RT, 'TicketAssignedPayload', 'assigneeId', 'responsableId'],
  [RT, 'TicketAssignedPayload', 'assigneeName', 'responsableNombre'],
  [RT, 'TicketAssignedPayload', 'assignedAt', 'asignadoAt'],
  [RT, 'MessageUpdatedPayload', 'messageId', 'mensajeId'],
  [RT, 'MessageUpdatedPayload', 'status', 'estadoDeEntrega'],
  [RT, 'MessageUpdatedPayload', 'failedReason', 'motivoDeFallo'],
  [RT, 'MessageUpdatedPayload', 'updatedAt', 'actualizadoAt'],
  [RT, 'TipoDeEventoEnTiempoReal', 'TicketCreated', 'TicketCreado'],
  [RT, 'TipoDeEventoEnTiempoReal', 'TicketStatusChanged', 'EstadoDeTicketCambiado'],
  [RT, 'TipoDeEventoEnTiempoReal', 'TicketAssigned', 'TicketAsignado'],
  [RT, 'TipoDeEventoEnTiempoReal', 'MessageAdded', 'MensajeAgregado'],
  [RT, 'TipoDeEventoEnTiempoReal', 'MessageUpdated', 'MensajeActualizado'],
  [RT, 'TipoDeEventoEnTiempoReal', 'TicketRead', 'TicketLeido'],
  [RT, 'MessageAddedPayload', '', 'MensajeAgregadoPayload'],
  [RT, 'TicketCreatedPayload', '', 'TicketCreadoPayload'],
  [RT, 'TicketStatusChangedPayload', '', 'EstadoDeTicketCambiadoPayload'],
  [RT, 'TicketAssignedPayload', '', 'TicketAsignadoPayload'],
  [RT, 'MessageUpdatedPayload', '', 'MensajeActualizadoPayload'],
  [MM, 'MensajeDto', 'message_id', 'mensajeId'],
  [MM, 'MensajeDto', 'direction', 'direccion'],
  [MM, 'MensajeDto', 'content', 'contenido'],
  [MM, 'MensajeDto', 'created_at', 'creadoAt'],
  [MM, 'MensajeDto', 'deliveryStatus', 'estadoDeEntrega'],
  [MM, 'MensajeDto', 'media.filename', 'nombreDeArchivo'],
  [MM, 'MensajeDto', 'media.size', 'tamanio'],
  [MM, 'MensajeDto', 'media.kind', 'tipo'],
  [MM, 'MensajeDto', 'media.caption', 'leyenda'],
  [MM, 'MensajeDto', 'media', 'adjunto'],
  ['data-access/conversacion/adjunto.model.ts', 'Adjunto', 'filename', 'nombreDeArchivo'],
  ['data-access/conversacion/adjunto.model.ts', 'Adjunto', 'size', 'tamanio'],
  ['data-access/conversacion/adjunto.model.ts', 'Adjunto', 'kind', 'tipo'],
  ['data-access/conversacion/adjunto.model.ts', 'Adjunto', 'caption', 'leyenda'],
  ['data-access/conversacion/mensaje-api.service.ts', 'GetMensajesResponse', 'meta.has_more', 'hayMas'],
  ['data-access/conversacion/mensaje-api.service.ts', 'GetMensajesResponse', 'meta.next_cursor', 'siguienteCursor'],
  ['data-access/conversacion/url-de-adjunto.service.ts', 'UrlDeAdjuntoResponse', 'expiresAt', 'vencimientoAt'],
];

const walk = (d, o = []) => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p, o) : o.push(p); } return o; };
const tsFiles = walk(web + '/src').filter(f => f.endsWith('.ts'));
const contents = new Map(tsFiles.map(f => [f, fs.readFileSync(f, 'utf8')]));
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
const problems = [];
const edits = new Map(); // file -> Map(start -> {len, text, old})

const membersOf = n => {
  if (ts.isInterfaceDeclaration(n) || ts.isTypeLiteralNode(n) || ts.isEnumDeclaration(n)) return n.members;
  if (ts.isTypeAliasDeclaration(n)) return membersOf(n.type);
  if (ts.isPropertySignature(n) && n.type) return membersOf(n.type);
  if (ts.isUnionTypeNode(n)) { const t = n.types.find(x => ts.isTypeLiteralNode(x)); return t ? t.members : []; }
  return [];
};
const memberType = m => m.type && (ts.isUnionTypeNode(m.type) ? m.type.types.find(ts.isTypeLiteralNode) : m.type);

let count = 0;
for (const [rel, container, dotted, nw] of RENAMES) {
  const file = path.join(app, rel);
  const sf = program.getSourceFile(file);
  let node = sf.statements.find(s => s.name && s.name.text === container);
  if (!node) { problems.push(`missing ${container} in ${rel}`); continue; }
  for (const part of dotted ? dotted.split('.') : []) {
    const scope = ts.isPropertySignature(node) ? memberType(node) : node;
    node = scope && membersOf(scope).find(m => m.name && m.name.getText(sf) === part);
    if (!node) { problems.push(`missing ${container}.${dotted} in ${rel}`); break; }
  }
  if (!node) continue;
  const old = node.name.getText(sf);
  const locs = ls.findRenameLocations(file, node.name.getStart(sf), false, false, { providePrefixAndSuffixTextForRename: true });
  if (!locs) { problems.push(`no rename locations: ${container}.${dotted}`); continue; }
  for (const l of locs) {
    const text = (l.prefixText || '') + nw + (l.suffixText || '');
    const m = edits.get(l.fileName) || new Map(); edits.set(l.fileName, m);
    const prev = m.get(l.textSpan.start);
    if (prev && prev.text !== text) problems.push(`conflict at ${path.relative(app, l.fileName)}:${l.textSpan.start} ${prev.text} vs ${text}`);
    m.set(l.textSpan.start, { len: l.textSpan.length, text, old });
    count++;
  }
}
const touched = [];
for (const [f, m] of edits) {
  let t = contents.get(f);
  for (const [s, e] of [...m.entries()].sort((a, b) => b[0] - a[0])) {
    if (t.slice(s, s + e.len) !== e.old) { problems.push(`span mismatch ${path.relative(app, f)}:${s} '${t.slice(s, s + e.len)}' != '${e.old}'`); continue; }
    t = t.slice(0, s) + e.text + t.slice(s + e.len);
  }
  contents.set(f, t);
  touched.push(path.relative(web, f));
}
console.log(`renames: ${RENAMES.length}, locations: ${count}, files: ${touched.length}`);
console.log(touched.sort().join('\n'));
if (problems.length) console.log('PROBLEMS:\n' + problems.join('\n'));
if (!write) { console.log('dry run'); process.exit(problems.length ? 1 : 0); }
if (problems.length) process.exit(1);
for (const f of edits.keys()) fs.writeFileSync(f, contents.get(f));
console.log('written');
