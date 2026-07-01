<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'number' => $this->number,
            'version' => $this->version,
            'render_status' => $this->render_status,
            'generated_at' => optional($this->generated_at)->toIso8601String(),
            'status' => $this->status?->value,
            // Relative URL so cookie auth works for the streamed download.
            'download_url' => $this->isReady() ? "/api/v1/documents/{$this->id}/download" : null,
        ];
    }
}
