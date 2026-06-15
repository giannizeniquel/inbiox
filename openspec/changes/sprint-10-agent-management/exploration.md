# Sprint 10 — Agent Management: Exploration

> **Status**: Strategic exploration complete. **Ready for proposal.**
> **Mode**: `openspec` — artifact written to `openspec/changes/sprint-10-agent-management/exploration.md`.
> **Author**: sdd-explore (minimax-m3) on 2026-06-14.

---

## 1. Current State

### What "agent" means in Inbiox today

Inbiox already has a **minimal, working** agent concept. It is **not** a full agent management system. The current surface is:

| Capability | Status | Where |
|---|---|---|
| Agent CRUD (name, email, active, maxActiveTickets) | ✅ Implemented | `apps/api/src/Domain/Agent/Agent.php`, `AgentRepository.php` |
| Manual ticket assignment | ✅ Implemented | `AssignTicketUseCase.php`, `POST /tickets/{id}/assign` |
| Auto assignment (least-loaded) | ✅ Implemented | `AutoAssignTicketUseCase.php`, `POST /tickets/{id}/auto-assign` |
| Per-agent assignment audit log | ✅ Implemented | `Domain/Assignment/Assignment.php` |
| Per-agent Mercure topic (`agent/{id}/inbox`) | ✅ Implemented | `MercureJwtService.php` (line 23) |
| Per-agent reporting metrics | ✅ Implemented | `ReportingRepository::getAgentPerformance()` |
| **Weekly shifts by day-of-week** | ❌ Not implemented | (no `AgentShift` entity, no `agent_shifts` table) |
| **Per-agent timezone** | ❌ Not implemented | (no `timezone` column on `Agent`) |
| **Deterministic availability evaluation** | ❌ Not implemented | (no `AgentAvailabilityService`) |
| **Manual availability override with TTL** | ❌ Not implemented | (no `is_available_override` column) |
| **Cached `current_active_tickets` counter** | ❌ Not implemented | (counted via `COUNT(*)` on every assignment) |
| **Presence states (online/away/busy/offline)** | ❌ Not implemented | (only `active` boolean exists) |
| **Team / Queue / Role entities** | ❌ Not implemented | (no `Team`, `Queue`, `Role` anywhere) |
| **Agent ↔ User link (no auth)** | ❌ Not implemented | (system has no `User` entity — every agent call is unauthenticated) |

### Hidden inconsistencies the exploration uncovered

Three inconsistencies that Sprint 10 should be aware of, **not necessarily fix**:

1. **`Assignment.agentId` is `string(100)` but `Agent.id` is `int(bigint)`.** The `assignments` table stores agent IDs as a string (legacy from when there was no real `Agent` entity), while the `agents` table and `tickets.assigned_agent_id` use `bigint`. This is a pre-existing data model smell — every read has to be careful with type coercion. Sprint 10 does NOT need to fix this; it can keep the string column and just write integer IDs as strings. **Flagged for future refactor.**

2. **`Ticket.assignedAgentId` and the most-recent `Assignment.agentId` can drift apart.** `AutoAssignTicketUseCase` updates `tickets.assigned_agent_id` directly without writing an `Assignment` row. Conversely, `AssignTicketUseCase` writes an `Assignment` row AND calls `setAssignedAgentId` (implicitly via ticket update). The system has **two sources of truth for "who owns the ticket"**. The proposed Sprint 10 design (re-evaluating the auto-assign path) will not worsen this, but the orchestrator should know.

3. **`RealtimeEventType` enum has no `AgentPresence` or `AgentAvailability` event.** The `events.md` doc lists `agent.assigned` / `agent.unassigned` (both undocumented in code), but the actual wire enum (`RealtimeEventType.php`) has zero agent events. If Sprint 10 introduces presence, this is a v1 RealtimeEventContract impact.

### What the existing code already does well

- ✅ **Concurrency safety on assignment.** `AutoAssignTicketUseCase` uses `SELECT ... FOR UPDATE` on both ticket and agent rows in the same transaction. Capacity is computed by re-reading `tickets.assigned_agent_id` counts inside the same transaction. This is **exactly the pattern** the Sprint 10 spec asks for. **Don't reinvent it.**
- ✅ **Mercure per-agent topic is wired.** `agent/{id}/inbox` already exists. Agents can subscribe to their own inbox with a JWT.
- ✅ **Outbox pattern + `last_event_seq` on ticket.** Sprint 9B has given the platform a robust, retry-safe, sequenced realtime pipeline. Agent events should follow the same pattern, not invent a new one.
- ✅ **Assignment audit log already in place.** The `assignments` table is a full history, not just a pointer.

---

## 2. Functional Map

