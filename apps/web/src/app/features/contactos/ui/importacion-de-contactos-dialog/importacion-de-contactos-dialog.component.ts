import { Component, ChangeDetectionStrategy, inject, output, signal, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  ContactoApiService,
  ResultadoDeImportacionDeContactos,
} from '../../../../data-access/contacto/contacto-api.service';

/** Spanish labels for the whole-file rejection reasons (HTTP 400) */
const FILE_REJECTION_REASONS: Record<string, string> = {
  file_missing: 'No se seleccionó ningún archivo.',
  file_too_large: 'El archivo supera el tamaño máximo permitido (2 MB).',
  invalid_file_type: 'Solo se aceptan archivos CSV.',
  missing_phone_column: 'El archivo no tiene una columna "Teléfono".',
  empty_file: 'El archivo no contiene filas de datos.',
  too_many_rows: 'El archivo supera el máximo de 1000 filas.',
};

/** Spanish labels for per-row error reasons */
const ROW_ERROR_REASONS: Record<string, string> = {
  phone_missing: 'Teléfono faltante',
  invalid_phone: 'Teléfono inválido',
  duplicate_phone: 'Ya existe un contacto con este teléfono',
  duplicate_in_file: 'Teléfono duplicado dentro del archivo',
  unknown_error: 'Error inesperado al importar esta fila',
};

/**
 * ImportacionDeContactosDialogComponent — minimal CSV bulk-import dialog.
 *
 * Per spec (contact-import):
 * - Native file picker (no drag-and-drop, no client-side CSV preview)
 * - Submits to POST /api/contactos/importar via ContactoApiService.importContacts()
 * - Displays imported/skipped counts + per-row error list with localized reasons
 * - Standalone, signals-based, OnPush
 */
@Component({
  selector: 'app-importacion-de-contactos-dialog',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './importacion-de-contactos-dialog.component.html',
  styleUrl: './importacion-de-contactos-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportacionDeContactosDialogComponent {
  private readonly contactoApi = inject(ContactoApiService);

  /** Emits the import result on close (null if cancelled before any submit) */
  readonly close = output<ResultadoDeImportacionDeContactos | null>();

  readonly selectedFile = signal<File | null>(null);
  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly result = signal<ResultadoDeImportacionDeContactos | null>(null);

  @HostListener('keydown.escape')
  onEscapeKey(): void {
    this.onClose();
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.selectedFile.set(file);
    this.errorMessage.set(null);
  }

  onSubmit(): void {
    const file = this.selectedFile();
    if (!file || this.loading()) {
      return;
    }

    this.loading.set(true);
    this.errorMessage.set(null);

    this.contactoApi.importarContactos(file).subscribe({
      next: (result) => {
        this.loading.set(false);
        this.result.set(result);
      },
      error: (err: { error?: { error?: string; mensaje?: string } }) => {
        this.loading.set(false);
        const reason = err.error?.error;
        this.errorMessage.set(
          (reason && FILE_REJECTION_REASONS[reason]) || err.error?.mensaje || 'Error al importar el archivo',
        );
      },
    });
  }

  reasonLabel(reason: string): string {
    return ROW_ERROR_REASONS[reason] ?? reason;
  }

  /** Close (cancel before submit, or done reviewing the result) */
  onClose(): void {
    this.close.emit(this.result());
  }
}
