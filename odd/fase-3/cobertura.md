# F3 — HTTP characterization coverage per controller action

Method: grep of `tests/` for path literals, including concatenated and interpolated paths and helper wrappers (`postJson`/`getJson`/`putJson`/`deleteRequest`/`$this->request(...)`). "AuthZ-only" means the test asserts only status or role gating, not the body. "Viol" means the action has rows in f3-violations.csv (rule numbers). Paths relative to `apps/api/`.

## Covered

| Route name | Method + path | Controller::action | Tests | Viol |
|---|---|---|---|---|
| admin_users_list | GET /api/admin/users | Administracion\UserController::list | tests/Controller/Admin/UserControllerTest.php | 1,4,5 |
| admin_users_create | POST /api/admin/users | UserController::create | Admin/UserControllerTest.php | 1,2,4 |
| admin_users_get | GET /api/admin/users/{id} | UserController::get | Admin/UserControllerTest.php | 1,4 |
| admin_users_update | PUT /api/admin/users/{id} | UserController::update | Admin/UserControllerTest.php | 1,2,4,5 |
| admin_users_delete | DELETE /api/admin/users/{id} | UserController::delete | Admin/UserControllerTest.php | 1,2,4 |
| admin_agents_list | GET /api/admin/agents | Administracion\AgentController::list | tests/Controller/Admin/AgentControllerTest.php | 1,4,5 |
| admin_agents_create | POST /api/admin/agents | AgentController::create | Admin/AgentControllerTest.php | 1,2,4 |
| admin_agents_get | GET /api/admin/agents/{id} | AgentController::get | Admin/AgentControllerTest.php | 1,4 |
| admin_agents_update | PUT /api/admin/agents/{id} | AgentController::update | Admin/AgentControllerTest.php | 1,2,4 |
| admin_agents_delete | DELETE /api/admin/agents/{id} | AgentController::delete | Admin/AgentControllerTest.php | 1,2,4 |
| admin_agents_link_user | POST /api/admin/agents/{id}/link-user | AgentLinkController::linkUser | tests/Controller/Admin/AgentLinkControllerTest.php | 1,2,4 |
| admin_agents_unlink_user | DELETE /api/admin/agents/{id}/link-user | AgentLinkController::unlinkUser | Admin/AgentLinkControllerTest.php | 1,2,4 |
| app_admin_teams_list | GET /api/admin/teams | Administracion\TeamController::list | tests/Controller/Admin/TeamControllerTest.php | 1,4,5 |
| app_admin_teams_create | POST /api/admin/teams | TeamController::create | Admin/TeamControllerTest.php | 1,2,4 |
| app_admin_teams_get | GET /api/admin/teams/{id} | TeamController::get | Admin/TeamControllerTest.php | 1,4 |
| app_admin_teams_update | PUT /api/admin/teams/{id} | TeamController::update | Admin/TeamControllerTest.php | 1,2,4 |
| app_admin_teams_delete | DELETE /api/admin/teams/{id} | TeamController::delete | Admin/TeamControllerTest.php | 1,2,4 |
| app_admin_teams_assign_agents | PUT /api/admin/teams/{id}/agents | TeamController::assignAgents | Admin/TeamControllerTest.php (:415,:442) | 1,2,4 |
| api_auth_login | POST /api/auth/login | (firewall json_login) | tests/Controller/AuthControllerTest.php + login helper in most HTTP tests | — |
| api_auth_refresh_path | POST /api/auth/refresh | (firewall refresh_jwt) | AuthControllerTest.php | — |
| api_auth_logout | POST /api/auth/logout | AuthController::logout | AuthControllerTest.php | 1,2 |
| api_auth_me | GET /api/auth/me | AuthController::me | AuthControllerTest.php | 4 |
| app_me_settings | GET /api/me/settings | Agente\MeController::getSettings | tests/Integration/SettingsPageTest.php | 4 |
| app_me_profile | PUT /api/me/profile | MeController::updateProfile | SettingsPageTest.php | 1,2,4 |
| app_me_notifications | PUT /api/me/notifications | MeController::updateNotifications | SettingsPageTest.php | 1,2,4 |
| app_me_auto_reply | PUT /api/me/auto-reply | MeController::updateAutoReply | SettingsPageTest.php | 1,2,4 |
| app_me_shifts_list | GET /api/me/shifts | MeController::getShifts | SettingsPageTest.php | 4 |
| app_me_shifts_replace | PUT /api/me/shifts | MeController::replaceShifts | SettingsPageTest.php | 4 (use case: rule 2 no-outbox) |
| app_teams_list | GET /api/teams | Agente\TeamController::list | tests/Controller/TeamControllerTest.php | 1,4,5 |
| app_ticket_assign | POST /api/tickets/{id}/assign | Asignacion\TicketController::assign | tests/Integration/Sprint10AgentManagementTest.php (:114, single happy path) | 1,4 |
| app_ticket_set_team | PUT /api/tickets/{id}/team | Asignacion\TicketController::setTeam | tests/Controller/TicketControllerSetTeamTest.php | 1,2,4 |
| app_inbox_tickets | GET /api/tickets | Ticket\InboxController::listTickets | tests/Controller/AuthorizationTest.php (AuthZ-only, :115,:137,:146,:264). Query logic: Integration/ListTicketsQueryFilterTest.php (not HTTP) | 4 |
| app_ticket_transition | POST /api/tickets/{id}/transition | TicketWorkflowController::transition | Integration/TicketClosureAuthorizationTest.php, Integration/TicketClosureAuditClassificationTest.php, Integration/TicketWorkflowControllerWindowIsolationTest.php | 1,2,4 |
| app_ticket_resolve | POST /api/tickets/{id}/resolve | TicketWorkflowController::resolve | TicketClosureAuthorizationTest.php, TicketClosureAuditClassificationTest.php | 1,2,4 |
| app_ticket_close | POST /api/tickets/{id}/close | TicketWorkflowController::close | TicketClosureAuthorizationTest.php, TicketClosureAuditClassificationTest.php, Unit/Controller/TicketWorkflowControllerAuditFailureTest.php (direct `new` + `close()` call) | 1,2,4 |
| app_provider_send | POST /api/provider/send | ProviderProxyController::send | Integration/ProviderProxyControllerAutoClaimTest.php, Integration/ProviderProxyControllerConversationWindowTest.php. Unit/Controller/ProviderProxyControllerResolveAgentTest.php covers the private resolveActingAgentId | 1,2,4 |
| app_provider_template_send | POST /api/provider/template/send | ProviderProxyController::sendTemplate | Integration/ProviderProxyControllerTemplateAttributionTest.php | 1,2,4 |
| app_contacts_list | GET /api/contacts | ContactController::list | Controller/AuthorizationTest.php (AuthZ-only :121,:156) | 4 |
| app_contacts_create | POST /api/contacts | ContactController::create | Controller/ContactImportControllerTest.php (:156, role check only) | 4 |
| app_contacts_import | POST /api/contacts/import | ContactController::import | Controller/ContactImportControllerTest.php. Use case: Unit/ImportContactsFromCsvUseCaseTest.php, Integration/ContactImportEmRecoveryTest.php | 4 |
| app_contacts_timeline | GET /api/contacts/{id}/timeline | ContactTimelineController::getTimeline | Integration/Sprint15ContactTimelineTest.php | 4 |
| app_workspace_available_agents | GET /api/workspaces/{id}/available-agents | WorkspaceAgentController::listAvailableAgents | Controller/WorkspaceAgentControllerTest.php | 1,4 |
| app_agent_set_availability | POST /api/agents/{id}/availability | AgentAvailabilityController::setAvailability | Integration/Sprint10ControllerTest.php | 1,4 |
| app_agent_clear_availability | DELETE /api/agents/{id}/availability | AgentAvailabilityController::clearAvailability | Sprint10ControllerTest.php | 1,4 |
| app_agent_get_availability | GET /api/agents/{id}/availability | AgentAvailabilityController::getAvailability | Sprint10ControllerTest.php | 1,4 |
| app_agent_create_shift | POST /api/agents/{id}/shifts | AgentAvailabilityController::createShift | Sprint10ControllerTest.php | 1,4 |
| app_agent_delete_shift | DELETE /api/agents/{id}/shifts/{shiftId} | AgentAvailabilityController::deleteShift | Sprint10ControllerTest.php | 1,4 |
| app_report_frt | GET /api/reports/frt | ReportingController::frt | Controller/AuthorizationTest.php (:127, unauthenticated 401 only) | 4 |
| app_report_agent_load | GET /api/reports/agent-load | ReportingController::agentLoad | Integration/ReportingV2Test.php | 4 |
| app_report_unassigned | GET /api/reports/unassigned | ReportingController::unassigned | ReportingV2Test.php | 4 |
| app_report_available_agents | GET /api/reports/available-agents | ReportingController::availableAgents | ReportingV2Test.php | 1,4,5 |
| app_report_frt_trend | GET /api/reports/frt-trend | ReportingController::frtTrend | ReportingV2Test.php | 4 |
| app_report_heatmap | GET /api/reports/heatmap | ReportingController::heatmap | ReportingV2Test.php | 4 |
| app_report_recurring | GET /api/reports/recurring-customers | ReportingController::recurringCustomers | ReportingV2Test.php | 4 |
| app_report_reopened | GET /api/reports/reopened | ReportingController::reopened | ReportingV2Test.php | 4 |
| app_report_export | GET /api/reports/export | ReportingController::export | Integration/ReportsCsvExportTest.php (query: Integration/ExportTicketsCsvQueryTest.php) | 4 |
| app_report_response_delay | GET /api/reports/response-delay | ReportingController::responseDelay | Integration/ReportingResponseDelayTest.php | 4 |
| app_report_ticket_participation | GET /api/reports/tickets/{id}/participation | ReportingController::ticketParticipation | ReportingResponseDelayTest.php | 4 |
| app_mercure_token | GET /api/mercure/token | MercureTokenController::getToken | Controller/AuthorizationTest.php | 4 |
| admin_outbox_publish | POST /admin/outbox/publish | OutboxController::publish | Controller/AuthorizationTest.php (:232,:241) | — |
| app_debug_inbound | POST /debug/inbound/{ticketId} | DebugController::inbound | Controller/AuthorizationTest.php (:302) | 4 |

