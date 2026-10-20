<?php

declare(strict_types=1);

namespace App\Conversacion\Controller;

use App\Conversacion\UseCase\ProcesarPayloadWebhookUseCase;
use App\Conversacion\UseCase\RegistrarPayloadWebhookUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint único de webhook para la WhatsApp Cloud API de Meta.
 *
 * GET  /webhook — verificación de Meta (hub.challenge)
 * POST /webhook — despacho de eventos (messages, statuses)
 *
 * Diseño: AD-1 — una sola ruta /webhook, despacho según el verbo HTTP (GET o POST).
 * La convención de Meta exige que la verificación y la entrega de eventos compartan la misma URL.
 */
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly string $verifyToken,
        private readonly string $appSecret,
        private readonly ProcesarPayloadWebhookUseCase $procesarPayloadWebhook,
        private readonly RegistrarPayloadWebhookUseCase $registrarPayloadWebhook,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Punto de entrada: despacha a verify() (GET) o receive() (POST).
     */
    #[Route('/webhook', name: 'app_webhook', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->getMethod() === 'GET') {
            return $this->verify($request);
        }

        return $this->receive($request);
    }

    /**
     * GET /webhook — verificación de la suscripción de Meta.
     *
     * Devuelve hub.challenge como texto plano cuando hub.verify_token coincide.
     * Devuelve 403 si no coincide y 400 si faltan parámetros.
     *
     * Nota: parse_str() de PHP convierte los puntos de los nombres de parámetros de la query en guiones bajos,
     * así que hub.mode se lee como hub_mode, hub.verify_token como hub_verify_token, etc.
     */
    private function verify(Request $request): Response
    {
        $mode = $request->query->get('hub_mode');
        $token = $request->query->get('hub_verify_token');
        $challenge = $request->query->get('hub_challenge');

        if (!$mode || !$token || $challenge === null) {
            return new Response(
                '{"error":"missing params"}',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json']
            );
        }

        if ($mode !== 'subscribe' || $token !== $this->verifyToken) {
            return new Response(
                '{"error":"invalid token"}',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'application/json']
            );
        }

        // Meta espera el challenge crudo como texto plano, no JSON
        return new Response($challenge, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * POST /webhook — recibe y despacha los eventos de webhook de Meta.
     *
     * 1. Verifica el HMAC de X-Hub-Signature-256 (control de seguridad)
     * 2. Parsea el cuerpo JSON
     * 3. Registra el payload crudo para depuración
     * 4. Enruta a los handlers según la estructura del payload
     *
     * Siempre devuelve 200 a Meta (una respuesta distinta de 2xx provoca reintentos de Meta).
     */
    private function receive(Request $request): Response
    {
        // Paso 1: lee el cuerpo crudo ANTES de cualquier parseo (el HMAC debe verificar el payload crudo)
        $rawBody = $request->getContent();

        // Paso 2: verifica la firma HMAC
        if (!$this->verifySignature($request, $rawBody)) {
            $this->logger->warning('Webhook received with invalid HMAC signature');

            return new Response(
                '{"error":"invalid signature"}',
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/json']
            );
        }

        // Paso 3: parsea el JSON
        $payload = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->logger->warning('Webhook received malformed JSON payload');

            return new Response(
                '{"error":"malformed payload"}',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json']
            );
        }

        // Paso 4: persiste el payload crudo del webhook ANTES de procesarlo
        // Así queda un registro aunque el procesador falle (T-02)
        try {
            $this->registrarPayloadWebhook->execute($payload);
        } catch (\Throwable $e) {
            // No romper el webhook: se registra en el log y se continúa
            $this->logger->error('Failed to persist webhook payload', [
                'error' => $e->getMessage(),
            ]);
        }

        // Paso 5: despacha a los handlers
        try {
            $this->procesarPayloadWebhook->execute($payload);
        } catch (\Throwable $e) {
            // Se registra en el log, pero siempre se devuelve 200: Meta reintenta ante fallos
            $this->logger->error('Webhook dispatch error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Paso 6: siempre 200 OK
        return new Response('{"ok":true}', Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    /**
     * Verifica el header X-Hub-Signature-256 con HMAC SHA256 y el app secret.
     *
     * Usa hash_equals() para comparar en tiempo constante y evitar ataques de timing.
     */
    private function verifySignature(Request $request, string $rawBody): bool
    {
        $signature = $request->headers->get('X-Hub-Signature-256', '');

        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $providedHash = substr($signature, 7);
        $expectedHash = hash_hmac('sha256', $rawBody, $this->appSecret);

        return hash_equals($expectedHash, $providedHash);
    }
}
