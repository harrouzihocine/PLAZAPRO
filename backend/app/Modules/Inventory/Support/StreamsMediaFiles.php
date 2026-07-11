<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a media file from a private disk — shared by the permission-gated
 * MediaController and the public showcase's PublicMediaController (which does
 * its own published-only authorization before calling this).
 */
trait StreamsMediaFiles
{
    /**
     * Serve a file from a private disk. Local disks stream with byte-range
     * support (needed for video seeking); remote disks redirect to a short-lived
     * signed URL.
     */
    private function stream(string $disk, string $path, string $mime, string $name, string $disposition = 'inline'): Response
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $contentDisposition = $disposition.'; filename="'.addslashes($name).'"';

        // Local disks stream from the filesystem (byte-range support for video
        // seeking). Remote disks (S3) hand back a short-lived signed URL.
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return response()->file($storage->path($path), [
                'Content-Type' => $mime,
                'Content-Disposition' => $contentDisposition,
            ]);
        }

        // Ask the signed URL to force the download disposition where supported (S3).
        $options = $disposition === 'attachment' ? ['ResponseContentDisposition' => $contentDisposition] : [];

        return redirect($storage->temporaryUrl($path, now()->addMinutes(5), $options));
    }
}
