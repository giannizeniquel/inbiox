import { Routes } from '@angular/router';
import { InboxPageComponent } from './features/inbox/pages/inbox-page/inbox-page.component';
import { ContactsPageComponent } from './features/contacts/pages/contacts-page/contacts-page.component';
import { ReportsPageComponent } from './features/reports/pages/reports-page/reports-page.component';

export const routes: Routes = [
  {
    path: '',
    redirectTo: 'inbox',
    pathMatch: 'full'
  },
  {
    path: 'inbox',
    component: InboxPageComponent
  },
  {
    path: 'contacts',
    component: ContactsPageComponent
  },
  {
    path: 'contacts/:contactId',
    component: ContactsPageComponent
  },
  {
    path: 'reports',
    component: ReportsPageComponent
  },
  {
    path: 'settings',
    redirectTo: 'inbox',
    pathMatch: 'full'
  },
  {
    path: '**',
    redirectTo: 'inbox'
  }
];