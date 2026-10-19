<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Storage;

use App\Conversacion\TipoDeAdjunto;
use App\Conversacion\AdjuntoDemasiadoGrandeException;
use App\Conversacion\MimeDeAdjuntoNoPermitidoException;
use App\Conversacion\ValidadorDeAdjunto;
use PHPUnit\Framework\TestCase;

class MediaValidatorTest extends TestCase
{
    private ValidadorDeAdjunto $validator;

    protected function setUp(): void
    {
        $this->validator = new ValidadorDeAdjunto();
    }

    public function test_validates_jpeg_under_5mb(): void
    {
        $this->validator->validate('image/jpeg', 5 * 1024 * 1024 - 1);
        $this->assertTrue(true); // No exception = pass
    }

    public function test_rejects_image_over_5mb(): void
    {
        $this->expectException(AdjuntoDemasiadoGrandeException::class);

        $this->validator->validate('image/jpeg', 5 * 1024 * 1024 + 1);
    }

    public function test_validates_png_at_boundary(): void
    {
        $this->validator->validate('image/png', 5 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_png_over_5mb(): void
    {
        $this->expectException(AdjuntoDemasiadoGrandeException::class);

        $this->validator->validate('image/png', 5 * 1024 * 1024 + 1024);
    }

    public function test_validates_webp_under_5mb(): void
    {
        $this->validator->validate('image/webp', 4 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_validates_video_under_16mb(): void
    {
        $this->validator->validate('video/mp4', 16 * 1024 * 1024 - 1);
        $this->assertTrue(true);
    }

    public function test_rejects_video_over_16mb(): void
    {
        $this->expectException(AdjuntoDemasiadoGrandeException::class);

        $this->validator->validate('video/mp4', 16 * 1024 * 1024 + 1);
    }

    public function test_validates_audio_under_16mb(): void
    {
        $this->validator->validate('audio/ogg', 15 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_audio_over_16mb(): void
    {
        $this->expectException(AdjuntoDemasiadoGrandeException::class);

        $this->validator->validate('audio/mpeg', 16 * 1024 * 1024 + 1024);
    }

    public function test_validates_pdf_under_100mb(): void
    {
        $this->validator->validate('application/pdf', 99 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_pdf_over_100mb(): void
    {
        $this->expectException(AdjuntoDemasiadoGrandeException::class);

        $this->validator->validate('application/pdf', 101 * 1024 * 1024);
    }

    public function test_rejects_exe_mime_type(): void
    {
        $this->expectException(MimeDeAdjuntoNoPermitidoException::class);

        $this->validator->validate('application/x-msdownload', 1024);
    }

    public function test_rejects_application_octet_stream(): void
    {
        $this->expectException(MimeDeAdjuntoNoPermitidoException::class);

        $this->validator->validate('application/octet-stream', 1024);
    }

    public function test_allows_text_plain(): void
    {
        $this->validator->validate('text/plain', 1024);
        $this->assertTrue(true);
    }

    public function test_allows_docx(): void
    {
        $this->validator->validate(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            1024
        );
        $this->assertTrue(true);
    }

    public function test_allows_xlsx(): void
    {
        $this->validator->validate(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            1024
        );
        $this->assertTrue(true);
    }

    public function test_allows_csv(): void
    {
        $this->validator->validate('text/csv', 1024);
        $this->assertTrue(true);
    }

    public function test_allows_mpeg_audio(): void
    {
        $this->validator->validate('audio/mpeg', 1024);
        $this->assertTrue(true);
    }

    public function test_get_max_size_returns_correct_caps(): void
    {
        $this->assertSame(5 * 1024 * 1024, $this->validator->getMaxSize('image/jpeg'));
        $this->assertSame(16 * 1024 * 1024, $this->validator->getMaxSize('video/mp4'));
        $this->assertSame(16 * 1024 * 1024, $this->validator->getMaxSize('audio/ogg'));
        $this->assertSame(100 * 1024 * 1024, $this->validator->getMaxSize('application/pdf'));
    }

    public function test_media_kind_from_mime(): void
    {
        $this->assertSame(TipoDeAdjunto::IMAGE, TipoDeAdjunto::fromMime('image/jpeg'));
        $this->assertSame(TipoDeAdjunto::IMAGE, TipoDeAdjunto::fromMime('image/png'));
        $this->assertSame(TipoDeAdjunto::VIDEO, TipoDeAdjunto::fromMime('video/mp4'));
        $this->assertSame(TipoDeAdjunto::AUDIO, TipoDeAdjunto::fromMime('audio/ogg'));
        $this->assertSame(TipoDeAdjunto::DOCUMENT, TipoDeAdjunto::fromMime('application/pdf'));
        $this->assertSame(TipoDeAdjunto::DOCUMENT, TipoDeAdjunto::fromMime('text/plain'));
        $this->assertSame(TipoDeAdjunto::DOCUMENT, TipoDeAdjunto::fromMime('application/msword'));
    }

    public function test_media_kind_from_unknown_defaults_to_document(): void
    {
        $this->assertSame(TipoDeAdjunto::DOCUMENT, TipoDeAdjunto::fromMime('application/x-custom'));
    }

    public function test_media_kind_to_extension(): void
    {
        $this->assertSame('jpg', TipoDeAdjunto::toExtension(TipoDeAdjunto::IMAGE));
        $this->assertSame('mp4', TipoDeAdjunto::toExtension(TipoDeAdjunto::VIDEO));
        $this->assertSame('ogg', TipoDeAdjunto::toExtension(TipoDeAdjunto::AUDIO));
        $this->assertSame('pdf', TipoDeAdjunto::toExtension(TipoDeAdjunto::DOCUMENT));
    }
}
