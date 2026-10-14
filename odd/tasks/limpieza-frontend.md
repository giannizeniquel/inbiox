# Frontend cleanup (apps/web)

Objective: regain control of the frontend. Remove overengineering and dead code, put every file where `docs/arquitectura.md` § Frontend says, and add no new features.
Branch: one branch per phase in the worktree `/home/gianni/projects/inbiox-worktrees/front` (phase 0: `chore/limpieza-frontend-fase-0`, from `chore/limpieza-backend-fase-0` at `6fcefec`, so it carries the shared rules and glossary). Delivery: one PR per phase into the integration branch `test-refactor` (from `test` at `b543593`), after the backend PRs it builds on; `test` keeps the working app and receives no cleanup PR. Push, PR and merge are user decisions. Test runner: none runnable for Karma specs in WSL2; gates are listed in S3.

## Specs

- **S1 — Order first, no new features.** User: "Necesitamos recuperar el control, es una app muy vibecodeada." and "muy importante: sobreingenieria".
  - Every phase preserves behavior. Phase gate: S3 holds after the phase.
- **S2 — Phases** (plan agreed with the user and the backend session, L2–L3):
  0. Safety net: baseline of S3.
  1. Delete dead code (`FakeWhatsAppProvider` and the unused code listed in `07-frontend.md` § Código sin uso, each one verified before deletion).
  2. Move code to `data-access/<modulo>` + `features/<pantalla>`; rename folders only.
  3. Remove pass-through layers: use cases without their own logic and the `PROVIDER_INTERFACE` abstraction are absorbed into their module's store or service. Screen state does not go into `data-access` (rule 2 of `docs/arquitectura.md` § Frontend).
  4. Split giant components and services (`conversation.component`, `ticket.store`, `mercure.service`).
  5. Rename identifiers together with the new API contract (backend phase 5).
- **S3 — Safety net.** Gates after every phase, compared against the baseline below:
  - `ng build --configuration production` exits 0 with no new warnings.
  - `tsc -p tsconfig.spec.json --noEmit` reports no new errors (`ng build` excludes specs, so moving folders could break them silently).
  - Manual smoke: `docs/02_sistema-actual/09-guia-de-pruebas-manuales.md` (P-01..P-24) for the screens a phase touches.
- **S4 — Docs policy.** Each phase updates `docs/02_sistema-actual/07-frontend.md` in the same PR. `docs/arquitectura.md` is owned by the backend session; the frontend asks for changes instead of editing it.
- **S5 — Local environment.** The `web` container serves this checkout's sibling, and Mercure only accepts `http://localhost:4200`. To run the app from this worktree: `docker compose up -d --scale web=0` from the main checkout, then `ng serve --proxy-config proxy.conf.json` on port 4200 from `apps/web` here.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2, S3 | inline | done | Baseline below (phase 0 commit) |
| T2 | S3, S4 | inline | done | `AGENTS.md` § Frontend and `07-frontend.md` updated (phase 0 commit) |
| T3 | S3 | delegated (browser) | done, 6 manual checks pending | Smoke baseline below |
| T4 | S2 (phase 1), S3, S4 | inline | done | Branch `chore/limpieza-frontend-fase-1`; phase 1 commit. Gates: build exit 0 with 12 warnings (NG8113 gone), spec `tsc` 0 errors, initial bundle 593.13 → 579.41 kB; routes and inbox checked in the browser (L6). Commit `529ff41`; native review: assessed high (`auth.guard.ts`), user granted, lenses risk + reliability, 0 findings, acknowledged |
| T5 | S2 (phase 2), S3, S4 | inline | done, guide 09 smoke pending | Branch `chore/limpieza-frontend-fase-2`; commit `e47b12e` (238 renames, 15 deletes: 13 re-export barrels, the features contact DTO re-export, `BaseModel`). Gates: build exit 0 with 12 warnings, spec `tsc` 0 errors, no import from `core/` or `shared/` into `data-access/` or `features/`; every route checked in the browser (L8). Native review: assessed high, user granted, start refused with `lens_context_budget_exceeded`; user chose to decline this candidate (L8). Smoke waits for the backend's closed-commit signal |

