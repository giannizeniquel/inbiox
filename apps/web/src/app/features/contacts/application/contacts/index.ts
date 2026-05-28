/**
 * Contacts Application Layer — Use Cases barrel.
 */
export { GetContactsUseCase } from './get-contacts.use-case';
export type { GetContactsInput, GetContactsOutput } from './get-contacts.use-case';
export { GetContactsFailed } from './get-contacts.use-case';

export { SearchContactsUseCase } from './search-contacts.use-case';
export type { SearchContactsInput, SearchContactsOutput } from './search-contacts.use-case';
export { SearchContactsFailed } from './search-contacts.use-case';

export { CreateContactUseCase } from './create-contact.use-case';
export type { CreateContactInput, CreateContactOutput } from './create-contact.use-case';
export { CreateContactFailed } from './create-contact.use-case';

export { UpdateContactUseCase } from './update-contact.use-case';
export type { UpdateContactInput, UpdateContactOutput } from './update-contact.use-case';
export { UpdateContactFailed } from './update-contact.use-case';

export { OpenConversationFromContactUseCase } from './open-conversation.use-case';
export type { OpenConversationInput, OpenConversationOutput } from './open-conversation.use-case';
export { ConversationOpenFailed } from './open-conversation.use-case';