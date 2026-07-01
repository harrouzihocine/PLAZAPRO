<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;

/**
 * "Removing" an item means deactivating it (is_active = false). Items are
 * referenced by other tables, so they are never hard-deleted.
 */
class DeactivateDynamicListItem
{
    public function handle(DynamicList $list, DynamicListItem $item): DynamicListItem
    {
        abort_unless((int) $item->dynamic_list_id === (int) $list->id, 404);

        $item->update(['is_active' => false]);

        return $item->fresh();
    }
}
