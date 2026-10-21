import { Injectable } from '@angular/core';
import { Subject, Observable } from 'rxjs';
import { EventoEnTiempoReal } from './evento-en-tiempo-real.model';

@Injectable({ providedIn: 'root' })
export class EventBusService {
  private readonly subject = new Subject<EventoEnTiempoReal>();
  readonly events$: Observable<EventoEnTiempoReal> = this.subject.asObservable();

  emit(event: EventoEnTiempoReal): void {
    this.subject.next(event);
  }
}
