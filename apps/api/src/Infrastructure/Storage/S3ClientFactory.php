<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Aws\S3\S3Client;

class S3ClientFactory
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
    ) {}

    public static function create(
        string $endpoint,
        string $region,
        string $accessKey,
        string $secretKey,
    ): S3Client {
        return new S3Client([
            'endpoint' => $endpoint,
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'use_path_style_endpoint' => true,
        ]);
    }
}
