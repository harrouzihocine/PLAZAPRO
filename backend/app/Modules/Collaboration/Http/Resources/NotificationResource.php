<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A single in-app notification for the bell feed. Flattens the `data` payload
 * (built by DomainNotification) onto the row so the frontend can render + route
 * without digging into a nested object.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
