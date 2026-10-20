<?php

namespace App\Compartido\Controller;

use App\Conversacion\UseCase\ProcesarMensajeEntranteUseCase;
use App\Asignacion\UseCase\AsignarTicketUseCase;
use App\Ticket\EstadoDeTicket;
use App\Ticket\UseCase\CambiarEstadoDeTicketUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Endpoints de depuración — solo disponibles en el entorno dev/test.
 * Devuelve 404 en producción.
 */
class DebugController extends AbstractController
{
    public function __construct(
        private ProcesarMensajeEntranteUseCase $procesarMensajeEntranteUseCase,
        private AsignarTicketUseCase $asignarTicketUseCase,
        private CambiarEstadoDeTicketUseCase $cambiarEstadoDeTicketUseCase,
        private KernelInterface $kernel,
    ) {}

    private function isDevOrTest(): bool
    {
        return \in_array($this->kernel->getEnvironment(), ['dev', 'test'], true);
    }

    private function noEncontrado(): JsonResponse
    {
        return new JsonResponse(['error' => 'Not found'], 404);
    }

    #[Route('/debug/inbound/{ticketId}', name: 'app_debug_inbound', methods: ['POST'])]
    public function entrante(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->noEncontrado();
        }

        $textos = [
            'Customer says: "I need help with my order"',
            'Customer says: "Where is my package?"',
            'Customer says: "Thanks for your assistance"',
            'Customer says: "Can I speak to a manager?"',
            'Customer says: "This is urgent!"',
            'Customer says: "Following up on my request"',
            'Customer says: "Problem still not resolved"',
            'Customer says: " appreciated!"',
        ];
        
        $texto = $textos[array_rand($textos)];
        
        try {
            $result = $this->procesarMensajeEntranteUseCase->procesarMensajeEntrante(
                '+54911' . str_pad($ticketId, 8, '0', STR_PAD_LEFT),
                $texto
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
    public function saliente(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->noEncontrado();
        }

        $responses = [
            'We are looking into your request',
            'Your order has been located',
            'Package is on its way',
            'Please allow 24-48 hours for resolution',
            'Here is your reference number',
            'A manager will contact you shortly',
        ];
        
        $texto = $responses[array_rand($responses)];
        
        try {
            $result = $this->procesarMensajeEntranteUseCase->procesarMensajeEntrante(
                '+54911' . str_pad($ticketId, 8, '0', STR_PAD_LEFT),
                $texto
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
    public function estado(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->noEncontrado();
        }

        // Transiciones válidas que funcionan desde cualquier estado: en espera o resuelto
        // La FSM rechaza las transiciones inválidas
        $estadosValidos = [EstadoDeTicket::EN_ESPERA->value, EstadoDeTicket::RESUELTO->value];
        
        try {
            $estadoDestino = $estadosValidos[array_rand($estadosValidos)];
            $result = $this->cambiarEstadoDeTicketUseCase->execute($ticketId, $estadoDestino, true, false);
            
            return new JsonResponse([
                'success' => true,
                'ticket_id' => $ticketId,
                'new_status' => $result['estadoNuevo'],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/debug/assign/{ticketId}', name: 'app_debug_assign', methods: ['POST'])]
    public function asignar(int $ticketId): JsonResponse
    {
        if (!$this->isDevOrTest()) {
            return $this->noEncontrado();
        }

        $agenteIds = [1, 2, 3, 4, 5];

        try {
            $agenteId = $agenteIds[array_rand($agenteIds)];
            $result = $this->asignarTicketUseCase->asignar(
                $ticketId,
                $agenteId,
                'system',
                'Auto-assigned for testing'
            );

            return new JsonResponse([
                'success' => true,
                'ticket_id' => $ticketId,
                'agent_id' => $agenteId,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
