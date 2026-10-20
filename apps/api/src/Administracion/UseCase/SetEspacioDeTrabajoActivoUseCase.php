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
     * Define el espacio de trabajo activo del usuario.
     *
     * Los administradores y supervisores ($puedeUsarCualquierEspacioDeTrabajo) pueden elegir cualquier espacio
     * de trabajo activo; si no tienen agente, se les crea uno. Los agentes solo pueden
     * elegir un espacio de trabajo al que estén asignados.
     *
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException     cuando workspaceId es inválido o el espacio de trabajo está inactivo
     * @throws NotFoundException             cuando no existe el espacio de trabajo, o el agente de un usuario con rol de agente
     * @throws EspacioDeTrabajoNoAsignadoException cuando un agente no está asignado al espacio de trabajo
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
