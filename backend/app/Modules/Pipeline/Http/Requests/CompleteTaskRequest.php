<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use App\Modules\Pipeline\Enums\TaskOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * The completion report: a task is never just ticked off — the person says
 * what they actually did, how it went and what got in the way.
 */
class CompleteTaskRequest extends FormRequest
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
            'summary' => ['required', 'string', 'max:5000'],
            'outcome' => ['required', new Enum(TaskOutcome::class)],
            'difficulties' => ['nullable', 'string', 'max:5000'],
            'time_spent_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ];
    }
}
