# Inbiox Agents Guide

## Project Status

Greenfield - no code yet. Documentation complete in `docs/`, implementation starts with Sprint 01.

---

## Architecture

- **Stack:** Symfony 7.4, PHP 8.2+, MySQL, Angular 19, Mercure (SSE)
- **Type:** SaaS customer support platform
- **Realtime:** Mercure with JWT-based topic authorization

---

## Pipeline SDD

| Fase | Primario | Backup / Soporte | Límite / 5h | Rol |
|------|----------|------------------|-------------|-----|
| orchestrator | DeepSeek V4 Pro | MiniMax M3 | 3.450 / 3.200 | Control e iteración continua |
| sdd-init | Hy3 | Qwen3.7 Plus | 4.300 / 4.300 | Bootstrap de contexto SDD y detección de stack |
| sdd-onboard | Hy3 | Qwen3.7 Plus | 4.300 / 4.300 | Walkthrough guiado del ciclo SDD completo |
| sdd-explore | MiniMax M2.7 | MiMo-V2.5-Pro | 3.400 / 3.250 | Lectura e interpretación del código base |
| sdd-propose | DeepSeek V4 Pro | GPT 5.6 Luna | 3.450 / 2.050 | Propuesta arquitectónica + veredicto final |
| sdd-spec | Qwen3.7 Plus | Hy3 | 4.300 / 4.300 | Escritura estructurada de requerimientos |
| sdd-design | MiniMax M3 | MiMo-V2.5-Pro | 3.200 / 3.250 | Diseño técnico detallado |
| sdd-tasks | Qwen3.7 Plus | Qwen3.6 Plus | 4.300 / 3.300 | Breakdown mecánico de subtareas |
| sdd-apply | MiMo-V2.5 | Hy3 | 30.100 / 4.300 | Codificación masiva — reemplaza a Flash |
| sdd-verify | MiniMax M2.7 | Hy3 | 3.400 / 4.300 | Validación estricta vs. spec |
| sdd-archive | MiMo-V2.5 | Hy3 | 30.100 / 4.300 | Cierre, limpieza y archivo |
| default | MiMo-V2.5 | Qwen3.7 Plus | 30.100 / 4.300 | Consultas generales fuera de SDD |

## Subagentes Especializados

| Subagente | Modelo Primario | Modelo Backup / Soporte | Límite / 5h | Rol |
|-----------|-----------------|------------------------|-------------|-----|
| Backend-Dev | DeepSeek V4 Pro | MiMo-V2.5 | 3.450 / 30.100 | Symfony — diseño + escritura de código |
| Frontend-Dev | Qwen3.7 Plus | Hy3 | 4.300 / 4.300 | Angular — jerarquías y maquetación |
| Code-Review | GPT 5.6 Luna | Kimi K2.7 Code | 2.050 / 1.350 | Revisión semántica y síntesis de fallos |
| Tester | MiniMax M2.7 | Hy3 | 3.400 / 4.300 | Testing e iteración de suites |
| Documentación | MiMo-V2.5-Pro | GPT 5.6 Luna | 3.250 / 2.050 | Contratos API y manuales técnicos |
| Auditor | Grok 4.5 / Kimi K3 | Qwen3.7 Max | 120 / 110 / 950 | Tercera opinión — solo bloqueos críticos |

## Reserva de Emergencia

| Modelo | Límite / 5h | Uso |
|--------|-------------|-----|
| GLM-5.1 | 880 | Formato de docs y auditorías puntuales |
| GLM-5.2 | 880 | Ídem — backup de Code-Review |
| Kimi K2.6 | 1.150 | Backup de último recurso |
| Qwen3.7 Max | 950 | Solo refinador puntual en sdd-propose |
| Qwen3.6 Plus | 3.300 | Overflow de sdd-tasks si Hy3/Plus se agotan |

## Notas

> ⚠️ DeepSeek V4 Flash confirmado offline (5 intentos en 3 agentes distintos).
> MiMo-V2.5 absorbe sus tres roles originales (sdd-apply, sdd-archive, default);
> Hy3 toma sdd-init y sdd-onboard para no sobrecargar a MiMo.
> Si MiMo falla, el pipeline cae a Hy3 + Qwen3.7 Plus (4.300 c/u).

> ⚠️ MiniMax M3 cubre tres roles simultáneos (orchestrator backup + sdd-explore
> + sdd-design) con 3.200 req/5h. Monitorear en ciclos intensivos.

> ✅ Cuando Flash vuelva: restaurar a sdd-apply, default, sdd-init y sdd-onboard;
> dejar MiMo-V2.5 como soporte.

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