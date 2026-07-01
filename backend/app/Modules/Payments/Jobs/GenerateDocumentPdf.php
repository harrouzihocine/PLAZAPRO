<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\Document;
use App\Modules\Payments\Support\Contracts\PdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders a Document's branded PDF on the queue worker and writes it to the
 * private disk, then flips render_status to ready. Idempotent: re-running simply
 * re-renders from the snapshotted meta. Failures mark the row failed.
 */
class GenerateDocumentPdf implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Document $document) {}

    public function handle(PdfRenderer $renderer): void
    {
        $document = $this->document;

        try {
            $pdf = $renderer->render($document->template, $document->meta ?? []);

            Storage::disk($document->disk)->put($document->path, $pdf);

            $document->update(['render_status' => 'ready']);
        } catch (Throwable $e) {
            $document->update(['render_status' => 'failed']);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->document->update(['render_status' => 'failed']);
    }
}
