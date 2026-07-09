<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;

/**
 * Cancel several units at once (the units table's multi-select). Same rule as
 * CancelUnit — only available units go — but instead of aborting on the first
 * held/sold unit it skips it and reports why, so one reserved apartment does
 * not block cleaning up the rest of the selection.
 */
class BulkCancelUnits
{
    /**
     * @param  list<int>  $ids
     * @return array{cancelled: int, skipped: list<array{id: int, reference: ?string, reason: string}>}
     */
    public function handle(array $ids, string $reason): array
    {
        $units = Unit::query()->active()->whereIn('id', $ids)->get()->keyBy('id');

        $cancelled = 0;
        $skipped = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $unit = $units->get($id);

            if ($unit === null) {
                // Already cancelled, superseded, or never existed.
                $skipped[] = ['id' => $id, 'reference' => null, 'reason' => 'not_found'];

                continue;
            }

            if ($unit->sale_status !== SaleStatus::Available) {
                $skipped[] = ['id' => $id, 'reference' => $unit->reference, 'reason' => 'not_available'];

                continue;
            }

            $unit->cancel($reason);
            $cancelled++;
        }

        return ['cancelled' => $cancelled, 'skipped' => $skipped];
    }
}
