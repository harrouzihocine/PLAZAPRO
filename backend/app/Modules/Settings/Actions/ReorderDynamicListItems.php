<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use Illuminate\Support\Facades\DB;

/**
 * Persist a new display order for a list's items. Only ids that belong to the
 * list are applied. Logs a single "reorder" entry on the list rather than one
 * "update" per item.
 */
class ReorderDynamicListItems
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function handle(DynamicList $list, array $orderedIds): DynamicList
    {
        return DB::transaction(function () use ($list, $orderedIds) {
            $validIds = $list->items()->whereIn('id', $orderedIds)->pluck('id')->all();

            foreach ($orderedIds as $position => $id) {
                if (in_array($id, $validIds, false)) {
                    $list->items()->whereKey($id)->update([
                        'sort_order' => $position,
                        'updated_at' => now(),
                    ]);
                }
            }

            $list->logActivity('reorder', [
                'order' => array_values(array_filter($orderedIds, fn ($id) => in_array($id, $validIds, false))),
            ]);

            return $list->fresh('activeItems');
        });
    }
}
