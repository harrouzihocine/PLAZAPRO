<?php

declare(strict_types=1);

namespace App\Core\Media;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared file plumbing for the media-processing jobs (OptimizeMedia,
 * OptimizeAttachment): temp workdir lifecycle, memory-safe disk↔local copies
 * (a source video can be 200 MB — never buffer it into a PHP string), and the
 * grace-window delete that lets in-flight streaming requests finish against
 * the pre-swap bytes.
 */
trait InteractsWithMediaFiles
{
    /** Create a private scratch dir for this run; caller must cleanupWorkDir(). */
    private function makeWorkDir(string $prefix): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/'.$prefix.'-'.Str::uuid();
        @mkdir($dir, 0700, true);

        return $dir;
    }

    private function cleanupWorkDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        // Recursive: LibreOffice runs leave a nested profile dir in the workdir.
        File::deleteDirectory($dir);
    }

    /** Stream a stored file into the local workdir without loading it into memory. */
    private function copyFromDisk(string $disk, string $path, string $dest): void
    {
        $in = Storage::disk($disk)->readStream($path);
        $out = fopen($dest, 'wb');

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /** Stream a local file onto a disk without loading it into memory. */
    private function putToDisk(string $disk, string $path, string $local): void
    {
        $stream = fopen($local, 'rb');

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Delete a superseded file after a grace window instead of immediately: a
     * request that resolved the row just before the swap can still be streaming
     * the old bytes. Queued on the app's default connection (sync in tests →
     * immediate, which the assertions rely on). Best-effort — an orphaned file
     * is a cleanup detail, not a failure.
     */
    private function deleteAfterGrace(string $disk, string $path): void
    {
        dispatch(function () use ($disk, $path): void {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
            }
        })->delay(now()->addMinutes(10));
    }
}
