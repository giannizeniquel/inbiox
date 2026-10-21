import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  AgenteDeGestionDTO,
  AdministracionApiService,
  UsuarioDeGestionDTO,
  VincularUsuarioResponse,
  PaginatedResponse,
} from './administracion-api.service';
import { environment } from '../../../environments/environment';

describe('AdministracionApiService', () => {
  let service: AdministracionApiService;
  let httpMock: HttpTestingController;
  const base = `${environment.apiUrl}/api/administracion`;

  const agente = (overrides: Partial<AgenteDeGestionDTO> = {}): AgenteDeGestionDTO => ({
    id: 1,
    nombre: 'Agent Bob',
    activo: true,
    disponible: true,
    zonaHoraria: 'UTC',
    usuarioId: null,
    usuarioEmail: null,
    creadoAt: '2024-01-01T00:00:00Z',
    actualizadoAt: '2024-01-01T00:00:00Z',
    ...overrides,
  });

  const usuario = (overrides: Partial<UsuarioDeGestionDTO> = {}): UsuarioDeGestionDTO => ({
    id: 10,
    email: 'newuser@example.com',
    roles: ['ROLE_AGENTE'],
    activo: true,
    agenteId: null,
    agenteNombre: null,
    creadoAt: '2024-01-01T00:00:00Z',
    actualizadoAt: '2024-01-01T00:00:00Z',
    ...overrides,
  });

  const page = <T>(data: T[], total: number, limit = 20, pageNo = 1): PaginatedResponse<T> => ({
    data,
    meta: { page: pageNo, limit, total, haySiguiente: pageNo * limit < total },
  });

  /** Responds with the backend's error envelope `{ error: message }`. */
  const failWith = (url: string, method: string, message: string): void => {
    httpMock
      .expectOne((r) => r.urlWithParams.startsWith(url) && r.method === method)
      .flush({ error: message }, { status: 400, statusText: 'Bad Request' });
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AdministracionApiService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  // ── Users ─────────────────────────────────────────────────

  describe('listUsers()', () => {
    it('should request the given page and limit', () => {
      service.listarUsuarios(1, 20).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/usuarios`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([usuario()], 1));
    });

    it('should map the response to items, total and totalPages', (done) => {
      const u = usuario({ id: 1, agenteId: 3, agenteNombre: 'Agent A' });
      service.listarUsuarios(2).subscribe((out) => {
        expect(out.items).toEqual([u]);
        expect(out.total).toBe(55);
        expect(out.totalPages).toBe(3); // ceil(55/20) = 3
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/usuarios`).flush(page([u], 55, 20, 2));
    });

    it('should default limit to 20 when not provided', () => {
      service.listarUsuarios(3).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/usuarios`);
      expect(req.request.params.get('page')).toBe('3');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([], 0));
    });

    it('should return totalPages of 1 when total is 0', (done) => {
      service.listarUsuarios(1).subscribe((out) => {
        expect(out.totalPages).toBe(1);
        expect(out.total).toBe(0);
        expect(out.items).toEqual([]);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/usuarios`).flush(page([], 0));
    });

    it('should fail with the backend message', (done) => {
      service.listarUsuarios(1).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/usuarios`, 'GET', 'Network error');
    });
  });

  describe('listUsersAvailableForLinking()', () => {
    const usuarioConAgente = usuario({ id: 1, email: 'already-linked@example.com', agenteId: 5, agenteNombre: 'Agent A' });
    const usuarioDisponible = usuario({ id: 2, email: 'available@example.com' });
    const usuarioInactivo = usuario({ id: 3, email: 'inactive@example.com', activo: false });

    it('should request page 1 with limit 200', () => {
      service.listarUsuariosDisponiblesParaVincular().subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/usuarios`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('200');
      req.flush(page([usuarioDisponible], 1, 200));
    });

    it('should filter to active users without agent_id', (done) => {
      service.listarUsuariosDisponiblesParaVincular().subscribe((out) => {
        expect(out.users).toHaveSize(1);
        expect(out.users[0].id).toBe(2);
        expect(out.users[0].email).toBe('available@example.com');
        done();
      });
      httpMock
        .expectOne((r) => r.url === `${base}/usuarios`)
        .flush(page([usuarioConAgente, usuarioDisponible, usuarioInactivo], 3, 200));
    });

    it('should return an empty array when all users are already linked', (done) => {
      service.listarUsuariosDisponiblesParaVincular().subscribe((out) => {
        expect(out.users).toHaveSize(0);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/usuarios`).flush(page([usuarioConAgente], 1, 200));
    });

    it('should fail with the backend message', (done) => {
      service.listarUsuariosDisponiblesParaVincular().subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/usuarios`, 'GET', 'Network error');
    });
  });

  describe('createUser()', () => {
    it('should post email, password, roles and agent_id', () => {
      service.createUsuario({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENTE'], agentId: null }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        email: 'newuser@example.com',
        password: 'secret123',
        roles: ['ROLE_AGENTE'],
        agenteId: null,
      });
      req.flush(usuario());
    });

    it('should default roles to ROLE_AGENTE when an empty array is provided', () => {
      service.createUsuario({ email: 'newuser@example.com', password: 'secret123', roles: [], agentId: 5 }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios`);
      expect(req.request.body).toEqual({
        email: 'newuser@example.com',
        password: 'secret123',
        roles: ['ROLE_AGENTE'],
        agenteId: 5,
      });
      req.flush(usuario());
    });

    it('should return { user } with the created user', (done) => {
      const created = usuario();
      service.createUsuario({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENTE'], agentId: null }).subscribe((out) => {
        expect(out.user).toEqual(created);
        done();
      });
      httpMock.expectOne(`${base}/usuarios`).flush(created);
    });

    it('should fail without a request when email is empty', (done) => {
      service.createUsuario({ email: '', password: 'secret123', roles: ['ROLE_AGENTE'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email and password are required');
          done();
        },
      });
      httpMock.expectNone(`${base}/usuarios`);
    });

    it('should fail without a request when password is empty', (done) => {
      service.createUsuario({ email: 'user@example.com', password: '', roles: ['ROLE_AGENTE'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email and password are required');
          done();
        },
      });
      httpMock.expectNone(`${base}/usuarios`);
    });

    it('should fail with the backend message on duplicate email', (done) => {
      service.createUsuario({ email: 'existing@example.com', password: 'secret123', roles: ['ROLE_AGENTE'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email already exists');
          done();
        },
      });
      failWith(`${base}/usuarios`, 'POST', 'Email already exists');
    });
  });

  describe('updateUser()', () => {
    const updated = usuario({ id: 5, email: 'updated@example.com', roles: ['ROLE_AGENTE', 'ROLE_SUPERVISOR'] });

    it('should put email and roles, always including email', () => {
      service.updateUsuario({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENTE'] }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/5`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ email: 'updated@example.com', roles: ['ROLE_AGENTE'] });
      req.flush(updated);
    });

    it('should include password only when non-empty', () => {
      service.updateUsuario({ id: 5, email: 'updated@example.com', password: 'newpass123', roles: ['ROLE_AGENTE'] }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/5`);
      expect(req.request.body).toEqual({
        email: 'updated@example.com',
        password: 'newpass123',
        roles: ['ROLE_AGENTE'],
      });
      req.flush(updated);
    });

    it('should not include password when it is an empty string', () => {
      service.updateUsuario({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENTE'] }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/5`);
      expect(Object.keys(req.request.body)).not.toContain('password');
      req.flush(updated);
    });

    it('should default roles to ROLE_AGENTE when an empty array is provided', () => {
      service.updateUsuario({ id: 5, email: 'updated@example.com', password: '', roles: [] }).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/5`);
      expect(req.request.body).toEqual({ email: 'updated@example.com', roles: ['ROLE_AGENTE'] });
      req.flush(updated);
    });

    it('should return { user } with the updated user', (done) => {
      service.updateUsuario({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENTE'] }).subscribe((out) => {
        expect(out.user).toEqual(updated);
        done();
      });
      httpMock.expectOne(`${base}/usuarios/5`).flush(updated);
    });

    it('should fail with the backend message on not-found', (done) => {
      service.updateUsuario({ id: 999, email: 'ghost@example.com', password: '', roles: ['ROLE_AGENTE'] }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('User not found');
          done();
        },
      });
      failWith(`${base}/usuarios/999`, 'PUT', 'User not found');
    });
  });

  describe('deactivateUser()', () => {
    it('should send DELETE for the user id', () => {
      service.desactivarUsuario(8).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/8`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should return { id } of the deactivated user', (done) => {
      service.desactivarUsuario(8).subscribe((out) => {
        expect(out.id).toBe(8);
        done();
      });
      httpMock.expectOne(`${base}/usuarios/8`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.desactivarUsuario(8).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/usuarios/8`, 'DELETE', 'Network error');
    });
  });

  describe('reactivateUser()', () => {
    it('should put is_active=true for the user id', () => {
      service.reactivarUsuario(4).subscribe();
      const req = httpMock.expectOne(`${base}/usuarios/4`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ activo: true });
      req.flush(usuario({ id: 4 }));
    });

    it('should return { id } of the reactivated user', (done) => {
      service.reactivarUsuario(4).subscribe((out) => {
        expect(out.id).toBe(4);
        done();
      });
      httpMock.expectOne(`${base}/usuarios/4`).flush(usuario({ id: 4 }));
    });

    it('should fail with the backend message', (done) => {
      service.reactivarUsuario(999).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('User not found');
          done();
        },
      });
      failWith(`${base}/usuarios/999`, 'PUT', 'User not found');
    });
  });

  // ── Agents ────────────────────────────────────────────────

  describe('listAgents()', () => {
    const a = agente({ nombre: 'Agent Alice', usuarioId: 10, usuarioEmail: 'alice@example.com' });

    it('should request the given page and limit', () => {
      service.listarAgentes(1, 20).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/agentes`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([a], 1));
    });

    it('should map the response to items, total and totalPages', (done) => {
      service.listarAgentes(1).subscribe((out) => {
        expect(out.items).toEqual([a]);
        expect(out.total).toBe(45);
        expect(out.totalPages).toBe(3); // ceil(45/20) = 3
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/agentes`).flush(page([a], 45));
    });

    it('should default limit to 20 when not provided', () => {
      service.listarAgentes(2).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/agentes`);
      expect(req.request.params.get('page')).toBe('2');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([], 0));
    });

    it('should return totalPages of 1 when total is 0', (done) => {
      service.listarAgentes(1).subscribe((out) => {
        expect(out.totalPages).toBe(1);
        expect(out.total).toBe(0);
        expect(out.items).toEqual([]);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/agentes`).flush(page([], 0));
    });

    it('should fail with the backend message', (done) => {
      service.listarAgentes(1).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/agentes`, 'GET', 'Network error');
    });
  });

  describe('createAgent()', () => {
    it('should post the trimmed name', () => {
      service.createAgente({ name: '  Agent Bob  ' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ nombre: 'Agent Bob' });
      req.flush(agente());
    });

    it('should return { agent } with the created agent', (done) => {
      const created = agente();
      service.createAgente({ name: 'Agent Bob' }).subscribe((out) => {
        expect(out.agent).toEqual(created);
        done();
      });
      httpMock.expectOne(`${base}/agentes`).flush(created);
    });

    it('should fail without a request when the name is empty', (done) => {
      service.createAgente({ name: '' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent name is required');
          done();
        },
      });
      httpMock.expectNone(`${base}/agentes`);
    });

    it('should fail without a request when the name is only whitespace', (done) => {
      service.createAgente({ name: '   ' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent name is required');
          done();
        },
      });
      httpMock.expectNone(`${base}/agentes`);
    });

    it('should fail with the backend message', (done) => {
      service.createAgente({ name: 'Agent Bob' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Duplicate agent name');
          done();
        },
      });
      failWith(`${base}/agentes`, 'POST', 'Duplicate agent name');
    });

    it('should accept a valid non-empty name', (done) => {
      service.createAgente({ name: 'ValidAgentName' }).subscribe((out) => {
        expect(out.agent.nombre).toBe('Agent Bob');
        done();
      });
      httpMock.expectOne(`${base}/agentes`).flush(agente());
    });

    it('should pass timezone through to the payload when provided', () => {
      service.createAgente({ name: 'Agent Bob', timezone: 'America/Sao_Paulo' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes`);
      expect(req.request.body).toEqual({ nombre: 'Agent Bob', zonaHoraria: 'America/Sao_Paulo' });
      req.flush(agente());
    });

    it('should omit timezone from the payload when not provided', () => {
      service.createAgente({ name: 'Agent Bob' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes`);
      expect(req.request.body).toEqual({ nombre: 'Agent Bob' });
      req.flush(agente());
    });
  });

  describe('updateAgent()', () => {
    const updated = agente({ id: 5, nombre: 'Updated Agent' });

    it('should put the trimmed name, active and timezone', () => {
      service.updateAgente({ id: 5, name: '  Updated Agent  ', active: true, timezone: 'UTC' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/5`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ nombre: 'Updated Agent', activo: true, zonaHoraria: 'UTC' });
      req.flush(updated);
    });

    it('should return { agent } with the updated agent', (done) => {
      service.updateAgente({ id: 5, name: 'Updated Agent', active: true, timezone: 'UTC' }).subscribe((out) => {
        expect(out.agent).toEqual(updated);
        done();
      });
      httpMock.expectOne(`${base}/agentes/5`).flush(updated);
    });

    it('should send active=false when deactivating', () => {
      service.updateAgente({ id: 5, name: 'Updated Agent', active: false, timezone: 'UTC' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/5`);
      expect(req.request.body).toEqual({ nombre: 'Updated Agent', activo: false, zonaHoraria: 'UTC' });
      req.flush({ ...updated, active: false });
    });

    it('should fail with the backend message on not-found', (done) => {
      service.updateAgente({ id: 999, name: 'Ghost', active: true, timezone: 'UTC' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent not found');
          done();
        },
      });
      failWith(`${base}/agentes/999`, 'PUT', 'Agent not found');
    });

    it('should pass a non-UTC timezone through to the payload', () => {
      service.updateAgente({ id: 5, name: 'Updated Agent', active: true, timezone: 'Europe/Lisbon' }).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/5`);
      expect(req.request.body).toEqual({ nombre: 'Updated Agent', activo: true, zonaHoraria: 'Europe/Lisbon' });
      req.flush(updated);
    });
  });

  describe('deactivateAgent()', () => {
    it('should send DELETE for the agent id', () => {
      service.desactivarAgente(7).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/7`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should return { id } of the deactivated agent', (done) => {
      service.desactivarAgente(7).subscribe((out) => {
        expect(out.id).toBe(7);
        done();
      });
      httpMock.expectOne(`${base}/agentes/7`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.desactivarAgente(7).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/agentes/7`, 'DELETE', 'Network error');
    });
  });

  describe('reactivateAgent()', () => {
    it('should put active=true for the agent id', () => {
      service.reactivarAgente(3).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/3`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ activo: true });
      req.flush(agente({ id: 3 }));
    });

    it('should return { id } of the reactivated agent', (done) => {
      service.reactivarAgente(3).subscribe((out) => {
        expect(out.id).toBe(3);
        done();
      });
      httpMock.expectOne(`${base}/agentes/3`).flush(agente({ id: 3 }));
    });

    it('should fail with the backend message', (done) => {
      service.reactivarAgente(999).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent not found');
          done();
        },
      });
      failWith(`${base}/agentes/999`, 'PUT', 'Agent not found');
    });
  });

  // ── Linking ───────────────────────────────────────────────

  describe('linkUser()', () => {
    const vinculoResponse: VincularUsuarioResponse = { id: 1, usuarioId: 10, usuarioEmail: 'user@example.com' };

    it('should post user_id to the agent link endpoint', () => {
      service.vincularUsuario(5, 10).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/5/vincular-usuario`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ usuarioId: 10 });
      req.flush(vinculoResponse);
    });

    it('should return the link response on success', (done) => {
      service.vincularUsuario(5, 10).subscribe((res) => {
        expect(res).toEqual(vinculoResponse);
        done();
      });
      httpMock.expectOne(`${base}/agentes/5/vincular-usuario`).flush(vinculoResponse);
    });

    it('should fail with the backend message', (done) => {
      service.vincularUsuario(5, 10).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Link conflict');
          done();
        },
      });
      failWith(`${base}/agentes/5/vincular-usuario`, 'POST', 'Link conflict');
    });
  });

  describe('unlinkUser()', () => {
    it('should send DELETE to the agent link endpoint', () => {
      service.desvincularUsuario(5).subscribe();
      const req = httpMock.expectOne(`${base}/agentes/5/vincular-usuario`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should emit no value on success', (done) => {
      service.desvincularUsuario(5).subscribe((res) => {
        expect(res).toBeNull();
        done();
      });
      httpMock.expectOne(`${base}/agentes/5/vincular-usuario`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.desvincularUsuario(5).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Not linked');
          done();
        },
      });
      failWith(`${base}/agentes/5/vincular-usuario`, 'DELETE', 'Not linked');
    });
  });
});
