<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agent;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Applies only the fields present in $data, checking them in order and
     * saving nothing if one fails.
     *
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is invalid
     */
    public function execute(Agent $agent, array $data): void
    {
        if (isset($data['name'])) {
            if (!\is_string($data['name']) || empty($data['name'])) {
                throw new \InvalidArgumentException('name must be a non-empty string');
            }
            // Agent has no name setter. Reflection sets the name without
            // touching updated_at, which is the current behavior.
            $nameProp = new \ReflectionProperty(Agent::class, 'name');
            $nameProp->setAccessible(true);
            $nameProp->setValue($agent, $data['name']);
        }

        if (isset($data['active'])) {
            $agent->setActive((bool) $data['active']);
        }

        if (isset($data['timezone'])) {
            if (!\is_string($data['timezone'])) {
                throw new \InvalidArgumentException('timezone must be a string');
            }
            $agent->setTimezone($data['timezone']);
        }

        $this->entityManager->flush();
    }
}
