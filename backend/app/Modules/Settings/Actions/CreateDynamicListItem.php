<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Support\Facades\DB;

/**
 * Add an item to a list. `value` uniqueness within the list and the parent
 * belonging to the same list are enforced by the FormRequest; sort_order
 * defaults to the end of the list.
 */
class CreateDynamicListItem
{
    public function handle(DynamicList $list, array $data): DynamicListItem
    {
        return DB::transaction(fn () => $list->items()->create([
            'label' => $data['label'],
            'value' => $data['value'],
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'meta' => $data['meta'] ?? null,
            'sort_order' => $data['sort_order'] ?? (((int) $list->items()->max('sort_order')) + 1),
        ]));
    }
}
