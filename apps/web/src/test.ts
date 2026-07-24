// Angular 21 zoneless test entry point
import { provideZonelessChangeDetection } from '@angular/core';
import { getTestBed } from '@angular/core/testing';

getTestBed().initTestEnvironment(
  [],
  getTestBed().platform || null,
  { teardown: { destroyAfterEach: true } }
);
