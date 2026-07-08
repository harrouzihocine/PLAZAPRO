<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Update an item. Guards that the item belongs to the list and that a new
 * parent does not create a self-reference or a cycle.
 */
class UpdateDynamicListItem
{
    public function handle(DynamicList $list, DynamicListItem $item, array $data): DynamicListItem
    {
        abort_unless((int) $item->dynamic_list_id === (int) $list->id, 404);

        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($item, $data['parent_id'] === null ? null : (int) $data['parent_id']);
        }

        return DB::transaction(function () use ($item, $data) {
            $item->update(Arr::only($data, ['label', 'label_translations', 'value', 'parent_id', 'is_active', 'meta', 'sort_order']));

            return $item->fresh();
        });
    }

    private function assertNoCycle(DynamicListItem $item, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        abort_if($parentId === (int) $item->id, 422, 'An item cannot be its own parent.');

        $cursor = DynamicListItem::find($parentId);
        while ($cursor !== null) {
            abort_if((int) $cursor->id === (int) $item->id, 422, 'Circular parent reference is not allowed.');
            $cursor = $cursor->parent_id !== null ? DynamicListItem::find($cursor->parent_id) : null;
        }
    }
}
