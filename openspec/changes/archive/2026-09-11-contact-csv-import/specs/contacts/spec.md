# Delta for Contacts

## MODIFIED Requirements

### Requirement: API Contracts

The system MUST define REST endpoints for contact operations. `POST /contacts` MUST be race-safe: a concurrent unique-constraint violation on `phone` at flush time MUST yield a deterministic duplicate error, never an uncaught exception, and MUST NOT leave the `EntityManager` unusable for subsequent requests.
(Previously: no explicit uniqueness/race-condition behavior was specified for `POST /contacts`.)

#### Scenario: GET /contacts endpoint

- GIVEN a GET request to /contacts
- WHEN query parameters include page, limit, search, status
- THEN the endpoint MUST return:
  ```ts
  interface ContactsListResponse {
    data: TransportContactDTO[];
    meta: {
      page: number;
      limit: number;
      total: number;
      hasMore: boolean;
    };
  }
  ```

#### Scenario: GET /contacts/:id endpoint

- GIVEN a GET request to /contacts/:id
- WHEN requesting a specific contact
- THEN the endpoint MUST return TransportContactDTO with enriched fields including related tickets

#### Scenario: POST /contacts endpoint

- GIVEN a POST request to /contacts
- WHEN creating a new contact
- THEN the request body MUST be:
  ```ts
  interface CreateContactRequest {
    phone: string;
    name?: string;
    email?: string;
  }
  ```
- AND response MUST return the created TransportContactDTO

#### Scenario: PATCH /contacts/:id endpoint

- GIVEN a PATCH request to /contacts/:id
- WHEN updating an existing contact
- THEN the request body MUST be:
  ```ts
  interface UpdateContactRequest {
    name?: string;
    email?: string;
    tags?: string[];
    status?: 'active' | 'inactive';
  }
  ```
- AND response MUST return the updated TransportContactDTO

#### Scenario: DELETE /contacts/:id endpoint

- GIVEN a DELETE request to /contacts/:id
- WHEN soft-deleting a contact
- THEN the endpoint MUST perform a soft delete (archive) rather than hard delete
- AND response MUST return success confirmation

#### Scenario: Concurrent duplicate phone on create

- GIVEN two requests attempt to create a contact with the same phone at nearly the same time
- WHEN the second request's flush hits the database's unique constraint
- THEN the system MUST return a deterministic duplicate-contact error (never a `500`)
- AND the `EntityManager` MUST remain usable for any subsequent operation in the same process
