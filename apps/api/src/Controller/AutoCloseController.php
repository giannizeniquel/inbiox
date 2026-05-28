<?php

namespace App\Controller;

use App\Domain\Rule\RuleContext;
use App\Domain\Rule\AutoCloseRule;
use App\Application\Rule\RuleExecutorService;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class AutoCloseController extends AbstractController
{
    public function __construct(
        private TicketRepository $ticketRepo,
        private RuleExecutorService $ruleExecutor,
    ) {}

    /**
     * Trigger auto-close rule manually
     * POST /tickets/{id}/auto-close
     */
    #[Route('/api/tickets/{ticketId}/auto-close', methods: ['POST'])]
    public function triggerAutoClose(int $ticketId): JsonResponse
    {
        $ticket = $this->ticketRepo->findById($ticketId);
        if (!$ticket) {
            return new JsonResponse(['error' => 'Ticket not found'], 404);
        }

        // Create manual trigger context
        $ruleContext = new RuleContext($ticket, null, 'manual');
        $this->ruleExecutor->execute($ruleContext);

        return new JsonResponse([
            'ticket_id' => $ticketId,
            'status' => 'rule_executed',
        ]);
    }
}