### Baseline (phase 0, at `6fcefec`, Node 20.19.5)

- `ng build --configuration production`: exit 0, 13 warnings:
  - 4 × Sass `@import` deprecation.
  - 3 × NG8107 (unnecessary optional chain).
  - 1 × NG8113: `ConversationSkeletonComponent` imported but not used in the template.
  - 1 × initial bundle 593.13 kB over the 500 kB budget.
  - 4 × component style budget (4 kB): `_admin-common.scss` 4.43 kB, `ticket-card` 5.39 kB, `contact-detail` 5.33 kB, `conversation-header` 4.09 kB.
- `tsc -p tsconfig.spec.json --noEmit`: exit 0, 0 errors.

### Smoke baseline (guide 09, 2026-10-14 02:00–02:35 UTC)

Run with browser automation against the running stack (backend at `4d6a972`, frontend code identical to this branch), browser timezone America/Buenos_Aires, Meta in mode B (every send to WhatsApp returns 502 by design). This is the behavior baseline for both cleanups. "Known" means it is already listed in the guide or in `00-estado-y-pendientes.md`.

| ID | Result | Observed |
|---|---|---|
| P-01, P-02, P-04, P-05, P-09, P-10, P-11, P-14, P-16, P-17, P-19, P-20, P-21, P-22 | PASS | P-05: the frontend sends the reply POST twice and the backend deduplicates it. P-14: the guide's `jq .data[].id` is stale (the field is `ticket_id`). P-17: phone cells starting with `+` are not neutralized in the CSV |
| P-03 | PASS | Tickets inserted in real time show the contact as "Unknown" until reload |
| P-06 | FAIL, known (R6) | Owner gets 409 `TICKET_NOT_OWNED` when replying to a resolved ticket |
| P-07 | PARTIAL | Text OK. The image attach sent no `/media/upload` request from the UI (automation could not confirm the file picker). Retry not run |
| P-08 | FAIL, new | API rejects correctly (422 `CONVERSATION_WINDOW_EXPIRED`), but the UI notice appeared at 28 h instead of 25 h: `last_inbound_message_at` comes without `Z` and the composer parses it as local time (late by the UTC offset) |
| P-12 | FAIL, new | Editing a contact's phone is silently ignored: `UpdateContactRequest` (`core/dto/contact-transport.dto.ts:54`) has no `phone`. The timeline shows only ticket events, with UTC times |
| P-13 | PARTIAL | Auto-assign respects capacity; auto-reply needs mode A |
| P-15 | FAIL | "Cola sin asignar" always 0 (known R8). "Volumen diario" shows 0 while the API returns 18 (probably the known non-UTC date issue). Agent load lists agents of another workspace |
| P-18 | PASS, known gaps | Supervisor's workspace select is empty (known 403). An inactive team still appears in the inbox filter |
| P-23 | PASS, known | No capacity field in the UI; the create-agent timezone list lacks `UTC` |
| P-24 | PARTIAL | Steps 1–3 OK; step 4 resolve not propagated (known); step 5 (Mercure outage) not run |

Pending manual checks by the user: P-07 (image attach and retry with a real file picker), P-08 (late notice in a UTC-3 browser), P-15 ("Volumen diario" at 0), P-12 (phone edit), P-13 step 3 and P-20 `automatic` (need mode A), P-24 step 5.

These failures are current behavior: the cleanup keeps them as they are, and fixing them is a separate decision.

Environment at run time: `inbiox_window_closer` has an empty `/var/www/html` (stale bind mount since 2026-10-13 13:26 UTC) and never runs; `inbiox_publisher` had the same problem until someone restarted it at 02:16 UTC, mid-run. Until then, events reached Mercure only through a host crontab (`app:outbox:publish` every minute), so real-time lagged up to 60 s. Test data created by the run: contacts named `Smoke …`, `Ana Prueba`, `Beto Prueba`; tickets 448–467; team "Soporte" (id 1); workspace 3 "Smoke WS P23"; agents 11–12; user `smoke.p23@inbiox.local`.

