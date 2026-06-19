import { TestBed } from '@angular/core/testing';
import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { of, throwError } from 'rxjs';
import { authInterceptor } from './auth.interceptor';
import { AuthStore } from '../stores/auth.store';
import { environment } from '../../../environments/environment';

describe('AuthInterceptor', () => {
  let httpMock: HttpTestingController;
  let http: HttpClient;
  let authStore: jasmine.SpyObj<AuthStore>;
  let routerSpy: jasmine.SpyObj<Router>;

  const apiUrl = environment.apiUrl;

  function configureTestBed() {
    authStore = jasmine.createSpyObj('AuthStore', [
      'refresh',
      'logout',
      'hasRole',
    ], {
      accessToken: 'test-access-token',
      isAuthenticated: true,
    });

    routerSpy = jasmine.createSpyObj('Router', ['navigate']);

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: AuthStore, useValue: authStore },
        { provide: Router, useValue: routerSpy },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    http = TestBed.inject(HttpClient);
  }

  beforeEach(() => {
    configureTestBed();
  });

  afterEach(() => {
    httpMock.verify();
  });

  // ── Token attachment ──

  it('should attach Bearer token to /api/ requests', () => {
    http.get(`${apiUrl}/api/auth/me`).subscribe();

    const req = httpMock.expectOne(`${apiUrl}/api/auth/me`);
    expect(req.request.headers.get('Authorization')).toBe('Bearer test-access-token');
    req.flush({ id: 1, email: 'test@test.com', roles: [], agent_id: null, is_active: true });
  });

  it('should NOT attach Bearer token to /api/auth/login', () => {
    http.post(`${apiUrl}/api/auth/login`, { email: 'a', password: 'b' }).subscribe();

    const req = httpMock.expectOne(`${apiUrl}/api/auth/login`);
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush({ access_token: 't', token_type: 'Bearer', expires_in: 900 });
  });

  it('should NOT attach Bearer token to /api/auth/refresh', () => {
    http.post(`${apiUrl}/api/auth/refresh`, {}).subscribe();

    const req = httpMock.expectOne(`${apiUrl}/api/auth/refresh`);
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush({ access_token: 't', token_type: 'Bearer', expires_in: 900 });
  });

  it('should NOT attach Bearer token to non-/api/ requests', () => {
    http.get(`${apiUrl}/tickets`).subscribe();

    const req = httpMock.expectOne(`${apiUrl}/tickets`);
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush({ data: [] });
  });

  // ── 401 → refresh → retry ──

  it('should refresh token on 401 and retry the original request', () => {
    // First call fails with 401
    authStore.refresh.and.returnValue(of(true));
    Object.defineProperty(authStore, 'accessToken', { get: () => 'refreshed-token' });

    http.get(`${apiUrl}/api/auth/me`).subscribe({
      next: (res: any) => {
        expect(res.id).toBe(1);
      },
    });

    // First request — 401
    const req1 = httpMock.expectOne(`${apiUrl}/api/auth/me`);
    expect(req1.request.headers.get('Authorization')).toBe('Bearer test-access-token');
    req1.flush({}, { status: 401, statusText: 'Unauthorized' });

    // Verify refresh was called
    expect(authStore.refresh).toHaveBeenCalled();

    // Retry with new token
    const req2 = httpMock.expectOne(`${apiUrl}/api/auth/me`);
    expect(req2.request.headers.get('Authorization')).toBe('Bearer refreshed-token');
    req2.flush({ id: 1, email: 'test@test.com', roles: [], agent_id: null, is_active: true });
  });

  // ── Double 401 → logout ──

  it('should logout on failed refresh (double 401)', () => {
    authStore.refresh.and.returnValue(of(false)); // Refresh fails

    http.get(`${apiUrl}/api/auth/me`).subscribe({
      error: (err: HttpErrorResponse) => {
        expect(err.status).toBe(401);
      },
    });

    const req = httpMock.expectOne(`${apiUrl}/api/auth/me`);
    req.flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(authStore.refresh).toHaveBeenCalled();
    expect(authStore.logout).toHaveBeenCalled();
    expect(routerSpy.navigate).toHaveBeenCalledWith(['/login']);
  });

  // ── Non-401 errors pass through ──

  it('should pass through non-401 errors', () => {
    http.get(`${apiUrl}/api/auth/me`).subscribe({
      error: (err: HttpErrorResponse) => {
        expect(err.status).toBe(500);
      },
    });

    const req = httpMock.expectOne(`${apiUrl}/api/auth/me`);
    req.flush({ message: 'Server error' }, { status: 500, statusText: 'Server Error' });

    expect(authStore.refresh).not.toHaveBeenCalled();
  });

  // ── Mercure URLs ──

  it('should skip Mercure hub URLs', () => {
    const mercureUrl = 'https://mercure.example.com';
    // Temporarily set mercureUrl for this test
    const original = environment.mercureUrl;
    (environment as any).mercureUrl = mercureUrl;

    http.get(`${mercureUrl}/.well-known/mercure`).subscribe({
      error: () => {}, // 404 is fine, just check headers
    });

    const req = httpMock.expectOne(`${mercureUrl}/.well-known/mercure`);
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush({}, { status: 404, statusText: 'Not Found' });

    (environment as any).mercureUrl = original;
  });
});
