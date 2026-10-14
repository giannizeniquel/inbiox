import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  AdminAgentDTO,
  AdminApiService,
  AdminUserDTO,
  LinkUserResponse,
  PaginatedResponse,
} from './admin-api.service';
import { environment } from '../../../environments/environment';

describe('AdminApiService', () => {
  let service: AdminApiService;
  let httpMock: HttpTestingController;
  const base = `${environment.apiUrl}/api/admin`;

  const agent = (overrides: Partial<AdminAgentDTO> = {}): AdminAgentDTO => ({
    id: 1,
    name: 'Agent Bob',
    active: true,
    is_available: true,
    timezone: 'UTC',
    user_id: null,
    user_email: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
    ...overrides,
  });

  const user = (overrides: Partial<AdminUserDTO> = {}): AdminUserDTO => ({
    id: 10,
    email: 'newuser@example.com',
    roles: ['ROLE_AGENT'],
    is_active: true,
    agent_id: null,
    agent_name: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
    ...overrides,
  });

  const page = <T>(data: T[], total: number, limit = 20, pageNo = 1): PaginatedResponse<T> => ({
    data,
    meta: { page: pageNo, limit, total, has_next: pageNo * limit < total },
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
    service = TestBed.inject(AdminApiService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  // ── Users ─────────────────────────────────────────────────

  describe('listUsers()', () => {
    it('should request the given page and limit', () => {
      service.listUsers(1, 20).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/users`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([user()], 1));
    });

    it('should map the response to items, total and totalPages', (done) => {
      const u = user({ id: 1, agent_id: 3, agent_name: 'Agent A' });
      service.listUsers(2).subscribe((out) => {
        expect(out.items).toEqual([u]);
        expect(out.total).toBe(55);
        expect(out.totalPages).toBe(3); // ceil(55/20) = 3
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/users`).flush(page([u], 55, 20, 2));
    });

    it('should default limit to 20 when not provided', () => {
      service.listUsers(3).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/users`);
      expect(req.request.params.get('page')).toBe('3');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([], 0));
    });

    it('should return totalPages of 1 when total is 0', (done) => {
      service.listUsers(1).subscribe((out) => {
        expect(out.totalPages).toBe(1);
        expect(out.total).toBe(0);
        expect(out.items).toEqual([]);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/users`).flush(page([], 0));
    });

    it('should fail with the backend message', (done) => {
      service.listUsers(1).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/users`, 'GET', 'Network error');
    });
  });

  describe('listUsersAvailableForLinking()', () => {
    const userWithAgent = user({ id: 1, email: 'already-linked@example.com', agent_id: 5, agent_name: 'Agent A' });
    const availableUser = user({ id: 2, email: 'available@example.com' });
    const inactiveUser = user({ id: 3, email: 'inactive@example.com', is_active: false });

    it('should request page 1 with limit 200', () => {
      service.listUsersAvailableForLinking().subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/users`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('200');
      req.flush(page([availableUser], 1, 200));
    });

    it('should filter to active users without agent_id', (done) => {
      service.listUsersAvailableForLinking().subscribe((out) => {
        expect(out.users).toHaveSize(1);
        expect(out.users[0].id).toBe(2);
        expect(out.users[0].email).toBe('available@example.com');
        done();
      });
      httpMock
        .expectOne((r) => r.url === `${base}/users`)
        .flush(page([userWithAgent, availableUser, inactiveUser], 3, 200));
    });

    it('should return an empty array when all users are already linked', (done) => {
      service.listUsersAvailableForLinking().subscribe((out) => {
        expect(out.users).toHaveSize(0);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/users`).flush(page([userWithAgent], 1, 200));
    });

    it('should fail with the backend message', (done) => {
      service.listUsersAvailableForLinking().subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/users`, 'GET', 'Network error');
    });
  });

  describe('createUser()', () => {
    it('should post email, password, roles and agent_id', () => {
      service.createUser({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe();
      const req = httpMock.expectOne(`${base}/users`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({
        email: 'newuser@example.com',
        password: 'secret123',
        roles: ['ROLE_AGENT'],
        agent_id: null,
      });
      req.flush(user());
    });

    it('should default roles to ROLE_AGENT when an empty array is provided', () => {
      service.createUser({ email: 'newuser@example.com', password: 'secret123', roles: [], agentId: 5 }).subscribe();
      const req = httpMock.expectOne(`${base}/users`);
      expect(req.request.body).toEqual({
        email: 'newuser@example.com',
        password: 'secret123',
        roles: ['ROLE_AGENT'],
        agent_id: 5,
      });
      req.flush(user());
    });

    it('should return { user } with the created user', (done) => {
      const created = user();
      service.createUser({ email: 'newuser@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe((out) => {
        expect(out.user).toEqual(created);
        done();
      });
      httpMock.expectOne(`${base}/users`).flush(created);
    });

    it('should fail without a request when email is empty', (done) => {
      service.createUser({ email: '', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email and password are required');
          done();
        },
      });
      httpMock.expectNone(`${base}/users`);
    });

    it('should fail without a request when password is empty', (done) => {
      service.createUser({ email: 'user@example.com', password: '', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email and password are required');
          done();
        },
      });
      httpMock.expectNone(`${base}/users`);
    });

    it('should fail with the backend message on duplicate email', (done) => {
      service.createUser({ email: 'existing@example.com', password: 'secret123', roles: ['ROLE_AGENT'], agentId: null }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Email already exists');
          done();
        },
      });
      failWith(`${base}/users`, 'POST', 'Email already exists');
    });
  });

  describe('updateUser()', () => {
    const updated = user({ id: 5, email: 'updated@example.com', roles: ['ROLE_AGENT', 'ROLE_SUPERVISOR'] });

    it('should put email and roles, always including email', () => {
      service.updateUser({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe();
      const req = httpMock.expectOne(`${base}/users/5`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ email: 'updated@example.com', roles: ['ROLE_AGENT'] });
      req.flush(updated);
    });

    it('should include password only when non-empty', () => {
      service.updateUser({ id: 5, email: 'updated@example.com', password: 'newpass123', roles: ['ROLE_AGENT'] }).subscribe();
      const req = httpMock.expectOne(`${base}/users/5`);
      expect(req.request.body).toEqual({
        email: 'updated@example.com',
        password: 'newpass123',
        roles: ['ROLE_AGENT'],
      });
      req.flush(updated);
    });

    it('should not include password when it is an empty string', () => {
      service.updateUser({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe();
      const req = httpMock.expectOne(`${base}/users/5`);
      expect(Object.keys(req.request.body)).not.toContain('password');
      req.flush(updated);
    });

    it('should default roles to ROLE_AGENT when an empty array is provided', () => {
      service.updateUser({ id: 5, email: 'updated@example.com', password: '', roles: [] }).subscribe();
      const req = httpMock.expectOne(`${base}/users/5`);
      expect(req.request.body).toEqual({ email: 'updated@example.com', roles: ['ROLE_AGENT'] });
      req.flush(updated);
    });

    it('should return { user } with the updated user', (done) => {
      service.updateUser({ id: 5, email: 'updated@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe((out) => {
        expect(out.user).toEqual(updated);
        done();
      });
      httpMock.expectOne(`${base}/users/5`).flush(updated);
    });

    it('should fail with the backend message on not-found', (done) => {
      service.updateUser({ id: 999, email: 'ghost@example.com', password: '', roles: ['ROLE_AGENT'] }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('User not found');
          done();
        },
      });
      failWith(`${base}/users/999`, 'PUT', 'User not found');
    });
  });

  describe('deactivateUser()', () => {
    it('should send DELETE for the user id', () => {
      service.deactivateUser(8).subscribe();
      const req = httpMock.expectOne(`${base}/users/8`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should return { id } of the deactivated user', (done) => {
      service.deactivateUser(8).subscribe((out) => {
        expect(out.id).toBe(8);
        done();
      });
      httpMock.expectOne(`${base}/users/8`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.deactivateUser(8).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/users/8`, 'DELETE', 'Network error');
    });
  });

  describe('reactivateUser()', () => {
    it('should put is_active=true for the user id', () => {
      service.reactivateUser(4).subscribe();
      const req = httpMock.expectOne(`${base}/users/4`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ is_active: true });
      req.flush(user({ id: 4 }));
    });

    it('should return { id } of the reactivated user', (done) => {
      service.reactivateUser(4).subscribe((out) => {
        expect(out.id).toBe(4);
        done();
      });
      httpMock.expectOne(`${base}/users/4`).flush(user({ id: 4 }));
    });

    it('should fail with the backend message', (done) => {
      service.reactivateUser(999).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('User not found');
          done();
        },
      });
      failWith(`${base}/users/999`, 'PUT', 'User not found');
    });
  });

  // ── Agents ────────────────────────────────────────────────

  describe('listAgents()', () => {
    const a = agent({ name: 'Agent Alice', user_id: 10, user_email: 'alice@example.com' });

    it('should request the given page and limit', () => {
      service.listAgents(1, 20).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/agents`);
      expect(req.request.params.get('page')).toBe('1');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([a], 1));
    });

    it('should map the response to items, total and totalPages', (done) => {
      service.listAgents(1).subscribe((out) => {
        expect(out.items).toEqual([a]);
        expect(out.total).toBe(45);
        expect(out.totalPages).toBe(3); // ceil(45/20) = 3
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/agents`).flush(page([a], 45));
    });

    it('should default limit to 20 when not provided', () => {
      service.listAgents(2).subscribe();
      const req = httpMock.expectOne((r) => r.url === `${base}/agents`);
      expect(req.request.params.get('page')).toBe('2');
      expect(req.request.params.get('limit')).toBe('20');
      req.flush(page([], 0));
    });

    it('should return totalPages of 1 when total is 0', (done) => {
      service.listAgents(1).subscribe((out) => {
        expect(out.totalPages).toBe(1);
        expect(out.total).toBe(0);
        expect(out.items).toEqual([]);
        done();
      });
      httpMock.expectOne((r) => r.url === `${base}/agents`).flush(page([], 0));
    });

    it('should fail with the backend message', (done) => {
      service.listAgents(1).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/agents`, 'GET', 'Network error');
    });
  });

  describe('createAgent()', () => {
    it('should post the trimmed name', () => {
      service.createAgent({ name: '  Agent Bob  ' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ name: 'Agent Bob' });
      req.flush(agent());
    });

    it('should return { agent } with the created agent', (done) => {
      const created = agent();
      service.createAgent({ name: 'Agent Bob' }).subscribe((out) => {
        expect(out.agent).toEqual(created);
        done();
      });
      httpMock.expectOne(`${base}/agents`).flush(created);
    });

    it('should fail without a request when the name is empty', (done) => {
      service.createAgent({ name: '' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent name is required');
          done();
        },
      });
      httpMock.expectNone(`${base}/agents`);
    });

    it('should fail without a request when the name is only whitespace', (done) => {
      service.createAgent({ name: '   ' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent name is required');
          done();
        },
      });
      httpMock.expectNone(`${base}/agents`);
    });

    it('should fail with the backend message', (done) => {
      service.createAgent({ name: 'Agent Bob' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Duplicate agent name');
          done();
        },
      });
      failWith(`${base}/agents`, 'POST', 'Duplicate agent name');
    });

    it('should accept a valid non-empty name', (done) => {
      service.createAgent({ name: 'ValidAgentName' }).subscribe((out) => {
        expect(out.agent.name).toBe('Agent Bob');
        done();
      });
      httpMock.expectOne(`${base}/agents`).flush(agent());
    });

    it('should pass timezone through to the payload when provided', () => {
      service.createAgent({ name: 'Agent Bob', timezone: 'America/Sao_Paulo' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents`);
      expect(req.request.body).toEqual({ name: 'Agent Bob', timezone: 'America/Sao_Paulo' });
      req.flush(agent());
    });

    it('should omit timezone from the payload when not provided', () => {
      service.createAgent({ name: 'Agent Bob' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents`);
      expect(req.request.body).toEqual({ name: 'Agent Bob' });
      req.flush(agent());
    });
  });

  describe('updateAgent()', () => {
    const updated = agent({ id: 5, name: 'Updated Agent' });

    it('should put the trimmed name, active and timezone', () => {
      service.updateAgent({ id: 5, name: '  Updated Agent  ', active: true, timezone: 'UTC' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents/5`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ name: 'Updated Agent', active: true, timezone: 'UTC' });
      req.flush(updated);
    });

    it('should return { agent } with the updated agent', (done) => {
      service.updateAgent({ id: 5, name: 'Updated Agent', active: true, timezone: 'UTC' }).subscribe((out) => {
        expect(out.agent).toEqual(updated);
        done();
      });
      httpMock.expectOne(`${base}/agents/5`).flush(updated);
    });

    it('should send active=false when deactivating', () => {
      service.updateAgent({ id: 5, name: 'Updated Agent', active: false, timezone: 'UTC' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents/5`);
      expect(req.request.body).toEqual({ name: 'Updated Agent', active: false, timezone: 'UTC' });
      req.flush({ ...updated, active: false });
    });

    it('should fail with the backend message on not-found', (done) => {
      service.updateAgent({ id: 999, name: 'Ghost', active: true, timezone: 'UTC' }).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent not found');
          done();
        },
      });
      failWith(`${base}/agents/999`, 'PUT', 'Agent not found');
    });

    it('should pass a non-UTC timezone through to the payload', () => {
      service.updateAgent({ id: 5, name: 'Updated Agent', active: true, timezone: 'Europe/Lisbon' }).subscribe();
      const req = httpMock.expectOne(`${base}/agents/5`);
      expect(req.request.body).toEqual({ name: 'Updated Agent', active: true, timezone: 'Europe/Lisbon' });
      req.flush(updated);
    });
  });

  describe('deactivateAgent()', () => {
    it('should send DELETE for the agent id', () => {
      service.deactivateAgent(7).subscribe();
      const req = httpMock.expectOne(`${base}/agents/7`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should return { id } of the deactivated agent', (done) => {
      service.deactivateAgent(7).subscribe((out) => {
        expect(out.id).toBe(7);
        done();
      });
      httpMock.expectOne(`${base}/agents/7`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.deactivateAgent(7).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Network error');
          done();
        },
      });
      failWith(`${base}/agents/7`, 'DELETE', 'Network error');
    });
  });

  describe('reactivateAgent()', () => {
    it('should put active=true for the agent id', () => {
      service.reactivateAgent(3).subscribe();
      const req = httpMock.expectOne(`${base}/agents/3`);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ active: true });
      req.flush(agent({ id: 3 }));
    });

    it('should return { id } of the reactivated agent', (done) => {
      service.reactivateAgent(3).subscribe((out) => {
        expect(out.id).toBe(3);
        done();
      });
      httpMock.expectOne(`${base}/agents/3`).flush(agent({ id: 3 }));
    });

    it('should fail with the backend message', (done) => {
      service.reactivateAgent(999).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Agent not found');
          done();
        },
      });
      failWith(`${base}/agents/999`, 'PUT', 'Agent not found');
    });
  });

  // ── Linking ───────────────────────────────────────────────

  describe('linkUser()', () => {
    const linkResponse: LinkUserResponse = { id: 1, user_id: 10, user_email: 'user@example.com' };

    it('should post user_id to the agent link endpoint', () => {
      service.linkUser(5, 10).subscribe();
      const req = httpMock.expectOne(`${base}/agents/5/link-user`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ user_id: 10 });
      req.flush(linkResponse);
    });

    it('should return the link response on success', (done) => {
      service.linkUser(5, 10).subscribe((res) => {
        expect(res).toEqual(linkResponse);
        done();
      });
      httpMock.expectOne(`${base}/agents/5/link-user`).flush(linkResponse);
    });

    it('should fail with the backend message', (done) => {
      service.linkUser(5, 10).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Link conflict');
          done();
        },
      });
      failWith(`${base}/agents/5/link-user`, 'POST', 'Link conflict');
    });
  });

  describe('unlinkUser()', () => {
    it('should send DELETE to the agent link endpoint', () => {
      service.unlinkUser(5).subscribe();
      const req = httpMock.expectOne(`${base}/agents/5/link-user`);
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('should emit no value on success', (done) => {
      service.unlinkUser(5).subscribe((res) => {
        expect(res).toBeNull();
        done();
      });
      httpMock.expectOne(`${base}/agents/5/link-user`).flush(null);
    });

    it('should fail with the backend message', (done) => {
      service.unlinkUser(5).subscribe({
        error: (err: Error) => {
          expect(err.message).toBe('Not linked');
          done();
        },
      });
      failWith(`${base}/agents/5/link-user`, 'DELETE', 'Not linked');
    });
  });
});