```
                    AGENT MANAGEMENT (Sprint 10 scope)
                    ───────────────────────────────────

   ┌──────────────────────────┐      ┌──────────────────────────┐
   │  SHIFT MANAGEMENT        │      │  AVAILABILITY            │
   │  ─────────────           │      │  ────────────            │
   │  • weekly shifts (DoW)   │      │  • override (with TTL)   │
   │  • per-agent timezone    │      │  • schedule match        │
   │  • cross-midnight        │      │  • capacity check        │
   │  • overlap (union)       │      │  • timezone-aware eval   │
   └──────────┬───────────────┘      └────────────┬─────────────┘
              │                                   │
              └─────────────┬─────────────────────┘
                            ▼
              ┌──────────────────────────┐
              │  AgentAvailabilityService│
              │  (deterministic)         │
              │  isAvailable(agent, t)   │
              │  listAvailable(now)      │
              └──────────┬───────────────┘
                         │
                         ▼
              ┌──────────────────────────┐      ┌────────────────────┐
              │  AutoAssignTicketUseCase │◀────▶│ AssignTicketUseCase│
              │  (least-loaded)          │      │ (manual)           │
              └──────────┬───────────────┘      └────────┬───────────┘
                         │                              │
                         │    ┌────────────────────────┐ │
                         └───▶│  TicketRepository      │◀┘
                              │  (FOR UPDATE on assign)│
                              └────────────┬───────────┘
                                           │
                                           ▼
                                ┌──────────────────────┐
                                │  Audit (Assignment)  │
                                │  + capacity counter  │
                                └──────────────────────┘
                                           │
                                           ▼
                                ┌──────────────────────┐
                                │  REALTIME EVENTS     │
                                │  ticket.assigned     │
                                │  agent.presence?     │
                                │  agent.availability? │
                                └──────────────────────┘

   ┌──────────────────────────┐
   │  METRICS (post-Sprint10) │
   │  ─────────               │
   │  • per-agent load        │
   │  • SLA per agent         │
   │  • assignment source     │
   │    (auto vs manual)      │
   └──────────────────────────┘
```

**Out of scope (for clarity):**
- Team management (no team entity today; not in Sprint 10)
- User/Auth (no user entity today; not in Sprint 10)
- Skill-based routing (mentioned in `0-documento-tecnico.md` 6.7 as future)
- Chatbot work (Sprint 9)
- Bot integration / multi-channel (future)

---

## 3. Competitive Benchmark

| Capability | **Inbiox today** | **Inbiox Sprint 10 (target)** | **Zendesk** | **Freshdesk** | **Intercom** | **Respond.io** | **WATI** |
|---|---|---|---|---|---|---|---|
| **Manual assignment** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Auto-assignment (round-robin / least-loaded)** | ✅ (least-loaded only) | ✅ + capacity counter | ✅ multiple strategies | ✅ | ✅ | ✅ | ⚠️ limited |
| **Per-agent capacity (max open tickets)** | ✅ (maxActiveTickets int) | ✅ + cached counter | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| **Weekly shifts per agent** | ❌ | ✅ | ⚠️ (only schedules per group) | ⚠️ | ❌ | ⚠️ | ❌ |
| **Per-agent timezone** | ❌ | ✅ (IANA TZ, UTC storage) | ⚠️ (TZ for SLA only) | ⚠️ | ⚠️ | ⚠️ | ❌ |
| **Manual override (e.g. "AFK 1h")** | ❌ | ✅ (with TTL) | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Capacity-aware real-time load bar** | ❌ | ✅ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| **Per-number assignment rules** | ❌ | ❌ (post-MVP) | ✅ | ✅ | ⚠️ | ✅ | ⚠️ |
| **Skill-based routing** | ❌ | ❌ (post-MVP, mentioned in 0-documento-tecnico.md 6.7) | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| **SLA-aware escalation** | ❌ (basic SLA only) | ❌ (post-MVP) | ✅ (advanced) | ✅ | ✅ | ⚠️ | ❌ |
| **Team / Group concept** | ❌ | ❌ (post-MVP) | ✅ | ✅ | ✅ (teams) | ✅ | ⚠️ |
| **Multi-tenant** | ❌ | ❌ (out of scope) | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| **Realtime per-agent push** | ✅ (`agent/{id}/inbox` topic) | ✅ + presence event | ⚠️ | ⚠️ | ✅ | ⚠️ | ❌ |
| **Agent leaderboard (realtime)** | ❌ | ✅ (in reports MVP) | ✅ | ✅ | ✅ | ⚠️ | ❌ |

**Reading the table:**
- The ✅ marks for Inbiox Sprint 10 are the **specific** capabilities that the existing implementation spec delivers.
- The big gaps to competitors are: **per-number rules, skills, escalation, multi-tenant, teams**. None of these is in the Sprint 10 spec — they are **post-MVP roadmap candidates** (see §12).
- The **unique-to-Inbiox** capabilities the spec delivers: **per-agent timezone + cross-midnight shifts + TTL-based manual override**. None of the listed competitors has this exact combination in this simple form. **This is the differentiator foundation.**

---

## 4. Differentiator Opportunities

The existing Sprint 10 spec is **conservative and correct** — it nails the foundation. The strategic question is: **where can Inbiox beat competitors without overbuilding?** Here are concrete opportunities, ordered by impact-to-effort ratio.

### 4.1 HIGH IMPACT, LOW EFFORT (include in Sprint 10 MVP)

#### A. Per-agent timezone with cross-midnight shifts
- **What**: `Agent.timezone (IANA)` + `AgentShift` (day-of-week, start_time, end_time, cross-midnight aware).
- **Why it wins**: Most WhatsApp support teams in LATAM are distributed or have night/weekend shifts. Zendesk and Freshdesk model this with "business hours" at the **group** level, not the agent. The per-agent, cross-midnight model is **operationally simpler for small teams** than configuring group business hours.
- **Effort**: Already in the spec. ✅

#### B. Manual override with TTL ("brb 1h")
- **What**: `POST /agents/{id}/availability` with `ttl_minutes: 120`. Agent can pause routing without deactivating. Resets automatically.
- **Why it wins**: The **most common operational pain** in WhatsApp support is: an agent steps away to grab coffee, forgets to switch off auto-assignment, and gets 4 new tickets. Competitors solve this with presence states, but those require an "Away" toggle. A TTL-based override is **simpler**: "BRB 1h" → automatically reverts. **The TTL is the killer feature.** No competitor has it in this simple form.
- **Effort**: Already in the spec. ✅
- **Real-world value**: Higher than it looks. The supervisor and the agent both benefit.

