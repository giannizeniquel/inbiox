<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Repository\AgenteRepository;
use App\Agente\Equipo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reemplaza los agentes del equipo por los ids dados. Los ids desconocidos se omiten.
 */
final class AsignarAgentesAEquipoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agenteRepository,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando agenteIds es inválido o un agente
     *                                   está fuera del espacio de trabajo del equipo
     */
    public function execute(Equipo $equipo, array $data): void
    {
        $agenteIds = $data['agenteIds'] ?? [];

        if (!\is_array($agenteIds)) {
            throw new \InvalidArgumentException('agenteIds must be an array of integers');
        }

        // Se ejecuta en una transacción por atomicidad: Team::addAgent() lanza
        // \InvalidArgumentException (invariante de pertenencia al espacio de trabajo,
        // design.md D3), lo que hace rollback de todo el lote (rechazo estricto,
        // todo o nada), igual que AsignarAgentesAEspacioDeTrabajoUseCase.
        $this->entityManager->wrapInTransaction(function () use ($equipo, $agenteIds): void {
            foreach ($equipo->getAgentes() as $agenteExistente) {
                $equipo->removeAgente($agenteExistente);
            }

            foreach ($agenteIds as $agenteId) {
                if (!\is_int($agenteId)) {
                    throw new \InvalidArgumentException('agenteIds must contain only integers');
                }
                $agente = $this->agenteRepository->find($agenteId);
                if (null !== $agente) {
                    $equipo->addAgente($agente);
                }
            }
        });
    }
}
