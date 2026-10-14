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
| T3 | S3 | manual | pending | Smoke baseline run of guide 09 (P-01..P-24) against the stack; not run yet |

### Baseline (phase 0, at `6fcefec`, Node 20.19.5)

- `ng build --configuration production`: exit 0, 13 warnings:
  - 4 × Sass `@import` deprecation.
  - 3 × NG8107 (unnecessary optional chain).
  - 1 × NG8113: `ConversationSkeletonComponent` imported but not used in the template.
  - 1 × initial bundle 593.13 kB over the 500 kB budget.
  - 4 × component style budget (4 kB): `_admin-common.scss` 4.43 kB, `ticket-card` 5.39 kB, `contact-detail` 5.33 kB, `conversation-header` 4.09 kB.
- `tsc -p tsconfig.spec.json --noEmit`: exit 0, 0 errors.

## Log

- **L1** (2026-10-13) User: "claude, en esta sesion nos vamos a centrar en el front, no vamos a tocar codigo aun, solo quiero saber tu opinion sobre la arquitectura, ubicaciones de archivos, legibilidad del codigo, y muy importante: sobreingenieria. Necesitamos recuperar el control, es una app muy vibecodeada."
- **L2** First read-only audit: ~35 use cases in `core/application/` that mostly pass data through to `core/api/`; `FakeWhatsAppProvider` (709 lines) unused; use cases, DTOs and models spread over `core/`, `dto/`, `domain/` and `features/contacts/`; inbox-only stores in `core/stores/`; hand-written virtual scroll (430 + 241 lines) next to `@angular/cdk`.
- **L3** Agreed with the backend session: structure `data-access/<modulo>` + `features/<pantalla>` with one-way dependencies and screen state in the screen (written in `docs/arquitectura.md` at `6fcefec`); `PROVIDER_INTERFACE` is in use, so removing it is phase 3, not phase 1; add the spec typecheck to the net; docs per phase.
- **L4** (2026-10-13) User: "si, crea el worktree y arranca la fase 0". Worktree created, dependencies installed with `npm ci`, CodeGraph index initialized, baseline recorded.
- **Next:** T3 (smoke baseline) and then phase 1.
