import { Component, ChangeDetectionStrategy, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { AuthStore } from '../../core/stores/auth.store';

@Component({
  selector: 'app-login-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  template: `
    <div class="login-container">
      <div class="login-card">
        <!-- Logo / Brand -->
        <div class="login-header">
          <div class="login-logo" aria-hidden="true">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
          <h1 class="login-title">Inbiox</h1>
          <p class="login-subtitle">Inicia sesión para continuar</p>
        </div>

        <!-- Error message -->
        @if (errorMessage()) {
          <div class="login-error" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="10"/>
              <line x1="15" y1="9" x2="9" y2="15"/>
              <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <span>{{ errorMessage() }}</span>
          </div>
        }

        <!-- Login form -->
        <form [formGroup]="loginForm" (ngSubmit)="onSubmit()" class="login-form">
          <div class="form-field">
            <label for="email" class="form-label">Email</label>
            <input
              id="email"
              type="email"
              formControlName="email"
              class="form-input"
              placeholder="tu@email.com"
              autocomplete="email"
              [class.form-input--error]="hasError('email')"
            />
            @if (hasError('email')) {
              <p class="form-error">El email es requerido</p>
            }
          </div>

          <div class="form-field">
            <label for="password" class="form-label">Contraseña</label>
            <input
              id="password"
              type="password"
              formControlName="password"
              class="form-input"
              placeholder="••••••••"
              autocomplete="current-password"
              [class.form-input--error]="hasError('password')"
            />
            @if (hasError('password')) {
              <p class="form-error">La contraseña es requerida</p>
            }
          </div>

          <button
            type="submit"
            class="login-button"
            [disabled]="loginForm.invalid || isLoading()"
          >
            @if (isLoading()) {
              <span class="spinner" aria-hidden="true"></span>
              <span>Iniciando sesión...</span>
            } @else {
              <span>Iniciar sesión</span>
            }
          </button>
        </form>
      </div>
    </div>
  `,
  styleUrl: './login-page.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginPageComponent {
  private readonly authStore = inject(AuthStore);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly isLoading = signal(false);
  readonly errorMessage = signal<string | null>(null);

  private loginSubscription: Subscription | null = null;

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

    this.isLoading.set(true);
    this.errorMessage.set(null);

    const { email, password } = this.loginForm.getRawValue();

    this.loginSubscription?.unsubscribe();
    this.loginSubscription = this.authStore.login(email, password).subscribe({
      next: () => {
        this.isLoading.set(false);
        this.router.navigate(['/inbox']);
      },
      error: (err: Error) => {
        this.isLoading.set(false);
        if (err.message?.includes('401')) {
          this.errorMessage.set('Email o contraseña incorrectos');
        } else {
          this.errorMessage.set('Error al iniciar sesión. Intente nuevamente.');
        }
      },
    });
  }
}
