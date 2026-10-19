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
 * Single webhook endpoint for Meta WhatsApp Cloud API.
 *
 * GET  /webhook — Meta verification (hub.challenge)
 * POST /webhook — Event dispatch (messages, statuses)
 *
 * Design: AD-1 — single /webhook route, dual HTTP verb dispatch.
 * Meta convention requires verification and event delivery to share the same URL.
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
     * Entry point: dispatches to verify() (GET) or receive() (POST).
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
     * GET /webhook — Meta subscription verification.
     *
     * Echoes hub.challenge as plain text when hub.verify_token matches.
     * Returns 403 on mismatch, 400 on missing params.
     *
     * Note: PHP's parse_str() converts dots in query param names to underscores,
     * so we read hub.mode as hub_mode, hub.verify_token as hub_verify_token, etc.
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

        // Meta expects the raw challenge string as plain text, not JSON
        return new Response($challenge, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * POST /webhook — Receive and dispatch Meta webhook events.
     *
     * 1. Verify X-Hub-Signature-256 HMAC (security gate)
     * 2. Parse JSON body
     * 3. Log raw payload for debugging
     * 4. Route to handlers based on payload structure
     *
     * Always returns 200 to Meta (non-2xx triggers Meta retries).
     */
    private function receive(Request $request): Response
    {
        // Step 1: Read raw body BEFORE any parsing (HMAC must verify the raw payload)
        $rawBody = $request->getContent();

        // Step 2: Verify HMAC signature
        if (!$this->verifySignature($request, $rawBody)) {
            $this->logger->warning('Webhook received with invalid HMAC signature');

            return new Response(
                '{"error":"invalid signature"}',
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/json']
            );
        }

        // Step 3: Parse JSON
        $payload = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->logger->warning('Webhook received malformed JSON payload');

            return new Response(
                '{"error":"malformed payload"}',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json']
            );
        }

        // Step 4: Persist raw webhook payload BEFORE processing
        // This ensures we have a record even if the processor crashes (T-02)
        try {
            $this->registrarPayloadWebhook->execute($payload);
        } catch (\Throwable $e) {
            // Don't crash the webhook — log and continue
            $this->logger->error('Failed to persist webhook payload', [
                'error' => $e->getMessage(),
            ]);
        }

        // Step 5: Dispatch to handlers
        try {
            $this->procesarPayloadWebhook->execute($payload);
        } catch (\Throwable $e) {
            // Log but always return 200 — Meta retries on failure
            $this->logger->error('Webhook dispatch error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Step 6: Always 200 OK
        return new Response('{"ok":true}', Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    /**
     * Verify X-Hub-Signature-256 header using SHA256 HMAC with app secret.
     *
     * Uses hash_equals() for constant-time comparison to prevent timing attacks.
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
