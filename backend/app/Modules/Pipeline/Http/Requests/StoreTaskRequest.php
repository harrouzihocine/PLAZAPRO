<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\TaskCategory;
use App\Modules\Pipeline\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tasks.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['sometimes', new Enum(TaskCategory::class)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            // Repeat-on-complete, in hours (12 = twice a day, 720 = monthly).
            'repeat_every_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }

    /**
     * Handing a task to someone else needs the tasks.assign layer; everyone
     * else creates for themselves only.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $assignee = $this->input('assigned_to');
            if ($assignee && (int) $assignee !== $this->user()->id && ! $this->user()->can('tasks.assign')) {
                $validator->errors()->add('assigned_to', __('You can only create tasks for yourself.'));
            }
        });
    }
}
