# Backend cleanup (apps/api)

Objective: regain control of the backend. Make it minimal, with an honest architecture and written development rules, and add no new features.
Branch: one branch per phase (phase 0: `chore/limpieza-backend-fase-0`, from `test` at `b543593`). Delivery: one PR per phase; push, PR and merge are user decisions. Test runner: PHPUnit (see `AGENTS.md` § Testing).

## Specs

- **S1 — Order first, no new features.** User: "debemos retomar el control y hacer la app lo mas minimalista posible, con la arquitectura correcta, reglas claras de desarrollo, etc. No escribiria mas codigo ni agregaria mas features, la prioridad es ordenar este desastre. Necesito que armes un plan, tenemos que hacerlo por partes porque sino corresmos riesgo de volver a cometer los mismos errores."
  - Every phase preserves behavior. Product contradictions (C-1..C-20 in `docs/02_sistema-actual/08`) go on a separate track that needs user decisions.
  - Phase gate: the list of failing test names after the phase is identical to or a subset of the baseline.
- **S2 — Architecture by business module.** User: "vamos a organizar el codigo por modulo de negocio."
  - Agreed rules:
    - Doctrine entities are the domain model.
    - Controllers only handle HTTP.
    - Every write goes through a use case.
    - No interface without two real implementations.
    - A module may call another module's use cases but never touch its tables via SQL.
    - Routes live only in `routes.yaml`.
- **S3 — SQL rule.** User: "evitar SQL puro tipo string. ej: $sql='SELECT...'. SIEMPRE debemos tener un repository que ejecute funciones que utilicen metodos de doctrine".
  - The adjusted rule, accepted by the user ("si me cierra"):
    - Use the Doctrine QueryBuilder (ORM or DBAL) by default.
    - Native SQL is allowed only inside a repository: in a named method, with bound parameters, a comment explaining why DQL is not enough, and its own test.
    - SQL is forbidden in use cases, controllers and commands, and concatenating SQL fragments is forbidden.
  - Stored procedures and views were rejected.
- **S4 — Spanish code.** User: "todos los metodos, variables, interfaces, entidades, comentarios, etc. necesito que esten en español." and "entiendo los contratos del framework no se pueden cambiar, pero todo lo demas que podamos cambiar debemos hacerlo. La app no esta en produccion, esta en desarrollo, asi que renombrar tablas-columnas y perder datos no es un problema".
  - Naming rule: "negocio en español y técnico en inglés".
  - Glossary answers: "ticket, espacio de trabajo, jerga en inglés, disponibilidadManual".
  - The renaming happens in phase 5. The glossary is fixed in phase 0.
- **S5 — Glossary shared with the frontend.** The module names and the naming rule are the same in `apps/web`. The API JSON contract is redefined jointly in phase 5.
- **S6 — Safety net (phase 0).** Repair the tests that are broken by themselves, delete obsolete ones, and keep the tests that expose real bugs or code drift failing and documented. Record the baseline of failing test names.
- **S7 — Docs policy (assumption, not explicitly confirmed by the user).** Each phase fixes the docs it invalidates in the same PR. A full docs pass happens in phase 6, after the renaming.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2, S3, S4, S5 | inline | pending | `docs/arquitectura.md`: target architecture, rules, glossary |
| T2 | S2, S3, S4 | inline | pending | `AGENTS.md`: rules summary + pointer to `docs/arquitectura.md` |
| T3 | S6 | inline | pending | Fix test groups A–E, J, K (39 tests); delete obsolete group I (1) |
| T4 | S6, S7 | inline | pending | Baseline of failing names; update `docs/02_sistema-actual/00` |

Later phases, each with its own branch and task list when it starts:

1. Delete dead code.
2. Move code to the module structure and remove single-implementation interfaces.
3. Enforce the layer rule: controllers only handle HTTP, a single outbox writer, a global exception listener.
4. Split the god methods, with characterization tests first: outbound, then inbound, then reporting.
5. Spanish renaming, a fresh migration baseline, and the joint API contract.
6. Docs pass.

## Log

- **L1 (2026-10-13), user request:** "si, debemos retomar el control y hacer la app lo mas minimalista posible, con la arquitectura correcta, reglas claras de desarrollo, etc. No escribiria mas codigo ni agregaria mas features, la prioridad es ordenar este desastre. Necesito que armes un plan, tenemos que hacerlo por partes porque sino corresmos riesgo de volver a cometer los mismos errores."
- **L2 (2026-10-13), analysis:** read-only audit of `apps/api/src` (Engram `inbiox/backend-architecture-audit`).
  - The architecture is a "hexagonal costume": the folder names are there, but the rules are not.
  - The outbound flow is split between `ProviderProxyController` and its use case.
  - Raw SQL appears in 23 files.
  - God methods reach ~239 lines.
  - The 94 `#[Route]` attributes are dead: verified that there is no attribute route loader.
- **L3 (2026-10-13), decisions:**
  - Simplify instead of completing hexagonal.
  - Organize by business module.
  - SQL rule (S3).
  - Spanish rule and glossary (S4).
  - Glossary moved from phase 5 to phase 0, so the frontend can reorganize in parallel on its own branch.
- **L4 (2026-10-13):** user: "si, arrancá con la fase 0". Branch `chore/limpieza-backend-fase-0` created from `test` (`b543593`).
