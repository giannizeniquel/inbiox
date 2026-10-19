<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\Repository\EspacioDeTrabajoRepository;
use App\Administracion\Usuario;
use App\Administracion\EspacioDeTrabajo;
use App\Administracion\EspacioDeTrabajoNoAsignadoException;
use App\Agente\UseCase\AsegurarAgenteDeUsuarioUseCase;
use App\Compartido\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class SetEspacioDeTrabajoActivoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EspacioDeTrabajoRepository $espacioDeTrabajoRepository,
        private readonly AsegurarAgenteDeUsuarioUseCase $asegurarAgenteDeUsuario,
    ) {}

    /**
     * Sets the user's active workspace.
     *
     * Admins and supervisors ($puedeUsarCualquierEspacioDeTrabajo) may pick any active
     * workspace; if they have no agent, one is created for them. Agents may
     * only pick a workspace they are assigned to.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException     when workspaceId is invalid or the workspace is inactive
     * @throws NotFoundException             when the workspace, or the agent of an agent-role user, does not exist
     * @throws EspacioDeTrabajoNoAsignadoException when an agent is not assigned to the workspace
     */
    public function execute(Usuario $usuario, bool $puedeUsarCualquierEspacioDeTrabajo, array $data): EspacioDeTrabajo
    {
        $espacioDeTrabajoId = $data['workspaceId'] ?? null;

        if (null === $espacioDeTrabajoId || !\is_int($espacioDeTrabajoId)) {
            throw new \InvalidArgumentException('workspaceId is required and must be an integer');
        }

        $espacioDeTrabajo = $this->espacioDeTrabajoRepository->find($espacioDeTrabajoId);

        if (null === $espacioDeTrabajo) {
            throw new NotFoundException('Workspace not found');
        }

        if (!$espacioDeTrabajo->isActivo()) {
            throw new \InvalidArgumentException('Workspace is not active');
        }

        if ($puedeUsarCualquierEspacioDeTrabajo) {
            $agente = $this->asegurarAgenteDeUsuario->execute($usuario);
            $agente->setEspacioDeTrabajoActivo($espacioDeTrabajo);
            $this->entityManager->flush();

            return $espacioDeTrabajo;
        }

        $agente = $usuario->getAgente();
        if (null === $agente) {
            throw new NotFoundException('Agent not found');
        }

        if (!$agente->getEspaciosDeTrabajo()->contains($espacioDeTrabajo)) {
            throw new EspacioDeTrabajoNoAsignadoException('Agent is not assigned to this workspace');
        }

        $agente->setEspacioDeTrabajoActivo($espacioDeTrabajo);
        $this->entityManager->flush();

        return $espacioDeTrabajo;
    }
}
