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
        redirectTo: 'bandeja',
        pathMatch: 'full',
      },
      {
        // The shell renders the inbox (bandeja) layout itself (no router-outlet on this route).
        path: 'bandeja',
        children: [],
      },
      {
        path: 'contactos',
        loadComponent: () =>
          import('./features/contactos/pages/contactos-page/contactos-page.component').then(m => m.ContactosPageComponent),
      },
      {
        path: 'contactos/:contactoId',
        loadComponent: () =>
          import('./features/contactos/pages/contactos-page/contactos-page.component').then(m => m.ContactosPageComponent),
      },
      {
        path: 'reportes',
        loadComponent: () =>
          import('./features/reportes/pages/reportes-page/reportes-page.component').then(m => m.ReportesPageComponent),
      },
      {
        path: 'agentes/:agenteId/disponibilidad',
        loadComponent: () =>
          import('./features/disponibilidad/pages/disponibilidad/disponibilidad.page').then(m => m.DisponibilidadPageComponent),
      },
      {
        path: 'agentes/:agenteId/turnos',
        loadComponent: () =>
          import('./features/disponibilidad/pages/turnos/turnos.page').then(m => m.TurnosPageComponent),
      },
      {
        path: 'administracion/usuarios',
        canActivate: [roleGuard('ROLE_ADMINISTRADOR')],
        loadComponent: () =>
          import('./features/administracion/pages/usuarios/usuarios-page.component').then(m => m.UsuariosPageComponent),
      },
      {
        path: 'administracion/agentes',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/agentes/agentes-page.component').then(m => m.AgentesPageComponent),
      },
      {
        path: 'administracion/espacios-de-trabajo',
        canActivate: [roleGuard('ROLE_ADMINISTRADOR')],
        loadComponent: () =>
          import('./features/administracion/pages/espacios-de-trabajo/espacios-de-trabajo-page.component').then(m => m.EspaciosDeTrabajoPageComponent),
      },
      {
        path: 'administracion/equipos',
        canActivate: [roleGuard('ROLE_SUPERVISOR')],
        loadComponent: () =>
          import('./features/administracion/pages/equipos/equipos-page.component').then(m => m.EquiposPageComponent),
      },
      {
        path: 'configuracion',
        loadComponent: () =>
          import('./features/configuracion/pages/configuracion-page/configuracion-page.component').then(m => m.ConfiguracionPageComponent),
      },
      // Stage 4: remove these redirects from the old URLs.
      { path: 'inbox', redirectTo: 'bandeja', pathMatch: 'full' },
      { path: 'contacts', redirectTo: 'contactos', pathMatch: 'full' },
      { path: 'contacts/:contactId', redirectTo: 'contactos/:contactId' },
      { path: 'reports', redirectTo: 'reportes', pathMatch: 'full' },
      { path: 'agents/:agentId/availability', redirectTo: 'agentes/:agentId/disponibilidad' },
      { path: 'agents/:agentId/shifts', redirectTo: 'agentes/:agentId/turnos' },
      { path: 'admin/users', redirectTo: 'administracion/usuarios', pathMatch: 'full' },
      { path: 'admin/agents', redirectTo: 'administracion/agentes', pathMatch: 'full' },
      { path: 'admin/workspaces', redirectTo: 'administracion/espacios-de-trabajo', pathMatch: 'full' },
      { path: 'admin/teams', redirectTo: 'administracion/equipos', pathMatch: 'full' },
      { path: 'settings', redirectTo: 'configuracion', pathMatch: 'full' },
      {
        path: '**',
        redirectTo: 'bandeja',
      },
    ],
  },
];