### Phase 2 destination map (approved 2026-10-14, L7)

Paths relative to `apps/web/src/app/`. Files move with their specs, templates and styles. Whole files move; splitting a file between modules is phase 3 or 4. Decisions marked **D#** are listed below the table.

| From | To |
|---|---|
| `core/api/ticket-api.service.ts` (includes `/assign`, D4), `core/api/workflow-api.service.ts`, `core/stores/ticket.store.ts`, `core/models/ticket.model.ts` | `data-access/ticket/` |
| `core/api/message-api.service.ts`, `core/api/media-url.service.ts`, `core/providers/*`, `core/application/messaging/*`, `core/stores/conversation.store.ts`, `core/models/message.model.ts`, `core/models/media.model.ts`, `core/utils/message-mapper.util.ts`, `core/utils/temp-id.util.ts`, `features/inbox/services/message-sender.service.ts` | `data-access/conversacion/` |
| `core/api/contacts-api.service.ts`, `core/dto/contact-transport.dto.ts` (D6), `core/dto/timeline-transport.dto.ts`, `features/contacts/models/*`, `features/contacts/stores/*` (D7), `features/contacts/application/contacts/*` | `data-access/contacto/` |
| `core/api/settings-api.service.ts`, `core/stores/settings.store.ts`, `core/dto/settings.dto.ts` (`/api/me/*`, D4, D5) | `data-access/agente/` |
| `core/services/agent.service.ts`, `core/stores/agent.store.ts`, `core/dto/agent-availability.dto.ts` | `data-access/disponibilidad/` |
| `core/api/reports-api.service.ts`, `core/application/reports/*`, `core/dto/reports.params.ts`, `core/dto/response-delay.dto.ts`, `features/reports/models/*` | `data-access/reporte/` |
| `core/api/admin-api.service.ts` (`/api/admin/agents`, `/api/admin/users`, D5), `core/api/team-api.service.ts` + `core/dto/team.dto.ts` + `core/application/admin/teams/*` (`/api/admin/teams`, D4, D5), `core/api/workspace-api.service.ts`, `core/stores/workspace.store.ts`, `core/dto/workspace.dto.ts`, `core/application/admin/{agents,linking,users,workspaces}/*` | `data-access/administracion/` |
| `core/api/auth-api.service.ts`, `core/stores/auth.store.ts`, `core/auth/*` (D1) | `core/auth/` |
| `core/realtime/*`, `core/stores/connection.store.ts`, `core/models/realtime-event.model.ts`, `core/metrics/metrics.service.ts` (only Mercure uses it) | `core/realtime/` |
| `core/utils/http-error.util.ts`, `core/utils/retry.util.ts`, `core/application/shared/*` | `core/http/` |
| `core/stores/theme.store.ts` | `core/layout/` |
| `core/models/base.model.ts` | deleted (no importer; missed in phase 1) |
| `features/inbox/containers/*` (D3), `features/inbox/components/*`, `core/stores/ui.store.ts` (D8) | `features/bandeja/pages/`, `features/bandeja/ui/`, `features/bandeja/` |
| `features/contacts/pages/*`, `features/contacts/components/*` | `features/contactos/{pages,ui}/` |
| `features/reports/pages/*`, `features/reports/components/*` | `features/reportes/{pages,ui}/` |
| `features/settings/pages/*` | `features/configuracion/pages/` |
| `features/admin/{agents,teams,users,workspaces}/*` | `features/administracion/pages/` |
| `features/agents/{availability,shifts}/*` | `features/disponibilidad/pages/` |
| `features/login/*` | `features/login/pages/` (D9) |
| `shell/*` | stays (D2) |
| `shared/*`, `app.*`, `environments/` | stay |

