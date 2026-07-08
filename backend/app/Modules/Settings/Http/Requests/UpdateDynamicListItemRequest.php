<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDynamicListItemRequest extends FormRequest
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
        $itemId = $this->route('item')->id;

        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            // Optional per-language display labels; the base label is the fallback.
            'label_translations' => ['sometimes', 'nullable', 'array'],
            'label_translations.en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'label_translations.fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'label_translations.ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'value' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('dynamic_list_items', 'value')->where('dynamic_list_id', $listId)->ignore($itemId)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('dynamic_list_items', 'id')->where('dynamic_list_id', $listId)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
