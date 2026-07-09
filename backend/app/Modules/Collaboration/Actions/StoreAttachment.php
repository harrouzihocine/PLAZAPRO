<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Jobs\OptimizeAttachment;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Store a chat attachment on the private `chat` disk under a randomised name and
 * record it. The kind is derived from the validated mime (never trusted from the
 * extension) — same hardening as inventory media (UploadMedia). Voice notes carry
 * a duration; images capture their pixel dimensions.
 */
class StoreAttachment
{
    public function handle(Message $message, UploadedFile $file, ?int $durationMs = null): MessageAttachment
    {
        $kind = AttachmentKind::fromMime((string) $file->getMimeType());
        abort_if($kind === null, 422, 'Unsupported attachment type.');

        // Per-kind size ceiling (e.g. images capped tighter than voice/files).
        abort_if($file->getSize() > $kind->maxKb() * 1024, 422, 'Attachment exceeds the size limit for its type.');

        // Derive the extension from the detected mime, never the client name.
        $ext = strtolower((string) $file->extension());
        $path = $file->storeAs(
            'attachments/'.now()->format('Y/m'),
            Str::uuid()->toString().($ext !== '' ? '.'.$ext : ''),
            ['disk' => 'chat'],
        );

        [$width, $height] = $this->dimensions($kind, $file);

        // Photos get an async in-place WebP re-encode (animated GIFs excluded —
        // OptimizeAttachment marks them skipped). Voice notes and PDFs are
        // already tight formats and are stored verbatim.
        $optimizes = $kind === AttachmentKind::Image && $file->getMimeType() !== 'image/gif';

        $attachment = $message->attachments()->create([
            'kind' => $kind->value,
            'disk' => 'chat',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'duration_ms' => $kind === AttachmentKind::Voice ? $durationMs : null,
            'width' => $width,
            'height' => $height,
            'optimize_status' => $optimizes ? 'pending' : null,
        ]);

        if ($optimizes) {
            // afterCommit: SendMessage wraps this in a transaction — the worker
            // must not race it and find no row.
            OptimizeAttachment::dispatch($attachment->id)->afterCommit();
        }

        return $attachment;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(AttachmentKind $kind, UploadedFile $file): array
    {
        if ($kind !== AttachmentKind::Image) {
            return [null, null];
        }

        $size = @getimagesize($file->getPathname());

        return $size === false ? [null, null] : [$size[0] ?? null, $size[1] ?? null];
    }
}