- **D1 — Authentication stays in `core/auth/`, no `data-access/autenticacion/`.** `docs/arquitectura.md` lists auth under `core`, and the interceptor, the guard and `MercureService` (all `core`) depend on `AuthStore`; putting it in `data-access` would make `core` import `data-access`.
- **D2 — `shell/` stays at the top level.** It renders the inbox containers directly, so inside `core/layout/` it would make `core` import `features`. It is the composition root, like `app.ts`.
- **D3 — The inbox containers (`ticket-list`, `conversation`) go to `features/bandeja/pages/`.** `/inbox` has no route component; these two are the page.
- **D4 — Services that span modules move whole, to their main module, and are split in phase 3:** `ticket-api` (`/assign`, `/assignment` and `/team` go to a new `data-access/asignacion/`, where the backend keeps them), `team-api` (`GET /api/teams` belongs to `agente`), `workspace-api` (`/api/me/workspace` belongs to `agente`, `/workspaces/{id}/available-agents` to `disponibilidad`). `/api/me/shifts` and `/api/me/auto-reply` stay in `agente`; if the backend splits `MeController` in phase 3, the frontend follows.
- **D5 — Follows the backend's controller mapping:** `administracion` owns all of `/api/admin/*`, team CRUD included; `agente` owns `GET /api/teams` and all of `/api/me/*`; `disponibilidad` owns `/api/agents/{id}/availability`, `/api/agents/{id}/shifts` and `/api/workspaces/{id}/available-agents`.
- **D6 — Two different files are both named `contact-transport.dto.ts`** (`core/dto/` and `features/contacts/models/dto/`, contents differ). Corrected while moving (L8): the one in `features/` only re-exported the one in `core/dto/`, so it was dropped with the other barrels and no rename was needed.
- **D7 — `ContactsStore` goes to `data-access/contacto/` whole,** with its `UIContact` model; its filter state is screen state and moves to `features/contactos/` in phase 3.
- **D8 — `UiStore` (`hasNewMessages`, `isAtBottom`) is inbox screen state** and goes to `features/bandeja/` (rule 2).
- **D9 — Screen names:** `bandeja`, `contactos`, `reportes`, `configuracion`, `administracion`, `disponibilidad`; `login` keeps its name (technical term).
- Barrels (`index.ts`) that only re-export are not carried over; imports point at the files.

## Log

