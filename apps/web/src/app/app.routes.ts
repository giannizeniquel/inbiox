import { Routes } from '@angular/router';
import { authGuard, roleGuard } from './core/auth/auth.guard';
import { InboxPageComponent } from './features/inbox/pages/inbox-page/inbox-page.component';
import { ContactsPageComponent } from './features/contacts/pages/contacts-page/contacts-page.component';
import { ReportsPageComponent } from './features/reports/pages/reports-page/reports-page.component';
import { AvailabilityPageComponent } from './features/agents/availability/availability.page';
import { ShiftsPageComponent } from './features/agents/shifts/shifts.page';
import { LoginPageComponent } from './features/login/login-page.component';

export const routes: Routes = [
  {
    path: 'login',
    component: LoginPageComponent,
  },
  {
    path: '',
    redirectTo: 'inbox',
    pathMatch: 'full',
  },
  {
    path: 'inbox',
    component: InboxPageComponent,
    canActivate: [authGuard],
  },
  {
    path: 'contacts',
    component: ContactsPageComponent,
    canActivate: [authGuard],
  },
  {
    path: 'contacts/:contactId',
    component: ContactsPageComponent,
    canActivate: [authGuard],
  },
  {
    path: 'reports',
    component: ReportsPageComponent,
    canActivate: [authGuard],
  },
  {
    path: 'agents/:agentId/availability',
    component: AvailabilityPageComponent,
    canActivate: [authGuard],
  },
  {
    path: 'agents/:agentId/shifts',
    component: ShiftsPageComponent,
    canActivate: [authGuard],
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
    path: 'settings',
    redirectTo: 'inbox',
    pathMatch: 'full',
  },
  {
    path: '**',
    redirectTo: 'inbox',
  },
];
