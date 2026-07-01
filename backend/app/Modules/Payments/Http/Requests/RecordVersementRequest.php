<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordVersementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('versements.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_on' => ['required', 'date'],
            'method_id' => ['required', 'integer', 'exists:dynamic_list_items,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            // The settled instalment must belong to this deal and still be active.
            'schedule_item_id' => [
                'nullable', 'integer',
                Rule::exists('payment_schedules', 'id')
                    ->where('client_project_id', $this->route('project')?->id)
                    ->where('status', 'active'),
            ],
        ];
    }
}
