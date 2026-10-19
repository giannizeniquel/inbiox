<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use Psr\Log\LoggerInterface;

/**
 * Routes a verified Meta webhook payload: inbound messages to
 * ProcesarMensajeEntranteUseCase, status updates to ProcesarActualizacionDeEstadoUseCase.
 *
 * Meta's payload structure (META-002):
 *   entry[].changes[].value.messaging_product === 'whatsapp'
 *   value.messages[]     → inbound messages
 *   value.statuses[]     → status updates
 *
 * Meta sends both messages and statuses under field = "messages", so the
 * route depends on which list is present. The phone_number_id from the
 * metadata goes to both handlers (CAP-7 / META-017).
 *
 * Each inbound message has its own catch, so one failure does not stop the
 * next. Status entries have none: one that throws stops the rest of the
 * payload and the exception reaches the caller (contradiction track, S10).
 */
final class ProcesarPayloadWebhookUseCase
{
    public function __construct(
        private readonly ProcesarMensajeEntranteUseCase $procesarMensajeEntrante,
        private readonly ProcesarActualizacionDeEstadoUseCase $procesarActualizacionDeEstado,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];

                // Validate messaging_product is 'whatsapp'
                if (($value['messaging_product'] ?? null) !== 'whatsapp') {
                    continue;
                }

                // Extract phone_number_id from metadata (CAP-7 / META-017)
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                // Handle inbound messages — process via ProcesarMensajeEntranteUseCase
                if (isset($value['messages']) && is_array($value['messages'])) {
                    // Build phone→name lookup from contacts[] (Meta sends wa_id + profile.name)
                    $nombresDeContacto = [];
                    if (isset($value['contacts']) && is_array($value['contacts'])) {
                        foreach ($value['contacts'] as $contacto) {
                            if (isset($contacto['wa_id'])) {
                                $nombresDeContacto[$contacto['wa_id']] = $contacto['profile']['name'] ?? null;
                            }
                        }
                    }

                    foreach ($value['messages'] as $msg) {
                        $from = $msg['from'] ?? null;
                        $wamid = $msg['id'] ?? null;
                        $msgType = $msg['type'] ?? null;

                        // Skip messages without a sender
                        if ($from === null) {
                            continue;
                        }

                        $nombreDeContacto = $nombresDeContacto[$from] ?? null;

                        // Supported message types: text, document, image, audio, video
                        $supportedTypes = ['text', 'document', 'image', 'audio', 'video'];
                        if (!in_array($msgType, $supportedTypes, true)) {
                            $this->logger->debug('Skipping unsupported inbound message type', [
                                'type' => $msgType,
                                'from' => $from,
                                'wamid' => $wamid,
                            ]);
                            continue;
                        }

                        // Extract message content based on type
                        $datosDeDescargaDeAdjunto = null;
                        if ($msgType === 'text') {
                            $text = $msg['text']['body'] ?? '';
                        } else {
                        // Media types: build descriptive content for inbox display
                        $mediaId = $msg[$msgType]['id'] ?? null;
                        $filename = $msg[$msgType]['filename'] ?? null;
                        $mimeType = $msg[$msgType]['mime_type'] ?? null;
                        $leyenda = $msg[$msgType]['caption'] ?? null;

                        $typeLabels = [
                            'document' => '📎 Documento',
                            'image' => '🖼 Imagen',
                            'audio' => '🎵 Audio',
                            'video' => '🎬 Video',
                        ];
                        $typeLabel = $typeLabels[$msgType] ?? '📁 ' . ucfirst($msgType);

                        $text = $leyenda
                            ? "{$typeLabel}: {$filename} — \"{$leyenda}\""
                            : "{$typeLabel}: {$filename}";

                        // Build media download payload for storage
                        $datosDeDescargaDeAdjunto = $mediaId ? [
                            'mediaId' => $mediaId,
                            'mimeType' => $mimeType,
                            'filename' => $filename,
                            'caption' => $leyenda,
                        ] : null;
                        }

                        try {
                            $result = $this->procesarMensajeEntrante->procesarMensajeEntrante(
                                $from,
                                $text,
                                $wamid,
                                $phoneNumberId,
                                $nombreDeContacto,
                                $datosDeDescargaDeAdjunto,
                            );

                            $this->logger->info('Inbound message processed', [
                                'wamid' => $wamid,
                                'from' => $from,
                                'message_id' => $result['message_id'],
                                'ticket_id' => $result['ticket_id'],
                                'ticket_status' => $result['ticket_status'] ?? 'ok',
                            ]);
                        } catch (\Throwable $e) {
                            $this->logger->error('Failed to process inbound message', [
                                'wamid' => $wamid,
                                'from' => $from,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // Handle status updates — pass phoneNumberId for multi-number (CAP-7)
                if (isset($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $statusEntry) {
                        $this->procesarActualizacionDeEstado->procesarActualizacionDeEstado($statusEntry, $phoneNumberId);
                    }
                }
            }
        }
    }
}
