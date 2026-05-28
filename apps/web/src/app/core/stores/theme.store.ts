import { Injectable, signal, effect } from '@angular/core';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'crm-theme';

/**
 * ThemeStore — manages light/dark theme via signal + localStorage persistence.
 *
 * Usage:
 *   - Read: `themeStore.theme()` → 'light' | 'dark'
 *   - Toggle: `themeStore.toggle()`
 *   - Effect: theme changes auto-apply `.dark` class to <html>
 */
@Injectable({ providedIn: 'root' })
export class ThemeStore {
  /** The current theme signal */
  readonly theme = signal<Theme>(this.getInitialTheme());

  constructor() {
    // Apply theme on init (read from localStorage or default to light)
    this.applyTheme(this.theme());

    // Auto-apply theme changes to the DOM
    effect(() => {
      const current = this.theme();
      this.applyTheme(current);
      localStorage.setItem(STORAGE_KEY, current);
    });
  }

  /** Toggle between light and dark */
  toggle(): void {
    this.theme.update(current => current === 'light' ? 'dark' : 'light');
  }

  /** Set a specific theme */
  setTheme(theme: Theme): void {
    this.theme.set(theme);
  }

  /** Returns true if dark mode is active */
  isDark(): boolean {
    return this.theme() === 'dark';
  }

  /** Read initial theme from localStorage or default to light */
  private getInitialTheme(): Theme {
    if (typeof window === 'undefined') return 'light';
    const saved = localStorage.getItem(STORAGE_KEY) as Theme | null;
    return saved === 'dark' ? 'dark' : 'light';
  }

  /** Apply theme to the DOM: add .dark class to <html>, with transition */
  private applyTheme(theme: Theme): void {
    if (typeof document === 'undefined') return;

    const html = document.documentElement;

    // Add transition class, toggle theme, remove class after transition
    html.classList.add('theme-transition');

    if (theme === 'dark') {
      html.classList.add('dark');
    } else {
      html.classList.remove('dark');
    }

    setTimeout(() => {
      html.classList.remove('theme-transition');
    }, 220);
  }
}