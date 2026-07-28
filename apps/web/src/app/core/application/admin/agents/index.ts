/**
 * Application Layer — Admin Agent Use Cases
 */

export { ListAgentsUseCase } from './list-agents.use-case';
export type { ListAgentsInput, ListAgentsOutput } from './list-agents.use-case';
export { AgentListFailed } from './list-agents.use-case';

export { GetAvailableUsersForLinkingUseCase } from './get-available-users-for-linking.use-case';
export type { GetAvailableUsersForLinkingInput, GetAvailableUsersForLinkingOutput } from './get-available-users-for-linking.use-case';
export { AvailableUsersFetchFailed } from './get-available-users-for-linking.use-case';

export { CreateAgentUseCase } from './create-agent.use-case';
export type { CreateAgentInput, CreateAgentOutput } from './create-agent.use-case';
export { InvalidAgentName, AgentCreationFailed } from './create-agent.use-case';

export { UpdateAgentUseCase } from './update-agent.use-case';
export type { UpdateAgentInput, UpdateAgentOutput } from './update-agent.use-case';
export { AgentUpdateFailed } from './update-agent.use-case';

export { DeactivateAgentUseCase } from './deactivate-agent.use-case';
export type { DeactivateAgentInput, DeactivateAgentOutput } from './deactivate-agent.use-case';
export { AgentDeactivationFailed } from './deactivate-agent.use-case';

export { ReactivateAgentUseCase } from './reactivate-agent.use-case';
export type { ReactivateAgentInput, ReactivateAgentOutput } from './reactivate-agent.use-case';
export { AgentReactivationFailed } from './reactivate-agent.use-case';
