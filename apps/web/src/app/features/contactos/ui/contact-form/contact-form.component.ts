import { Component, ChangeDetectionStrategy, input, output, OnInit, viewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { UIContact } from '../../../../data-access/contacto/contact-ui.model';

/**
 * ContactFormComponent — reusable form for creating and editing contacts.
 *
 * Per spec:
 * - Standalone, NO store injection
 * - Input: initialValue (optional partial UIContact for edit mode)
 * - Output: formChange = output<FormGroup>(), submit = output<{phone, name, email}>()
 * - Reactive Forms with explicit validators
 * - Track dirty state, show validation messages
 * - OnPush, standalone
 */
@Component({
  selector: 'app-contact-form',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './contact-form.component.html',
  styleUrl: './contact-form.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactFormComponent implements OnInit {
  /** Optional initial value for edit mode (partial UIContact) */
  readonly initialValue = input<UIContact | null>(null);

  /** Emit form group on any change (for parent to track dirty state) */
  readonly formChange = output<FormGroup>();

  /** Emit submit event with form values */
  readonly submitForm = output<{ phone: string; name: string | null; email: string | null }>();

  /** Expose phone input for autofocus from parent dialog */
  readonly phoneInput = viewChild<ElementRef<HTMLInputElement>>('phoneInput');

  form!: FormGroup;

  constructor(private readonly fb: FormBuilder) {}

  ngOnInit(): void {
    this.initForm();

    // Emit initial form on init
    this.formChange.emit(this.form);

    // Subscribe to changes
    this.form.valueChanges.subscribe(() => {
      this.formChange.emit(this.form);
    });
  }

  private initForm(): void {
    const initial = this.initialValue();

    this.form = this.fb.group({
      phone: [
        initial?.phone ?? '',
        [
          Validators.required,
          Validators.pattern(/^\+?54\d{8,14}$/),
        ],
      ],
      name: [
        initial?.name ?? '',
        [Validators.maxLength(100)],
      ],
      email: [
        initial?.email ?? '',
        [Validators.email],
      ],
    });
  }

  /** Check if a field has a specific error */
  hasError(controlName: string, errorType: string): boolean {
    const control = this.form.get(controlName);
    return control ? control.hasError(errorType) && control.touched : false;
  }

  /** Check if a field is touched and has any error */
  showError(controlName: string): boolean {
    const control = this.form.get(controlName);
    return control ? control.invalid && control.touched : false;
  }

  /** Get error message for a field */
  getErrorMessage(controlName: string): string {
    const control = this.form.get(controlName);
    if (!control || !control.errors) return '';

    if (control.errors['required']) {
      return 'Este campo es obligatorio';
    }
    if (control.errors['maxlength']) {
      const max = control.errors['maxlength'].requiredLength;
      return `Máximo ${max} caracteres`;
    }
    if (control.errors['email']) {
      return 'Ingresa un email válido';
    }
    if (control.errors['pattern']) {
      return 'Formato inválido (ej: +5491155555555)';
    }

    return 'Valor inválido';
  }

  /** Handle form submit */
  onSubmit(): void {
    if (this.form.invalid) {
      // Mark all fields as touched to show errors
      this.form.markAllAsTouched();
      return;
    }

    const { phone, name, email } = this.form.value;
    this.submitForm.emit({
      phone: phone?.trim() ?? '',
      name: name?.trim() ?? null,
      email: email?.trim() ?? null,
    });
  }

  /** Check if form is dirty */
  isDirty(): boolean {
    return this.form.dirty;
  }

  /** Check if form is valid */
  isValid(): boolean {
    return this.form.valid;
  }
}