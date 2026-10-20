import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ConfiguracionStore } from './configuracion.store';
import { environment } from '../../../environments/environment';

describe('ConfiguracionStore', () => {
  let store: ConfiguracionStore;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        ConfiguracionStore,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    store = TestBed.inject(ConfiguracionStore);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('loads settings via GET /api/me/settings and hydrates signals', () => {
    const mockResponse = {
      profile: { name: 'Ana', email: 'ana@test.com', signature: null },
      notifications: { sound: true, desktop: true },
      autoReply: { enabled: false, message: null },
      workspace: null,
      businessHours: [],
    };

    store.loadSettings();

    const req = httpMock.expectOne(`${environment.apiUrl}/api/me/settings`);
    expect(req.request.method).toBe('GET');
    req.flush(mockResponse);

    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
    expect(store.profile()).toEqual(mockResponse.profile);
    expect(store.notifications()).toEqual(mockResponse.notifications);
    expect(store.autoReply()).toEqual(mockResponse.autoReply);
    expect(store.workspace()).toBeNull();
    expect(store.businessHours()).toEqual([]);
  });

  it('sets loading to true during load and false after', () => {
    store.loadSettings();
    expect(store.loading()).toBe(true);

    const req = httpMock.expectOne(`${environment.apiUrl}/api/me/settings`);
    req.flush({ profile: {}, notifications: {}, autoReply: {}, workspace: null, businessHours: [] });

    expect(store.loading()).toBe(false);
  });

  it('sets error on load failure', () => {
    store.loadSettings();

    const req = httpMock.expectOne(`${environment.apiUrl}/api/me/settings`);
    req.error(new ProgressEvent('Network error'));

    expect(store.loading()).toBe(false);
    expect(store.error()).toBeTruthy();
  });

  it('saveNotifications updates notifications signal and clears saving', () => {
    const mockResponse = { sound: false, desktop: true };

    store.saveNotifications({ sound: false });

    expect(store.saving()).toBe(true);

    const req = httpMock.expectOne(`${environment.apiUrl}/api/me/notifications`);
    expect(req.request.method).toBe('PUT');
    req.flush(mockResponse);

    expect(store.saving()).toBe(false);
    expect(store.notifications()).toEqual(mockResponse);
  });

  it('saveNotifications sets error on failure and clears saving', () => {
    store.saveNotifications({ sound: false });

    const req = httpMock.expectOne(`${environment.apiUrl}/api/me/notifications`);
    req.error(new ProgressEvent('Server error'));

    expect(store.saving()).toBe(false);
    expect(store.error()).toBeTruthy();
  });
});
