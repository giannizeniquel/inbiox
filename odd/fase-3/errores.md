# F3 — Error shapes returned today (apps/api, Symfony 7.4)

All paths relative to `apps/api/`. "Logs" = calls `$this->logger->error(...)` before returning.

## 0. Global infrastructure (what exists today)

- **No `kernel.exception` listener/subscriber exists.** Nothing in `src/` or `config/` matches `ExceptionEvent`, `onKernelException`, `kernel.exception`, `error_controller`, or `framework.exceptions`. `config/packages/framework.yaml` has no exception mapping.
- The only event listeners in the app:
  - `src/Autenticacion/EventListener/AuthenticationSuccessListener.php` is wired in `config/services.yaml` (tag `lexik_jwt_authentication.on_authentication_success`, priority -10). It changes the login success payload, not errors.
  - `src/Asignacion/EventListener/TicketAutoAssignmentRequestedListener.php:9` uses `#[AsEventListener(TicketNeedsAutoAssignmentEvent)]`. It is a domain listener that calls `AutoAssignTicketUseCase::executeInTransaction`.
- Anything a controller does not catch is rendered by Symfony's default `ErrorListener`/`ErrorRenderer`. `symfony/serializer` is installed, so a request whose preferred format is JSON gets an RFC 7807 body like `{"type":"https://tools.ietf.org/html/rfc2616#section-10","title":"An error occurred","status":N,"detail":"..."}`, with extra `class`/`trace` in debug mode. Other requests get HTML. Verify this with a characterization test before relying on it.
- **`symfony/validator` is NOT installed** (not in composer.json or composer.lock). The `#[Assert\...]` attributes on the `*Request` DTOs (`src/Agente/Controller/Update*Request.php`, `src/Asignacion/Controller/AssignTicketRequest.php`, `src/Disponibilidad/Controller/{CreateAgentShift,ReplaceShifts,SetAgentAvailability}Request.php`, `src/Automatizacion/Controller/UpdateAutoReplyRequest.php`) are therefore inert. A `#[MapRequestPayload]` failure can only come from deserialization (HttpException 400/415/422 → framework-rendered). There is no app-defined "validation" shape.
- Security-layer errors (not controller-generated):
  - Lexik JWT entry point: 401 `{"code":401,"message":"JWT Token not found" | "Invalid JWT Token" | "Expired JWT Token"}`.
  - json_login failure: 401 `{"code":401,"message":"Invalid credentials."}`. A disabled user is rejected by `UserChecker` (`src/Autenticacion/Security/UserChecker.php:35`, `DisabledException`).
  - login_throttling: 429.
  - `#[IsGranted]` / access_control denial: 403 `AccessDeniedHttpException`, framework-rendered.
  - `MediaUrlController` entity value resolver: 404 when the Message is not found.

## 1. Distinct controller-produced error shapes

