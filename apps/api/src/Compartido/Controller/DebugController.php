<?php

namespace App\Compartido\Controller;

use App\Conversacion\UseCase\HandleInboundMessageUseCase;
use App\Asignacion\UseCase\AssignTicketUseCase;
use App\Ticket\UseCase\TransitionTicketStatusUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Debug endpoints — only available in dev/test environment.
 * Returns 404 in production.
 */
class DebugController extends AbstractController
{
    public function __construct(
        private HandleInboundMessageUseCase $handleInbound,
        private AssignTicketUseCase $assignTicket,
        private TransitionTicketStatusUseCase $transitionStatus,
        private KernelInterface $kernel,
    ) {}

    private function isDevOrTest(): bool
    {
        return \in_array($this->kernel->getEnvironment(), ['dev', 'test'], true);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'Not found'], 404);
    }

    #[Route('/debug/inbound/{ticketId}', name: 'app_debug_inbound', methods: ['POST'])]
    public function inbound(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->notFound();
        }

        $messages = [
            'Customer says: "I need help with my order"',
            'Customer says: "Where is my package?"',
            'Customer says: "Thanks for your assistance"',
            'Customer says: "Can I speak to a manager?"',
            'Customer says: "This is urgent!"',
            'Customer says: "Following up on my request"',
            'Customer says: "Problem still not resolved"',
            'Customer says: " appreciated!"',
        ];
        
        $message = $messages[array_rand($messages)];
        
        try {
            $result = $this->handleInbound->processInboundMessage(
                '+54911' . str_pad($ticketId, 8, '0', STR_PAD_LEFT),
                $message
            );
            
            return new JsonResponse([
                'success' => true,
                'ticket_id' => $result['ticket_id'],
                'message' => 'Inbound message processed',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/debug/outbound/{ticketId}', name: 'app_debug_outbound', methods: ['POST'])]
    public function outbound(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->notFound();
        }

        $responses = [
            'We are looking into your request',
            'Your order has been located',
            'Package is on its way',
            'Please allow 24-48 hours for resolution',
            'Here is your reference number',
            'A manager will contact you shortly',
        ];
        
        $message = $responses[array_rand($responses)];
        
        try {
            $result = $this->handleInbound->processInboundMessage(
                '+54911' . str_pad($ticketId, 8, '0', STR_PAD_LEFT),
                $message
            );
            
            return new JsonResponse([
                'success' => true,
                'ticket_id' => $result['ticket_id'],
                'message' => 'Outbound processed',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/debug/status/{ticketId}', name: 'app_debug_status', methods: ['POST'])]
    public function status(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->notFound();
        }

        // Valid transitions that work from any state: waiting or resolved
        // The FSM will reject invalid transitions
        $validStatuses = ['waiting', 'resolved'];
        
        try {
            $toStatus = $validStatuses[array_rand($validStatuses)];
            $result = $this->transitionStatus->execute($ticketId, $toStatus, true, false);
            
            return new JsonResponse([
                'success' => true,
                'ticket_id' => $ticketId,
                'new_status' => $result['new_status'],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/debug/assign/{ticketId}', name: 'app_debug_assign', methods: ['POST'])]
    public function assign(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->notFound();
        }

        $agentIds = [1, 2, 3, 4, 5];

        try {
            $agentId = $agentIds[array_rand($agentIds)];
            $result = $this->assignTicket->assignTicket(
                $ticketId,
                $agentId,
                'system',
                'Auto-assigned for testing'
            );

            return new JsonResponse([
                'success' => true,
                'ticket_id' => $ticketId,
                'agent_id' => $agentId,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
