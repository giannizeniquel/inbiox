<?php

declare(strict_types=1);

namespace App\Conversacion\UseCase;

use Psr\Log\LoggerInterface;

/**
 * Enruta un payload de webhook de Meta verificado: los mensajes entrantes a
 * ProcesarMensajeEntranteUseCase y las actualizaciones de estado a ProcesarActualizacionDeEstadoUseCase.
 *
 * Estructura del payload de Meta (META-002):
 *   entry[].changes[].value.messaging_product === 'whatsapp'
 *   value.messages[]     → mensajes entrantes
 *   value.statuses[]     → actualizaciones de estado
 *
 * Meta envía tanto messages como statuses bajo field = "messages", así que la
 * ruta depende de qué lista está presente. El phone_number_id de la
 * metadata va a ambos handlers (CAP-7 / META-017).
 *
 * Cada mensaje entrante tiene su propio catch, así un fallo no detiene el
 * siguiente. Las entradas de estado no lo tienen: una que lanza detiene el resto del
 * payload y la excepción llega a quien llama (contradiction track, S10).
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

                // Valida que messaging_product sea 'whatsapp'
                if (($value['messaging_product'] ?? null) !== 'whatsapp') {
                    continue;
                }

                // Extrae phone_number_id de metadata (CAP-7 / META-017)
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                // Atiende los mensajes entrantes: se procesan con ProcesarMensajeEntranteUseCase
                if (isset($value['messages']) && is_array($value['messages'])) {
                    // Arma el mapa teléfono→nombre a partir de contacts[] (Meta envía wa_id + profile.name)
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

                        // Omite los mensajes sin remitente
                        if ($from === null) {
                            continue;
                        }

                        $nombreDeContacto = $nombresDeContacto[$from] ?? null;

                        // Tipos de mensaje soportados: text, document, image, audio, video
                        $supportedTypes = ['text', 'document', 'image', 'audio', 'video'];
                        if (!in_array($msgType, $supportedTypes, true)) {
                            $this->logger->debug('Skipping unsupported inbound message type', [
                                'type' => $msgType,
                                'from' => $from,
                                'wamid' => $wamid,
                            ]);
                            continue;
                        }

                        // Extrae el contenido del mensaje según el tipo
                        $datosDeDescargaDeAdjunto = null;
                        if ($msgType === 'text') {
                            $text = $msg['text']['body'] ?? '';
                        } else {
                        // Tipos con adjunto: arma un contenido descriptivo para mostrar en el inbox
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

                        // Arma el payload de descarga del adjunto para el almacenamiento
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

                // Atiende las actualizaciones de estado: pasa phoneNumberId para multinúmero (CAP-7)
                if (isset($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $statusEntry) {
                        $this->procesarActualizacionDeEstado->procesarActualizacionDeEstado($statusEntry, $phoneNumberId);
                    }
                }
            }
        }
    }
}
