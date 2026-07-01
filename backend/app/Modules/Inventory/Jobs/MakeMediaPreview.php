<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Jobs;

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
 * Render a PPTX to a PDF (via LibreOffice headless) so it can be viewed inline
 * in the SPA, exactly like a native PDF. Runs on the queue worker, which has the
 * `soffice` binary installed. On success it stores the derived PDF and marks the
 * media preview ready; on failure it marks it failed (the original is unaffected).
 */
class MakeMediaPreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);
        if ($media === null || $media->preview_status === 'ready') {
            return;
        }

        $disk = Storage::disk($media->disk);
        $work = rtrim(sys_get_temp_dir(), '/').'/media-preview-'.Str::uuid();

        try {
            @mkdir($work, 0700, true);
            $source = $work.'/'.basename($media->path);
            file_put_contents($source, $disk->get($media->path));

            $process = new Process([
                config('services.libreoffice.bin'),
                '--headless', '--convert-to', 'pdf', '--outdir', $work, $source,
            ]);
            $process->setTimeout($this->timeout);
            $process->run();

            $pdf = $work.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
            if (! $process->isSuccessful() || ! is_file($pdf)) {
                throw new \RuntimeException('LibreOffice conversion failed: '.$process->getErrorOutput());
            }

            $previewPath = 'previews/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.pdf';
            $disk->put($previewPath, file_get_contents($pdf));

            $media->update(['preview_path' => $previewPath, 'preview_status' => 'ready']);
        } catch (\Throwable $e) {
            $media->update(['preview_status' => 'failed']);
            report($e);
        } finally {
            $this->cleanup($work);
        }
    }

    public function failed(\Throwable $e): void
    {
        Media::whereKey($this->mediaId)->update(['preview_status' => 'failed']);
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
