import { Injectable } from '@angular/core';
import { Subject, Observable } from 'rxjs';
import { RealtimeEvent } from '../models/realtime-event.model';

@Injectable({ providedIn: 'root' })
export class EventBusService {
  private readonly subject = new Subject<RealtimeEvent>();
  readonly events$: Observable<RealtimeEvent> = this.subject.asObservable();

  emit(event: RealtimeEvent): void {
    this.subject.next(event);
  }
}
