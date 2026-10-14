# Frontend cleanup (apps/web)

Objective: regain control of the frontend. Remove overengineering and dead code, put every file where `docs/arquitectura.md` § Frontend says, and add no new features.
Branch: one branch per phase in the worktree `/home/gianni/projects/inbiox-worktrees/front` (phase 0: `chore/limpieza-frontend-fase-0`, from `chore/limpieza-backend-fase-0` at `6fcefec`, so it carries the shared rules and glossary). Delivery: one PR per phase; push, PR and merge are user decisions. Test runner: none runnable for Karma specs in WSL2; gates are listed in S3.

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
  - Kept: `MercureService.forceReconnect()` has no caller now, but it is the only way out of `permanent_failure`; whether a UI should call it is a product decision.
  - Browser check from this worktree (`docker stop inbiox_web` + `ng serve` on :4200): login lands on `/inbox`; `/` and `/no-existe` redirect to `/inbox`; `/contacts`, `/reports`, `/settings` render; `/inbox?ticketId=448` opens the conversation; no console errors. `inbiox_web` restarted afterwards.
  - Docs: 30 `file.ts:line` references in `docs/02_sistema-actual/` remapped from the diff (none pointed at deleted code); sentences about the deleted code updated in 02, 04, 07 and 08.
- **Next:** phase 2 (move to `data-access/<modulo>` + `features/<pantalla>`). Run the guide 09 smoke for the screens it touches.
