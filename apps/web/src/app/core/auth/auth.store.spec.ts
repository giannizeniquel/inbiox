import { TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { AuthStore } from './auth.store';
import { AuthApiService, LoginResponse, UserDTO, MercureTokenResponse } from './auth-api.service';
import { MercureService } from '../realtime/mercure.service';
import { environment } from '../../../environments/environment';

describe('AuthStore', () => {
  let store: AuthStore;
  let httpMock: HttpTestingController;
  let mercureSpy: jasmine.SpyObj<MercureService>;
  let routerSpy: jasmine.SpyObj<Router>;

  const mockUser: UserDTO = {
    id: 1,
    email: 'admin@inbiox.com',
    roles: ['ROLE_ADMIN', 'ROLE_USER'],
    agent_id: 1,
    agent_name: 'Admin Agent',
    is_active: true,
  };

  const mockLoginResponse: LoginResponse = {
    access_token: 'eyJhbGciOiJSUzI1NiJ9.valid-token',
    token_type: 'Bearer',
    expires_in: 900,
  };

  const mockMercureToken: MercureTokenResponse = {
    token: 'mercure-jwt-token',
  };

  beforeEach(() => {
    mercureSpy = jasmine.createSpyObj('MercureService', [
      'setMercureToken',
      'connect',
      'disconnect',
    ]);
    routerSpy = jasmine.createSpyObj('Router', ['navigate']);

    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
      providers: [
        AuthStore,
        AuthApiService,
        { provide: MercureService, useValue: mercureSpy },
        { provide: Router, useValue: routerSpy },
      ],
    });

    store = TestBed.inject(AuthStore);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  // ── Initial state ──

  it('should start unauthenticated', () => {
    expect(store.currentUser()).toBeNull();
    expect(store.accessToken()).toBeNull();
    expect(store.isAuthenticated()).toBeFalse();
    expect(store.isAdmin()).toBeFalse();
    expect(store.isSupervisor()).toBeFalse();
    expect(store.isLoading()).toBeFalse();
    expect(store.error()).toBeNull();
  });

  // ── login() ──

  it('should authenticate on successful login', () => {
    const email = 'admin@inbiox.com';
    const password = 'secret';

    store.login(email, password).subscribe({
      next: () => {
        expect(store.isAuthenticated()).toBeTrue();
        expect(store.currentUser()).toEqual(mockUser);
        expect(store.accessToken()).toBe(mockLoginResponse.access_token);
        expect(store.isAdmin()).toBeTrue();
        expect(store.isLoading()).toBeFalse();
        expect(store.error()).toBeNull();
      },
    });

    // Expect login POST
    const loginReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/login`);
    expect(loginReq.request.method).toBe('POST');
    expect(loginReq.request.body).toEqual({ email, password });
    loginReq.flush(mockLoginResponse);

    // Expect /me GET
    const meReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/me`);
    expect(meReq.request.method).toBe('GET');
    meReq.flush(mockUser);

    // Expect mercure token GET
    const mercureReq = httpMock.expectOne(`${environment.apiUrl}/api/mercure/token`);
    expect(mercureReq.request.method).toBe('GET');
    mercureReq.flush(mockMercureToken);

    // Verify mercure integration
    expect(mercureSpy.setMercureToken).toHaveBeenCalledWith(mockMercureToken.token);
    expect(mercureSpy.connect).toHaveBeenCalled();
  });

  it('should set error on failed login', () => {
    store.login('wrong@email.com', 'wrong').subscribe({
      error: () => {
        expect(store.isAuthenticated()).toBeFalse();
        expect(store.currentUser()).toBeNull();
        expect(store.isLoading()).toBeFalse();
        expect(store.error()).toBeTruthy();
      },
    });

    const loginReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/login`);
    loginReq.flush({ message: 'Invalid credentials' }, { status: 401, statusText: 'Unauthorized' });
  });

  it('should set isLoading during login', () => {
    expect(store.isLoading()).toBeFalse();

    store.login('admin@inbiox.com', 'secret').subscribe();

    expect(store.isLoading()).toBeTrue();

    const loginReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/login`);
    loginReq.flush(mockLoginResponse);
    httpMock.expectOne(`${environment.apiUrl}/api/auth/me`).flush(mockUser);
    httpMock.expectOne(`${environment.apiUrl}/api/mercure/token`).flush(mockMercureToken);
  });

  // ── logout() ──

  it('should clear state and disconnect Mercure on logout', () => {
    // First login
    store.currentUser.set(mockUser);
    store.accessToken.set(mockLoginResponse.access_token);

    store.logout();

    // Should call backend logout
    const logoutReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/logout`);
    expect(logoutReq.request.method).toBe('POST');
    logoutReq.flush(null);

    // Should disconnect mercure
    expect(mercureSpy.disconnect).toHaveBeenCalled();

    // Should clear state
    expect(store.currentUser()).toBeNull();
    expect(store.accessToken()).toBeNull();

    // Should redirect to login
    expect(routerSpy.navigate).toHaveBeenCalledWith(['/login']);
  });

  // ── refresh() ──

  it('should refresh token and update mercure token', () => {
    let result = false;
    store.refresh().subscribe(r => (result = r));

    const refreshReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/refresh`);
    expect(refreshReq.request.method).toBe('POST');
    refreshReq.flush({
      access_token: 'new-token',
      token_type: 'Bearer',
      expires_in: 900,
    });

    // refresh() also fetches a new mercure token
    const mercureReq = httpMock.expectOne(`${environment.apiUrl}/api/mercure/token`);
    mercureReq.flush(mockMercureToken);

    expect(result).toBeTrue();
    expect(store.accessToken()).toBe('new-token');
    expect(mercureSpy.setMercureToken).toHaveBeenCalledWith(mockMercureToken.token);
  });

  it('should return false on failed refresh', () => {
    let result: boolean | undefined;
    store.refresh().subscribe(r => (result = r));

    const refreshReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/refresh`);
    refreshReq.flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(result).toBeFalse();
    expect(store.accessToken()).toBeNull();
    expect(mercureSpy.setMercureToken).not.toHaveBeenCalled();
  });

  // ── hasRole() ──

  it('should check roles correctly', () => {
    expect(store.hasRole('ROLE_ADMIN')).toBeFalse();

    store.currentUser.set(mockUser);

    expect(store.hasRole('ROLE_ADMIN')).toBeTrue();
    expect(store.hasRole('ROLE_USER')).toBeTrue();
    expect(store.hasRole('ROLE_SUPERVISOR')).toBeFalse();
  });

  // ── isAdmin / isSupervisor ──

  it('should compute isAdmin and isSupervisor from roles', () => {
    expect(store.isAdmin()).toBeFalse();
    expect(store.isSupervisor()).toBeFalse();

    store.currentUser.set(mockUser);

    expect(store.isAdmin()).toBeTrue();
    expect(store.isSupervisor()).toBeFalse();

    store.currentUser.set({ ...mockUser, roles: ['ROLE_SUPERVISOR'] });
    expect(store.isAdmin()).toBeFalse();
    expect(store.isSupervisor()).toBeTrue();
  });

  // ── loadCurrentUser() ──

  it('should hydrate user from /me endpoint', () => {
    let user: UserDTO | null = null;
    store.loadCurrentUser().subscribe(u => (user = u));

    const meReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/me`);
    meReq.flush(mockUser);

    expect(user!).toEqual(mockUser);
    expect(store.currentUser()).toEqual(mockUser);
  });

  it('should return null on failed /me call', () => {
    let user: UserDTO | null = 'anything' as unknown as UserDTO | null;
    store.loadCurrentUser().subscribe(u => (user = u));

    const meReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/me`);
    meReq.flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(user).toBeNull();
    expect(store.currentUser()).toBeNull();
  });

  // ── initialize() ──
  //
  // Note: refresh() now also fetches the mercure token internally,
  // so initialize() only expects refresh + /me + connect.

  it('should initialize with valid refresh cookie', (done) => {
    store.initialize().then(() => {
      expect(store.isAuthenticated()).toBeTrue();
      expect(store.currentUser()).toEqual(mockUser);
      expect(mercureSpy.setMercureToken).toHaveBeenCalledWith(mockMercureToken.token);
      expect(mercureSpy.connect).toHaveBeenCalled();
      done();
    });

    // Expect refresh (calls /api/auth/refresh + /api/mercure/token internally)
    const refreshReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/refresh`);
    refreshReq.flush(mockLoginResponse);

    // Expect mercure token fetched by refresh()
    const mercureReq = httpMock.expectOne(`${environment.apiUrl}/api/mercure/token`);
    mercureReq.flush(mockMercureToken);

    // Expect /me (called by initialize after refresh)
    const meReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/me`);
    meReq.flush(mockUser);
  });

  it('should stay unauthenticated on initialize without valid cookie', (done) => {
    store.initialize().then(() => {
      expect(store.isAuthenticated()).toBeFalse();
      expect(store.currentUser()).toBeNull();
      expect(mercureSpy.setMercureToken).not.toHaveBeenCalled();
      expect(mercureSpy.connect).not.toHaveBeenCalled();
      done();
    });

    const refreshReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/refresh`);
    refreshReq.flush({}, { status: 401, statusText: 'Unauthorized' });
  });

  // ── Mercure on token refresh ──

  it('should reconnect mercure after token refresh in initialize', (done) => {
    const newToken = 'refreshed-token';
    mockLoginResponse.access_token = newToken;

    store.initialize().then(() => {
      expect(store.accessToken()).toBe(newToken);
      expect(mercureSpy.setMercureToken).toHaveBeenCalled();
      expect(mercureSpy.connect).toHaveBeenCalled();
      done();
    });

    const refreshReq = httpMock.expectOne(`${environment.apiUrl}/api/auth/refresh`);
    refreshReq.flush(mockLoginResponse);

    // Mercure token fetched internally by refresh()
    httpMock.expectOne(`${environment.apiUrl}/api/mercure/token`).flush(mockMercureToken);

    httpMock.expectOne(`${environment.apiUrl}/api/auth/me`).flush(mockUser);
  });
});
