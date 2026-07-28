# Proposal: Reactive Forms for Admin Pages

## Intent

`AgentsPageComponent` and `UsersPageComponent` use template-driven `ngModel` with manual validation inside `createAgent()` / `createUser()`. This produces no per-field error UI, inconsistent UX with the rest of the app, and validation logic that mixes "missing field" with API failure.

This change aligns both admin pages with the login-page pattern: `FormBuilder.nonNullable.group`, declarative `Validators`, `hasError()` helper, and `markAllAsTouched()` on submit.

## Scope

### In Scope

- Convert agents-page **Create/Edit Agent** and **Link User** modals to Reactive `FormGroup`s.
- Convert users-page **Create/Edit User** modal to a Reactive `FormGroup`.
- Add `atLeastOneRoleValidator` for the roles field.
- Add `.form-error` and `.form-input--error` to `_admin-common.scss`.
- Migrate submit handlers from `(click)="save()"` to `(ngSubmit)="onSubmit()"` on a `<form>`.

### Out of Scope

- Use cases, API layer, or DTOs (no change).
- Refactoring roles checkboxes to a `FormArray` (deferred).
- Extracting a shared `<app-admin-form-field>` component (deferred).
- Delete / Unlink confirmation dialogs (not forms).

## Capabilities

### New Capabilities

None.

### Modified Capabilities

None — `openspec/specs/administration/spec.md` describes API behavior, not form implementation. Pure UI refactor.

## Approach

Follow the **login-page** pattern exactly (`apps/web/src/app/features/login/login-page.component.ts`): inject `FormBuilder`, swap `FormsModule` → `ReactiveFormsModule`, build forms with `this.fb.nonNullable.group({...})` (nullable group only for fields that default to `null`), attach per-field `Validators`, write a `hasError(field)` helper that checks `touched && errors?.['required']`, and have `onSubmit()` call `markAllAsTouched()` if invalid or read `getRawValue()` otherwise. Templates use `[formGroup]` / `[formControlName]` / `[class.form-input--error]` / `@if (hasError(...))`.

A single `FormGroup` per page is reused for create and edit; `openCreateForm()` calls `reset({...})` and `openEditForm(x)` calls `reset({...from x})`, then the `password` validator is toggled via `setValidators` (required only on create).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/features/admin/agents/agents-page.component.ts` | Modified | Replace `formData` + manual checks with `FormGroup` + `hasError()`; swap module import |
| `apps/web/src/app/features/admin/users/users-page.component.ts` | Modified | Same; add `atLeastOneRoleValidator`; password validators toggle per mode |
| `apps/web/src/app/shared/styles/_admin-common.scss` | Modified | Add `.form-error` and `.form-input--error` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Edit-mode password validation (empty = keep) breaks required-rule | Medium | `setValidators([Validators.minLength(8)])` on edit, `setValidators([Validators.required, Validators.minLength(8)])` on create; `updateValueAndValidity()` |
| Per-field error CSS not defined → silent visual bug | High | Add `.form-error` + `.form-input--error` to shared SCSS **before** any component conversion (Phase 1) |
| `toggleRole()` mutates array in place → stale FormControl | Low | `toggleRole()` calls `this.userForm.controls.roles.setValue([...])` so the validator re-runs |

## Rollback Plan

Revert the single PR. No DB migration, no API change, no shared dependency added.

## Dependencies

None — `@angular/forms` is already in the project.

## Success Criteria

- [ ] `ng build apps/web` passes with zero TypeScript errors.
- [ ] No `[(ngModel)]` remains in either admin page.
- [ ] Both forms show per-field `.form-error` when a required field is touched and empty.
- [ ] Empty submit marks all fields as touched and shows every required-field error at once.
- [ ] `atLeastOneRole` validator prevents submitting users with zero selected roles.
- [ ] `.form-error` and `.form-input--error` classes exist in `_admin-common.scss`.
