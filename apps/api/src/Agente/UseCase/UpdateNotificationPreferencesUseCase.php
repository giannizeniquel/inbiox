<?php

declare(strict_types=1);

namespace App\Agente\UseCase;

use App\Agente\Agent;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateNotificationPreferencesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * A null preference is left unchanged.
     */
    public function execute(Agent $agent, ?bool $sound, ?bool $desktop): void
    {
        if ($sound !== null) {
            $agent->setNotificationSound($sound);
        }
        if ($desktop !== null) {
            $agent->setDesktopNotifications($desktop);
        }

        $this->entityManager->flush();
    }
}
