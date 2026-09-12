# Proposal: Agent Timezone Editing

## Intent

`Agent::$timezone` drives `AgentAvailabilityService` (availability, `isActiveNow()`, assignment roster), but no UI or API path can set it — every agent silently stays on the `'UTC'` DB default. Supervisors cannot correct an agent's timezone, so non-UTC agents are evaluated against the wrong local clock in the ticket-assignment dropdown. This change exposes the already-validated field through the existing admin CRUD surface.

It also closes a latent authorization defect found while scoping: the controller is stricter than every spec, config, and test around it.

## Scope

### In Scope
- `AgentController::create()` / `update()` accept optional `timezone`, routed through `Agent::setTimezone()` (never `\ReflectionProperty`); `serializeAgent()` returns `timezone`.
- Fix the role-gating discrepancy so ROLE_ADMIN **and** ROLE_SUPERVISOR reach the whole controller.
- Angular: `timezone` control in the existing agent modal, threaded through `AdminApiService` DTOs and the create/update use cases.
- Tests: controller create/update happy + invalid-IANA 400 paths, role coverage, frontend use-case specs, and the missing `Agent::setTimezone()` negative-path unit test.

### Out of Scope
- Schema/migration changes (field, getter, validated setter already exist).
- Activating `AgentVoter::EDIT_AGENT` (dead code, wider blast radius).
- Self-service timezone editing by plain ROLE_AGENT.
- New endpoints, routes, pages, UseCase classes, or timezone libraries.
- Refactoring `update()`'s pre-existing reflection-based `name` write.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `administration`: "Agent CRUD (Admin/Supervisor)" gains `timezone` as an optional create/update input and a serialized response field, with invalid IANA names rejected as 400.

### Unchanged (code aligns to existing spec)
- `authorization`: "Supervisor can manage agents" already specifies the intended behavior; only the implementation is being corrected.

## Approach

**Source of truth for access — verified, not assumed.** Two gates run today:

1. Firewall `access_control: { path: ^/api/admin/agents, roles: ROLE_SUPERVISOR }` — passes for supervisors and (via `role_hierarchy`) admins.
2. Class-level `#[IsGranted('ROLE_ADMIN')]` — **this is enforced.** `IsGranted` declares `Attribute::TARGET_CLASS`, and `ControllerEvent::getAttributes()` merges class attributes with method attributes (`array_merge($class?->getAttributes() ?? [], $this->controllerReflector->getAttributes())`), which `IsGrantedAttributeListener` consumes on `kernel.controller_arguments`. Symfony is pinned at `7.4.*`, where class-level support exists.

Since `ROLE_SUPERVISOR: [ROLE_AGENT]` does not grant `ROLE_ADMIN`, the attribute is the **narrower, deciding** gate: supervisor requests reach the firewall, then 403 at the listener. The controller docblock ("requires ROLE_SUPERVISOR"), the firewall, both specs, and ~12 supervisor-authenticated tests in `AgentControllerTest` all disagree with it — the attribute is the single wrong artifact.

**Fix:** change the class attribute to `#[IsGranted('ROLE_SUPERVISOR')]`. Admins pass by hierarchy; supervisors pass directly; plain agents still 403 at the firewall and the listener. No config change, no new mechanism, one line.

**Feature:** follow the established partial-update shape — `if (array_key_exists('timezone', $data)) { $agent->setTimezone(...); }`. The existing `catch (\InvalidArgumentException)` → 400 in both methods already handles the setter's rejection, so no new error handling. Frontend defaults the control to `Intl.DateTimeFormat().resolvedOptions().timeZone` on create and to the agent's stored value on edit, with options from `Intl.supportedValuesOf('timeZone')`; server-side validation stays the only source of truth.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Controller/Admin/AgentController.php` | Modified | `timezone` in `create()`/`update()`/`serializeAgent()`; `IsGranted` → `ROLE_SUPERVISOR`; docblock |
| `apps/api/src/Domain/Agent/Agent.php` | Unchanged | Field + validated setter already complete |
| `apps/api/config/packages/security.yaml` | Unchanged | Already correct at `ROLE_SUPERVISOR` |
| `apps/api/tests/Controller/Admin/AgentControllerTest.php` | Modified | Timezone CRUD + 400 cases; explicit admin/supervisor coverage on create/update |
| `apps/api/tests/Unit/Domain/Agent/AgentTest.php` | Modified | Negative-path: `setTimezone()` throws on invalid IANA |
| `apps/web/src/app/core/api/admin-api.service.ts` | Modified | `timezone` on `AdminAgentDTO`, `CreateAgentPayload`, `UpdateAgentPayload` |
| `apps/web/src/app/core/application/admin/agents/{create,update}-agent.use-case.ts` | Modified | `timezone` in Input + payload passthrough |
| `apps/web/src/app/features/admin/agents/agents-page.component.{ts,html}` | Modified | `timezone` form control + `<select>` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Supervisor-authenticated `AgentControllerTest` cases are currently red (or not run against a live DB), masking the 403 | High | Establish a baseline run before changing code; the attribute fix should turn them green rather than alter expectations |
| Widening the attribute is misread as a privilege escalation | Low | It restores parity with the firewall, docblock, and both specs; the effective gate stays ROLE_SUPERVISOR, not looser |
| `Intl.supportedValuesOf` unsupported on legacy browsers (no polyfill precedent) | Low | Accepted evergreen-only constraint; guard with `typeof` and fall back to the current value + `'UTC'` |
| Timezone set on the wrong agent by an admin who doesn't know the agent's locale | Medium | Out of scope to solve; default to browser timezone only for create, show the value in the list |

## Rollback Plan

Single revert of the change's commits. No migration, no data backfill, no persisted state introduced — `timezone` columns already hold `'UTC'` defaults, and any values written before a revert remain valid and keep being consumed by `AgentAvailabilityService`. Reverting the `IsGranted` line alone restores the prior (stricter, spec-violating) gate if the widening is ever contested.

## Dependencies

- None. No new packages on either side.

## Success Criteria

- [ ] A supervisor can set and change an agent's timezone from the existing admin modal and see it persisted on reload.
- [ ] An admin can perform the same operations (no 403 anywhere in `AgentController`).
- [ ] An invalid IANA name returns 400 with the setter's message, from both `create()` and `update()`.
- [ ] Omitting `timezone` leaves the stored value untouched on update and defaults to `'UTC'` on create.
- [ ] `Agent::setTimezone()` has a passing negative-path unit test.
- [ ] A plain ROLE_AGENT still receives 403 on every `AgentController` route.

## Proposal question round

Product decisions 1 and 2 arrived resolved, and scope boundaries were given explicitly, so the proposal was written against them. These remain open and would refine it — answer, correct, or skip:

1. **Create default** — should a new agent default to the admin's browser timezone (proposed, fewer wrong values) or stay on `'UTC'` (matches the DB default, no surprise)?
2. **Visibility** — should `timezone` be shown as a column in the agent list, or only inside the edit modal (proposed)?
3. **Existing agents** — is leaving every current agent on `'UTC'` until someone edits them acceptable, or is a communication/backfill step expected?
4. **Blast radius of the gate fix** — `AgentController` is the only controller being corrected; should the same attribute-vs-firewall audit be run across other admin controllers now, or tracked separately?
