<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spike: proves the `ob_start(); $response->sendContent(); ob_get_clean();`
 * idiom captures `StreamedResponse` output inside this repo's `WebTestCase`
 * environment (headers already sent by the test client's kernel boot must
 * not interfere). This is a prerequisite proof for `ExportTicketsCsvQuery`'s
 * chunked-write design (`design.md`, Testing Strategy) — run and confirmed
 * BEFORE building the chunking logic on top of it.
 */
class StreamedResponseCaptureSpikeTest extends WebTestCase
{
    public function test_streamed_response_content_is_captured_via_ob_buffer(): void
    {
        static::createClient();

        $response = new StreamedResponse(function () {
            echo 'a,b,c';
            echo "\n";
            echo '1,2,3';
        });
        $response->headers->set('Content-Type', 'text/csv');

        ob_start();
        $response->sendContent();
        $captured = ob_get_clean();

        self::assertSame("a,b,c\n1,2,3", $captured);
    }

    public function test_streamed_response_supports_multiple_flushed_chunks(): void
    {
        static::createClient();

        $response = new StreamedResponse(function () {
            foreach (['chunk-1', 'chunk-2', 'chunk-3'] as $chunk) {
                echo $chunk . "\n";
                flush();
            }
        });

        ob_start();
        $response->sendContent();
        $captured = ob_get_clean();

        self::assertSame("chunk-1\nchunk-2\nchunk-3\n", $captured);
    }
}
