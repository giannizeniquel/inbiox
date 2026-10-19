<?php

declare(strict_types=1);

namespace App\Tests\Integration\Storage;

use App\Compartido\Storage\AdjuntoStorageService;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for AdjuntoStorageService against a local MinIO instance.
 *
 * Requires MinIO running: docker-compose up -d minio
 *
 * @group integration
 * @group storage
 */
class MediaStorageServiceTest extends TestCase
{
    private ?AdjuntoStorageService $service = null;
    private ?S3Client $s3Client = null;

    protected function setUp(): void
    {
        $endpoint = getenv('S3_ENDPOINT') ?: 'http://minio:9000';
        $key = getenv('S3_KEY') ?: 'app';
        $secret = getenv('S3_SECRET') ?: 'app-secret';
        $region = getenv('S3_REGION') ?: 'us-east-1';
        $bucket = getenv('S3_BUCKET') ?: 'inbiox-media';

        $this->s3Client = new S3Client([
            'endpoint' => $endpoint,
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
            'use_path_style_endpoint' => true,
        ]);

        // Ensure bucket exists
        try {
            $this->s3Client->createBucket(['Bucket' => $bucket]);
        } catch (\Throwable $e) {
            // Bucket may already exist
        }

        $this->service = new AdjuntoStorageService(
            $this->s3Client,
            $bucket,
            new NullLogger(),
            300,
        );
    }

    public function test_put_and_exists_roundtrip(): void
    {
        $key = 'test-tenant/999/' . uniqid('test-', true) . '.txt';
        $content = 'Hello, MinIO!';

        $this->service->put($key, $content, 'text/plain');

        $this->assertTrue($this->service->exists($key));

        // Cleanup
        $this->service->delete($key);
    }

    public function test_get_returns_stored_content(): void
    {
        $key = 'test-tenant/999/' . uniqid('get-', true) . '.txt';
        $content = 'Content to retrieve';

        $this->service->put($key, $content, 'text/plain');

        $retrieved = $this->service->get($key);

        $this->assertSame($content, $retrieved);

        // Cleanup
        $this->service->delete($key);
    }

    public function test_exists_returns_false_for_missing_key(): void
    {
        $key = 'nonexistent-' . uniqid() . '.txt';

        $this->assertFalse($this->service->exists($key));
    }

    public function test_temporary_url_returns_valid_url(): void
    {
        $key = 'test-tenant/999/' . uniqid('url-', true) . '.txt';
        $content = 'URL test content';

        $this->service->put($key, $content, 'text/plain');

        $url = $this->service->temporaryUrl($key, 60);

        $this->assertStringContainsString('X-Amz-Signature', $url);
        $this->assertStringContainsString('inbiox-media', $url);
        $this->assertStringContainsString($key, $url);

        // Cleanup
        $this->service->delete($key);
    }

    public function test_delete_removes_object(): void
    {
        $key = 'test-tenant/999/' . uniqid('del-', true) . '.txt';

        $this->service->put($key, 'to be deleted', 'text/plain');
        $this->assertTrue($this->service->exists($key));

        $this->service->delete($key);

        $this->assertFalse($this->service->exists($key));
    }

    public function test_put_with_binary_content(): void
    {
        $key = 'test-tenant/999/' . uniqid('bin-', true) . '.jpg';
        $binary = random_bytes(1024);

        $this->service->put($key, $binary, 'image/jpeg');

        $retrieved = $this->service->get($key);

        $this->assertSame($binary, $retrieved);

        // Cleanup
        $this->service->delete($key);
    }
}
