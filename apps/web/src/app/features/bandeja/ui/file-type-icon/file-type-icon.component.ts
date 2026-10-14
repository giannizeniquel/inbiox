import { Component, ChangeDetectionStrategy, input, computed } from '@angular/core';

/**
 * FileTypeIconComponent — renders a Tabler icon for a file MIME type.
 *
 * Maps MIME type to a recognizable file-type icon (PDF, Word, Excel, CSV,
 * text, PPT, ZIP, SVG, music/audio, voice note/microphone, image, video,
 * generic file). Icons are Tabler (MIT license), stroke-based and use
 * currentColor so they adapt to the theme.
 */

const ICONS: Record<string, string[]> = {
  pdf: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M5 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6',
    'M17 18h2',
    'M20 15h-3v6',
    'M11 15v6h1a2 2 0 0 0 2 -2v-2a2 2 0 0 0 -2 -2h-1z',
  ],
  doc: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M5 15v6h1a2 2 0 0 0 2 -2v-2a2 2 0 0 0 -2 -2h-1z',
    'M20 16.5a1.5 1.5 0 0 0 -3 0v3a1.5 1.5 0 0 0 3 0',
    'M12.5 15a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1 -3 0v-3a1.5 1.5 0 0 1 1.5 -1.5z',
  ],
  xls: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M4 15l4 6',
    'M4 21l4 -6',
    'M17 20.25c0 .414 .336 .75 .75 .75h1.25a1 1 0 0 0 1 -1v-1a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1v-1a1 1 0 0 1 1 -1h1.25a.75 .75 0 0 1 .75 .75',
    'M11 15v6h3',
  ],
  csv: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M7 16.5a1.5 1.5 0 0 0 -3 0v3a1.5 1.5 0 0 0 3 0',
    'M10 20.25c0 .414 .336 .75 .75 .75h1.25a1 1 0 0 0 1 -1v-1a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1v-1a1 1 0 0 1 1 -1h1.25a.75 .75 0 0 1 .75 .75',
    'M16 15l2 6l2 -6',
  ],
  txt: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M16.5 15h3',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M4.5 15h3',
    'M6 15v6',
    'M18 15v6',
    'M10 15l4 6',
    'M10 21l4 -6',
  ],
  ppt: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6',
    'M11 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6',
    'M16.5 15h3',
    'M18 15v6',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
  ],
  zip: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M16 18h1.5a1.5 1.5 0 0 0 0 -3h-1.5v6',
    'M12 15v6',
    'M5 15h3l-3 6h3',
  ],
  rar: [
    'M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z',
    'M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-10',
    'M10 12l4 0',
  ],
  sevenz: [
    'M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z',
    'M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-10',
    'M10 12l4 0',
  ],
  exe: [
    'M8 9l3 3l-3 3',
    'M13 15l3 0',
    'M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z',
  ],
  svg: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M5 12v-7a2 2 0 0 1 2 -2h7l5 5v4',
    'M4 20.25c0 .414 .336 .75 .75 .75h1.25a1 1 0 0 0 1 -1v-1a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1v-1a1 1 0 0 1 1 -1h1.25a.75 .75 0 0 1 .75 .75',
    'M10 15l2 6l2 -6',
    'M20 15h-1a2 2 0 0 0 -2 2v2a2 2 0 0 0 2 2h1v-3',
  ],
  music: [
    'M3 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0',
    'M13 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0',
    'M9 17v-13h10v13',
    'M9 8h10',
  ],
  mic: [
    'M9 2m0 3a3 3 0 0 1 3 -3h0a3 3 0 0 1 3 3v5a3 3 0 0 1 -3 3h0a3 3 0 0 1 -3 -3z',
    'M5 10a7 7 0 0 0 14 0',
    'M8 21l8 0',
    'M12 17l0 4',
  ],
  photo: [
    'M15 8h.01',
    'M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z',
    'M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5',
    'M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3',
  ],
  video: [
    'M15 10l4.553 -2.276a1 1 0 0 1 1.447 .894v6.764a1 1 0 0 1 -1.447 .894l-4.553 -2.276v-4z',
    'M3 6m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z',
  ],
  file: [
    'M14 3v4a1 1 0 0 0 1 1h4',
    'M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z',
  ],
};

const ICON_COLORS: Record<string, string> = {
  pdf: '#e53935',    // red
  doc: '#2b579a',    // Word blue
  xls: '#217346',    // Excel green
  csv: '#00897b',    // teal
  txt: '#607d8b',    // blue-gray
  ppt: '#d24726',    // PowerPoint orange
  zip: '#f9a825',    // amber
  rar: '#e67e22',    // orange
  sevenz: '#6d4c41', // brown
  exe: '#37474f',    // dark blue-gray
  svg: '#7b1fa2',    // purple
  music: '#8e24aa',  // deep purple
  mic: '#43a047',    // green (voice note)
  photo: '#1976d2',  // blue
  video: '#e53935',  // red
  file: '#9e9e9e',   // gray
};

@Component({
  selector: 'app-file-type-icon',
  standalone: true,
  template: `
    <svg
      xmlns="http://www.w3.org/2000/svg"
      [attr.width]="size()"
      [attr.height]="size()"
      viewBox="0 0 24 24"
      fill="none"
      [attr.stroke]="color()"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      @for (d of paths(); track d) {
        <path [attr.d]="d" />
      }
    </svg>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FileTypeIconComponent {
  readonly mimeType = input.required<string>();
  readonly size = input<number>(24);

  readonly iconType = computed<string>(() => this.resolveIconType(this.mimeType()));

  readonly paths = computed<string[]>(() => ICONS[this.iconType()] ?? ICONS['file']);

  readonly color = computed<string>(() => ICON_COLORS[this.iconType()] ?? ICON_COLORS['file']);

  private resolveIconType(mimeType: string): string {
    const m = mimeType.toLowerCase().split(';')[0].trim();

    if (m === 'application/pdf') return 'pdf';
    if (m === 'application/msword' || m.includes('wordprocessingml.document')) return 'doc';
    if (m === 'application/vnd.ms-excel' || m.includes('spreadsheetml.sheet')) return 'xls';
    if (m === 'text/csv') return 'csv';
    if (m === 'text/plain') return 'txt';
    if (m === 'application/vnd.ms-powerpoint' || m.includes('presentationml.presentation')) return 'ppt';
    if (m === 'application/x-7z-compressed') return 'sevenz';
    if (m === 'application/x-rar-compressed' || m === 'application/vnd.rar' || m === 'application/x-rar' || m === 'application/rar' || m === 'application/x-compressed') return 'rar';
    if (m === 'application/zip' || m === 'application/x-zip-compressed') return 'zip';
    if (m === 'application/x-msdownload' || m.includes('portable-executable') || m.includes('msdos')) return 'exe';
    if (m === 'image/svg+xml') return 'svg';
    // Voice notes arrive as audio/ogg (Ogg Opus) — show microphone
    if (m === 'audio/ogg' || m === 'audio/opus') return 'mic';
    // Other audio (mp3, wav, m4a, aac, ...) — show music note
    if (m.startsWith('audio/')) return 'music';
    if (m.startsWith('image/')) return 'photo';
    if (m.startsWith('video/')) return 'video';
    return 'file';
  }
}
