<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Jobs\MakeMediaPreview;
use App\Modules\Inventory\Jobs\OptimizeMedia;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replace a media file with a new version: store the new file, insert a new
 * media row (version + 1, linked via supersedes_id) at the same position, and
 * cancel the old row. The old version's file is kept (history stays viewable;
 * only OptimizeMedia ever swaps a row's own bytes for the smaller re-encode).
 */
class ReplaceMedia
{
    public function handle(Media $media, UploadedFile $file, User $user): Media
    {
        $type = MediaType::fromMime($file->getMimeType());
        abort_if($type === null, 422, 'Unsupported media type.');

        return DB::transaction(function () use ($media, $file, $user, $type) {
            // Derive the extension from the detected mime, never the client name.
            $ext = strtolower((string) $file->extension());
            $path = $file->storeAs(
                'uploads/'.now()->format('Y/m'),
                Str::uuid()->toString().($ext !== '' ? '.'.$ext : ''),
                ['disk' => 'media'],
            );

            $replacement = new Media([
                'collection' => $media->collection,
                'type' => $type->value,
                'disk' => 'media',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sort_order' => $media->sort_order,
                'preview_status' => $type->needsPreview() ? 'pending' : null,
                'optimize_status' => $type->needsOptimization() ? 'pending' : null,
            ]);
            $replacement->mediable_type = $media->mediable_type;
            $replacement->mediable_id = $media->mediable_id;
            $replacement->uploaded_by = $user->id;
            $replacement->version = $media->version + 1;
            $replacement->supersedes_id = $media->id;
            $replacement->save();

            $media->cancel('Replaced by version '.$replacement->version);

            if ($type->needsPreview()) {
                MakeMediaPreview::dispatch($replacement->id);
            }

            // afterCommit: the media worker must not race the transaction.
            if ($type->needsOptimization()) {
                OptimizeMedia::dispatch($replacement->id)->afterCommit();
            }

            return $replacement;
        });
    }
}
