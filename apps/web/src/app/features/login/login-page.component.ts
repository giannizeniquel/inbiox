import { Component, ChangeDetectionStrategy, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { Subscription } from 'rxjs';
import { AuthStore } from '../../core/stores/auth.store';

@Component({
  selector: 'app-login-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './login-page.component.html',
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
