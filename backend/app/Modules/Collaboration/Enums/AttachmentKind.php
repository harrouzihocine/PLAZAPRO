<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Enums;

/**
 * The kind of chat attachment. Derived server-side from the validated mime type
 * — never trusted from the client or the extension (same rule as inventory
 * media). Each kind carries its own mime allow-list and size ceiling.
 */
enum AttachmentKind: string
{
    case Image = 'image';
    case Voice = 'voice';
    case File = 'file';

    /**
     * Map a validated mime type to a kind, or null if unsupported.
     */
    public static function fromMime(string $mime): ?self
    {
        return match (true) {
            in_array($mime, self::imageMimes(), true) => self::Image,
            in_array($mime, self::voiceMimes(), true) => self::Voice,
            in_array($mime, self::fileMimes(), true) => self::File,
            default => null,
        };
    }

    /** The corresponding message type for a message whose payload is this kind. */
    public function messageType(): MessageType
    {
        return match ($this) {
            self::Image => MessageType::Image,
            self::Voice => MessageType::Voice,
            self::File => MessageType::File,
        };
    }

    /** Per-kind upload ceiling in kilobytes (validation `max:`). */
    public function maxKb(): int
    {
        return match ($this) {
            self::Image => 10 * 1024,  // 10 MB
            self::Voice => 25 * 1024,  // 25 MB
            self::File => 25 * 1024,   // 25 MB
        };
    }

    /**
     * The full server-side mime allow-list for chat attachments. Anything not
     * here is rejected at validation.
     *
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return [...self::imageMimes(), ...self::voiceMimes(), ...self::fileMimes()];
    }

    /** @return list<string> */
    public static function imageMimes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    }

    /** @return list<string> */
    public static function voiceMimes(): array
    {
        return [
            'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
            // Browser MediaRecorder produces webm/ogg *containers*; content-based
            // MIME detection (libmagic) can report an audio-only recording as
            // video/webm|ogg. Chat has no video kind and the file input excludes
            // video, so treating these safe containers as voice makes voice notes
            // reliable without opening a security hole.
            'video/webm', 'video/ogg',
        ];
    }

    /** @return list<string> */
    public static function fileMimes(): array
    {
        return ['application/pdf'];
    }
}
