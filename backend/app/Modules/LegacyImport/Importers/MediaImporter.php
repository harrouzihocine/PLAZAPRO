<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Support\Facades\Storage;

/**
 * §5.15 — the 3 legacy Spatie media rows (photos of EstateCategory #70) →
 * media rows on the location, private `media` disk, path
 * legacy/<legacy id>/<file_name>. Files are NOT in the SQL dump: they need a
 * one-time rsync from the old server into storage/app/media/legacy/… — the
 * importer only writes rows and warns when the file is missing. Photos need
 * no preview (preview_status stays NULL).
 */
class MediaImporter extends BaseImporter
{
    public function phase(): string
    {
        return 'media';
    }

    protected function sourceTable(): string
    {
        return 'media';
    }

    protected function targetTable(): string
    {
        return 'media';
    }

    protected function beforeRun(): void
    {
        $this->ctx->preloadMap('estate_categories');
    }

    protected function map(object $row): ?array
    {
        if (! str_ends_with((string) $row->model_type, 'EstateCategory')) {
            $this->ctx->warn('media_unknown_model', "Legacy media #{$row->id} targets {$row->model_type} — not covered by the plan; skipped.");

            return null;
        }

        $type = MediaType::fromMime((string) $row->mime_type);
        if ($type === null) {
            $this->ctx->warn('media_unknown_mime', "Legacy media #{$row->id} has unsupported mime {$row->mime_type} — imported as photo.");
        }

        $path = "legacy/{$row->id}/{$row->file_name}";
        if (! Storage::disk('media')->exists($path)) {
            $this->ctx->warn('media_file_missing', "Media file not found on the media disk: {$path} — rsync it from the old server (storage/app/public/{$row->id}/{$row->file_name}).");
        }

        return [
            'mediable_type' => 'location',
            'mediable_id' => $this->ctx->requireMapId('estate_categories', $row->model_id),
            'collection' => 'photos',
            'type' => ($type ?? MediaType::Photo)->value,
            'disk' => 'media',
            'path' => $path,
            'original_name' => $row->name,
            'mime_type' => $row->mime_type,
            'size_bytes' => (int) $row->size,
            'sort_order' => (int) ($row->order_column ?? 0),
            'version' => 1,
            'uploaded_by' => $this->ctx->requireSystemUserId(),
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }
}
