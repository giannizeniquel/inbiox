<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Storage;

use App\Domain\Media\MediaKind;
use App\Exception\MediaTooLargeException;
use App\Exception\MediaTypeNotAllowedException;
use App\Infrastructure\Storage\MediaValidator;
use PHPUnit\Framework\TestCase;

class MediaValidatorTest extends TestCase
{
    private MediaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MediaValidator();
    }

    public function test_validates_jpeg_under_5mb(): void
    {
        $this->validator->validate('image/jpeg', 5 * 1024 * 1024 - 1);
        $this->assertTrue(true); // No exception = pass
    }

    public function test_rejects_image_over_5mb(): void
    {
        $this->expectException(MediaTooLargeException::class);

        $this->validator->validate('image/jpeg', 5 * 1024 * 1024 + 1);
    }

    public function test_validates_png_at_boundary(): void
    {
        $this->validator->validate('image/png', 5 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_png_over_5mb(): void
    {
        $this->expectException(MediaTooLargeException::class);

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
        $this->expectException(MediaTooLargeException::class);

        $this->validator->validate('video/mp4', 16 * 1024 * 1024 + 1);
    }

    public function test_validates_audio_under_16mb(): void
    {
        $this->validator->validate('audio/ogg', 15 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_audio_over_16mb(): void
    {
        $this->expectException(MediaTooLargeException::class);

        $this->validator->validate('audio/mpeg', 16 * 1024 * 1024 + 1024);
    }

    public function test_validates_pdf_under_100mb(): void
    {
        $this->validator->validate('application/pdf', 99 * 1024 * 1024);
        $this->assertTrue(true);
    }

    public function test_rejects_pdf_over_100mb(): void
    {
        $this->expectException(MediaTooLargeException::class);

        $this->validator->validate('application/pdf', 101 * 1024 * 1024);
    }

    public function test_rejects_exe_mime_type(): void
    {
        $this->expectException(MediaTypeNotAllowedException::class);

        $this->validator->validate('application/x-msdownload', 1024);
    }

    public function test_rejects_application_octet_stream(): void
    {
        $this->expectException(MediaTypeNotAllowedException::class);

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
        $this->assertSame(MediaKind::IMAGE, MediaKind::fromMime('image/jpeg'));
        $this->assertSame(MediaKind::IMAGE, MediaKind::fromMime('image/png'));
        $this->assertSame(MediaKind::VIDEO, MediaKind::fromMime('video/mp4'));
        $this->assertSame(MediaKind::AUDIO, MediaKind::fromMime('audio/ogg'));
        $this->assertSame(MediaKind::DOCUMENT, MediaKind::fromMime('application/pdf'));
        $this->assertSame(MediaKind::DOCUMENT, MediaKind::fromMime('text/plain'));
        $this->assertSame(MediaKind::DOCUMENT, MediaKind::fromMime('application/msword'));
    }

    public function test_media_kind_from_unknown_defaults_to_document(): void
    {
        $this->assertSame(MediaKind::DOCUMENT, MediaKind::fromMime('application/x-custom'));
    }

    public function test_media_kind_to_extension(): void
    {
        $this->assertSame('jpg', MediaKind::toExtension(MediaKind::IMAGE));
        $this->assertSame('mp4', MediaKind::toExtension(MediaKind::VIDEO));
        $this->assertSame('ogg', MediaKind::toExtension(MediaKind::AUDIO));
        $this->assertSame('pdf', MediaKind::toExtension(MediaKind::DOCUMENT));
    }
}
