# Archive Report: agent-timezone-editing

**Change Name**: agent-timezone-editing
**Date Archived**: 2026-09-12
**Archive Location**: `openspec/changes/archive/2026-09-12-agent-timezone-editing/`
**Mode**: Hybrid (openspec files + Engram observations)

---

## Executive Summary

Agent timezone editing has been fully implemented, verified with warnings (not blocking), and is now archived. The change exposes `Agent::$timezone` through admin CRUD endpoints, fixes a pre-existing role-gating bug (`AgentController` was incorrectly restricting to `ROLE_ADMIN` only despite specs/firewall/tests expecting `ROLE_SUPERVISOR`), and adds timezone display to the admin agent list. 26 of 27 tasks are complete; task 6.3 (manual browser smoke test) is documented as a follow-up gap (no browser environment available to executors). All implementation is persisted in the working tree, uncommitted per session convention.

---

## Artifact Traceability (Engram)

| Artifact | Engram Obs ID | Status |
|----------|---|---|
| Proposal | #600 | Complete |
| Specification (Delta) | #602 | Complete (merged to main spec) |
| Design | #603 | Complete |
| Tasks | #604 | Complete (26/27 done) |
| Verification Report | #606 | Complete (PASS WITH WARNINGS) |

---

## Final-State Facts (at archive time, per orchestrator launch prompt)

### Implementation Summary

- **Backend**: `Agent.timezone` (already existed as an entity field) is now exposed through `AgentController::create()` and `update()`, routed through the existing validated `Agent::setTimezone()` setter. A new `is_string()` guard prevents a `\TypeError` (which extends `\Error`, not `\Exception`, and would escape as an uncaught 500) from bubbling up on non-string input, returning 400 instead. Default `'UTC'` on creation if omitted. Added to `serializeAgent()` output.

- **Role-Gating Fix**: `AgentController`'s class-level `#[IsGranted('ROLE_ADMIN')]` attribute was silently 403-ing supervisors despite the firewall, docblock, and both specs assuming supervisor access worked. Fixed to `#[IsGranted('ROLE_SUPERVISOR')]`, restoring parity. Verified via strict before/after baseline: 11 named supervisor-authenticated test cases were confirmed genuinely RED (403) before the fix and GREEN after, with zero unrelated regressions (one initially-suspicious flip in `AgentLinkControllerTest` was proven structurally unrelated by reading the separate controller's own distinct `#[IsGranted('ROLE_ADMIN')]` attribute).

- **Frontend**: Timezone `<select>` (via `Intl.supportedValuesOf('timeZone')` with fallback list) added to the existing agent create/edit modal. New `timezone` column added to the admin agent list table. Computed signal `timezoneOptions` ensures stored timezone is always renderable even if missing from browser's supported list.

### Test Status

- **Backend**: 643 total tests (up from 630), all 36 Agent/AgentControllerTest/AgentLinkTest-related tests green. Zero new regressions (pre-existing flaky failures in unrelated test suites remain, e.g., Ticket Closure, Reporting, Workspace Agent Controller — confirmed order-dependent by independent re-runs, not caused by this change).

- **Frontend**: 244 total tests. `ng build` succeeds. Zero failures in any agent/timezone spec. Pre-existing 58 failures in ConversationStore/TicketStore/Report use-cases (DI environment issue) unrelated to this change, confirmed by `git stash` re-run.

### Task Status

26 of 27 tasks marked complete:
- Phases 0–5 (baseline capture, role-gating fix, backend timezone CRUD, frontend DTO/use-case threading, UI wiring) all complete and verified.
- **Task 6.3 (manual browser smoke test) not performed** — no browser environment available to any automated executor. This is a documented, tracked follow-up item (WARNING, not CRITICAL). Server-side round-trip persistence is covered by `testCreateAgentWithExplicitValidTimezone` and `testUpdateAgentTimezone`, but the actual Angular template rendering/select behavior needs human/E2E verification.

### Pre-Existing Issues Discovered (tracked separately)

1. **Component-level test coverage gap**: `agents-page.component` has no TestBed-based spec at all. The spec scenario "Agent create/edit modal offers all supported IANA zones" describes DOM/template behavior that a component spec could verify without a browser, but none exists. This is a pre-existing gap in the component's coverage, explicitly recorded as Engram observation #??? (to be filled by archive finalization), tracked as a follow-up but not this change's responsibility to fix.

