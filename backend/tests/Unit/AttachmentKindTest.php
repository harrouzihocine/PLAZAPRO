<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Collaboration\Enums\AttachmentKind;
use PHPUnit\Framework\TestCase;

class AttachmentKindTest extends TestCase
{
    public function test_images_map_to_image(): void
    {
        $this->assertSame(AttachmentKind::Image, AttachmentKind::fromMime('image/jpeg'));
        $this->assertSame(AttachmentKind::Image, AttachmentKind::fromMime('image/png'));
    }

    public function test_audio_and_browser_recording_containers_map_to_voice(): void
    {
        $this->assertSame(AttachmentKind::Voice, AttachmentKind::fromMime('audio/webm'));
        $this->assertSame(AttachmentKind::Voice, AttachmentKind::fromMime('audio/ogg'));
        // Content-based detection may report an audio-only webm/ogg as video/*.
        $this->assertSame(AttachmentKind::Voice, AttachmentKind::fromMime('video/webm'));
        $this->assertSame(AttachmentKind::Voice, AttachmentKind::fromMime('video/ogg'));
    }

    public function test_pdf_maps_to_file(): void
    {
        $this->assertSame(AttachmentKind::File, AttachmentKind::fromMime('application/pdf'));
    }

    public function test_unsupported_types_are_rejected(): void
    {
        $this->assertNull(AttachmentKind::fromMime('application/x-msdownload'));
        $this->assertNull(AttachmentKind::fromMime('text/html'));
        $this->assertNull(AttachmentKind::fromMime('application/octet-stream'));
    }

    public function test_message_type_matches_kind(): void
    {
        $this->assertSame('voice', AttachmentKind::Voice->messageType()->value);
        $this->assertSame('image', AttachmentKind::Image->messageType()->value);
        $this->assertSame('file', AttachmentKind::File->messageType()->value);
    }
}