#### C. Cached `current_active_tickets` counter
- **What**: `Agent.current_active_tickets INT` maintained inside the same transaction as the assignment change.
- **Why it wins**: Currently every auto-assign runs a `COUNT(*)` over active tickets. For a 50-agent team with 500 open tickets this is fine; at 5,000 open tickets the lock contention starts to hurt. The cached counter (with `SELECT FOR UPDATE` on the agent row) is **synchronous, deterministic, and cheap**.
- **Effort**: Already in the spec. ✅
- **Real-world value**: Not user-facing, but **protects Sprint 10 from the "it works at 10 agents, breaks at 200" trap**.

### 4.2 HIGH IMPACT, MEDIUM EFFORT (post-MVP, but cheap to design now)

#### D. Capacity bar in the inbox (UI)
- **What**: The Angular inbox shows a side panel "Team: 3/5 active" with each agent's current load as a thin bar. Self-balancing by social pressure.
- **Why it wins**: **Zendesk, Intercom, and Respond.io have this. Inbiox does not.** The reports page has an `agent-leaderboard` component but it doesn't show real-time load. Bringing the existing leaderboard data into the inbox sidebar (subscribed to `agent/{id}/inbox`) is the same data, different place.
- **Effort**: ~1 day of frontend work + 1 day of wiring realtime updates to agent load.
- **Real-world value**: **Massive**. Team leads stop having to ask "¿quién puede tomar este?" — the UI answers it visually.

#### E. One-click "Reassign" with audit note
- **What**: Already exists (`POST /tickets/{id}/assign`), but the UI surfaces it as "Take over" vs "Reassign to..." with a one-line note. The note becomes the audit entry.
- **Why it wins**: **Every** WhatsApp support team in 3-50 agent range has the pattern "I took this from Maria, she was at lunch." Making the note required (or strongly prompted) gives you the **handoff audit trail for free**.
- **Effort**: ~0.5 day frontend + DTO update.
- **Real-world value**: High. Operationally simple.

### 4.3 MEDIUM IMPACT, MEDIUM EFFORT (next sprint after Sprint 10)

#### F. SLA-aware visual escalation
- **What**: When a ticket is `waiting` or `new` and crosses an SLA threshold, the UI shows a red "1h 12m past SLA" badge and a "Reassign" button suggests team leads.
- **Why it wins**: The current system has SLA timestamps but **no UI surfaces them per agent**. The data is there; the surfacing is missing.
- **Effort**: ~2 days frontend + 1 day wiring.
- **Real-world value**: Medium-high.

#### G. WhatsApp-template-style "internal note" on ticket
- **What**: A `Message` with `direction = 'internal'` and `visibility = 'agent_only'` that doesn't go to the customer, but is part of the timeline.
- **Why it wins**: **Every** agent in **every** support team uses this pattern ("@Maria, I already refunded him, just confirm with the customer"). It's standard in Intercom, Zendesk, and Help Scout. The data model already supports it (`Message` table can take a new visibility enum value).
- **Effort**: ~2 days. Domain change is small.
- **Real-world value**: Very high.

### 4.4 NOT in Sprint 10 (post-roadmap)

- Skill-based routing — mentioned in `0-documento-tecnico.md` 6.7 as future
- Per-number assignment rules ("Sales line agents" vs "Support line agents")
- Team/Group entity
- Multi-tenant
- ML-based assignment
- SLA-based auto-escalation to a manager

### Strategic conclusion

> **Sprint 10 should ship the foundation (4.1) perfectly.** Items 4.2 (D, E) and 4.3 (F, G) should be considered in the **post-MVP roadmap** (§12) but **must NOT bloat the MVP**. The biggest risk is the spec already covers 4.1; trying to also ship 4.2-4.3 in the same sprint will damage quality of the foundation.

---

## 5. Agent Model Analysis

### What the spec proposes

| Entity | New / Update | Fields |
|---|---|---|
| `Agent` | **UPDATE** | + `timezone (VARCHAR, IANA, NOT NULL)` + `is_available_override (BOOLEAN, NULLABLE)` + `override_until (DATETIME_IMMUTABLE, NULLABLE)` + `current_active_tickets (INT, NOT NULL, default 0)` |
| `AgentShift` | **NEW** | id, agent_id (FK), day_of_week (1-7), start_time (TIME), end_time (TIME) |

### Recommended entities and their relationships

```
┌────────────┐         ┌─────────────────┐
│   Agent    │  1───N  │   AgentShift    │
│            │────────▶│                 │
│  id        │         │  id             │
│  name      │         │  agent_id (FK)  │
│  email     │         │  day_of_week    │
│  active    │         │  start_time     │
│  timezone  │         │  end_time       │
│  max_active│         └─────────────────┘
│  curr_act  │
│  override  │         ┌─────────────────┐
│  override_ │         │  Ticket         │
│   until    │  1───N  │                 │
│  created_at│────────▶│  assigned_      │
│  updated_at│         │   agent_id (FK) │
└────────────┘         └─────────────────┘
```

**Decisions to validate:**

1. **Should `Agent` belong to a `Team`?**
   - **Recommendation: NO for Sprint 10.**
   - Rationale: 3-50 agent teams typically have a single "team" (the whole company). The "team" concept is meaningful at 50+ agents. Adding a Team entity now forces us to model team-level capacity, team-level routing, team-level reporting — all of which are post-MVP.
   - When teams arrive, the `Agent → Team` relation is a 1-N relation (`Agent.team_id`) — **additive**, doesn't break Sprint 10.

