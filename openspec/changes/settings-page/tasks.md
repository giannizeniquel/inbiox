# Tasks: Settings Page

**Change**: `settings-page`
**Phases**: 7
**Total tasks**: 15
**Gates**: `php bin/phpunit` (backend) + `npx ng build --configuration=production` (frontend) MUST pass after every phase.

---

## Phase 1 — Database Migration ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 1.1 | ✅ Add 5 new columns to `Agent` entity + create migration `Version20260801000001`. Columns: `signature` (TEXT NULL), `notification_sound` (TINYINT(1) NOT NULL DEFAULT 1), `desktop_notifications` (TINYINT(1) NOT NULL DEFAULT 1), `auto_reply_enabled` (TINYINT(1) NOT NULL DEFAULT 0), `auto_reply_message` (TEXT NULL). Add accessors/getters/setters following the existing `setIsAvailableOverride` pattern (setters update `updatedAt`). `down()` drops all 5 columns. | `src/Domain/Agent/Agent.php`, `migrations/Version20260728170214.php` | Migration applied, columns exist |

---

## Phase 2 — Backend API: MeController Extensions ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 2.1 | ✅ Add `GET /api/me/settings` to `MeController`. Returns composite DTO with all 5 sections. Routes registered in `config/routes.yaml`. | `src/Controller/MeController.php`, `config/routes.yaml` | Tested via integration tests |
| 2.2 | ✅ Add `PUT /api/me/profile`, `PUT /api/me/notifications`, `PUT /api/me/auto-reply` to `MeController` with `#[MapRequestPayload]` DTOs & validation. | `src/Controller/MeController.php`, `src/Application/DTO/Update{Profile,Notifications,AutoReply}Request.php` | Tested via integration tests |
| 2.3 | ✅ Add `GET /api/me/shifts` and `PUT /api/me/shifts` with `ReplaceAgentShiftsUseCase` + `AgentShiftRepository::deleteAllForAgent`. | `src/Controller/MeController.php`, `src/Application/DTO/ReplaceShiftsRequest.php`, `src/Application/DTO/ShiftInputDTO.php`, `src/Application/UseCase/ReplaceAgentShiftsUseCase.php`, `src/Domain/Agent/AgentShiftRepository.php` | Tested via integration tests |

---

## Phase 3 — Auto-Reply Logic ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 3.1 | ✅ Create `AutoReplyService` with fire-and-forget `maybeSend(Ticket): void`. | `src/Application/Service/AutoReplyService.php` | Unit test covers all branches |
| 3.2 | ✅ Wire `AutoReplyService` into `HandleInboundMessageUseCase` after transaction commit. | `src/Application/UseCase/HandleInboundMessageUseCase.php` | Existing unit test updated |

---

## Phase 4 — Backend Tests ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 4.1 | ✅ Integration test for MeController settings endpoints (12 tests). | `tests/Integration/SettingsPageTest.php` | `php bin/phpunit --filter SettingsPageTest` ✅ |
| 4.2 | ✅ Unit test for AutoReplyService::maybeSend (7 tests, all branches). | `tests/Unit/Service/AutoReplyServiceTest.php` | `php bin/phpunit --filter AutoReplyServiceTest` ✅ |

---

## Phase 5 — Frontend API + Store ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 5.1 | ✅ Create `SettingsApiService` + `settings.dto.ts` with typed interfaces. | `core/api/settings-api.service.ts`, `core/dto/settings.dto.ts` | `npx ng build` ✅ |
| 5.2 | ✅ Create `SettingsStore` with signals + `loadSettings()` / `save*()` methods + spec. | `core/stores/settings.store.ts`, `core/stores/settings.store.spec.ts` | Build passes |

---

## Phase 6 — Frontend UI: Component + Route ✅

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 6.1 | ✅ Create `SettingsPageComponent` (standalone, 5 tabs, Reactive Forms, save per section). | `features/settings/pages/settings-page/settings-page.component.{ts,html,scss}` | `npx ng build` ✅ |
| 6.2 | ✅ Wire route in `app.routes.ts` — replace redirect with `loadComponent`. | `app.routes.ts` | Navigate to /settings |
| 6.3 | ✅ Sidebar already has `/settings` link — no change needed. | — | Already present |

---

## Phase 7 — Verification ✅

| # | Task | Verifiable when |
|---|------|-----------------|
| 7.1 | ✅ Backend tests (26 relevant tests) + frontend build both pass. | `php bin/phpunit --filter "SettingsPageTest|AutoReplyServiceTest|HandleInboundMessageUseCaseTest"` ✅; `npx ng build` ✅ |
| 7.2 | Manual smoke: log in as ROLE_AGENT → click "Configuración" in sidebar → all 5 tabs render with persisted data → save in 3 sections → refresh → values persist. | Manual run-through |

---