## NOT covered by any HTTP test

| Route name | Method + path | Controller::action | Viol |
|---|---|---|---|
| app_admin_workspaces_list | GET /api/admin/workspaces | Administracion\WorkspaceController::list | 1,4,5 |
| app_admin_workspaces_create | POST /api/admin/workspaces | WorkspaceController::create | 1,2,4 |
| app_admin_workspaces_get | GET /api/admin/workspaces/{id} | WorkspaceController::get | 1,4 |
| app_admin_workspaces_update | PUT /api/admin/workspaces/{id} | WorkspaceController::update | 1,2,4 |
| app_admin_workspaces_delete | DELETE /api/admin/workspaces/{id} | WorkspaceController::delete | 1,2,4 |
| app_admin_workspaces_assign_agents | PUT /api/admin/workspaces/{id}/agents | WorkspaceController::assignAgents | 1,2,4 (tx in controller) |
| app_me_workspace | GET /api/me/workspace | MeController::getActiveWorkspace | 4 (S12 404 body) |
| app_me_workspace_set | PUT /api/me/workspace | MeController::setActiveWorkspace | 1,2,4 (ensureAgent side-write) |
| app_me_workspaces | GET /api/me/workspaces | MeController::listMyWorkspaces | 1,4,5 (raw SQL) |
| app_ticket_auto_assign | POST /api/tickets/{id}/auto-assign | AutoAssignController::autoAssign | 4 (use case is tested only directly: Integration/Sprint10AgentManagementTest.php, Sprint10ConcurrencyTest.php) |
| app_ticket_assignment | GET /api/tickets/{id}/assignment | Asignacion\TicketController::getAssignment | 1,4 |
| app_inbox_ticket_detail | GET /api/tickets/{id} | InboxController::getTicket | 4 |
| app_inbox_ticket_messages | GET /api/tickets/{id}/messages | InboxController::getMessages | 4 (query: Integration/GetTicketMessagesQueryAttributionTest.php, not HTTP) |
| app_ticket_mark_read | PUT /api/tickets/{id}/mark-read | InboxController::markRead | 1,2,3,4,5 (**raw outbox insert**) |
| app_provider_media_upload | POST /api/provider/media/upload | ProviderProxyController::uploadMedia | 4 |
| app_provider_media_download | GET /api/provider/media/{mediaId} | ProviderProxyController::downloadMedia | 4 |
| app_message_media_url | GET /api/messages/{id}/media | MediaUrlController::__invoke | 4 |
| app_webhook | GET+POST /webhook | WebhookController::handle | 1,2,4 (use cases tested directly: Unit/HandleInboundMessageUseCaseTest.php, Integration/HandleInboundMessageUseCaseWindowReopenTest.php, InboundMessageTimestampTrackingTest.php) |
| app_contacts_detail | GET /api/contacts/{id} | ContactController::get | 4 |
| app_contacts_update | PUT /api/contacts/{id} | ContactController::update | 4 |
| app_contacts_delete | DELETE /api/contacts/{id} | ContactController::delete | 4 (IAE→404) |
| app_report_rt | GET /api/reports/rt | ReportingController::rt | 4 |
| app_report_counts | GET /api/reports/counts | ReportingController::counts | 4 |
| app_report_volume | GET /api/reports/volume | ReportingController::volume | 4 |
| app_report_agents | GET /api/reports/agents | ReportingController::agents | 4 |
| app_ticket_events_resync | GET /api/tickets/{id}/events | TicketEventsController::listSince | 1,4,5 (raw SQL on outbox_events) |
| app_debug_outbound / app_debug_status / app_debug_assign | POST /debug/{outbound,status,assign}/{ticketId} | DebugController | 4 |

Weak coverage (status/AuthZ only, no body or side-effect assertions): app_inbox_tickets, app_contacts_list, app_contacts_create, app_report_frt, app_debug_inbound, admin_outbox_publish, app_ticket_assign (one happy path).

No test asserts the 500 envelope: a grep of `tests/` for "Internal server error", "Internal error" and `['reference']` returns nothing. 34 test files touch `outbox_events`, mostly for cleanup or assertions.

Unit tests that construct controllers directly will break on constructor changes:
- `tests/Unit/Controller/TicketWorkflowControllerAuditFailureTest.php:95` builds `TicketWorkflowController` with its current ctor and calls `close(42, $user)`.
- `tests/Unit/Controller/ProviderProxyControllerResolveAgentTest.php` exercises private `resolveActingAgentId` (reflection) with `em`.
