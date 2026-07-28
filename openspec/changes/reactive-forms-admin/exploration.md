# Exploration: Reactive Forms for Admin Pages

## Current State

Both admin pages currently use **template-driven forms** with `ngModel` two-way binding and manual validation scattered through save methods.

### AgentsPageComponent (`agents-page.component.ts`)

**Forms present:**
1. **Create/Edit Agent modal** — fields: `name` (text), `active` (checkbox, edit-only)
2. **Link User modal** — fields: `linkUserId` (select)

**Current pattern:**
- `import { FormsModule } from '@angular/forms'`
- `readonly formData = { name: '', active: true }` — plain object
- Validation is **manual inline checks** in `createAgent()`:
  ```ts
  if (!this.formData.name?.trim()) {
    this.error.set('Agent name is required');
    return;
  }
  ```
- **No error display** in the template — no `form-error` class, no validation feedback
- Submit via `(click)="saveAgent()"` on button, not via `ngSubmit`

**Fields & validation:**

| Field | Current validation | Reactive Forms validators to add |
|-------|------------------|-------------------------------|
| `name` | Manual trim+required check | `Validators.required`, `Validators.minLength(2)`, `Validators.maxLength(100)` |

**No error CSS class exists** — `.form-error` is not defined in `_admin-common.scss`.

---

### UsersPageComponent (`users-page.component.ts`)

**Forms present:**
1. **Create/Edit User modal** — fields: `email`, `password`, `roles[]`, `agent_id` (create-only)

**Current pattern:**
- `import { FormsModule } from '@angular/forms'`
- `readonly formData = { email: '', password: '', roles: ['ROLE_AGENT'], agent_id: null }`
- Validation is **manual checks** in `createUser()`:
  ```ts
  if (!this.formData.email || !this.formData.password) {
    this.error.set('Email and password are required');
    return;
  }
  ```
- `toggleRole()` manually splices array — no reactive form `FormArray`
- **No error display** in the template
- Roles checkbox group styled in `users-page.component.scss` (`.checkbox-group`, `.checkbox-label`), not shared

**Fields & validation:**

| Field | Current validation | Reactive Forms validators to add |
|-------|------------------|-------------------------------|
| `email` | Manual required check | `Validators.required`, `Validators.email` |
| `password` | Manual required (create only) | `Validators.required`, `Validators.minLength(8)` (create); optional on edit |
| `roles` | None — at least one should be required | Custom validator: at least one role selected |
| `agent_id` | None | Optional |

---

### Existing Reactive Forms Usage

The project **already uses Reactive Forms** in two places — establishing a clear pattern to follow:

**Login page** (`login-page.component.ts`):
```ts
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
// ...
readonly loginForm = this.fb.nonNullable.group({
  email: ['', [Validators.required, Validators.email]],
  password: ['', [Validators.required]],
});

hasError(field: 'email' | 'password'): boolean {
  const control = this.loginForm.get(field);
  return !!control && control.touched && !!control.errors?.['required'];
}

onSubmit(): void {
  if (this.loginForm.invalid) {
    this.loginForm.markAllAsTouched();
    return;
  }
  // ...
}
```

**Contact form** (`contact-form.component.ts`):
- Uses `FormBuilder`, `FormGroup`, `Validators`
- Pattern consistent with login page

**No `ReactiveFormsModule` in either admin page** — both currently import only `CommonModule` + `FormsModule`.

---

### Shared Styles (`_admin-common.scss`)

Form styles already present:
- `.form-group`, `.form-label`, `.form-input` — fully styled
- `.modal`, `.modal__header`, `.modal__body`, `.modal__footer` — fully styled

**Missing styles needed for reactive forms error display:**
- `.form-error` — error message text (font-size ~0.8125rem, color: var(--color-error))
- `.form-input--error` — input border/text on validation error
- `.checkbox-group`, `.checkbox-label` — already exist in `users-page.component.scss` (component-specific), NOT in shared styles — acceptable as-is

---

## Affected Areas

| File | Impact |
|------|--------|
| `apps/web/src/app/features/admin/agents/agents-page.component.ts` | Replace `formData` object with `FormGroup`, add validators, error display |
| `apps/web/src/app/features/admin/users/users-page.component.ts` | Replace `formData` with `FormGroup`, add validators, `FormArray` for roles, error display |
| `apps/web/src/app/shared/styles/_admin-common.scss` | Add `.form-error` and `.form-input--error` CSS classes |
| `apps/web/src/app/features/admin/agents/agents-page.component.html` | N/A — uses inline template |
| `apps/web/src/app/features/admin/users/users-page.component.html` | N/A — uses inline template |

---

## Approaches

### Approach 1: Minimal — Replace formData with FormGroup, add validators + error CSS *(recommended)*

Replace plain `formData` objects with `FormBuilder`-built `FormGroup` per component. Add `Validators.required`, `Validators.minLength`, `Validators.email` as appropriate. Add a `hasError()` helper mirroring the login-page pattern. Add `.form-error` + `.form-input--error` to `_admin-common.scss`. Keep the modal open/close state management with signals.

- **Pros:** Consistent with existing reactive forms pattern (login page), type-safe, testable, minimum blast radius
- **Cons:** Roles checkboxes still need manual toggle logic unless moved to `FormArray`
- **Effort:** Medium — 2 components + 1 shared SCSS file

### Approach 2: Extract shared admin form component

Create a reusable `<app-admin-form-field>` component wrapping label + input + error message. Extract role-checkboxes into a separate `RoleSelectorComponent`.

- **Pros:** Maximum reuse, consistent UX across all admin forms
- **Cons:** Higher effort, adds new component files, more moving parts
- **Effort:** High

### Approach 3: Hybrid — FormGroup with component-level RoleCheckbox component

Same as Approach 1 for main forms, but extract roles checkbox group into a small `RoleCheckboxGroupComponent` with its own `ControlValueAccessor` for cleaner `FormArray`-like behavior.

- **Pros:** Good balance of reuse and simplicity
- **Cons:** Introduces new component even with limited scope
- **Effort:** Medium-High

---

## Recommendation

**Approach 1 (Minimal)** is the right starting point. The login page already establishes the project's reactive forms pattern — follow it exactly. This gives:
- Type safety on form values
- Declarative validation
- `markAllAsTouched()` UX pattern already familiar to the user
- No new component files

Roles remain as manual array toggle (same as current `toggleRole()`) — the `FormArray` refactor can be a future enhancement if needed.

The custom "at least one role" validator should be added as a standalone function (no class needed).

---

## Risks

1. **Missing error CSS** — `.form-error` and `.form-input--error` don't exist in `_admin-common.scss`. Must add before reactive forms error display works visually.
2. **Roles validation** — "at least one role" requires a custom validator function. Standard validators cover everything else.
3. **Inline templates** — both components use inline `template:` in TS, not separate HTML files. Refactor modifies the `.ts` file directly.
4. **No `ngSubmit`** — current buttons call `saveAgent()`/`saveUser()` directly via `(click)`. Should migrate to `(ngSubmit)="onSubmit()"` on the `<form>` element for proper form interaction.

---

## Ready for Proposal

**Yes.** The scope is clear: two components, shared SCSS update, following the existing login-page pattern. No ambiguous decisions remaining.
