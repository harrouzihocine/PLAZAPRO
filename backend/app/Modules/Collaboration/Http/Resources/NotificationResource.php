<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Notifications\DomainNotification;
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

        $title = $data['title'] ?? null;
        $body = $data['body'] ?? null;

        // Keyed rows re-render in the reader's CURRENT locale (the SPA sends it
        // as Accept-Language, so switching language then re-fetching relabels
        // the whole feed). Rows written before this shipped — and free-text
        // ones that never had a key — keep their stored send-time strings.
        if (! empty($data['key'])) {
            $rendered = DomainNotification::localize($data['key'], $data['params'] ?? []);
            $title = $rendered['title'];
            $body = $rendered['body'] ?? $body;
        }

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? null,
            'title' => $title,
            'body' => $body,
            'link' => $data['link'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
