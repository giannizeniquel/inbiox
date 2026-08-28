# Inbiox Agents Guide

## Architecture

- **Type:** SaaS customer support platform

---

## Pipeline SDD

Model routing for the SDD pipeline and subagents: see `docs/sdd-model-routing.md`.

---

## Workflow

```
ORQUESTADOR
    │
    ├─→ SDD phases (explore → propose → spec → design → tasks)
    │         │
    │    sdd-apply───────┼──────────→ backend-dev
    │                   │                ↓
    │                   │           code-reviewer ←──← tester + documenter
    │                   │
    │                   └──────────→ frontend-dev
    │                                    ↓
    │                               code-reviewer ←──← tester + documenter
    │
    └─→ sdd-verify → sdd-archive
```

---

## Local Development

- **Location:** Always work inside WSL2 (`/home/...`), NEVER in `/mnt/c`
- **Setup:** See `docs/07_dev/setup_local.md`
- **Services:** Docker with MySQL, Redis, Mercure, Mailhog
- **Commands:** see `Makefile` (`make up`, `make bash`, `make migrate`, `make test`)

---

## Key Conventions

- Single source of truth: REST API
- Deterministic behavior: same input → same result
- Event-driven realtime: not state sync
- FSM-based workflows: no invalid transitions
- Auditability: all actions traceable

---

## Documentation

Start at `docs/README.md` for product and architecture docs.
See `docs/04_sprints/` for implementation plan (10 sprints MVP).
