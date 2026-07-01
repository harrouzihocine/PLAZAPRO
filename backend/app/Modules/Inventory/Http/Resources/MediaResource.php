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
        $hasPreview = $this->type === MediaType::Pptx;

        return [
            'id' => $this->id,
            'collection' => $this->collection,
            'type' => $this->type?->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'version' => $this->version,
            'sort_order' => $this->sort_order,
            'preview_status' => $this->preview_status,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            // Permission-gated streaming endpoints (private disk — no direct URLs).
            'file_url' => route('media.file', $this->id),
            'preview_url' => $hasPreview ? route('media.preview', $this->id) : route('media.file', $this->id),
            'created_at' => $this->created_at,
        ];
    }
}
