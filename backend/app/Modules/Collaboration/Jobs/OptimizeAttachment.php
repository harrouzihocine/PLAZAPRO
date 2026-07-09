<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Jobs;

use App\Core\Media\ImageOptimizer;
use App\Core\Media\InteractsWithMediaFiles;
use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Models\MessageAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Re-encode a chat image in place to a capped WebP (bubbles and the lightbox
 * share the one file — chat needs no thumbnail derivative). Runs on the default
 * queue: libvips finishes a 10 MB photo in well under a second, so chat stays
 * snappy. Same guard rails as OptimizeMedia — a larger re-encode or any failure
 * leaves the original untouched and servable. Voice notes and PDFs never enter
 * this pipeline.
 */
class OptimizeAttachment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithMediaFiles;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Must stay BELOW the default connection's retry_after (90 s) — otherwise a
    // still-running encode gets re-delivered to a second worker mid-swap. libvips
    // finishes a 10 MB image in well under a second; 80 s is generous headroom.
    public int $timeout = 80;

    public int $tries = 2;

    public function __construct(public int $attachmentId) {}

    public function handle(ImageOptimizer $images): void
    {
        $attachment = MessageAttachment::find($this->attachmentId);
        if ($attachment === null || $attachment->optimize_status !== 'pending') {
            return;
        }

        // Only images; animated GIFs keep their frames (a WebP would freeze them).
        if ($attachment->kind !== AttachmentKind::Image
            || $attachment->mime_type === 'image/gif'
            || ! config('media.optimize.enabled')) {
            $attachment->update(['optimize_status' => 'skipped']);

            return;
        }

        $cfg = config('media.optimize.image');
        $work = $this->makeWorkDir('attachment-optimize');

        try {
            $ext = strtolower(pathinfo($attachment->path, PATHINFO_EXTENSION));
            $source = $work.'/source'.($ext !== '' ? '.'.$ext : '');
            $this->copyFromDisk($attachment->disk, $attachment->path, $source);

            $out = $images->toWebp($source, $work.'/out.webp', $cfg['chat_max_edge'], $cfg['chat_quality']);

            if ($out['bytes'] >= $attachment->size_bytes) {
                $attachment->update(['optimize_status' => 'ready']); // source already tighter

                return;
            }

            $oldPath = $attachment->path;
            $newPath = 'attachments/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.webp';
            $this->putToDisk($attachment->disk, $newPath, $work.'/out.webp');

            $attachment->update([
                'path' => $newPath,
                'mime_type' => 'image/webp',
                'size_bytes' => $out['bytes'],
                'original_size_bytes' => $attachment->size_bytes,
                'width' => $out['width'],
                'height' => $out['height'],
                'optimize_status' => 'ready',
            ]);

            // Grace window: a recipient's client may still be streaming the
            // original bubble image it resolved just before the swap.
            $this->deleteAfterGrace($attachment->disk, $oldPath);
        } catch (\Throwable $e) {
            $attachment->update(['optimize_status' => 'failed']);
            report($e);
        } finally {
            $this->cleanupWorkDir($work);
        }
    }

    public function failed(\Throwable $e): void
    {
        MessageAttachment::whereKey($this->attachmentId)->update(['optimize_status' => 'failed']);
    }
}
