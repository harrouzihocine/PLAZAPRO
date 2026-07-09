<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Jobs\MakeMediaPreview;
use App\Modules\Inventory\Jobs\OptimizeMedia;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store an uploaded file on the private media disk under a randomised name and
 * record it. The media type is derived from the validated mime (never trusted
 * from the extension). PPTX files queue a LibreOffice PDF-preview job so they
 * can be viewed inline.
 */
class UploadMedia
{
    public function handle(Model $mediable, UploadedFile $file, ?string $collection, User $user): Media
    {
        $type = MediaType::fromMime($file->getMimeType());
        abort_if($type === null, 422, 'Unsupported media type.');

        // Land in the catch-all bucket when no tab was specified. Resolved before
        // use so both the per-collection sort_order and the stored row agree.
        $collection = ($collection === null || $collection === '')
            ? MediaCollection::default()->value
            : $collection;

        return DB::transaction(function () use ($mediable, $file, $collection, $user, $type) {
            // Derive the extension from the detected mime, never the client name.
            $ext = strtolower((string) $file->extension());
            $path = $file->storeAs(
                'uploads/'.now()->format('Y/m'),
                Str::uuid()->toString().($ext !== '' ? '.'.$ext : ''),
                ['disk' => 'media'],
            );

            $nextSort = (int) $mediable->media()->where('collection', $collection)->max('sort_order') + 1;

            $media = $mediable->media()->make([
                'collection' => $collection,
                'type' => $type->value,
                'disk' => 'media',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sort_order' => $nextSort,
                'preview_status' => $type->needsPreview() ? 'pending' : null,
                'optimize_status' => $type->needsOptimization() ? 'pending' : null,
            ]);
            $media->uploaded_by = $user->id; // privileged field, set by the Action
            $media->save();

            if ($type->needsPreview()) {
                MakeMediaPreview::dispatch($media->id);
            }

            // afterCommit: the media worker is a separate process — don't let it
            // race the transaction and find no row.
            if ($type->needsOptimization()) {
                OptimizeMedia::dispatch($media->id)->afterCommit();
            }

            return $media;
        });
    }
}
