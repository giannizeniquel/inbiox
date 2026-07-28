# Design: Reactive Forms for Admin Pages

## Technical Approach

Convert the two admin pages' template-driven forms to Reactive Forms, mirroring the **login-page pattern** (`apps/web/src/app/features/login/login-page.component.ts`) line-for-line: `FormBuilder.nonNullable.group`, declarative `Validators`, a `hasError(field)` helper, and `markAllAsTouched()` on submit. Architecture stays component-local (no service extraction); validation lives next to the form definition; the modal lifecycle is unchanged — only the form primitive flips from a plain object to a `FormGroup`.

Reuses `_admin-common.scss` for the new `.form-error` and `.form-input--error` classes so other admin pages can adopt the same pattern later without duplicating styles.

## Architecture Decisions

| Decision | Choice | Alternatives considered | Rationale |
|----------|--------|-------------------------|-----------|
| Where form lives | `FormBuilder` **inside the component** (same as login) | Extracted `AgentFormService` / `UserFormService` | Login + contact form already follow the component-local pattern. Extracting now is premature; the forms are not reused. |
| `nonNullable` vs nullable | `nonNullable.group` for `name`/`email`/`password`/`active`; regular `fb.group` for `userId`/`agent_id` | Always nullable | Non-nullable gives `getRawValue()` typed strings; nullable for fields that legitimately default to `null` |
| Roles control | `FormControl<string[]>` + custom validator; manual `toggleRole()` calls `setValue([...next])` | `FormArray<FormControl<boolean>>` per role | Exploration recommended deferring `FormArray`; this preserves current UX with minimal diff |
| Password validation in edit mode | `setValidators([Validators.minLength(8)])` only — empty = "keep current" | Custom cross-field validator | Matches the existing UX; backend already handles empty-password-on-update as no-op |
| Submit binding | `(ngSubmit)="onSubmit()"` on `<form>` element | Keep `(click)="save()"` on the button | Enables Enter-to-submit, native form semantics |
| Error CSS | Add to `_admin-common.scss` | Component-scoped SCSS | Login page already uses these classes globally; single source prevents drift |

## Data Flow

```
User clicks "+ Create" / "Edit" row
        │
        ▼
openCreateForm() / openEditForm(x)
  • agentForm.reset({...})            (or userForm.reset + setValidators)
  • showForm.set(true)
        │
        ▼
   Modal opens — user types
        │
        ▼
   User clicks "Create/Save"
        │
        ▼
   onSubmit()
   ┌─────┴──────┐
invalid       valid
markAll       saving.set(true)
AsTouched()   call use case
show errors       │
            ┌─────┴──────┐
          success      error
          closeForm()  error.set(msg)
          reload list
```

Link User follows the same lifecycle with a single-field `linkForm`.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/web/src/app/shared/styles/_admin-common.scss` | Modify | Add `.form-input--error` modifier + `.form-error` class after existing `.form-input` block |
| `apps/web/src/app/shared/validators/at-least-one-role.validator.ts` | Create | `atLeastOneRoleValidator(): ValidatorFn` returning `{ atLeastOneRole: true }` for empty arrays |
| `apps/web/src/app/features/admin/agents/agents-page.component.ts` | Modify | Replace `formData` + manual checks with `agentForm` + `linkForm`; add `hasError` helpers; migrate `(click)` → `(ngSubmit)` |
| `apps/web/src/app/features/admin/users/users-page.component.ts` | Modify | Same pattern; `userForm` with dynamic password validators; `toggleRole()` updates FormControl via `setValue` |

No new components, modules, DTOs, or API contracts.

## Interfaces / Contracts

```ts
// apps/web/src/app/shared/validators/at-least-one-role.validator.ts
import { AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';

export function atLeastOneRoleValidator(): ValidatorFn {
  return (control: AbstractControl<string[]>): ValidationErrors | null => {
    const value = control.value;
    return !Array.isArray(value) || value.length === 0
      ? { atLeastOneRole: true }
      : null;
  };
}
```

```ts
// agents-page.component.ts
readonly agentForm = this.fb.nonNullable.group({
  name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(100)]],
  active: [true],
});
readonly linkForm = this.fb.group({
  userId: [null as number | null, [Validators.required]],
});

// users-page.component.ts
readonly userForm = this.fb.nonNullable.group({
  email: ['', [Validators.required, Validators.email]],
  password: ['', [Validators.required, Validators.minLength(8)]],
  roles: [['ROLE_AGENT'] as string[], [atLeastOneRoleValidator()]],
  agent_id: [null as number | null],
});
```

## Testing Strategy

This is a pure UI refactor with no behavior change — coverage is verification, not new tests:

| Layer | What to verify | Approach |
|-------|----------------|----------|
| Build | TypeScript + Angular template compile | `ng build apps/web` passes (zero errors) |
| Manual smoke | Each modal: empty submit → all required errors; valid submit closes modal | 5 min walkthrough per page |
| Manual edge | Edit user with empty password → no password in PUT; deselect all roles → submit blocked | 2 min |

No new unit tests added in this change. Future extraction to a service should add unit tests for the validator function and any form state machine.

## Migration / Rollout

No migration required. Single feature, single PR. Both pages flip from template-driven to reactive in the same release. If a regression is found post-deploy, `git revert` of the merge commit restores prior behavior (no DB impact, no API impact).

## Open Questions

None. Exploration confirmed scope; proposal locks the approach. The only judgment call (password-validator swap on edit) is documented in the Architecture Decisions table.
