<?php

declare(strict_types=1);

namespace App\Conversacion\Controller;

use App\Compartido\Graph\GraphClient;
use App\Agente\UseCase\AsegurarAgenteDeUsuarioUseCase;
use App\Conversacion\UseCase\EnviarMensajeSalienteUseCase;
use App\Conversacion\UseCase\EnviarPlantillaUseCase;
use App\Conversacion\UseCase\SubirAdjuntoUseCase;
use App\Administracion\Usuario;
use App\Conversacion\VentanaVencidaException;
use App\Conversacion\TelefonoDeDestinatarioNoEncontradoException;
use App\Compartido\NotFoundException;
use App\Compartido\Graph\GraphApiException;
use App\Conversacion\AdjuntoDemasiadoGrandeException;
use App\Conversacion\MimeDeAdjuntoNoPermitidoException;
use App\Compartido\ConflictoDeEstadoException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AGENT')]
class ProxyDeProveedorController extends AbstractController
{
    private const GRAPH_AUTH_ERROR = 401;   // Errores de auth/oauth de Meta
    private const GRAPH_RATE_LIMIT = 429;    // Demasiadas solicitudes

    public function __construct(
        private readonly GraphClient $graphClient,
        private readonly EnviarMensajeSalienteUseCase $enviarMensajeSaliente,
        private readonly EnviarPlantillaUseCase $enviarPlantillaUseCase,
        private readonly AsegurarAgenteDeUsuarioUseCase $asegurarAgenteDeUsuario,
        private readonly SubirAdjuntoUseCase $subirAdjuntoUseCase,
    ) {}

