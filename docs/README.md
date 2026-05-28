# Inbiox

**Inbiox** is a customer support operations platform that centralizes conversations, automates workflows, and enables teams to work efficiently from a unified inbox.

> **Tagline:** All your support, in one inbox.

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
- [`01_product/vision.md`](./01_product/vision.md)
- [`01_product/posicionamiento.md`](./01_product/posicionamiento.md)
- [`01_product/justificacion_especificacion.md`](./01_product/justificacion_especificacion.md)

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

Current phase: **MVP**

Core system includes:

- Ticket lifecycle (FSM)
- Assignment engine
- Automation rules
- Reporting (SLA + metrics)
- Realtime updates (Mercure)
- Agent management (shifts + availability)

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

- Advanced SLA (priorities, deadlines)
- Multi-channel support (email, webchat)
- Skill-based routing
- SaaS multi-tenant architecture
- Billing system

---

## 📌 Status

🟡 In active development