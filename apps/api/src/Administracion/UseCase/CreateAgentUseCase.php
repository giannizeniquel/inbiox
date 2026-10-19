<?php

declare(strict_types=1);

namespace App\Administracion\UseCase;

use App\Agente\Agente;
use Doctrine\ORM\EntityManagerInterface;

final class CreateAgentUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $data decoded request body
     *
     * @throws \InvalidArgumentException when a field is missing or invalid
     */
    public function execute(array $data): Agente
    {
        $name = $data['name'] ?? null;

        if (empty($name) || !\is_string($name)) {
            throw new \InvalidArgumentException('name is required and must be a string');
        }

        $timezone = $data['timezone'] ?? 'UTC';

        if (!\is_string($timezone)) {
            throw new \InvalidArgumentException('timezone must be a string');
        }

        $agent = new Agente($name);
        $agent->setTimezone($timezone);
        $this->entityManager->persist($agent);
        $this->entityManager->flush();

        return $agent;
    }
}
