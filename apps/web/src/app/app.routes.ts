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
          import('./features/contactos/pages/contactos-page/contactos-page.component').then(m => m.ContactosPageComponent),
      },
      {
        path: 'contacts/:contactId',
        loadComponent: () =>
          import('./features/contactos/pages/contactos-page/contactos-page.component').then(m => m.ContactosPageComponent),
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reportes/pages/reportes-page/reportes-page.component').then(m => m.ReportesPageComponent),
      },
      {
        path: 'agents/:agentId/availability',
        loadComponent: () =>
          import('./features/disponibilidad/pages/disponibilidad/disponibilidad.page').then(m => m.DisponibilidadPageComponent),
      },
      {
        path: 'agents/:agentId/shifts',
        loadComponent: () =>
          import('./features/disponibilidad/pages/turnos/turnos.page').then(m => m.TurnosPageComponent),
      },
      {
        path: 'admin/users',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/administracion/pages/usuarios/usuarios-page.component').then(m => m.UsuariosPageComponent),
      },
      {
        path: 'admin/agents',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/agentes/agentes-page.component').then(m => m.AgentesPageComponent),
      },
      {
        path: 'admin/workspaces',
        canActivate: [roleGuard('ROLE_ADMIN')],
        loadComponent: () =>
          import('./features/administracion/pages/espacios-de-trabajo/espacios-de-trabajo-page.component').then(m => m.EspaciosDeTrabajoPageComponent),
      },
      {
        path: 'admin/teams',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/equipos/equipos-page.component').then(m => m.EquiposPageComponent),
      },
      {
        path: 'settings',
        loadComponent: () =>
          import('./features/configuracion/pages/configuracion-page/configuracion-page.component').then(m => m.ConfiguracionPageComponent),
      },
      {
        path: '**',
        redirectTo: 'inbox',
      },
    ],
  },
];
