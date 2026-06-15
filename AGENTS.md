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
| orchestrator | deepseek-v4-pro | Coordina workflow SDD, nunca implementa |
| sdd-explore | minimax-m3 | Investiga y analiza |
| sdd-propose | deepseek-v4-pro | Crea change proposals |
| sdd-spec | qwen3.7-plus | Especificaciones detalladas |
| sdd-design | minimax-m3 | Diseño técnico |
| sdd-tasks | qwen3.7-plus | Breakdown en tareas |
| sdd-apply | deepseek-v4-flash | Ejecuta tareas (delega a stack agents) |
| sdd-verify | minimax-m3 | Valida contra specs |
| sdd-archive | deepseek-v4-flash | Archive artifacts |

### Stack Specialists

| Agent | Model | Role |
|-------|-------|------|
| backend-dev | deepseek-v4-flash | Symfony 7.4 entities, controllers, UseCases |
| frontend-dev | deepseek-v4-flash | Angular components, services, signals |
| tester | deepseek-v4-flash | PHPUnit tests, coverage |
| documenter | deepseek-v4-flash | API contracts, architecture docs |
| code-reviewer | deepseek-v4-flash | Code review, security, best practices |

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