2. **Should a `Ticket` be owned by a `Team` OR an `Agent`?**
   - **Recommendation: Agent only. The "team" of a ticket is inferred from the owner's team.**
   - Rationale: Two sources of truth for ownership is a known pain point (see §1, hidden inconsistency #2). Adding a team as owner **on top of** agent owner makes it worse. Inferred ownership keeps it clean.

3. **Should a ticket have multiple agents (participants)?**
   - **Recommendation: Already exists in the data model (`0-documento-tecnico.md` 6.9: `ticket_participants` with "1 owner + multiple participants") but NOT implemented yet.**
   - **Decision needed from the orchestrator/user**: Is this in scope for Sprint 10, or do we defer it? **Recommendation: DEFER.** The "owner only" model handles 90% of real-world cases. Participants are a UX feature that depends on the internal-note feature (4.3.G) to be useful.

4. **Should `current_active_tickets` be a cached counter on `Agent` or computed on read?**
   - **Recommendation: cached counter (already in the spec).**
   - Rationale: At 3-50 agents the COUNT(*) is fast. The real reason for caching is **transactional consistency with `SELECT FOR UPDATE` on the agent row**. If we don't cache, the auto-assign can race (read-then-write) and over-allocate. The cached counter inside the same transaction is the only race-free way to enforce the limit.
   - Trade-off: more bookkeeping (must increment on assign, decrement on resolve/close). But the bookkeeping is straightforward and lives in the same `TransitionTicketStatusUseCase` flow.

5. **Should `AgentShift` support multiple shifts per day (split shifts)?**
   - **Recommendation: YES, by design** (the spec's "UNIQUE(agent_id, day_of_week, start_time, end_time)" already allows it; "Shifts MAY overlap — treated as union").
   - Rationale: Common in real schedules (lunch break = 1h gap). The union semantic is what the spec says, so just honor it.

6. **Should there be a `shift_exception` table (date-specific overrides, e.g. "Off Friday March 14")?**
   - **Recommendation: NO for Sprint 10. Defer.**
   - Rationale: The TTL manual override already covers "I'm off today" with `is_available_override = false, override_until = null`. Adding a per-date exception table before the per-week shift table is widely used is overbuilding.

---

## 6. Operational States

### What the spec proposes

The spec doesn't explicitly define a presence state model. The current `Agent` has:
- `active: bool` (account active/disabled — admin-controlled)
- `is_available: bool` (routing-eligible — admin-controlled)

After Sprint 10 it adds:
- `is_available_override: bool | null`
- `override_until: datetime | null`
- (still no "presence" state — online/away/busy/offline)

### Recommendation: **Don't introduce presence states in Sprint 10**

This is the **single most important strategic call** in this exploration.

**Rationale:**

- **Presence is a UX feature, not a routing feature.** The current auto-assign already filters by `active + is_available + capacity`. Presence states add a third filter that **the system doesn't need** for routing — it just needs the override.
- **Auto-presence (detect browser open) is unreliable.** It works in apps where you have a native client (Slack). In a browser-based inbox it requires a heartbeat (which is a websocket/Mercure concern), and **gets it wrong on tab background, browser sleep, multi-tab**.
- **Manual presence is the right MVP.** The override-with-TTL is the simplest possible manual presence: "I'm brb 1h" → automatically comes back. **It IS presence, but with a TTL built in.** No new "online" state needed.

**Proposed state model (intentionally minimal):**

| State | Driven by | Affects routing? | TTL? |
|---|---|---|---|
| Account: `active` / `inactive` | Admin | YES (inactive never assigned) | No |
| Override: `null` (no override) / `true` / `false` | Agent (via UI) | YES (overrides schedule) | YES (auto-expires) |
| Schedule: matched / not matched | Time + timezone + shifts | YES (when no override) | Implicit (re-evaluated every assignment) |
| Capacity: under / at max | Tickets counter | YES | No |

**Transition rules:**

- Override with `override_until = null` = **persistent** (agent is on vacation)
- Override with `override_until = T` = **expires at T**, then re-evaluates schedule
- An admin setting `is_available = false` should clear any override (admin override wins)
- If override is set but `active = false` → still not assignable (admin always wins)

**Post-MVP presence:** A future sprint can add `AgentSession` (login/logout events) and heartbeat-based presence if user demand justifies it. **Don't pre-build it.**

---

## 7. Metrics Strategy

### Current state

`ReportingRepository` already has per-agent metrics:
- `tickets_assigned` (count)
- `tickets_resolved` (count)
- `avg_frt_minutes`
- `avg_rt_minutes`

The frontend `agent-leaderboard.component.ts` has a "load bar" but it's computed as `tickets_assigned * 20` — **a fake metric** because the report query doesn't include current open count.

### MVP for Sprint 10

**MUST add:**
1. **`current_active_tickets` per agent** in the agent metrics query (real, not synthetic). This is a SQL `COUNT(*)` over the `tickets` table joined to `agents` where status is active. **Cheap** for 3-50 agents.
2. **Agent name in the report response** (currently the frontend shows `Agent ${id}`). Join the `agents` table on the query.

**SHOULD add:**
3. **Assignment source** (auto / manual) — already tracked via `assignments.assigned_by`. Add it to the report.
4. **Per-agent FRT distribution** (p50, p90) — supports the leaderboard's "response time" column with a more useful metric than just average. **Defer to post-MVP** — average is fine for MVP.

**DEFER to post-MVP:**
- SLA compliance per agent (requires SLA engine maturation, see `sprint_07 (Workflow + SLA base).md`)
- Per-number breakdown (requires per-number assignment rules)
- "Time in state" per ticket per agent

### Data structures

The current `ReportingRepository::getAgentPerformance()` returns:
```php
[
    'agent_id' => int,
    'tickets_assigned' => int,
    'tickets_resolved' => int,
    'avg_frt_seconds' => float|null,
    'avg_frt_minutes' => float|null,
    'avg_rt_seconds' => float|null,
    'avg_rt_minutes' => float|null,
]
```

**Sprint 10 should add** (minimal change):
```php
[
    // ... existing fields ...
    'agent_name' => string,  // JOIN with agents table
    'current_active_tickets' => int,  // real load, not synthetic
    'is_available' => bool,  // reflects current effective availability
    'is_in_shift' => bool,   // for context in leaderboard
]
```

**No new tables needed.** No new endpoints. The existing `GET /reports/agents` endpoint is the integration point.

---

## 8. Architecture Impact

### 8.1 Realtime (Mercure SSE)

**New events needed:**

| Event | When | Topic | Payload |
|---|---|---|---|
| `agent.availability_changed` | Override set / cleared / expired | `agent/{id}/availability` + `agents` (broadcast) | `{ agentId, isAvailable, source: 'override'\|'schedule'\|'capacity', changedAt }` |
| `agent.shift_changed` | Shift created/updated/deleted | `agent/{id}/shifts` + `agents` (broadcast) | `{ agentId, shiftId, action }` |

**Why two topics:** The `agent/{id}/...` per-agent topic is for the agent's own UI (their own settings page). The `agents` broadcast topic is for the inbox sidebar (capacity bars need everyone's state).

**Impact on `RealtimeEventType`:** This is a **RealtimeEventContract v1 → v2** addition. **Compatibility:** additive, no v1 field is removed, so the frontend can opt in.

**Impact on `MercureJwtService`:** The `generateToken(agentId, ...)` method already takes the topics list. Add `agent/{id}/availability` and the `agents` broadcast (broadcast should be limited to the agent's org — no public broadcast). For dev, the existing wildcard pattern works.

**Risk: auth.** `MercureTokenController::getToken()` does **not** authenticate the caller. Anyone can ask for a token claiming to be agent #5. This is a pre-existing security issue. **Sprint 10 should not fix it** (out of scope) but the orchestrator should know that **any per-agent presence event is currently readable by any caller**. Document it as a known risk.

### 8.2 Frontend stores (Angular signals)

**New store: `AgentStore`** in `apps/web/src/app/core/stores/agent.store.ts`
- `agents: Signal<Agent[]>` — list of all agents
- `currentAgent: Signal<Agent | null>` — the agent viewing the UI (post-MVP: tied to login)
- `loadAgents(): Promise<void>` — calls `GET /agents`
- `setAvailability(isAvailable, ttlMinutes)` — calls `POST /agents/{id}/availability`
- `setShift(...)` / `deleteShift(...)` — calls shift endpoints

**Update: `ConnectionStore` / `MercureService`** to subscribe to the new topics:
- `agent/{id}/availability` (for own agent's settings page)
- `agents` (for inbox sidebar — only subscribed if user can see "all agents")

**Impact: minimal.** The Angular layer is already organized around signals + use-cases.

### 8.3 Reports

See §7. Add two fields to the existing query, no new tables, no new endpoints. The frontend `agent-leaderboard.component.ts` gets cleaner (real names, real load).

### 8.4 Multi-number (CAP-7)

**Current state:** `tickets.phone_number_id` and `conversations.phone_number_id` are set. The `HandleInboundMessageUseCase` propagates `phoneNumberId` to ticket/conversation on create.

**Impact of Sprint 10:** **None if we follow the spec.** The spec's `AgentAvailabilityService` and `AutoAssignTicketUseCase` are number-agnostic. Tickets with `phone_number_id = X` go to the same agent pool as tickets with `phone_number_id = Y`.

**Strategic question for the orchestrator:** Should Sprint 10 consider per-number assignment rules?
- **Recommendation: NO.** Per-number rules imply per-number agent pools, which implies a Team/Group concept. The Team concept is post-MVP.
- **Simple alternative for now:** A future field `Agent.handles_number_ids (JSON)` would let the system filter eligible agents per number. **Defer.**

### 8.5 Future scalability

- **100 agents / 10,000 tickets:** fine. The cached counter avoids the COUNT(*) bottleneck.
- **1,000 agents / 100,000 tickets:** the per-agent topic count (one Mercure subscription per online agent) starts to matter. Mercure scales well here but the JWT for each agent becomes a hot path. **Not a Sprint 10 problem.**
- **10,000 agents:** the Agent table is indexed correctly. Shifts need a composite index on `(agent_id, day_of_week)` (the spec has this ✅). The cached counter is the right call. Still fine.

### 8.6 Database migrations

Sprint 10 will add **two new tables** and **alter one table**:
- `agents` (ALTER): add `timezone`, `is_available_override`, `override_until`, `current_active_tickets`
- `agent_shifts` (NEW): id, agent_id, day_of_week, start_time, end_time + UNIQUE(agent_id, day_of_week, start_time, end_time) + INDEX(agent_id, day_of_week)
- (no third table — `Assignment` is unchanged)

The migration is **additive** and **non-destructive**. Default for `timezone` should be `'UTC'` for safety. The `current_active_tickets` backfill must be done in a single `UPDATE agents a SET current_active_tickets = (SELECT COUNT(*) FROM tickets t WHERE t.assigned_agent_id = a.id AND t.status IN ('new','active','waiting'))` — **this is a long-running query** if the tickets table is big. **Schedule it during a low-traffic window or chunk it.**

---

## 9. Findings Classification

### Critical (block proposal until resolved)

- **C1. The data type of `Assignment.agent_id` (string 100 chars) is inconsistent with `Agent.id` (int bigint).** The spec says nothing about this. The proposal must explicitly state: "we KEEP the string column and cast int → string on write". This is the least-risk path. **Do not migrate the column type.**
- **C2. `autoAssign` does NOT write an `Assignment` row.** The spec asks the auto-assign path to integrate with `AutoAssignTicketUseCase`, but the current implementation bypasses the `Assignment` audit table. The proposal must decide: does Sprint 10 force auto-assign to write an `Assignment` row? **Recommendation: YES, for audit consistency. But the spec doesn't require it.** This is a call the orchestrator should validate.

### High (mitigate in design)

- **H1. Mercure topic authorization is not actually authenticated.** The `/api/mercure/token` endpoint is open. Any new `agent/{id}/availability` topic added in Sprint 10 inherits this risk. **Mitigation: scope the topic auth to "the agent can subscribe to their own topics" via a JWT claim, even without a real login. Post-MVP: real auth.**
- **H2. `current_active_tickets` backfill may lock the tickets table.** Use a chunked update or a maintenance window.
- **H3. Cross-midnight shift evaluation is tricky in SQL.** PHP-side evaluation (as the spec proposes) is fine, but the spec should mandate that the **evaluation is unit-tested** for the cross-midnight case (already a test case in the spec, ✅).
- **H4. Override TTL with no `override_until` (null) means "persistent".** This is a real UX risk: an agent clicks "brb" and forgets about it, the override persists forever. **Mitigation: a daily "stale override" check** OR a UI warning when an override is older than 24h with no TTL. **For MVP, just the TTL parameter is required; null = user explicitly opted into persistent.** Already in the spec ✅.

### Medium (note, monitor)

- **M1. The `agents` table has no `email` UNIQUE among soft-deleted rows.** If you soft-delete an agent and re-create with the same email, the unique constraint fails. **Not in Sprint 10 scope.**
- **M2. The existing `AutoAssignTicketUseCase` filters by `active AND is_available`.** The new `AgentAvailabilityService.isAvailable()` should be a **superset** of these checks (it must include override + schedule + capacity). **The new check MUST be called instead of the old hardcoded check** — otherwise the two diverge.
- **M3. `ReportingRepository::getAgentPerformance` does not filter by `phone_number_id`.** Post-Sprint 10, if a future sprint adds per-number agent pools, this query needs to grow. **Not Sprint 10.**

### Low (informational)

- **L1. The frontend `agent-leaderboard` shows `Agent ${id}` as the name today.** The fix is JOINing the `agents` table. Trivial.
- **L2. The `events.md` doc lists events that aren't in the code** (`agent.assigned`, `agent.unassigned`). Out of date. Not Sprint 10's problem.

---

## 10. Capability Matrix

| Capability | **Inbiox before Sprint 10** | **Inbiox after Sprint 10 (MVP)** | **Zendesk** | **Freshdesk** | **Intercom** | **Respond.io** | **WATI** |
|---|---|---|---|---|---|---|---|
| Manual assignment | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Auto-assignment (least-loaded) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| Per-agent max concurrent tickets | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| **Cached current-load counter (race-free)** | ❌ | ✅ | ✅ | ⚠️ | ✅ | ⚠️ | ❌ |
| Weekly shifts per agent | ❌ | ✅ | ⚠️ (group) | ⚠️ | ❌ | ⚠️ | ❌ |
| Per-agent IANA timezone | ❌ | ✅ | ⚠️ | ⚠️ | ⚠️ | ⚠️ | ❌ |
| Cross-midnight shifts | ❌ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| Manual override with TTL | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Persistent override (vacation) | ❌ | ✅ | ⚠️ | ⚠️ | ⚠️ | ⚠️ | ❌ |
| Capacity bar in inbox UI | ❌ | ⚠️ (needs UI work) | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Per-number assignment rules | ❌ | ❌ | ✅ | ✅ | ⚠️ | ✅ | ⚠️ |
| Skill-based routing | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| SLA-aware escalation | ❌ | ❌ | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| Team / Group entity | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| Multi-tenant | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| Agent presence (online/away/busy) | ❌ | ❌ (deferred) | ✅ | ✅ | ✅ | ✅ | ⚠️ |
| Internal notes on ticket | ❌ | ❌ (deferred) | ✅ | ✅ | ✅ | ⚠️ | ❌ |
| WhatsApp-specific routing rules | ❌ | ❌ | ⚠️ | ⚠️ | ⚠️ | ✅ | ✅ |

**Reading the matrix:**
- Sprint 10 makes Inbiox **competitive on the foundation** (shifts, timezone, override) with a **unique TTL override** that no major competitor has in this exact form.
- Sprint 10 does **not** close the gap on **skills, per-number rules, escalation, teams**. These are real gaps but **post-MVP** (§12).
- **Inbiox's niche positioning** vs competitors: WhatsApp-first, 3-50 agent teams, simple operations. **Sprint 10 reinforces that positioning** — none of the foundation capabilities are "enterprise-grade complexity".

---

## 11. MVP Scope Recommendation

### What MUST be in Sprint 10 (the foundation)

| # | Capability | Why must-ship |
|---|---|---|
| 1 | `Agent` entity update: `timezone`, `is_available_override`, `override_until`, `current_active_tickets` | Foundation. No shifts/availability without this. |
| 2 | `AgentShift` entity (weekly, by DoW, with cross-midnight support) | Foundation. |
| 3 | `AgentAvailabilityService` (deterministic, timezone-aware, override-first, then schedule, then capacity) | Foundation. **Single source of truth for "is this agent available now?"** |
| 4 | `AutoAssignTicketUseCase` integration: **replace** the hardcoded `active AND is_available` check with `AgentAvailabilityService::listAvailableAgents()`. Maintain `SELECT FOR UPDATE` on agent row. Increment `current_active_tickets` inside the same transaction. | The whole point of Sprint 10. |
| 5 | `POST /agents/{id}/availability` and `DELETE /agents/{id}/availability` (override with TTL) | The single most user-visible differentiator. |
| 6 | `POST /agents/{id}/shifts` and `DELETE /agents/{id}/shifts/{id}` | Required to populate `AgentAvailabilityService`. |
| 7 | Concurrency safety: capacity counter is updated **inside the same transaction** as the assignment change, with `SELECT FOR UPDATE` on the agent row | Already proven pattern in current code. Preserve it. |
| 8 | Migrations: ALTER `agents`, CREATE `agent_shifts`, backfill `current_active_tickets` | Required. |
| 9 | Tests: 10 scenarios from the spec, plus 2 added ones (capacity counter consistency, override expiration race) | Required. |
| 10 | Documentation: API contract for new endpoints, update `events.md` | Required. |

### What SHOULD NOT be in Sprint 10 MVP

| Capability | Why defer | When |
|---|---|---|
| Agent presence (online/away/busy/offline) | UX feature, not routing feature. Override-with-TTL is the simpler MVP presence. | Next sprint |
| Skill-based routing | Out of `0-documento-tecnico.md` 6.7 "future" scope | Future sprint |
| Per-number assignment rules | Requires Team/Group concept | Future sprint |
| Team/Group entity | Out of MVP scope (single-tenant single-team) | Future sprint |
| SLA-aware escalation | Requires SLA engine maturation (basic SLA is in Sprint 7) | Future sprint |
| Capacity bar in inbox UI (item 4.2.D) | Frontend work, depends on backend realtime presence | Next sprint (post-10) |
| Internal notes on ticket (item 4.3.G) | Message domain change, deserves its own sprint | Future sprint |
| One-click "Reassign" with audit note (item 4.2.E) | Mostly UI work, doable next sprint | Next sprint |
| User/Auth (no current User entity) | Big, cross-cutting, deserves its own sprint | Future sprint |
| Auto re-evaluation cron (re-evaluate expired overrides) | Override is checked on read; cron is unnecessary if every assignment re-evaluates | Already handled by read-time eval |

### Definition of Done for Sprint 10 MVP

- [ ] `AgentAvailabilityService` has 100% unit test coverage on the 10 spec scenarios + 2 added scenarios (capacity consistency, override expiration race).
- [ ] `AutoAssignTicketUseCase` integration test proves no over-allocation under 50 concurrent assignment requests.
- [ ] A real `RebalanceUnassignedTicketsUseCase` exists and is tested (the spec mentions it).
- [ ] Migration runs and backfills `current_active_tickets` correctly on a 10k-ticket dataset.
- [ ] A small HTTP-level integration test (Postman collection or phpunit WebTestCase) exercises the override endpoint with and without TTL.
- [ ] `docs/realtime/events.md` is updated to document the new `agent.availability_changed` and `agent.shift_changed` events.
- [ ] The existing `agent-leaderboard` component shows real names (not `Agent ${id}`) and real load (not synthetic).

### Forecast: lines of code & PR size

Following `skills/_shared/sdd-phase-common.md` §E (400-line review budget):

- New code: `Agent` entity update (~30 lines), `AgentShift` entity (~80 lines), `AgentAvailabilityService` (~250 lines), shift CRUD endpoints (~100 lines), override endpoints (~80 lines), `AutoAssignTicketUseCase` update (~50 lines net), `RebalanceUnassignedTicketsUseCase` (~80 lines), migration (~50 lines), tests (~500 lines), doc updates (~100 lines).
- **Total: ~1,300 lines.**
- **400-line budget risk: HIGH.** This is a clear case for **chained PRs**.

**Recommended PR slicing** (proposal should formalize this):

1. **PR #1 — Foundation data model:** `Agent` updates, `AgentShift` entity, migration (with backfill). ~250 lines.
2. **PR #2 — Availability service:** `AgentAvailabilityService` + 10 test scenarios. ~400 lines.
3. **PR #3 — Assignment integration:** `AutoAssignTicketUseCase` rewrite to use the service, rebalance use case. ~250 lines.
4. **PR #4 — Endpoints + realtime events:** shift CRUD, override endpoints, new Mercure events. ~250 lines.
5. **PR #5 — Reports + frontend touch-ups:** real names + real load in agent leaderboard, `events.md` update. ~150 lines.

Each PR is self-contained, has clear test surface, and respects the 400-line budget. **The orchestrator should plan for chained PRs at proposal time.**

---

## 12. Post-MVP Roadmap

The right ordering after Sprint 10 (not all of these; just the next 2-3 most impactful):

| Priority | Capability | Sprint candidate | Why this order |
|---|---|---|---|
| **P1** | **Capacity bar in inbox UI** (item 4.2.D) | Sprint 10.5 or 11 | Highest user-facing value, low cost, depends on Sprint 10's realtime presence events. |
| **P1** | **One-click Reassign with required note** (item 4.2.E) | Same as above | The audit log already exists; this is mostly UI + a "note is required" DTO validation. |
| **P2** | **SLA-aware visual escalation** (item 4.2.F) | Sprint 11 or 12 | The data exists (`first_response_at`, `resolved_at`); the surfacing doesn't. Depends on FRT/RT reports being real. |
| **P2** | **Internal notes on ticket** (item 4.3.G) | Sprint 12 | Requires a `Message` domain change (new `visibility` enum value). Useful in 90% of real teams. |
| **P3** | **Agent presence (online/away/busy)** with heartbeat | Sprint 13 | UX feature, not routing. Depends on a real auth (User entity) to be done right. |
| **P3** | **Team/Group entity** | Sprint 14 | Gates per-number assignment rules. Big schema change. |
| **P3** | **Per-number agent pools** (`Agent.handles_number_ids`) | Sprint 15 | Natural follow-on to Teams. |
| **P4** | **Skill-based routing** | Sprint 16+ | Mentioned in `0-documento-tecnico.md` 6.7 as future. |
| **P4** | **SLA-driven auto-escalation** | Sprint 16+ | Requires a real SLA engine (not just timestamps). |
| **P5** | **User/Auth/SSO** | Cross-cutting, blocks multi-tenant | Most important but biggest — its own multi-sprint effort. |
| **P5** | **Multi-tenant** | After User/Auth | The natural SaaS endpoint state. |

**Strategic note for the product owner:** Items P1 (capacity bar + one-click reassign with note) together take ~3 days of work and are the **single highest user-visible improvement** that follows Sprint 10. **Do them as soon as Sprint 10 is verified.**

---

## 13. Recommendation

### Ready for Proposal: **YES, with clarifications**

The exploration confirms that:

1. **The spec is sound and worth implementing as-is for the MVP.** The four foundational capabilities (per-agent timezone, weekly shifts with cross-midnight, manual override with TTL, cached capacity counter) are the right MVP slice for a 3-50 agent WhatsApp support team.

2. **Three clarifications are required before the proposal is written:**

   - **Q1 (to product/owner):** Should auto-assignment write an `Assignment` row for audit consistency? (Currently it does not.) The spec implies it should, but the design doc is silent. **My recommendation: YES, write the row.**
   - **Q2 (to product/owner):** Is the "1 owner + N participants" ticket model in scope for Sprint 10, or deferred? `0-documento-tecnico.md` 6.9 mentions it but it's not in the spec. **My recommendation: DEFER.**
   - **Q3 (to product/owner):** Are the post-MVP items (capacity bar, internal notes, presence) something the team wants as a follow-up sprint, or are they out of scope? **My recommendation: plan for capacity bar + one-click reassign as Sprint 10.5; defer the rest.**

3. **Strategic call to validate:** **Don't introduce presence states in Sprint 10.** The override-with-TTL is the simpler MVP presence. A presence state machine is a UX feature, not a routing feature, and gets in the way of the foundation.

4. **Engineering call to validate:** **Force `autoAssign` to write an `Assignment` row.** This is the cleanest path to a single source of truth for "who owns this ticket" and the audit trail. The current `autoAssign` bypasses the audit table.

5. **PR plan:** Sprint 10 will be **~1,300 lines**, which **exceeds the 400-line review budget**. **The proposal MUST plan chained PRs (5 slices)**, per `skills/_shared/sdd-phase-common.md` §E.

### Risks at a glance

| Risk | Severity | Mitigation |
|---|---|---|
| `Assignment.agentId` is string, `Agent.id` is int (legacy) | C1 | Cast on write. Do not migrate column type. |
| `autoAssign` does not write `Assignment` row (audit gap) | C2 | Force it to write the row. |
| Mercure topic auth is not actually authenticated | H1 | Document as known. Real auth is its own sprint. |
| `current_active_tickets` backfill may lock the tickets table | H2 | Chunked update or maintenance window. |
| Override TTL with null = persistent (UX risk) | H4 | UI warning for stale overrides post-MVP. |
| 1,300 LOC > 400-line review budget | E1 (per sdd-phase-common §E) | **Chained PRs**, 5 slices. |

### What the orchestrator should tell the user

> "Sprint 10 is **ready to move to proposal**. The strategic exploration confirms the existing spec is **correct for MVP** and identifies three differentiators (per-agent timezone with cross-midnight, manual override with TTL, cached capacity counter) that no major competitor has in this simple form. The proposal must plan **chained PRs** (5 slices, ~250-400 lines each) because total scope is ~1,300 lines. Three product clarifications are needed first: (1) should auto-assign write an Assignment audit row, (2) is the 'multiple participants per ticket' model in scope, (3) is there appetite for a follow-up sprint with the capacity bar UI. Strategic call I'm making: **do not introduce presence states in Sprint 10** — the override-with-TTL is the simpler MVP presence. The presence state machine is a UX feature for a later sprint."

### Artifacts

- **File:** `openspec/changes/sprint-10-agent-management/exploration.md` (this document)
- **Change folder:** `openspec/changes/sprint-10-agent-management/`

### Next phase

**sdd-propose** — write the change proposal with:
- Intent: ship the foundation described in §11.
- Scope: explicit list of "in" and "out" (use §11 directly).
- Approach: chained PRs (5 slices, §11).
- Rollback plan: per-PR rollback (the foundation is additive; each PR can be reverted without breaking the others).
- The 3 product clarifications from §13 must be resolved before the proposal is finalized.
