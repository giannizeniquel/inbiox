import { Routes } from '@angular/router';
import { authGuard, roleGuard } from './core/auth/auth.guard';
import { AppShellComponent } from './shell/app-shell.component';
import { LoginPageComponent } from './features/login/pages/login-page.component';

export const routes: Routes = [
  // ── Public: no shell, no auth ──
  {
    path: 'login',
    component: LoginPageComponent,
  },

  // ── Authenticated routes wrapped in AppShell ──
  {
    path: '',
    component: AppShellComponent,
    canActivate: [authGuard],
    children: [
      {
        path: '',
        redirectTo: 'inbox',
        pathMatch: 'full',
      },
      {
        // The shell renders the inbox layout itself (no router-outlet on this route).
        path: 'inbox',
        children: [],
      },
      {
        path: 'contacts',
        loadComponent: () =>
          import('./features/contactos/pages/contacts-page/contacts-page.component').then(m => m.ContactsPageComponent),
      },
      {
        path: 'contacts/:contactId',
        loadComponent: () =>
          import('./features/contactos/pages/contacts-page/contacts-page.component').then(m => m.ContactsPageComponent),
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reportes/pages/reports-page/reports-page.component').then(m => m.ReportsPageComponent),
      },
      {
        path: 'agents/:agentId/availability',
        loadComponent: () =>
          import('./features/disponibilidad/pages/availability/availability.page').then(m => m.AvailabilityPageComponent),
      },
      {
        path: 'agents/:agentId/shifts',
        loadComponent: () =>
          import('./features/disponibilidad/pages/shifts/shifts.page').then(m => m.ShiftsPageComponent),
      },
      {
        path: 'admin/users',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/administracion/pages/users/users-page.component').then(m => m.UsersPageComponent),
      },
      {
        path: 'admin/agents',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/agents/agents-page.component').then(m => m.AgentsPageComponent),
      },
      {
        path: 'admin/workspaces',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/administracion/pages/workspaces/workspaces-page.component').then(m => m.WorkspacesPageComponent),
      },
      {
        path: 'admin/teams',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/teams/teams-page.component').then(m => m.TeamsPageComponent),
      },
      {
        path: 'settings',
        loadComponent: () =>
          import('./features/configuracion/pages/settings-page/settings-page.component').then(m => m.SettingsPageComponent),
      },
      {
        path: '**',
        redirectTo: 'inbox',
      },
    ],
  },
];
