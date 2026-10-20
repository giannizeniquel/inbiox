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

  it('loads settings via GET /api/mi/configuracion and hydrates signals', () => {
    const mockResponse = {
      perfil: { nombre: 'Ana', email: 'ana@test.com', firma: null },
      notificaciones: { sonido: true, escritorio: true },
      respuestaAutomatica: { habilitada: false, texto: null },
      espacioDeTrabajo: null,
      horarioDeAtencion: [],
    };

    store.cargarConfiguracion();

    const req = httpMock.expectOne(`${environment.apiUrl}/api/mi/configuracion`);
    expect(req.request.method).toBe('GET');
    req.flush(mockResponse);

    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
    expect(store.perfil()).toEqual(mockResponse.perfil);
    expect(store.notificaciones()).toEqual(mockResponse.notificaciones);
    expect(store.respuestaAutomatica()).toEqual(mockResponse.respuestaAutomatica);
    expect(store.espacioDeTrabajo()).toBeNull();
    expect(store.businessHours()).toEqual([]);
  });

  it('sets loading to true during load and false after', () => {
    store.cargarConfiguracion();
    expect(store.loading()).toBe(true);

    const req = httpMock.expectOne(`${environment.apiUrl}/api/mi/configuracion`);
    req.flush({ perfil: {}, notificaciones: {}, respuestaAutomatica: {}, espacioDeTrabajo: null, horarioDeAtencion: [] });

    expect(store.loading()).toBe(false);
  });

  it('sets error on load failure', () => {
    store.cargarConfiguracion();

    const req = httpMock.expectOne(`${environment.apiUrl}/api/mi/configuracion`);
    req.error(new ProgressEvent('Network error'));

    expect(store.loading()).toBe(false);
    expect(store.error()).toBeTruthy();
  });

  it('saveNotifications updates notifications signal and clears saving', () => {
    const mockResponse = { sonido: false, escritorio: true };

    store.guardarNotificaciones({ sonido: false });

    expect(store.saving()).toBe(true);

    const req = httpMock.expectOne(`${environment.apiUrl}/api/mi/notificaciones`);
    expect(req.request.method).toBe('PUT');
    req.flush(mockResponse);

    expect(store.saving()).toBe(false);
    expect(store.notificaciones()).toEqual(mockResponse);
  });

  it('saveNotifications sets error on failure and clears saving', () => {
    store.guardarNotificaciones({ sonido: false });

    const req = httpMock.expectOne(`${environment.apiUrl}/api/mi/notificaciones`);
    req.error(new ProgressEvent('Server error'));

    expect(store.saving()).toBe(false);
    expect(store.error()).toBeTruthy();
  });
});
