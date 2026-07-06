<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add an item to a list. When `value` is omitted it is derived from the label
 * (a stable machine key, unique within the list) so admins never have to think
 * about it. A supplied value's uniqueness is enforced by the FormRequest;
 * sort_order defaults to the end of the list.
 */
class CreateDynamicListItem
{
    public function handle(DynamicList $list, array $data): DynamicListItem
    {
        return DB::transaction(fn () => $list->items()->create([
            'label' => $data['label'],
            'value' => $data['value'] ?? $this->uniqueValue($list, $data['label']),
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'meta' => $data['meta'] ?? null,
            'sort_order' => $data['sort_order'] ?? (((int) $list->items()->max('sort_order')) + 1),
        ]));
    }

    /**
     * Slugify the label into a machine value that is unique within the list,
     * appending _2, _3… on collision (mirrors CreateRole::uniqueSlug).
     */
    private function uniqueValue(DynamicList $list, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'item';
        $value = $base;
        $n = 2;

        while ($list->items()->where('value', $value)->exists()) {
            $value = $base.'_'.$n++;
        }

        return $value;
    }
}
