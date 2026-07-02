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
     * they share the same PDF-preview path.
     */
    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => self::Photo,
            'video/mp4', 'video/webm', 'video/quicktime' => self::Video,
            'application/pdf' => self::Pdf,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation' => self::Pptx,
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text' => self::Docx,
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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
            // Presentations (PowerPoint + OpenDocument)
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation',
            // Word-processor documents (Word + OpenDocument)
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            // Spreadsheets (Excel + OpenDocument)
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
        ];
    }
}
