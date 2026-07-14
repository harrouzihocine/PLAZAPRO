<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resources;

use App\Modules\Collaboration\Models\Broadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A sent broadcast for the company-wide history list. The audience is returned
 * structured (type + role name) so the frontend renders the label in the
 * viewer's language; `read_count` is set on the model by the controller from a
 * single grouped query over the recipients' notification rows.
 *
 * @mixin Broadcast
 */
class BroadcastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_name' => $this->sender?->name,
            'audience_type' => $this->audience_type,
            'role_name' => $this->role?->name,
            'recipient_count' => $this->recipient_count,
            'read_count' => (int) ($this->read_count ?? 0),
            // A short one-line preview in the viewer's language for the list row.
            'preview' => Str::limit($this->bodyFor(app()->getLocale()), 120),
            'created_at' => $this->created_at,
        ];
    }
}
