<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\WorkspaceRepository;
use App\Administracion\User;
use App\Administracion\Workspace;
use App\Administracion\WorkspaceNotAssignedException;
use App\Agente\UseCase\AsegurarAgenteDeUsuarioUseCase;
use App\Compartido\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class SetActiveWorkspaceUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AsegurarAgenteDeUsuarioUseCase $ensureAgentForUser,
    ) {}

    /**
     * Sets the user's active workspace.
     *
     * Admins and supervisors ($canUseAnyWorkspace) may pick any active
     * workspace; if they have no agent, one is created for them. Agents may
     * only pick a workspace they are assigned to.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException     when workspaceId is invalid or the workspace is inactive
     * @throws NotFoundException             when the workspace, or the agent of an agent-role user, does not exist
     * @throws WorkspaceNotAssignedException when an agent is not assigned to the workspace
     */
    public function execute(User $user, bool $canUseAnyWorkspace, array $data): Workspace
    {
        $workspaceId = $data['workspaceId'] ?? null;

        if (null === $workspaceId || !\is_int($workspaceId)) {
            throw new \InvalidArgumentException('workspaceId is required and must be an integer');
        }

        $workspace = $this->workspaceRepository->find($workspaceId);

        if (null === $workspace) {
            throw new NotFoundException('Workspace not found');
        }

        if (!$workspace->isActive()) {
            throw new \InvalidArgumentException('Workspace is not active');
        }

        if ($canUseAnyWorkspace) {
            $agent = $this->ensureAgentForUser->execute($user);
            $agent->setEspacioDeTrabajoActivo($workspace);
            $this->entityManager->flush();

            return $workspace;
        }

        $agent = $user->getAgent();
        if (null === $agent) {
            throw new NotFoundException('Agent not found');
        }

        if (!$agent->getEspaciosDeTrabajo()->contains($workspace)) {
            throw new WorkspaceNotAssignedException('Agent is not assigned to this workspace');
        }

        $agent->setEspacioDeTrabajoActivo($workspace);
        $this->entityManager->flush();

        return $workspace;
    }
}
