# Inbiox Agents Guide

## Project Status

Greenfield - no code yet. Documentation complete in `docs/`, implementation starts with Sprint 01.

---

## Architecture

- **Stack:** Symfony 7.4, PHP 8.2+, MySQL, Angular 19, Mercure (SSE)
- **Type:** SaaS customer support platform
- **Realtime:** Mercure with JWT-based topic authorization

---

## Team Agents (SDD Phases)

| Fase | Primario | Backup / Soporte | Límite / 5h | Rol |
|------|----------|------------------|-------------|-----|
| orchestrator | DeepSeek V4 Pro | MiniMax M3 | 3.450 / 3.200 | Control e iteración continua |
| sdd-explore | MiniMax M2.7 | MiMo-V2.5-Pro | 3.400 / 3.250 | Lectura del código base |
| sdd-propose | DeepSeek V4 Pro | Qwen3.7 Max (refinador) | 3.450 / 950 | Propuesta + veredicto final |
| sdd-spec | Qwen3.7 Plus | Hy3 | 4.300 / 4.300 | Escritura estructurada de requerimientos |
| sdd-design | MiniMax M3 | MiMo-V2.5-Pro | 3.200 / 3.250 | Diseño arquitectónico profundo |
| sdd-tasks | Qwen3.7 Plus | Qwen3.6 Plus | 4.300 / 3.300 | Breakdown mecánico de tareas |
| sdd-apply | DeepSeek V4 Flash | MiMo-V2.5 | 31.650 / 30.100 | Codificación masiva — 61k+ peticiones |
| sdd-verify | MiniMax M2.7 | Hy3 | 3.400 / 4.300 | Validación vs. spec |
| sdd-archive | DeepSeek V4 Flash | — | 31.650 | Cierre y limpieza administrativa |

### Stack Specialists

| Agente | Primario | Backup | Límite / 5h | Rol |
|--------|----------|--------|-------------|-----|
| backend-dev | DeepSeek V4 Pro | DeepSeek V4 Flash | 3.450 / 31.650 | Symfony — diseño + escritura |
| frontend-dev | Qwen3.7 Plus | Hy3 | 4.300 / 4.300 | Angular — jerarquías y maquetación |
| code-reviewer | Kimi K2.7 Code | GLM-5.2 | 1.350 / 880 | Revisión enfocada y síntesis de fallos |
| tester | MiniMax M2.7 | Hy3 | 3.400 / 4.300 | Iteraciones de tests unitarios |
| documenter | MiMo-V2.5-Pro | — | 3.250 | Contratos API y manuales |
| auditor | Grok 4.5 / Kimi K3 | — | 120 / 110 (220 2x) | Tercera opinión — solo bloqueos críticos |
| general | DeepSeek V4 Flash | MiMo-V2.5 | 31.650 / 30.100 | Consultas generales |

### Reserva

| Modelo | Límite / 5h | Uso |
|--------|-------------|-----|
| GLM-5.1 | 880 | Formato de docs y auditorías puntuales |
| GLM-5.2 | 880 | Formato de docs y auditorías puntuales |
| Kimi K2.6 | 1.150 | Backup de último recurso |
| Qwen3.7 Max | 950 | Solo como refinador en sdd-propose |

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