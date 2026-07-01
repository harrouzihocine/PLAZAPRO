<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDynamicListItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $listId = $this->route('list')->id;

        return [
            'label' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:255', Rule::unique('dynamic_list_items', 'value')->where('dynamic_list_id', $listId)],
            'parent_id' => ['nullable', 'integer', Rule::exists('dynamic_list_items', 'id')->where('dynamic_list_id', $listId)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