    #[Route('/api/provider/send', name: 'app_provider_send', methods: ['POST'])]
    public function enviar(Request $request, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !isset($payload['ticketId']) || !isset($payload['content'])) {
            return new JsonResponse(
                ['error' => 'Missing required fields: ticketId, content'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $ticketId = (int) $payload['ticketId'];
        $contenido = $payload['content'];
        $mediaId = $payload['mediaId'] ?? null;
        $mimeType = $payload['mimeType'] ?? null;
        $leyenda = $payload['caption'] ?? null;
        $mediaKey = $payload['mediaKey'] ?? null;
        $filename = $payload['filename'] ?? null;
        $tamanio = $payload['size'] ?? null;

        // Resuelve el ID del agente a partir del usuario autenticado (degrada a null, nunca
        // hace fallar el envío; design.md D4).
        $agenteId = $this->getAgenteQueActuaId($usuario) ?? 0;

        // Guarda de responsable en tickets resueltos/cerrados (design.md D3): role_hierarchy hace que
        // ROLE_ADMIN herede ROLE_SUPERVISOR, así que esta única verificación cubre ambos.
        $remitenteEsPrivilegiado = $this->isGranted('ROLE_SUPERVISOR');

        try {
            $result = $this->enviarMensajeSaliente->execute(
                ticketId: $ticketId,
                contenido: $contenido,
                agenteId: $agenteId,
                remitenteEsPrivilegiado: $remitenteEsPrivilegiado,
                mediaId: $mediaId,
                mimeType: $mimeType,
                leyenda: $leyenda,
                mediaKey: $mediaKey,
                filename: $filename,
                tamanio: $tamanio,
            );
        } catch (VentanaVencidaException $e) {
            return new JsonResponse(
                [
                    'type' => 'CONVERSATION_WINDOW_EXPIRED',
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (ConflictoDeEstadoException $e) {
            return new JsonResponse(
                [
                    'type' => 'TICKET_NOT_OWNED',
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_CONFLICT
            );
        } catch (NotFoundException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_NOT_FOUND
            );
        } catch (TelefonoDeDestinatarioNoEncontradoException $e) {
            return new JsonResponse(
                ['type' => 'PHONE_NOT_FOUND', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (GraphApiException $e) {
            $httpStatus = $this->mapGraphErrorCode($e->getGraphCode());
            $errorType = $this->mapGraphErrorType($e->getGraphCode());

            return new JsonResponse(
                [
                    'type' => $errorType,
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                $httpStatus
            );
        }

        return new JsonResponse(
            [
                // ID del mensaje en la BD: el frontend lo usa para pedir la URL del adjunto
                'messageId' => $result['messageId'],
                // ID del mensaje en WhatsApp (WAMID)
                'externalId' => $result['externalId'],
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    /**
     * Resuelve el id del agente que actúa a partir del usuario autenticado, degradando
     * sin errores (nunca lanza) en lugar de hacer fallar el envío de quien llama.
     *
     * Si el usuario no tiene un registro de Agent vinculado (p. ej. admin), crea o
     * busca uno para que se le puedan asignar tickets. Extraído del fallback en línea
     * original de send() (design.md D4).
     */
    private function getAgenteQueActuaId(?Usuario $usuario): ?int
    {
        if ($usuario === null) {
            return null;
        }

        try {
            return $this->asegurarAgenteDeUsuario->execute($usuario)->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Route('/api/provider/template/send', name: 'app_provider_template_send', methods: ['POST'])]
    public function enviarPlantilla(Request $request, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)
            || !isset($payload['ticketId'])
            || !isset($payload['templateName'])
            || !isset($payload['language'])
        ) {
            return new JsonResponse(
                ['error' => 'Missing required fields: ticketId, templateName, language'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $ticketId = (int) $payload['ticketId'];
        $nombreDePlantilla = $payload['templateName'];
        $idioma = $payload['language'];
        $parametros = $payload['parameters'] ?? [];

        if (!is_array($parametros)) {
            return new JsonResponse(
                ['error' => 'Parameters must be an array of strings'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $wamid = $this->enviarPlantillaUseCase->execute(
                ticketId: $ticketId,
                nombreDePlantilla: $nombreDePlantilla,
                idioma: $idioma,
                parametros: $parametros,
                resolverAgenteRemitenteId: fn (): ?int => $this->getAgenteQueActuaId($usuario),
            );
        } catch (NotFoundException $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_NOT_FOUND
            );
        } catch (TelefonoDeDestinatarioNoEncontradoException $e) {
            return new JsonResponse(
                ['type' => 'PHONE_NOT_FOUND', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (GraphApiException $e) {
            $httpStatus = $this->mapGraphErrorCode($e->getGraphCode());
            $errorType = $this->mapGraphErrorType($e->getGraphCode());

            return new JsonResponse(
                [
                    'type' => $errorType,
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                $httpStatus
            );
        }

        return new JsonResponse(
            [
                'messageId' => $wamid,
                'status' => 'sent',
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/api/provider/media/upload', name: 'app_provider_media_upload', methods: ['POST'])]
    public function subirAdjunto(Request $request, #[CurrentUser] ?Usuario $usuario): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(
                ['error' => 'No valid file provided. Use multipart/form-data with a "file" field.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $result = $this->subirAdjuntoUseCase->execute(
                filePath: $file->getPathname(),
                mimeType: $file->getMimeType() ?? 'application/octet-stream',
                tamanioDeArchivo: $file->getSize(),
                filename: $file->getClientOriginalName(),
                ticketId: $request->request->all()['ticketId'] ?? null,
            );
        } catch (AdjuntoDemasiadoGrandeException $e) {
            return new JsonResponse(
                [
                    'error' => 'FILE_TOO_LARGE',
                    'maxSize' => $e->getTamanioMaximo(),
                    'kind' => $e->getTipo(),
                ],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        } catch (MimeDeAdjuntoNoPermitidoException $e) {
            return new JsonResponse(
                [
                    'error' => 'UNSUPPORTED_TYPE',
                    'mimeType' => $e->getMimeType(),
                ],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE
            );
        } catch (GraphApiException $e) {
            return new JsonResponse(
                [
                    'type' => 'GRAPH_API_ERROR',
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return new JsonResponse($result, Response::HTTP_OK);
    }

    #[Route('/api/provider/media/{mediaId}', name: 'app_provider_media_download', methods: ['GET'])]
    public function descargarAdjunto(string $mediaId): Response
    {
        try {
            $contenido = $this->graphClient->downloadMedia($mediaId);

            return new Response($contenido, Response::HTTP_OK);
        } catch (GraphApiException $e) {
            return new JsonResponse(
                [
                    'type' => 'GRAPH_API_ERROR',
                    'code' => $e->getGraphCode(),
                    'message' => $e->getMessage(),
                ],
                $this->mapGraphErrorCode($e->getGraphCode())
            );
        }
    }

    /**
     * Mapea el código de error de Graph API a un estado HTTP.
     */
    private function mapGraphErrorCode(int $code): int
    {
        return match ($code) {
            131030, 131031, 131047, 132000 => Response::HTTP_UNPROCESSABLE_ENTITY, // 422
            131050, 132001 => Response::HTTP_TOO_MANY_REQUESTS,                    // 429
            default => Response::HTTP_BAD_GATEWAY,                                 // 502
        };
    }

    /**
     * Mapea el código de error de Graph API a un tipo de error de Inbiox.
     */
    private function mapGraphErrorType(int $code): string
    {
        return match ($code) {
            131030 => 'RECIPIENT_NOT_ALLOWED',
            131031 => 'INVALID_RECIPIENT',
            131047 => 'RECIPIENT_BLOCKED',
            132000 => 'MESSAGE_TOO_OLD',
            131050, 132001 => 'RATE_LIMIT_EXCEEDED',
            default => 'GRAPH_API_ERROR',
        };
    }
}
