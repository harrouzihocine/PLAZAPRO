<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Resources;

use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NextAction
 */
class NextActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'type' => $this->type?->value,
            'state' => $this->state?->value,
            'due_at' => $this->due_at,
            'completed_at' => $this->completed_at,
            'is_overdue' => $this->state?->value === 'pending' && $this->due_at?->isPast(),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null),
        ];
    }
}
