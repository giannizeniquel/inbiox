import { Component, ChangeDetectionStrategy, inject, input, output, HostListener, viewChild, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ContactFormComponent } from '../contact-form/contact-form.component';
import { ContactsApiService, UpdateContactInput } from '../../../../data-access/contacto/contacts-api.service';
import { UIContact } from '../../../../data-access/contacto/contact-ui.model';

/**
 * ContactEditDialogComponent — modal dialog for editing existing contacts.
 *
 * Per spec:
 * - Title: "Editar Contacto"
 * - Pre-fills form with selectedContact data (phone, name, email)
 * - Buttons: "Guardar" (primary), "Cancelar" (secondary)
 * - Calls ContactsApiService.updateContact() on submit
 * - Handles loading/error states
 * - Emits close with updated contact on success, or without data on cancel
 * - OnPush, standalone
 * - Accessibility: autofocus on first input, escape key closes, ARIA attributes
 */
@Component({
  selector: 'app-contact-edit-dialog',
  standalone: true,
  imports: [CommonModule, ContactFormComponent],
  templateUrl: './contact-edit-dialog.component.html',
  styleUrl: './contact-edit-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactEditDialogComponent implements AfterViewInit {
  private readonly contactsApi = inject(ContactsApiService);

  /** The contact to edit (required) */
  readonly contact = input.required<UIContact>();

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

  /** Emit close event with updated contact on success */
  readonly close = output<UIContact | null>();

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

  /** Handle form submit from ContactFormComponent */
  onFormSubmit(formValue: { phone: string; name: string | null; email: string | null }): void {
    if (this.loading) return;

    this.submit({
      id: this.contact().id,
      name: formValue.name,
      email: formValue.email,
    });
  }

  /** Submit data to ContactsApiService.updateContact */
  private submit(data: { id: string; name: string | null; email: string | null }): void {
    this.loading = true;
    this.errorMessage = null;

    const input: UpdateContactInput = {
      id: data.id,
      name: data.name ?? undefined,
      email: data.email ?? undefined,
    };

    this.contactsApi.updateContact(input).subscribe({
      next: (contact) => {
        this.loading = false;
        this.close.emit(contact);
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