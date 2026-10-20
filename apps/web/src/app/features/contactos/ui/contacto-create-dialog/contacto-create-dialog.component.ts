import { Component, ChangeDetectionStrategy, inject, output, HostListener, ElementRef, viewChild, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ContactoFormComponent } from '../contacto-form/contacto-form.component';
import { ContactoApiService, CreateContactoInput } from '../../../../data-access/contacto/contacto-api.service';
import { ContactoUI } from '../../../../data-access/contacto/contacto-ui.model';

/**
 * ContactoCreateDialogComponent — modal dialog for creating new contacts.
 *
 * Per spec:
 * - Title: "Crear Contacto"
 * - Buttons: "Guardar" (primary), "Cancelar" (secondary)
 * - Calls ContactoApiService.createContact() on submit
 * - Handles loading/error states
 * - Emits close with created contact on success, or without data on cancel
 * - OnPush, standalone
 * - Accessibility: autofocus on first input, escape key closes, ARIA attributes
 */
@Component({
  selector: 'app-contacto-create-dialog',
  standalone: true,
  imports: [CommonModule, ContactoFormComponent],
  templateUrl: './contacto-create-dialog.component.html',
  styleUrl: './contacto-create-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContactoCreateDialogComponent implements AfterViewInit {
  private readonly contactsApi = inject(ContactoApiService);

  /** Reference to the form component for accessing phone input */
  private readonly formComponent = viewChild<ContactoFormComponent>('contactForm');

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
      phone: formValue.phone,
      name: formValue.name,
      email: formValue.email,
    });
  }

  /** Submit data to ContactoApiService.createContact */
  private submit(data: { phone: string; name: string | null; email: string | null }): void {
    this.loading = true;
    this.errorMessage = null;

    const input: CreateContactoInput = {
      phone: data.phone,
      name: data.name ?? undefined,
      email: data.email ?? undefined,
    };

    this.contactsApi.createContact(input).subscribe({
      next: (contact) => {
        this.loading = false;
        this.close.emit(contact);
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