| # | Shape | Status | Logs? | Produced by |
|---|---|---|---|---|
| S1 | `{"error":"Internal server error","reference":"<16 hex>"}` | 500 | yes, logger->error with `reference` and `exception`. A `trace` is included in Admin*, AgentLink, AutoAssign, Asignacion\TicketController, Inbox (except markRead), Contact*, AgentAvailability. **No trace** in MeController, WorkspaceAgentController, ReportingController, InboxController::markRead | catch-all `\Exception` in: Administracion User/Agent/Workspace/Team/AgentLink (all actions); Agente\MeController (all actions); Asignacion AutoAssignController, TicketController (assign/setTeam/getAssignment); Ticket\InboxController (all 4); Contacto ContactController (all 6), ContactTimelineController; Disponibilidad WorkspaceAgentController, AgentAvailabilityController (5); Reporte ReportingController (14 JSON actions; not export) |
| S2 | `{"error":"Internal error","reference":"<hex>"}` (different text) | 500 | yes, message concatenated into the log string, context key **`ref`** (not `reference`), no trace | `src/Ticket/Controller/TicketWorkflowController.php:80,108,136` (transition/resolve/close) |
| S3 | `{"error":"<message>"}` | 400/401/403/404/409/422 | no | inline returns and `catch(\InvalidArgumentException)` almost everywhere (see the mapping table in §2) |
| S4 | `{"type":"AGENT_NOT_ACTIVE","error":"<msg>"}` | 409 | no | `src/Asignacion/Controller/TicketController.php:58-61` (app_ticket_assign) |
| S5 | `{"type":"<CODE>","message":"<msg>"}` | 422 / 409 | no | ProviderProxyController: `CONVERSATION_WINDOW_EXPIRED` 422 (:103-110), `TICKET_NOT_OWNED` 409 (:111-118), `PHONE_NOT_FOUND` 422 (:133-147, :267-281) |
| S6 | `{"type":"<GRAPH_TYPE>","code":<int>,"message":"<msg>"}` | 422/429/502 via `mapGraphErrorCode` (:434-441). uploadMedia always 502 | no | ProviderProxyController send (:159-170), sendTemplate (:289-300), uploadMedia (:375-384, type always `GRAPH_API_ERROR`), downloadMedia (:419-427). Types (:446-455): RECIPIENT_NOT_ALLOWED, INVALID_RECIPIENT, RECIPIENT_BLOCKED, MESSAGE_TOO_OLD, RATE_LIMIT_EXCEEDED, GRAPH_API_ERROR |
| S7 | `{"error":"FILE_TOO_LARGE","maxSize":n,"kind":"..."}` / `{"error":"UNSUPPORTED_TYPE","mimeType":"..."}` | 413 / 415 | no | ProviderProxyController::uploadMedia :353-370 |
| S8 | `{"error":"Forbidden","code":"TICKET_CLOSURE_FORBIDDEN","reason":"no_linked_agent\|unowned_ticket\|not_ticket_owner","action":"transition\|resolve\|close","ticket_id":n,"message":"Only the ticket's assigned agent..."}` | 403 | no | `src/Ticket/Controller/TicketWorkflowController.php:159-166` |
| S9 | `{"error":"<reason_code>","message":"<msg>"}` | 400 | no | ContactController::import, `file_missing` (:176-179) and `ImportRejectedException` (:189-190). Reasons: missing_phone_column, empty_file, file_missing, invalid_file_type, ... (`src/Contacto/UseCase/ImportContactsFromCsvUseCase.php:58-123`) |
| S10 | `{"success":false,"error":"<exception message>"}` | 500 | no (leaks message) | `src/Compartido/Controller/DebugController.php:67,104,132,163`. Non-dev env returns 404 `{"error":"Not found"}` (:33) |
| S11 | raw `Response` strings with `Content-Type: application/json`: `{"error":"missing params"}` 400, `{"error":"invalid token"}` 403, `{"error":"invalid signature"}` 401, `{"error":"malformed payload"}` 400. All processing failures are swallowed and return 200 `{"ok":true}` | 400/401/403/200 | warning on bad HMAC/JSON; error on swallowed failures | `src/Conversacion/Controller/WebhookController.php:65-147` |
| S12 | `{"workspace_id":null,"phone_number_id":null,"display_name":null}` (data-shaped body with an error status) | 404 | no | `src/Agente/Controller/MeController.php:289-292` (app_me_workspace) |
| S13 | Framework-rendered (problem+json / HTML) | 500 (or HttpException status) | Symfony default logging only | Uncaught exceptions in actions without try/catch: AuthController::logout, MediaUrlController, OutboxController::publish, TicketEventsController, MercureTokenController, Agente\TeamController. Also ProviderProxyController::send when the use case throws `\InvalidArgumentException('Ticket not found'/'Ticket has no conversation')` or `ConcurrencyException` (only CONVERSATION_WINDOW_EXPIRED/StateConflict are caught at :103-118). So a missing ticket gives **500, not the 404** at :124-128. Also AgentAvailabilityController pre-try repo calls (:63-74 etc.) and ReportingController::export while streaming |

## 2. Exception → status mappings done in controllers today (path:line)

