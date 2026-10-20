<?php

declare(strict_types=1);

namespace App\Tests\Unit\Graph;

use App\Compartido\Graph\GraphClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What goes to Meta does not change with the API contract: the attachment kind is
 * stored in Spanish (`imagen`, `documento`…), but the media message sent to Graph
 * keeps Meta's `type` values (`image`, `video`, `audio`, `document`).
 */
final class GraphClientMediaTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mimeTypes(): iterable
    {
        yield 'image' => ['image/jpeg', 'image'];
        yield 'video' => ['video/mp4', 'video'];
        yield 'audio' => ['audio/ogg', 'audio'];
        yield 'document' => ['application/pdf', 'document'];
        yield 'unknown is a document' => ['application/x-custom', 'document'];
    }

    #[DataProvider('mimeTypes')]
    public function testMediaMessageUsesMetaTypes(string $mimeType, string $metaType): void
    {
        $sent = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true);

            return new MockResponse('{"messages":[{"id":"wamid.1"}]}', ['http_code' => 200]);
        });
        $client = new GraphClient('token', '111', $http);

        $client->sendMediaMessage('+5491100000000', 'media-1', $mimeType, 'leyenda', '111', 'a.bin');

        self::assertIsArray($sent);
        self::assertSame($metaType, $sent['type']);
        self::assertSame('media-1', $sent[$metaType]['id']);
        self::assertSame($metaType === 'audio' ? null : 'leyenda', $sent[$metaType]['caption'] ?? null);
        self::assertSame($metaType === 'document' ? 'a.bin' : null, $sent[$metaType]['filename'] ?? null);
    }
}
