<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

use App\Core\Media\ImageOptimizer;
use App\Core\Media\InteractsWithMediaFiles;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Render an office document to a PDF (via LibreOffice headless) so it can be
 * viewed inline in the SPA, exactly like a native PDF. Presentations go one
 * step further: every PDF page is rasterized to its own WebP (SlideShare-style)
 * so the frontend can page through the deck fullscreen like PowerPoint, and the
 * first slide becomes the gallery thumbnail.
 *
 * Runs on the dedicated `media` worker (its image ships `soffice`, the
 * vips-poppler PDF loader and the fonts decks need). On success it stores the
 * derived files and marks the preview ready; on failure it marks it failed
 * (the original is unaffected and still downloadable).
 */
class MakeMediaPreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithMediaFiles;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $mediaId)
    {
        $this->connection = 'media';
        $this->queue = 'media';
    }

    public function handle(ImageOptimizer $images): void
    {
        $media = Media::find($this->mediaId);
        if ($media === null || $this->alreadyDone($media)) {
            return;
        }

        $work = $this->makeWorkDir('media-preview');

        try {
            // Rows converted before the slide pipeline existed reuse their
            // stored PDF — no need to run LibreOffice again on a backfill.
            if ($media->preview_status === 'ready' && $media->preview_path !== null) {
                $pdf = $work.'/preview.pdf';
                $this->copyFromDisk($media->disk, $media->preview_path, $pdf);
            } else {
                $pdf = $this->convertToPdf($media, $work);
                $previewPath = 'previews/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.pdf';
                $this->putToDisk($media->disk, $previewPath, $pdf);
                $media->preview_path = $previewPath;
            }

            if ($media->type === MediaType::Pptx) {
                $this->renderSlides($media, $images, $work, $pdf);
            }

            $media->preview_status = 'ready';
            $media->save();
        } catch (\Throwable $e) {
            // Query update, not $media->update(): the model may carry half-set
            // slide/preview attributes from before the failure — never persist those.
            Media::whereKey($media->id)->update(['preview_status' => 'failed']);
            report($e);
        } finally {
            $this->cleanupWorkDir($work);
        }
    }

    public function failed(\Throwable $e): void
    {
        Media::whereKey($this->mediaId)->update(['preview_status' => 'failed']);
    }

    /** Ready rows only re-run when a presentation still lacks its slides. */
    private function alreadyDone(Media $media): bool
    {
        return $media->preview_status === 'ready'
            && ($media->type !== MediaType::Pptx || $media->slide_count !== null);
    }

    private function convertToPdf(Media $media, string $work): string
    {
        $source = $work.'/'.basename($media->path);
        $this->copyFromDisk($media->disk, $media->path, $source);

        $process = new Process([
            config('services.libreoffice.bin'),
            '--headless',
            // Private profile per run: concurrent conversions sharing the
            // default profile deadlock on its lock file.
            '-env:UserInstallation=file://'.$work.'/lo-profile',
            '--convert-to', 'pdf', '--outdir', $work, $source,
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        $pdf = $work.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
        if (! $process->isSuccessful() || ! is_file($pdf)) {
            throw new \RuntimeException('LibreOffice conversion failed: '.$process->getErrorOutput());
        }

        return $pdf;
    }

    /**
     * Rasterize every PDF page into `{slides_path}/0001.webp`, `0002.webp`, …
     * on the media disk and make the first slide the gallery thumbnail.
     */
    private function renderSlides(Media $media, ImageOptimizer $images, string $work, string $pdf): void
    {
        $cfg = config('media.slides');
        $pages = min($images->pdfPageCount($pdf), max(1, (int) $cfg['max_pages']));

        $dir = 'slides/'.now()->format('Y/m').'/'.Str::uuid()->toString();
        for ($page = 1; $page <= $pages; $page++) {
            $slide = $work.'/slide.webp';
            // vips page indices are 0-based; dpi renders dense enough that the
            // max_edge cap downscales (crisp) instead of upscaling (soft).
            $images->toWebp(
                sprintf('%s[page=%d,dpi=%d]', $pdf, $page - 1, $cfg['dpi']),
                $slide, $cfg['max_edge'], $cfg['quality'],
            );
            $this->putToDisk($media->disk, sprintf('%s/%04d.webp', $dir, $page), $slide);
            @unlink($slide);
        }

        if ($media->thumb_path === null) {
            $thumbCfg = config('media.optimize.image');
            $images->toWebp(
                sprintf('%s[page=0,dpi=%d]', $pdf, $cfg['dpi']),
                $work.'/thumb.webp', $thumbCfg['thumb_edge'], $thumbCfg['thumb_quality'],
            );
            $thumbPath = 'thumbs/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.webp';
            $this->putToDisk($media->disk, $thumbPath, $work.'/thumb.webp');
            $media->thumb_path = $thumbPath;
        }

        $media->slides_path = $dir;
        $media->slide_count = $pages;
    }
}
