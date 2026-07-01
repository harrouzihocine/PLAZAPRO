<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Resources;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'due_at' => $this->due_at,
            'priority' => $this->priority?->value,
            'state' => $this->state?->value,
            'status' => $this->status?->value,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            // A human label for the linked record (client/deal/unit), when loaded.
            'subject_label' => $this->when(
                $this->relationLoaded('subject') && $this->subject !== null,
                fn () => $this->subjectLabel($this->subject),
            ),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null),
        ];
    }

    private function subjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Client => trim($subject->first_name.' '.$subject->last_name),
            $subject instanceof ClientProject => 'Deal #'.$subject->id,
            $subject instanceof Unit => 'Unit '.$subject->reference,
            default => class_basename($subject),
        };
    }
}
