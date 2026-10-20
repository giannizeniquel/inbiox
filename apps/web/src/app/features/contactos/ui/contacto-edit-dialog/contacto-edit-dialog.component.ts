import { Component, ChangeDetectionStrategy, inject, input, output, HostListener, viewChild, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ContactoFormComponent } from '../contacto-form/contacto-form.component';
import { ContactoApiService, UpdateContactoInput } from '../../../../data-access/contacto/contacto-api.service';
import { ContactoUI } from '../../../../data-access/contacto/contacto-ui.model';

/**
 * ContactoEditDialogComponent — modal dialog for editing existing contacts.
 *
 * Per spec:
 * - Title: "Editar Contacto"
 * - Pre-fills form with selectedContact data (phone, name, email)
 * - Buttons: "Guardar" (primary), "Cancelar" (secondary)
 * - Calls ContactoApiService.updateContact() on submit
 * - Handles loading/error states
 * - Emits close with updated contact on success, or without data on cancel
 * - OnPush, standalone
 * - Accessibility: autofocus on first input, escape key closes, ARIA attributes
 */
@Component({
  selector: 'app-contacto-edit-dialog',
  standalone: true,
  imports: [CommonModule, ContactoFormComponent],
  templateUrl: './contacto-edit-dialog.component.html',
  styleUrl: './contacto-edit-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactoEditDialogComponent implements AfterViewInit {
  private readonly contactoApi = inject(ContactoApiService);

  /** The contact to edit (required) */
  readonly contacto = input.required<ContactoUI>();

  /** Reference to the form component for accessing phone input */
  private readonly formComponent = viewChild<ContactoFormComponent>('contactForm');

  ngAfterViewInit(): void {
    // Focus first input when dialog opens
    setTimeout(() => {
      this.formComponent()?.telefonoInput()?.nativeElement.focus();
    }, 0);
  }

  /** Handle escape key to close dialog */
  @HostListener('keydown.escape')
  onEscapeKey(): void {
    this.onCancel();
  }

  /** Emit close event with updated contact on success */
  readonly close = output<ContactoUI | null>();

  /** Loading state */
  loading = false;

  /** Error message from the API service */
  errorMessage: string | null = null;

  /** Form dirty state */
  isDirty = false;

  /** Handle form change to track dirty state */
  onFormChange(isDirty: boolean): void {
    this.isDirty = isDirty;
  }

  /** Handle form submit from ContactoFormComponent */
  onFormSubmit(formValue: { phone: string; name: string | null; email: string | null }): void {
    if (this.loading) return;

    this.submit({
      id: this.contacto().id,
      name: formValue.name,
      email: formValue.email,
    });
  }

  /** Submit data to ContactoApiService.updateContact */
  private submit(data: { id: string; name: string | null; email: string | null }): void {
    this.loading = true;
    this.errorMessage = null;

    const input: UpdateContactoInput = {
      id: data.id,
      name: data.name ?? undefined,
      email: data.email ?? undefined,
    };

    this.contactoApi.updateContacto(input).subscribe({
      next: (contacto) => {
        this.loading = false;
        this.close.emit(contacto);
      },
      error: (err: Error) => {
        this.loading = false;
        this.errorMessage = err.message || 'Error al actualizar contacto';
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