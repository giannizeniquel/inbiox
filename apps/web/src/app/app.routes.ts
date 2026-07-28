import { Routes } from '@angular/router';
import { authGuard, roleGuard } from './core/auth/auth.guard';
import { AppShellComponent } from './shell/app-shell.component';
import { LoginPageComponent } from './features/login/login-page.component';

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
        path: 'inbox',
        loadComponent: () =>
          import('./features/inbox/pages/inbox-page/inbox-page.component').then(m => m.InboxPageComponent),
      },
      {
        path: 'contacts',
        loadComponent: () =>
          import('./features/contacts/pages/contacts-page/contacts-page.component').then(m => m.ContactsPageComponent),
      },
      {
        path: 'contacts/:contactId',
        loadComponent: () =>
          import('./features/contacts/pages/contacts-page/contacts-page.component').then(m => m.ContactsPageComponent),
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reports/pages/reports-page/reports-page.component').then(m => m.ReportsPageComponent),
      },
      {
        path: 'agents/:agentId/availability',
        loadComponent: () =>
          import('./features/agents/availability/availability.page').then(m => m.AvailabilityPageComponent),
      },
      {
        path: 'agents/:agentId/shifts',
        loadComponent: () =>
          import('./features/agents/shifts/shifts.page').then(m => m.ShiftsPageComponent),
      },
      {
        path: 'admin/users',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/admin/users/users-page.component').then(m => m.UsersPageComponent),
      },
      {
        path: 'admin/agents',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/admin/agents/agents-page.component').then(m => m.AgentsPageComponent),
      },
      {
        path: 'admin/workspaces',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/admin/workspaces/workspaces-page.component').then(m => m.WorkspacesPageComponent),
      },
      {
        path: 'settings',
        redirectTo: 'inbox',
        pathMatch: 'full',
      },
      {
        path: '**',
        redirectTo: 'inbox',
      },
    ],
  },
];
