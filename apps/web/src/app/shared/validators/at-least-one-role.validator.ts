import { AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';

export function atLeastOneRoleValidator(): ValidatorFn {
  return (control: AbstractControl<string[]>): ValidationErrors | null => {
    const value = control.value;
    return !Array.isArray(value) || value.length === 0
      ? { atLeastOneRole: true }
      : null;
  };
}
