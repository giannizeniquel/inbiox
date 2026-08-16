<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

class FlysystemFactory
{
    public static function create(S3Client $s3Client, string $bucket): FilesystemOperator
    {
        $adapter = new AwsS3V3Adapter($s3Client, $bucket);

        return new Filesystem($adapter);
    }
}
