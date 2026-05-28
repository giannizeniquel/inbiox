# Inbiox Agents Guide

## Project Status

Greenfield - no code yet. Documentation complete in `docs/`, implementation starts with Sprint 01.

---

## Architecture

- **Stack:** Symfony 7.4, PHP 8.2+, MySQL, Angular 19, Mercure (SSE)
- **Type:** SaaS customer support platform
- **Realtime:** Mercure with JWT-based topic authorization

---

## Team Agents (SDD + Implementation)

| Agent | Model | Role |
|-------|-------|------|
| orchestrator | minimax-m2.5-free | Coordina workflow SDD, nunca implementa |
| sdd-explore | hy3-preview-free | Investiga y analiza |
| sdd-propose | big-pickle | Crea change proposals |
| sdd-spec | hy3-preview-free | Especificaciones detalladas |
| sdd-design | big-pickle | Diseño técnico |
| sdd-tasks | hy3-preview-free | Breakdown en tareas |
| sdd-apply | hy3-preview-free | Ejecuta tareas (delega a stack agents) |
| sdd-verify | big-pickle | Valida contra specs |
| sdd-archive | hy3-preview-free | Archive artifacts |

### Stack Specialists

| Agent | Model | Role |
|-------|-------|------|
| backend-dev | big-pickle | Symfony 7.4 entities, controllers, UseCases |
| frontend-dev | big-pickle | Angular components, services, signals |
| tester | hy3-preview-free | PHPUnit tests, coverage |
| documenter | hy3-preview-free | API contracts, architecture docs |
| code-reviewer | big-pickle | Code review, security, best practices |

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

## Directory Structure

```
inbiox/
├── apps/api/      # Symfony 7.4 backend (not created yet)
├── apps/web/      # Angular frontend (not created yet)
├── apps/worker/   # Background jobs (future)
├── docs/         # Product, business, architecture, sprints
├── infra/        # Docker, Mercure, configs
└── docker-compose.yml
```

---

## Local Development

- **Location:** Always work inside WSL2 (`/home/...`), NEVER in `/mnt/c`
- **Setup:** See `docs/07_dev/setup_local.md`
- **Services:** Docker with MySQL, Redis, Mercure, Mailhog

### Quick Start (when code exists)

```bash
cd /home/gianni/projects/inbiox
docker-compose up -d
docker exec -it chatapp_php bash
```

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