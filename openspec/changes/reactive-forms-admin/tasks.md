# Tasks: Reactive Forms for Admin Pages

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~280–360 (2 components + 1 SCSS + 1 new validator file) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | All four changes ship together as a focused refactor PR | PR 1 | Mechanical diff; phases 1–4 land in one reviewable commit stack |

## Phase 1: Foundation — Styles + Shared Validator

- [x] 1.1 In `_admin-common.scss`, add `.form-input--error` (red border + focus ring) after `.form-input`.
- [x] 1.2 In the same file, add `.form-error` (0.8125rem, `var(--color-error)`, small top margin).
- [x] 1.3 Create `apps/web/src/app/shared/validators/at-least-one-role.validator.ts` exporting `atLeastOneRoleValidator(): ValidatorFn` returning `{ atLeastOneRole: true }` for empty arrays.
- [x] 1.4 `ng build apps/web` — must pass before Phase 2.

## Phase 2: Convert AgentsPage (Create/Edit + Link forms)

- [x] 2.1 Swap `FormsModule` → `ReactiveFormsModule`; import `FormBuilder, ReactiveFormsModule, Validators`; inject `fb`.
- [x] 2.2 Replace `formData` with `agentForm` (name, active) and add `linkForm` (userId) per design.md.
- [x] 2.3 Add `hasAgentError(field)` and `hasLinkError(field)` helpers (login-page pattern: touched + `errors?.['required']`).
- [x] 2.4 Update `openCreateForm` / `openEditForm` / `openLinkForm` to call `.reset({...})` on the right form.
- [x] 2.5 Migrate Create/Edit modal inputs to `formControlName` + `[class.form-input--error]` + `@if` error blocks; wrap modal body in `<form [formGroup]="agentForm" (ngSubmit)="onAgentSubmit()">`.
- [x] 2.6 Migrate Link modal select to `formControlName="userId"`; wrap in `<form [formGroup]="linkForm" (ngSubmit)="onLinkSubmit()">`.
- [x] 2.7 Add `onAgentSubmit()` / `onLinkSubmit()`: `markAllAsTouched()` if invalid, else read `getRawValue()` and route to create/update/link.
- [x] 2.8 Refactor `createAgent` / `updateAgent` / `executeLink` to accept typed values from `onSubmit`.
- [x] 2.9 `ng build apps/web` — must pass.

## Phase 3: Convert UsersPage (Create/Edit form)

- [x] 3.1 Swap `FormsModule` → `ReactiveFormsModule`; import `FormBuilder, Validators, ReactiveFormsModule, atLeastOneRoleValidator`; inject `fb`.
- [x] 3.2 Replace `formData` with `userForm` (email, password, roles, agent_id) per design.md.
- [x] 3.3 Add `hasUserError(field)` and `hasUserRolesError()` helpers.
- [x] 3.4 In `openCreateForm()`: `userForm.reset({...})` then `password.setValidators([Validators.required, Validators.minLength(8)])` + `updateValueAndValidity()`.
- [x] 3.5 In `openEditForm(user)`: `userForm.reset({...})` then `password.setValidators([Validators.minLength(8)])` + `updateValueAndValidity()`.
- [x] 3.6 Rewrite `toggleRole()` to mutate the array then `this.userForm.controls.roles.setValue([...this.userForm.controls.roles.value])` so the validator re-runs.
- [x] 3.7 Migrate the four modal inputs (email, password, roles, agent_id) to `formControlName` + error blocks; wrap modal body in `<form [formGroup]="userForm" (ngSubmit)="onUserSubmit()">`.
- [x] 3.8 Add `onUserSubmit()`: `markAllAsTouched()` if invalid; read `getRawValue()`, omit `password` from update payload when editing and empty, route to create/update.
- [x] 3.9 Refactor `createUser` / `updateUser` to accept typed values; remove the manual `if (!email || !password)` check.
- [x] 3.10 `ng build apps/web` — must pass.

## Phase 4: Cleanup + Verification

- [x] 4.1 Grep both files for `[(ngModel)]` and `formData` — both must return zero matches.
- [x] 4.2 Grep `_admin-common.scss` for `.form-error` and `.form-input--error` — both must be present.
- [ ] 4.3 Manual smoke: empty submit on each of the 3 modals → all required errors visible; valid submit → modal closes + success banner.
- [ ] 4.4 Manual edge: edit user with empty password → no `password` in PUT body; deselect all roles → submit blocked with "at least one role" message.
