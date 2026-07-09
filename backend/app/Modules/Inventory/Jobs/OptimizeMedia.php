<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Core\Media\ImageOptimizer;
use App\Core\Media\InteractsWithMediaFiles;
use App\Core\Media\VideoOptimizer;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Optimize an uploaded photo/video in place (owner decision 2026-07-09: the
 * smaller visually-lossless file REPLACES the original bytes) and produce a
 * grid derivative — photo thumbnail or video poster frame.
 *
 *  photo → WebP (longest edge capped) + a small WebP thumbnail.
 *  video → 1080p-class faststart H.264/AAC MP4 (or a lossless remux when the
 *          source is already efficient) + a WebP poster frame.
 *
 * Guard rails: if the re-encode comes out larger than the source the original
 * bytes are kept (still `ready` — thumbnails are generated regardless); on any
 * failure the row is marked `failed` and the original stays untouched and
 * fully servable. Runs on the dedicated `media` queue connection — encodes can
 * outlive the default workers' 120 s timeout by an order of magnitude.
 */
class OptimizeMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithMediaFiles;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $mediaId)
    {
        $this->connection = 'media';
        $this->queue = 'media';
    }

    public function handle(ImageOptimizer $images, VideoOptimizer $videos): void
    {
        $media = Media::find($this->mediaId);
        if ($media === null || $media->optimize_status !== 'pending') {
            return; // gone, already processed, or not marked for the pipeline
        }

        // Rows cancelled while queued (fast replace/remove) keep their history
        // bytes as-is — no point spending encoder time on them.
        if (! $media->isActive() || ! config('media.optimize.enabled')) {
            $media->update(['optimize_status' => 'skipped']);

            return;
        }

        $work = $this->makeWorkDir('media-optimize');

        try {
            $source = $work.'/source'.$this->sourceExtension($media);
            // Streamed — a source video can be 200 MB.
            $this->copyFromDisk($media->disk, $media->path, $source);

            match ($media->type) {
                MediaType::Photo => $this->optimizePhoto($media, $images, $work, $source),
                MediaType::Video => $this->optimizeVideo($media, $images, $videos, $work, $source),
                default => $media->update(['optimize_status' => 'skipped']),
            };
        } catch (\Throwable $e) {
            $media->update(['optimize_status' => 'failed']);
            report($e);
        } finally {
            $this->cleanupWorkDir($work);
        }
    }

    public function failed(\Throwable $e): void
    {
        Media::whereKey($this->mediaId)->update(['optimize_status' => 'failed']);
    }

    private function optimizePhoto(Media $media, ImageOptimizer $images, string $work, string $source): void
    {
        $cfg = config('media.optimize.image');

        // Thumbnail first — generated even when the main swap is skipped.
        // (For an animated GIF this is its first frame, which is exactly
        // what a grid tile needs.)
        $thumb = $images->toWebp($source, $work.'/thumb.webp', $cfg['thumb_edge'], $cfg['thumb_quality']);
        $thumbPath = $this->storeDerivative($media, $work.'/thumb.webp');

        // Animated GIFs keep their original bytes — a still WebP would freeze
        // them, and animated re-encoding isn't worth the edge case.
        if ($media->mime_type === 'image/gif') {
            $this->keepOriginal($media, $images, $source, $thumbPath);

            return;
        }

        $main = $images->toWebp($source, $work.'/main.webp', $cfg['max_edge'], $cfg['quality']);

        if ($main['bytes'] < $media->size_bytes) {
            $this->swapFile($media, $work.'/main.webp', 'webp', 'image/webp', [
                'optimize_status' => 'ready', 'thumb_path' => $thumbPath,
                'width' => $main['width'], 'height' => $main['height'],
            ]);
        } else {
            // Source is already tighter than our re-encode — keep it.
            $this->keepOriginal($media, $images, $source, $thumbPath);
        }
    }

    /** Mark ready without swapping bytes: record the original's facts + thumb. */
    private function keepOriginal(Media $media, ImageOptimizer $images, string $source, string $thumbPath): void
    {
        [$w, $h] = $images->dimensions($source);
        $media->update([
            'optimize_status' => 'ready', 'thumb_path' => $thumbPath,
            'width' => $w ?: null, 'height' => $h ?: null,
        ]);
    }

    private function optimizeVideo(Media $media, ImageOptimizer $images, VideoOptimizer $videos, string $work, string $source): void
    {
        $cfg = config('media.optimize.video');
        $probe = $videos->probe($source);

        $out = $work.'/out.mp4';
        if ($videos->canRemux($probe, $cfg['max_edge'], $cfg['copy_bitrate_threshold'])) {
            $videos->remux($source, $out);
        } else {
            $videos->transcode(
                $source, $out,
                $cfg['max_edge'], $cfg['crf'], $cfg['preset'], $cfg['audio_bitrate'],
            );
        }

        $swap = filesize($out) < $media->size_bytes;
        $final = $swap ? $out : $source;

        // Poster frame from the file we will actually serve — a quarter of the
        // way in (capped at 3 s) so fade-in/logo openings don't yield a black
        // tile, without seeking deep into long clips.
        $videos->posterFrame($final, $work.'/poster.png', min(3.0, $probe['duration'] / 4));
        $images->toWebp($work.'/poster.png', $work.'/poster.webp', $cfg['poster_edge'], $cfg['poster_quality']);
        $thumbPath = $this->storeDerivative($media, $work.'/poster.webp');

        $finalProbe = $swap ? $videos->probe($out) : $probe;
        $facts = [
            'optimize_status' => 'ready', 'thumb_path' => $thumbPath,
            'width' => $finalProbe['width'] ?: null, 'height' => $finalProbe['height'] ?: null,
            'duration_seconds' => (int) round($finalProbe['duration']) ?: null,
        ];

        if ($swap) {
            $this->swapFile($media, $out, 'mp4', 'video/mp4', $facts);
        } else {
            $media->update($facts);
        }
    }

    /** Store a derivative (thumb/poster) on the media disk, streamed from disk. */
    private function storeDerivative(Media $media, string $local): string
    {
        $path = 'thumbs/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.webp';
        $this->putToDisk($media->disk, $path, $local);

        return $path;
    }

    /**
     * Replace the original bytes with the optimized file: write the new file,
     * flip the row (path/mime/size + optimization facts, keeping the original
     * size for the audit trail), then drop the old file after a grace window —
     * a request that resolved the row pre-swap may still be streaming it.
     */
    private function swapFile(Media $media, string $local, string $ext, string $mime, array $facts): void
    {
        $oldPath = $media->path;
        $newPath = 'uploads/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$ext;
        $this->putToDisk($media->disk, $newPath, $local);

        $media->update($facts + [
            'path' => $newPath,
            'mime_type' => $mime,
            'size_bytes' => (int) filesize($local),
            'original_size_bytes' => $media->size_bytes,
        ]);

        $this->deleteAfterGrace($media->disk, $oldPath);
    }

    /** ffmpeg/vips sniff content, but a real extension helps demuxer selection. */
    private function sourceExtension(Media $media): string
    {
        $ext = strtolower(pathinfo($media->path, PATHINFO_EXTENSION));

        return $ext !== '' ? '.'.$ext : '';
    }
}
