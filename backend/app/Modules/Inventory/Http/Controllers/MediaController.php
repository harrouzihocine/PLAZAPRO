<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\CancelMedia;
use App\Modules\Inventory\Actions\ReorderMedia;
use App\Modules\Inventory\Actions\ReplaceMedia;
use App\Modules\Inventory\Actions\UploadMedia;
use App\Modules\Inventory\Http\Requests\ReorderMediaRequest;
use App\Modules\Inventory\Http\Requests\ReplaceMediaRequest;
use App\Modules\Inventory\Http\Requests\UploadMediaRequest;
use App\Modules\Inventory\Http\Resources\MediaResource;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Polymorphic media. Reads/streaming require units.view; uploads, reorders,
 * replacements and removals require media.manage. Files live on a private disk
 * and are served only through the permission-gated streaming endpoints below.
 */
class MediaController extends Controller
{
    /** Morph aliases that can carry media. */
    private const MEDIABLES = [
        'locations' => Location::class,
        'units' => Unit::class,
    ];

    public function index(Request $request, string $mediableType, int $mediableId): AnonymousResourceCollection
    {
        $mediable = $this->resolveMediable($mediableType, $mediableId);

        $media = $mediable->media()
            ->active()
            ->when($request->filled('collection'), fn ($q) => $q->where('collection', $request->query('collection')))
            ->orderBy('collection')
            ->orderBy('sort_order')
            ->get();

        return MediaResource::collection($media);
    }

    public function store(UploadMediaRequest $request, string $mediableType, int $mediableId, UploadMedia $action): MediaResource
    {
        $mediable = $this->resolveMediable($mediableType, $mediableId);

        $media = $action->handle(
            $mediable,
            $request->file('file'),
            $request->input('collection'),
            $request->user(),
        );

        return new MediaResource($media);
    }

    public function reorder(ReorderMediaRequest $request, string $mediableType, int $mediableId, ReorderMedia $action): AnonymousResourceCollection
    {
        $mediable = $this->resolveMediable($mediableType, $mediableId);
        $action->handle($mediable, $request->validated('order'));

        return MediaResource::collection($mediable->media()->active()->orderBy('sort_order')->get());
    }

    public function replace(ReplaceMediaRequest $request, Media $media, ReplaceMedia $action): MediaResource
    {
        return new MediaResource($action->handle($media, $request->file('file'), $request->user()));
    }

    public function destroy(Request $request, Media $media, CancelMedia $action): MediaResource
    {
        $reason = (string) $request->input('reason', 'Removed by user');

        return new MediaResource($action->handle($media, $reason));
    }

    /** Stream the original file inline (private disk — never a public URL). */
    public function file(Media $media): Response
    {
        return $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name);
    }

    /** Stream the inline preview (PPTX's derived PDF), falling back to the original. */
    public function preview(Media $media): Response
    {
        if ($media->preview_path !== null && $media->preview_status === 'ready') {
            return $this->stream($media->disk, $media->preview_path, 'application/pdf', 'preview.pdf');
        }

        return $this->file($media);
    }

    /** Download the original file as an attachment (keeps the original name). */
    public function download(Media $media): Response
    {
        return $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name, 'attachment');
    }

    private function resolveMediable(string $type, int $id): Model
    {
        abort_unless(isset(self::MEDIABLES[$type]), 404);

        return self::MEDIABLES[$type]::query()->findOrFail($id);
    }

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