- **L1** (2026-10-13) User: "claude, en esta sesion nos vamos a centrar en el front, no vamos a tocar codigo aun, solo quiero saber tu opinion sobre la arquitectura, ubicaciones de archivos, legibilidad del codigo, y muy importante: sobreingenieria. Necesitamos recuperar el control, es una app muy vibecodeada."
- **L2** First read-only audit: ~35 use cases in `core/application/` that mostly pass data through to `core/api/`; `FakeWhatsAppProvider` (709 lines) unused; use cases, DTOs and models spread over `core/`, `dto/`, `domain/` and `features/contacts/`; inbox-only stores in `core/stores/`; hand-written virtual scroll (430 + 241 lines) next to `@angular/cdk`.
- **L3** Agreed with the backend session: structure `data-access/<modulo>` + `features/<pantalla>` with one-way dependencies and screen state in the screen (written in `docs/arquitectura.md` at `6fcefec`); `PROVIDER_INTERFACE` is in use, so removing it is phase 3, not phase 1; add the spec typecheck to the net; docs per phase.
- **L4** (2026-10-13) User: "si, crea el worktree y arranca la fase 0". Worktree created, dependencies installed with `npm ci`, CodeGraph index initialized, baseline recorded.
- **L5** (2026-10-14) Rebased onto `4d6a972` (backend's latest docs commit). User approved running the smoke baseline with browser automation ("ok"); results above. Two findings verified by hand: the missing `phone` in `UpdateContactRequest`, and the container state.
- **L6** (2026-10-14) User: "entonces seguimos" (start phase 1). Deleted, each verified with zero references outside its own file and barrels:
  - `FakeWhatsappProvider`, `core/application/providers/` (`ProviderRegistry`, `providerRegistry`, `ProviderFactory`, which always returned `null`), `ProviderConfig`/`PROVIDER_CONFIG`, `sendTemplate()` on the interface and `WhatsAppCloudProvider`.
  - `app/dto/`, `app/domain/`.
  - Components `status-actions`, `chat-skeleton`, `conversation-skeleton` (imported but unused in `conversation.component`), `conversation-error`, `inbox-error`.
  - `InboxPageComponent` and `DebugPanelComponent`: on `/inbox` the shell renders the inbox itself and has no `<router-outlet>`, so the page was never instantiated. The `/inbox` route stays as `children: []` so the `**` and `''` redirects still land on it. (I first misread it as the live page; the doc was right.)
  - `adminGuard`, `ContactsApiService.deleteContact()`/`getEmptyContacts()`, `MessageApiService.sendMessage()`, `SearchContactsUseCase`, `OpenConversationFromContactUseCase`.
  - The workspace reload chain: `WorkspaceStore.onWorkspaceChange()`/`fireReloadCallbacks()` (never fired), `TicketStore`/`ContactsStore` `reload()`, `reload$` (no subscribers), `onReload()`, and the two registrations plus the now unused `ContactsStore` injection in `AppShellComponent` (`ContactsStore` has no constructor or subscriptions). Switching workspace works through `window.location.reload()` in `setActiveWorkspace()`.
  - First kept `MercureService.forceReconnect()` (no caller, but the only way out of `permanent_failure`). Deleted it afterwards on the backend session's advice, approved by the user ("si, borralo ahora en la fase 1"): no current use, and keeping it suggested the recovery was solved. The circuit-breaker log and the `ConnectionStore` comment no longer mention it. Recovering real time from `permanent_failure` is a separate product decision (guide 09, P-24 step 5).
  - Browser check from this worktree (`docker stop inbiox_web` + `ng serve` on :4200): login lands on `/inbox`; `/` and `/no-existe` redirect to `/inbox`; `/contacts`, `/reports`, `/settings` render; `/inbox?ticketId=448` opens the conversation; no console errors. `inbiox_web` restarted afterwards.
  - Docs: 30 `file.ts:line` references in `docs/02_sistema-actual/` remapped from the diff (none pointed at deleted code); sentences about the deleted code updated in 02, 04, 07 and 08.
- **L7** (2026-10-14) User relayed the backend's answer: "Tabla aprobada, con un ajuste en D5". Backend mapping: "administracion: todo /api/admin/*, incluido el ABM de equipos (/api/admin/teams)"; "agente: solo /api/teams (la lista) y todo /api/me/*"; "disponibilidad: /api/agents/{id}/availability, /api/agents/{id}/shifts y /api/workspaces/{id}/available-agents". "D4 va bien como transición. Cuando en la fase 3 se parta ticket-api, /assign, /assignment y /team pasan a un data-access/asignacion nuevo". "D1, D2 y D6 aprobadas." `team-api` is 5/6 `/api/admin/teams`, so it moves whole to `administracion` (D4).
- **L8** (2026-10-14) Phase 2 moved with a scratch script (`git mv` per the map, relative paths recomputed, imports from dropped barrels split per declaring file). D6 corrected: the `features/` contact DTO was a re-export, not a different file. `reports.models.ts` keeps two `export *` lines next to its own interfaces; untangling it is phase 3. Four comments that named old paths or the old layer rule were rewritten. Browser check from the worktree: login, `/inbox?ticketId=448` (conversation renders), `/contacts`, `/reports`, `/settings`, `/admin/agents`, `/admin/teams`, `/agents/1/availability`, `/agents/1/shifts` render their components; `/admin/users` and `/admin/workspaces` redirect to `/inbox` for the supervisor (`ROLE_ADMIN` routes); `/no-existe` redirects; no console errors. Native review could not start (`lens_context_budget_exceeded`, 267 files). Mechanical check instead: every added line in the diff is an import or path, except four comment edits. User chose to close it without native review ("A"); the decline was recorded for this candidate only. Docs: `file:line` references remapped from the move commit's diff, frontend structure in 02 and the README glossary rewritten, W3 and W6 in 02 and the DTO row in 08 marked resolved.
- **Next:** guide 09 smoke for phase 2 when the backend signals a closed commit (record that commit); then phase 3.
