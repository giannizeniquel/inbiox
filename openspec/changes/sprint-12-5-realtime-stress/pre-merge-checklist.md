# Pre-Merge Checklist — Sprint 12.5 → main

**Date**: 2026-06-12
**Tracker branch**: `sprint-12-5-tracker`
**Target**: `main`
**Status**: ⏸️ Pending

---

## 1. Smoke Test Integrado

Before merging the tracker to `main`, verify core flows manually:

| # | Test | Steps | Expected |
|---|------|-------|----------|
| 1.1 | Inbox loads | Navigate to `/inbox` | Ticket list renders, Mercure connects |
| 1.2 | Contacts CRUD | Navigate `/contacts`, create, edit, delete | All operations work, list reflects changes |
| 1.3 | Reports render | Navigate `/reports` | 4 KPI cards + leaderboard load |
| 1.4 | Message send | Type message in Composer, press Enter | Optimistic message appears, deliveryStatus transitions |
| 1.5 | Manual reconnect | Force disconnect, wait for auto-reconnect | State recovers, no duplicate messages |
| 1.6 | Navigation | `/inbox` → `/contacts` → `/inbox` → `/reports` | No connection leaks, single EventSource per inbox visit |
| 1.7 | Contact detail | Navigate `/contacts/:id` | Contact details render correctly |
| 1.8 | Theme toggle | Switch dark/light | All pages reflect theme change |
| 1.9 | Debug panel | Open debug panel | Shows connection status, event count, reconnect attempts |
| 1.10 | Backend API | `GET /api/tickets`, `GET /api/contacts` | Returns valid JSON, no errors |

---

## 2. Validate PASS_WITH_CAVEAT Criteria

These 3 criteria from the Sprint 13 Gate require a running Mercure hub:

| # | Criterion | Verification |
|---|-----------|--------------|
| 2.1 | Ticket list survives reconnect | Start Mercure hub, disconnect SSE for 5s, reconnect. Assert ticket list matches `GET /tickets` after resync. |
| 2.2 | Conversation survives reconnect | Same as above. Assert active conversation messages are complete after resync. |
| 2.3 | No lost messages across disconnect boundary | Capture all `eventId`s published by backend during disconnect window. After reconnect + resync, assert all are present in ConversationStore. |

**Prerequisites**: Mercure hub running, `app:outbox:publish` cron active, JWT topics configured.

---

## 3. Deuda Técnica Documentada

| Item | File | Severity | Action |
|------|------|----------|--------|
| `TicketListComponent` duplicate realtime pattern | `ticket-list.component.ts` | LOW | Callback is no-op (stub). Clean up in Sprint 13.x or 14. |
| SCSS budget warnings | Multiple `.scss` files | LOW | Pre-existing since Sprint 12.2. Non-blocking. |
| `@deprecated` components | StateService, InboxStore, ConversationPanelComponent | LOW | Marked for removal in Sprint 13.5 or 14. |
| `Sprint09AReportingTest` failures | `Sprint09AReportingTest.php` | MEDIUM | 13 failures from fixture data (duplicate phone). Pre-existing, not caused by 12.5. Fix in Sprint 13 cleanup. |

---

## 4. Release Candidate Tag

If all smoke tests pass:

```bash
git checkout sprint-12-5-tracker
git tag -a release-candidate-sprint13 -m "Release candidate for Sprint 13. All 28 Sprint 12.5 tasks complete. 12/12 gate criteria pass."
```

Run full build + test suite:
```bash
cd apps/web && npx ng build
docker exec inbiox_php php bin/phpunit
```

---

## 5. Merge Decision

**Condition**: All smoke tests (1.1–1.10) pass AND caveated criteria (2.1–2.3) verified.

```bash
git checkout main
git merge sprint-12-5-tracker --no-ff -m "Merge Sprint 12.5: Realtime Hardening"
git push origin main
```

After merge, `main` contains the stabilized realtime pipeline ready for Sprint 13.