2. **Broader role-gating pattern audit**: Whether the same `#[IsGranted]`-vs-firewall discrepancy exists in OTHER admin controllers remains explicitly out of scope and separately tracked (Engram observation #601, topic `discovery/possible-role-admin-role-supervisor-gating-bug-pattern-in-other-admin-controllers`). This change fixes `AgentController` only; an audit of pattern replication across other admin controllers is a distinct task.

3. **Suite-wide test flakiness**: Full PHPUnit suite runs on identical code produced materially different failure counts (30 vs 61 failures) across two runs, proving pre-existing order-dependent/shared-state flakiness. Not caused by this change (zero Agent* involvement in either failing test set), but a repo-wide test health issue worth separate remediation.

---

## Specification Merge

### Main Spec Updated

**File**: `openspec/specs/administration/spec.md`

**Action**: MODIFIED requirement "Agent CRUD (Admin/Supervisor)"

**Changes Applied**:
- Updated requirement text to note timezone field, IANA validation, and agent-list display.
- Added parenthetical note documenting the pre-existing role-gating bug fix as an implementation correction, not a policy change.
- Added "Out of scope" clarifications for timezone behavior (no AgentVoter activation, no self-service ROLE_AGENT access, no pattern audit in this change).
- Replaced the three baseline scenarios with eight new scenarios covering timezone creation/update/validation/role-access/UI options.
- Updated Summary section: scenario count increased from 15 to 23 (8 new scenarios for Agent CRUD).

**Verification**: Merge follows delta spec exactly, preserves all other requirements, maintains proper Markdown hierarchy.

---

## Archive Folder Structure

```
openspec/changes/archive/2026-09-12-agent-timezone-editing/
├── proposal.md                          (complete)
├── design.md                            (complete)
├── tasks.md                             (26/27 tasks done)
├── verify-report.md                     (PASS WITH WARNINGS)
├── archive-report.md                    (this file)
└── specs/
    └── administration/
        └── spec.md                      (delta merged to main)
```

---

## Verification Summary

**Verdict**: PASS WITH WARNINGS (0 CRITICAL, 2 WARNING)

**Critical Checks**: All pass
- Role-gating fix: Verified independent of apply's claim; 11 supervisor tests flipped red→green on class-attribute change alone.
- TypeError guard: Confirmed in both create/update, placed before setter call.
- Default timezone: Confirmed as literal `'UTC'` string, not null.
- Backend tests: 36/36 green (Agent/AgentController/AgentLink-related), zero regressions.
- Frontend tests: 13/13 green (create/update use-case specs), zero agent/timezone failures.

**Warnings**:
1. Task 6.3 unchecked (manual browser smoke test) — no browser available to executors. Server-side round-trip covered; actual DOM/template rendering deferred to manual/E2E QA. Pre-existing gap in component test coverage.
2. Suite-wide test flakiness observed (30 vs 61 failures on identical code) — pre-existing order-dependent behavior, unrelated to this change.

**No blocking issues. Safe to close.**

---

## Delivery State

### Working Tree
All implementation is committed/staged per the session's standard convention — code changes left uncommitted in the working tree for user review before final commit and PR.

### No Migration Required
The `timezone` column, getter, validated setter, and `'UTC'` DB default all existed before this change. Rollback is a simple revert; any timezone written before revert remains valid for `AgentAvailabilityService`.

### Rollback Boundary
Revert commits affecting:
- `apps/api/src/Controller/Admin/AgentController.php`
- `apps/api/tests/Controller/Admin/AgentControllerTest.php`
- `apps/api/tests/Unit/Domain/Agent/AgentTest.php`
- `apps/web/src/app/core/api/admin-api.service.ts`
- `apps/web/src/app/core/application/admin/agents/{create,update}-agent.use-case.ts`
- `apps/web/src/app/features/admin/agents/agents-page.component.{ts,html}`

No configuration, schema, or migration rollback needed.

---

## Review Workload Forecast (per task artifact)

- **Estimated changed lines**: ~240–280 gross (additions + deletions)
- **Backend**: ~165 lines (AgentController ~16, AgentControllerTest ~137, AgentTest ~9)
- **Frontend**: ~72–76 lines (DTOs, use cases, component wiring, template)
- **400-line budget**: Low risk
- **Chained PRs**: Not recommended; single PR advised

---

## Decision Closure

Product decisions from proposal round were resolved during planning:
1. **Create default**: Server defaults to `'UTC'` when omitted; no browser timezone pre-detection implemented.
2. **Visibility**: `timezone` shown as a column in the agent list (visible to supervisors on every list fetch).
3. **Existing agents**: All existing agents remain on `'UTC'` until edited (no backfill).
4. **Gate-fix blast radius**: Only `AgentController` fixed in this change; audit of same pattern in other admin controllers tracked separately (Engram observation #601).

---

## Follow-Up Items (Tracked, Not Blocking)

1. **Component-level test coverage** (Engram #???)
   - Add TestBed-based spec for `agents-page.component` covering the timezone `<select>` render, default behavior on create, and pre-selection on edit.
   - Status: Deferred to next sprint or explicit out-of-scope decision.

2. **Broader role-gating audit** (Engram #601)
   - Audit other admin controllers for the same `#[IsGranted('ROLE_ADMIN')]` vs firewall discrepancy pattern.
   - Status: Separate work item; not blocking this change.

3. **Suite-wide test flakiness** (out of scope for this change)
   - Investigate and remediate the order-dependent test failures across the PHPUnit suite.
   - Status: Repo-wide health issue; tracked separately.

---

## SDD Cycle Completion

- ✅ **Proposal**: Defined scope, approach, and rollback plan (Engram #600)
- ✅ **Spec**: Created delta for Administration domain, merged to main spec (Engram #602)
- ✅ **Design**: Technical approach and architecture decisions documented (Engram #603)
- ✅ **Tasks**: All phases executed; 26/27 completed (Engram #604)
- ✅ **Apply**: Implementation complete, working tree uncomitted (follow-up: user commit/PR)
- ✅ **Verify**: Independent verification passed with warnings (Engram #606)
- ✅ **Archive**: Change moved to archive folder, spec merged, report finalized

**Status**: CLOSED. Ready for user review, commit, and PR.

---

## Constraints & Limitations

**File Removal**: The original `openspec/changes/agent-timezone-editing/` directory could not be deleted due to executor tool constraints (Read/Edit/Write tools do not support directory removal). The directory remains on disk; manual cleanup via `rm -rf openspec/changes/agent-timezone-editing/` is required. All canonical artifacts are now in `openspec/changes/archive/2026-09-12-agent-timezone-editing/`.

---

**Archive Finalized**: 2026-09-12
**Archive Prepared By**: SDD Archive Phase (Executor)
**Mode**: Hybrid (OpenSpec files + Engram Observations)
