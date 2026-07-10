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
            'category' => $this->category?->value,
            'due_at' => $this->due_at,
            'priority' => $this->priority?->value,
            'state' => $this->state?->value,
            'status' => $this->status?->value,
            'repeat_every_hours' => $this->repeat_every_hours,
            // Completion report (null until the task is done).
            'completed_at' => $this->completed_at,
            'completion_outcome' => $this->completion_outcome?->value,
            'completion_summary' => $this->completion_summary,
            'completion_difficulties' => $this->completion_difficulties,
            'time_spent_minutes' => $this->time_spent_minutes,
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy ? [
                'id' => $this->completedBy->id,
                'name' => $this->completedBy->name,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
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
            $subject instanceof Client => $subject->full_name,
            $subject instanceof ClientProject => 'Deal #'.$subject->id,
            $subject instanceof Unit => 'Unit '.$subject->reference,
            default => class_basename($subject),
        };
    }
}
