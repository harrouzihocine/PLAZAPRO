<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Http\Resources\DocumentResource;
use App\Modules\Payments\Models\Document;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generated documents. Metadata reads and file downloads require versements.view;
 * the files live on a private disk and are only ever served through the gated
 * download endpoint below (never a public URL).
 */
class DocumentController extends Controller
{
    public function show(Document $document): DocumentResource
    {
        return new DocumentResource($document);
    }

    /** Stream the generated PDF from the private disk (permission-gated). */
    public function download(Document $document): Response
    {
        abort_unless($document->isReady(), 409, 'The document is still being generated.');

        $disk = $document->disk;
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($document->path), 404);

        $filename = "{$document->number}.pdf";

        // Local disks stream from the filesystem; remote disks hand back a
        // short-lived signed URL (mirrors the media streaming pattern).
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return response()->file($storage->path($document->path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            ]);
        }

        return redirect($storage->temporaryUrl($document->path, now()->addMinutes(5)));
    }
}
