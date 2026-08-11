# Inbiox

**Inbiox** is a Customer Operations Intelligence platform that centralizes conversations, automates workflows, predicts operational issues, and enables teams to work efficiently from a unified inbox.

> **Tagline:** All your support, in one inbox.
> **Vision:** El inbox que no solo gestiona tu equipo de soporte; aprende cómo funciona tu operación, anticipa los problemas y actúa para resolverlos.

---

## 🧭 Overview

Inbiox is designed to replace fragmented support tools (e.g. chatbot platforms, manual WhatsApp handling, limited CRMs) with a single system that provides:

- Multi-agent inbox
- Automated ticket assignment
- Structured workflows (FSM)
- SLA tracking and reporting
- Real-time updates
- Agent availability and shift management

---

## 📚 Documentation Index

This repository is organized into clearly separated domains:

### 📦 Product
- [`01_product/vision.md`](./01_product/vision.md) — Visión de producto: Customer Operations Intelligence
- [`01_product/posicionamiento.md`](./01_product/posicionamiento.md) — Posicionamiento frente al mercado
- [`01_product/roadmap.md`](./01_product/roadmap.md) — Plan de sprints MVP + post-MVP (Autopilot)
- [`01_product/0-junstificacion-especificacion.md`](./01_product/0-junstificacion-especificacion.md) — Justificación original del proyecto

---

### 💼 Business
- [`02_business/propuesta_inicial.md`](./02_business/propuesta_inicial.md)
- [`02_business/comparativa_saas.md`](./02_business/comparativa_saas.md)
- [`02_business/build_vs_buy.md`](./02_business/build_vs_buy.md)

---

### 🧱 Architecture
- [`03_architecture/overview.md`](./03_architecture/overview.md)
- [`03_architecture/documento_tecnico.md`](./03_architecture/documento_tecnico.md)
- [`03_architecture/modelo_datos.md`](./03_architecture/modelo_datos.md)
- [`03_architecture/decisiones.md`](./03_architecture/decisiones.md)

---

### 🚀 Sprints (Execution Plan)
- [`04_sprints/plan_mvp.md`](./04_sprints/plan_mvp.md)
- [`04_sprints/sprint_01.md`](./04_sprints/sprint_01.md)
- [`04_sprints/sprint_02.md`](./04_sprints/sprint_02.md)
- [`04_sprints/sprint_03.md`](./04_sprints/sprint_03.md)
- [`04_sprints/sprint_04.md`](./04_sprints/sprint_04.md)
- [`04_sprints/sprint_05.md`](./04_sprints/sprint_05.md)
- [`04_sprints/sprint_06.md`](./04_sprints/sprint_06.md)
- [`04_sprints/sprint_07.md`](./04_sprints/sprint_07.md)
- [`04_sprints/sprint_08.md`](./04_sprints/sprint_08.md)
- [`04_sprints/sprint_09A.md`](./04_sprints/sprint_09A.md)
- [`04_sprints/sprint_09B.md`](./04_sprints/sprint_09B.md)
- [`04_sprints/sprint_10.md`](./04_sprints/sprint_10.md)

---

### 🔌 API
- [`05_api/endpoints.md`](./05_api/endpoints.md)
- [`05_api/contracts.md`](./05_api/contracts.md)

---

### 🎨 Frontend
- [`06_frontend/inbox_ux.md`](./06_frontend/inbox_ux.md)
- [`06_frontend/estados_ui.md`](./06_frontend/estados_ui.md)

---

### ⚙️ Development
- [`07_dev/setup_local.md`](./07_dev/setup_local.md)
- [`07_dev/docker.md`](./07_dev/docker.md)
- [`07_dev/desarrollo_local.md`](./07_dev/desarrollo_local.md)

---

## 🧪 Development Status

Current phase: **MVP — Sprint 11A/11B/12** (Angular Inbox + Realtime + Provider Abstraction)

Core system includes:

- Ticket lifecycle (FSM)
- Assignment engine with capacity + timezone-awareness
- Automation rules
- Reporting (SLA + metrics)
- Realtime updates (Mercure SSE)
- Agent management (shifts + availability)

Next: Intelligence Foundation + Copilot (post-MVP, Sprints 13+)

---

## 🔐 Key Design Decisions

- FSM for ticket lifecycle (no invalid states)
- SLA timestamps are immutable
- All business logic flows through UseCases
- Realtime is event-based (not state sync)
- REST API is the source of truth
- Deterministic and auditable operations

---

## 🚧 Future Scope

- **Intelligence Foundation** — Conversation signals (intent, sentiment, complexity)
- **Agent Copilot** — Summary, suggested replies, next-action recommendations
- **Policy Engine** — IF/THEN rules between AI interpretation and domain execution
- **Predictive Operations** — SLA prediction, predictive assignment, load forecasting
- **Autopilot** — Progressive autonomy (Assist → Guarded → Autopilot)
- **Operational Digital Twin** — "What if" simulation and staffing recommendations
- Advanced SLA (priorities, deadlines)
- Multi-channel support (email, webchat, Telegram, Instagram)
- Skill-based routing
- SaaS multi-tenant architecture
- Billing system

---

## 📌 Status

🟡 In active development