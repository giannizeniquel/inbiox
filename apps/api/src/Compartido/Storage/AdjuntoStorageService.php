<?php

declare(strict_types=1);

namespace App\Compartido\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;

class AdjuntoStorageService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $bucket,
        private readonly LoggerInterface $logger,
        private readonly int $urlTtl = 300,
        private readonly ?S3Client $publicS3Client = null,
    ) {}

    /**
     * Guarda el contenido bajo la clave dada.
     *
     * @param string|resource $body Contenido a guardar
     */
    public function put(string $key, $body, string $mimeType = 'application/octet-stream'): void
    {
        $this->s3Client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $mimeType,
        ]);
    }

    /**
     * Obtiene el contenido por clave.
     */
    public function get(string $key): string
    {
        $result = $this->s3Client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    /**
     * Verifica si un objeto existe.
     */
    public function exists(string $key): bool
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Genera una URL prefirmada para acceso temporal.
     *
     * Usa el cliente del endpoint público (si está configurado) para que el hostname de la URL sea
     * accesible desde el navegador. El hostname del cliente interno (p. ej.
     * "minio:9000" dentro de Docker) no se resuelve fuera del contenedor.
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 300): string
    {
        $client = $this->publicS3Client ?? $this->s3Client;

        $command = $client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $presign = $client->createPresignedRequest($command, "+{$ttlSeconds} seconds");

        return (string) $presign->getUri();
    }

    /**
     * Borra un objeto.
     */
    public function delete(string $key): void
    {
        $this->s3Client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }
}