| Exception | Status / body | Where |
|---|---|---|
| `\InvalidArgumentException` | **400** `{error}` | Admin User :129,220; Admin Agent :103,177; Admin Workspace :114,186,276; Admin Team :132,204,297; AgentLink :99; MeController :266,359; Asignacion TicketController :64,125; InboxController :88,154; TicketWorkflow :76,106,134; ContactController :122,148; Reporting :123,153,183,213,248,306,383,411,464,493,515,561 |
| `\InvalidArgumentException` | **422** `{error}` | AgentAvailabilityController :86,125,250,299 |
| `\InvalidArgumentException` | **404** `{error}` | ContactController::delete :209. Note: the same "Contact not found" IAE from update gives **400** (:148) |
| `\InvalidArgumentException` | **500** S1 | AutoAssignController :32 (no IAE arm) |
| `\InvalidArgumentException` | **500** framework S13 | ProviderProxyController::send (not caught) |
| `App\Ticket\InvalidTransitionException` (extends `\Exception`, `src/Ticket/TicketStateMachine.php:105`) | **422** `{error}` | TicketWorkflowController::transition :78. In resolve/close it is caught by `\Exception` → **500 S2** |
| `App\Disponibilidad\AgentNotActiveException` (extends StateConflictException, `src/Disponibilidad/AgentNotActiveException.php:23`) | 409 S4 | Asignacion TicketController :58 (must precede :62) |
| `App\Compartido\StateConflictException` (`src/Compartido/StateConflictException.php:12`) | 409 `{error}` | Asignacion TicketController :62 |
| `StateConflictException` | 409 `{type:'TICKET_NOT_OWNED',message}` | ProviderProxyController :111 |
| `App\Conversacion\ConversationWindowExpiredException` (`src/Conversacion/ConversationWindowExpiredException.php:18`) | 422 S5 | ProviderProxyController :103 |
| `App\Compartido\Graph\GraphApiException` (`src/Compartido/Graph/GraphApiException.php:8`) | 422/429/502 S6 | ProviderProxyController :159,289,375,419 |
| `App\Conversacion\MediaTooLargeException` extends `HttpException(413)` (`src/Conversacion/MediaTooLargeException.php:9,16`) | 413 S7 (it would also be 413 if uncaught) | ProviderProxyController :353 |
| `App\Conversacion\MediaTypeNotAllowedException` extends `HttpException(415)` (`src/Conversacion/MediaTypeNotAllowedException.php:9,15`) | 415 S7 | ProviderProxyController :362 |
| `App\Contacto\ImportRejectedException` (`src/Contacto/ImportRejectedException.php:15`) | 400 S9 | ContactController :189 |
| `App\Compartido\ConcurrencyException` (`src/Compartido/ConcurrencyException.php:11`) | **no mapping** → framework 500 (ProviderProxy::send) or S1 500 elsewhere | thrown in `HandleOutboundMessageUseCase.php:124,155` |
| `Doctrine\DBAL\Exception\LockWaitTimeoutException` | 409 `{error:'Resource locked, try again'}` | AgentAvailabilityController :84,123,246,297 |
| `Doctrine\DBAL\Exception\UniqueConstraintViolationException` | 409 `{error:'Duplicate shift: ...'}` / `{error:'Link conflict: ...'}` | AgentAvailabilityController :248; AgentLinkController :89 (nested try around flush) |
| `\Throwable` (swallowed) | none, the response continues | TicketWorkflowController :205 (audit write); ProviderProxyController :222 (resolveActingAgentId → null), :399 (S3 upload); WebhookController :128,138,326 |
| `\Exception` catch-all | 500 S1/S2/S10 | every try/catch controller listed above |

## 3. Inline (non-exception) 4xx bodies, by status

All use `{"error":"<text>"}` unless noted:
- 400: `Invalid JSON body`, field validation texts (Admin*, MeController setActiveWorkspace, Contact create/update). ProviderProxy uses `Missing required fields: ...` and `Parameters must be an array of strings`. Others: `status is required` (TicketWorkflow :45), `Invalid ticket ID` (AutoAssign :26), `teamId is required ...` / `Team not found` (**400**, Asignacion TicketController :97,114), `Workspace not found` (**400** in Admin Team create :111), `afterSequence must be >= -1` (TicketEvents :51), `Workspace is not active` (MeController :329, WorkspaceAgent :41), AgentLink link precondition errors (:71-81,128).
- 401: `Not authenticated` (AuthController::me via `$this->json`), `Unauthorized` (MeController :334,393; Agente TeamController :42; MercureToken :27), `Unauthorized — missing or invalid JWT` (TicketEvents :39).
- 403: `Forbidden` (Asignacion TicketController :48; AgentAvailability :64,111,150,220,275), `Agent is not assigned to this workspace` (Me :352), `Forbidden — JWT does not grant access to this ticket` (TicketEvents :45), plus S8.
- 404: `<Entity> not found` across controllers. MeController :289-292 uses S12. ReportingController :585.
- 409: `Email already exists` / `Email already in use` (User), `A workspace with this phone number ID already exists`, `A team with this name already exists in this workspace`, `Agent already linked to another user` (User create :119).
- 422: `is_available is required`, `start_time and end_time must differ` (AgentAvailability :73,232).

## 4. Implications for a single exception listener

- The same exception class maps to different statuses per controller (IAE → 400/404/422/500). A single listener needs either per-route overrides or new exception types thrown from use cases. Changing which exception is thrown is a use-case change, not a controller-only change.
- The two 500 envelopes differ in text (`Internal server error` vs `Internal error`), in log context key (`reference` vs `ref`), and in whether a trace is logged.
- S12 and the webhook "always 200" behaviour cannot go through a generic listener.
- Debug S10 leaks exception messages.
