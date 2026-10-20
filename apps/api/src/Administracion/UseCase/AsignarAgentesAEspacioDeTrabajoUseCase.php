<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Administracion\EspacioDeTrabajo;
use App\Agente\Repository\AgenteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reemplaza los agentes del espacio de trabajo por los ids dados. Los ids desconocidos se omiten.
 */
final class AsignarAgentesAEspacioDeTrabajoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenteRepository $agenteRepository,
    ) {}

    /**
     * @param array<mixed> $data cuerpo de la request decodificado
     *
     * @throws \InvalidArgumentException cuando agentIds es inválido
     */
    public function execute(EspacioDeTrabajo $espacioDeTrabajo, array $data): void
    {
        $agenteIds = $data['agentIds'] ?? [];

        if (!\is_array($agenteIds)) {
            throw new \InvalidArgumentException('agentIds must be an array of integers');
        }

        // Todo o nada: un id no entero hace rollback de todo el lote.
        $this->entityManager->wrapInTransaction(function () use ($espacioDeTrabajo, $agenteIds): void {
            foreach ($espacioDeTrabajo->getAgentes() as $agenteExistente) {
                $espacioDeTrabajo->removeAgente($agenteExistente);
            }

            foreach ($agenteIds as $agenteId) {
                if (!\is_int($agenteId)) {
                    throw new \InvalidArgumentException('agentIds must contain only integers');
                }
                $agente = $this->agenteRepository->find($agenteId);
                if (null !== $agente) {
                    $espacioDeTrabajo->addAgente($agente);
                }
            }
        });
    }
}
