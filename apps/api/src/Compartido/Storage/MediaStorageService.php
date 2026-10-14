<?php

declare(strict_types=1);

namespace App\Compartido\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;

class MediaStorageService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $bucket,
        private readonly LoggerInterface $logger,
        private readonly int $urlTtl = 300,
        private readonly ?S3Client $publicS3Client = null,
    ) {}

    /**
     * Store content under the given key.
     *
     * @param string|resource $body Content to store
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
     * Retrieve content by key.
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
     * Check if an object exists.
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
     * Generate a pre-signed URL for temporary access.
     *
     * Uses the public-endpoint client (if configured) so the URL hostname is
     * reachable from the browser. The internal client's hostname (e.g.
     * "minio:9000" inside Docker) is not resolvable outside the container.
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
     * Delete an object.
     */
    public function delete(string $key): void
    {
        $this->s3Client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }
}
