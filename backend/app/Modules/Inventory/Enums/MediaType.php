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
    case Docx = 'docx';
    case Xlsx = 'xlsx';

    /**
     * Map a validated mime type to a MediaType, or null if unsupported. Office
     * formats collapse to one type per family (MS + OpenDocument alike) since
     * they share the same PDF-preview path. Each family lists what PowerPoint /
     * Word / Excel actually emit: plain, slideshow/template, and macro-enabled
     * variants — LibreOffice converts them all.
     */
    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => self::Photo,
            'video/mp4', 'video/webm', 'video/quicktime' => self::Video,
            'application/pdf' => self::Pdf,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'application/vnd.openxmlformats-officedocument.presentationml.template',
            'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation' => self::Pptx,
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'application/vnd.ms-word.document.macroEnabled.12',
            'application/vnd.oasis.opendocument.text' => self::Docx,
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.oasis.opendocument.spreadsheet' => self::Xlsx,
            default => null,
        };
    }

    /**
     * Office documents (presentations, word docs, spreadsheets) are converted to
     * PDF by MakeMediaPreview so they can be viewed inline — nothing is downloaded.
     */
    public function needsPreview(): bool
    {
        return in_array($this, [self::Pptx, self::Docx, self::Xlsx], true);
    }

    /**
     * Photos and videos go through the OptimizeMedia pipeline (WebP / 1080p
     * H.264 re-encode + grid derivative). Documents are stored verbatim.
     */
    public function needsOptimization(): bool
    {
        return in_array($this, [self::Photo, self::Video], true);
    }

}
