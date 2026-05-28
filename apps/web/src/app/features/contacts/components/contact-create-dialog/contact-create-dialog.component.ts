import { Component, ChangeDetectionStrategy, inject, output, HostListener, ElementRef, viewChild, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ContactFormComponent } from '../contact-form/contact-form.component';
import { CreateContactUseCase, CreateContactInput } from '../../application/contacts';
import { UIContact } from '../../models';

/**
 * ContactCreateDialogComponent — modal dialog for creating new contacts.
 *
 * Per spec:
 * - Title: "Crear Contacto"
 * - Buttons: "Guardar" (primary), "Cancelar" (secondary)
 * - Calls CreateContactUseCase.execute() on submit
 * - Handles loading/error states
 * - Emits close with created contact on success, or without data on cancel
 * - OnPush, standalone
 * - Accessibility: autofocus on first input, escape key closes, ARIA attributes
 */
@Component({
  selector: 'app-contact-create-dialog',
  standalone: true,
  imports: [CommonModule, ContactFormComponent],
  templateUrl: './contact-create-dialog.component.html',
  styleUrl: './contact-create-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactCreateDialogComponent implements AfterViewInit {
  private readonly createContact = inject(CreateContactUseCase);

  /** Reference to the form component for accessing phone input */
  private readonly formComponent = viewChild<ContactFormComponent>('contactForm');

  ngAfterViewInit(): void {
    // Focus first input when dialog opens
    setTimeout(() => {
      this.formComponent()?.phoneInput()?.nativeElement.focus();
    }, 0);
  }

  /** Handle escape key to close dialog */
  @HostListener('keydown.escape')
  onEscapeKey(): void {
    this.onCancel();
  }

  /** Emit close event with created contact on success */
  readonly close = output<UIContact | null>();

  /** Loading state */
  loading = false;

  /** Error message from use case */
  errorMessage: string | null = null;

  /** Form dirty state */
  isDirty = false;

  /** Handle form change to track dirty state */
  onFormChange(isDirty: boolean): void {
    this.isDirty = isDirty;
  }

  /** Handle form submit from ContactFormComponent */
  onFormSubmit(formValue: { phone: string; name: string | null; email: string | null }): void {
    if (this.loading) return;

    this.submitToUseCase({
      phone: formValue.phone,
      name: formValue.name,
      email: formValue.email,
    });
  }

  /** Submit data to CreateContactUseCase */
  private submitToUseCase(data: { phone: string; name: string | null; email: string | null }): void {
    this.loading = true;
    this.errorMessage = null;

    const input: CreateContactInput = {
      phone: data.phone,
      name: data.name ?? undefined,
      email: data.email ?? undefined,
    };

    this.createContact.execute(input).subscribe({
      next: (output) => {
        this.loading = false;
        this.close.emit(output.contact);
      },
      error: (err: Error) => {
        this.loading = false;
        this.errorMessage = err.message || 'Error al crear contacto';
      },
    });
  }

  /** Handle cancel */
  onCancel(): void {
    this.close.emit(null);
  }

  /** Trigger form submit */
  onSave(): void {
    this.formComponent()?.onSubmit();
  }
}