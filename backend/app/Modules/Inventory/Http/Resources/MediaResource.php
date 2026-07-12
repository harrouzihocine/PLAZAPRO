<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasPreview = $this->type?->needsPreview() ?? false;

        // Cache-buster: optimization swaps the bytes behind a stable id (jpeg →
        // webp, mov → mp4), so URLs carry the row's last-touched timestamp. When
        // the pipeline finishes, the URL changes and clients re-fetch — otherwise
        // an open gallery would keep the full-res fallback for the whole session.
        $v = $this->updated_at ? '?v='.$this->updated_at->getTimestamp() : '';
        $fileUrl = route('media.file', $this->id).$v;

        return [
            'id' => $this->id,
            'collection' => $this->collection?->value,
            'type' => $this->type?->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'version' => $this->version,
            'sort_order' => $this->sort_order,
            // Public-showcase visibility (the globe toggle in the gallery).
            'is_public' => (bool) $this->is_public,
            'preview_status' => $this->preview_status,
            // Optimization pipeline: pending | ready | failed | skipped (null =
            // untouched types / rows predating the pipeline).
            'optimize_status' => $this->optimize_status,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            // Permission-gated streaming endpoints (private disk — no direct URLs).
            'file_url' => $fileUrl,
            // Grid derivative: photos always resolve (endpoint falls back to the
            // original until optimized); videos only once their poster exists.
            'thumb_url' => $this->type === MediaType::Photo || $this->thumb_path !== null
                ? route('media.thumb', $this->id).$v
                : null,
            'preview_url' => $hasPreview ? route('media.preview', $this->id) : $fileUrl,
            // Presentation decks: one URL per rasterized slide (WebP), in order.
            // Empty until MakeMediaPreview has rendered them.
            'slide_count' => $this->slide_count,
            'slide_urls' => $this->slide_count
                ? array_map(
                    fn (int $page) => route('media.slide', ['media' => $this->id, 'page' => $page]).$v,
                    range(1, $this->slide_count),
                )
                : [],
            'created_at' => $this->created_at,
        ];
    }
}
