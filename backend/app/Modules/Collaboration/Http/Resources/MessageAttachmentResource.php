<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageAttachment
 */
class MessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'duration_ms' => $this->duration_ms,
            'width' => $this->width,
            'height' => $this->height,
            // Participant-gated streaming endpoint (never a public URL).
            'url' => '/api/v1/attachments/'.$this->id,
        ];
    }
}
