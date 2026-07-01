<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * The kind of media asset. Derived server-side from the validated mime type —
 * never trusted from the client or the file extension.
 */
enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Pdf = 'pdf';
    case Pptx = 'pptx';

    /**
     * Map a validated mime type to a MediaType, or null if unsupported.
     */
    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => self::Photo,
            'video/mp4', 'video/webm', 'video/quicktime' => self::Video,
            'application/pdf' => self::Pdf,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint' => self::Pptx,
            default => null,
        };
    }

    /** Presentations are converted to PDF so they can be viewed inline. */
    public function needsPreview(): bool
    {
        return $this === self::Pptx;
    }

    /**
     * The server-side mime allow-list. Anything not here (e.g. executables) is
     * rejected at validation — the extension is never trusted.
     *
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'video/webm', 'video/quicktime',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
        ];
    }
}
