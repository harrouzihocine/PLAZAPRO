<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderDynamicListItemsRequest extends FormRequest
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
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct', Rule::exists('dynamic_list_items', 'id')->where('dynamic_list_id', $listId)],
        ];
    }
}
