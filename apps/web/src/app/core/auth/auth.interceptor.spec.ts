import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { provideRouter } from '@angular/router';
import { Subject, of } from 'rxjs';
import { AuthStore } from './auth.store';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor (functional)', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;
  let authStoreSpy: {
    accessToken: () => string | null;
    refresh: jasmine.Spy;
    logout: jasmine.Spy;
  };
  let routerSpy: { navigate: jasmine.Spy };

  beforeEach(() => {
    authStoreSpy = {
      accessToken: () => 'test-token',
      refresh: jasmine.createSpy('refresh').and.returnValue(of(true)),
      logout: jasmine.createSpy('logout'),
    };
    routerSpy = { navigate: jasmine.createSpy('navigate') };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthStore, useValue: authStoreSpy },
        { provide: Router, useValue: routerSpy },
      ],
    });

    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('attaches Bearer token to /api/* request', () => {
    authStoreSpy.accessToken = () => 'jwt-abc';

    http.get('/api/tickets').subscribe();
    const req = httpMock.expectOne('/api/tickets');
    expect(req.request.headers.get('Authorization')).toBe('Bearer jwt-abc');
    req.flush([]);
  });

  it('skips Authorization for /api/auth/login', () => {
    authStoreSpy.accessToken = () => 'jwt-abc';

    http.post('/api/auth/login', { email: 'test', password: 'pass' }).subscribe();
    const req = httpMock.expectOne(r => r.url === '/api/auth/login');
    expect(req.request.headers.get('Authorization')).toBeNull();
    req.flush({});
  });

  it('skips Authorization for /api/auth/refresh', () => {
    authStoreSpy.accessToken = () => 'jwt-abc';

    http.post('/api/auth/refresh', {}).subscribe();
    const req = httpMock.expectOne(r => r.url === '/api/auth/refresh');
    expect(req.request.headers.get('Authorization')).toBeNull();
    req.flush({});
  });

  it('skips Authorization for non-API URLs', () => {
    authStoreSpy.accessToken = () => 'jwt-abc';

    http.get('https://mercure.example.com/stream').subscribe();
    const req = httpMock.expectOne(r => r.url === 'https://mercure.example.com/stream');
    expect(req.request.headers.get('Authorization')).toBeNull();
    req.flush(null);
  });

  it('on 401, refresh succeeds — retries with new token', () => {
    const refreshSubject = new Subject<boolean>();
    authStoreSpy.accessToken = () => 'old-token';
    authStoreSpy.refresh.and.returnValue(refreshSubject.asObservable());

    const responses: unknown[] = [];
    http.get('/api/tickets').subscribe({
      next: (r) => responses.push(r),
      error: (err) => fail(err),
    });

    // First request gets 401
    const req1 = httpMock.expectOne('/api/tickets');
    expect(req1.request.headers.get('Authorization')).toBe('Bearer old-token');
    req1.flush({ message: 'unauthorized' }, { status: 401, statusText: 'Unauthorized' });

    // Refresh is called
    expect(authStoreSpy.refresh).toHaveBeenCalled();

    // Update token for retry
    authStoreSpy.accessToken = () => 'new-token';

    // Complete refresh successfully
    refreshSubject.next(true);

    // Retry request with new token
    const req2 = httpMock.expectOne(r => r.url === '/api/tickets');
    expect(req2.request.headers.get('Authorization')).toBe('Bearer new-token');
    req2.flush([]);

    expect(responses.length).toBe(1);
  });

  it('on 401, refresh fails — logout + navigate to login', () => {
    authStoreSpy.accessToken = () => 'old-token';
    authStoreSpy.refresh.and.returnValue(of(false));

    let errorCaught = false;
    http.get('/api/tickets').subscribe({
      next: () => fail('expected error'),
      error: () => { errorCaught = true; },
    });

    const req = httpMock.expectOne('/api/tickets');
    req.flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(authStoreSpy.refresh).toHaveBeenCalled();
    expect(authStoreSpy.logout).toHaveBeenCalled();
    expect(errorCaught).toBeTrue();
  });

  it('concurrent 401s trigger only one refresh', () => {
    const refreshSubject = new Subject<boolean>();
    authStoreSpy.accessToken = () => 'token';
    authStoreSpy.refresh.and.returnValue(refreshSubject.asObservable());

    let responseCount = 0;
    http.get('/api/a').subscribe({ next: () => responseCount++, error: (err) => fail(err) });
    http.get('/api/b').subscribe({ next: () => responseCount++, error: (err) => fail(err) });

    // Both requests get 401
    httpMock.expectOne('/api/a').flush({}, { status: 401, statusText: 'Unauthorized' });
    httpMock.expectOne('/api/b').flush({}, { status: 401, statusText: 'Unauthorized' });

    // Only ONE refresh call should have been made
    expect(authStoreSpy.refresh.calls.count()).toBe(1);

    // Complete refresh
    authStoreSpy.accessToken = () => 'new-token';
    refreshSubject.next(true);

    // Both requests are retried
    httpMock.expectOne('/api/a').flush([]);
    httpMock.expectOne('/api/b').flush([]);

    expect(responseCount).toBe(2);
  });
});
