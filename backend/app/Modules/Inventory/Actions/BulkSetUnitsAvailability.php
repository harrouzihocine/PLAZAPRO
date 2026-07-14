<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Inventory\Models\Unit;

/**
 * Park / un-park several units at once (the units table's multi-select), the
 * bulk twins of MakeUnitUnavailable / MakeUnitAvailable. Same guards, but instead
 * of aborting on the first ineligible unit it skips it and reports why, so one
 * held/sold apartment does not block the rest of the selection.
 *
 * Parking flips available → unavailable; restoring flips unavailable → available
 * (and re-runs the reverse desire-match per freed unit). Unlike the single-unit
 * toggle, each flip is saved QUIETLY (+ an explicit audit entry, like bulk
 * cancel): a 50-unit selection must not fire the per-unit status bell 50× to
 * every user. The audit trail is kept; open public views simply repaint on their
 * next load rather than live.
 */
class BulkSetUnitsAvailability
{
    /**
     * @param  list<int>  $ids
     * @return array{changed: int, skipped: list<array{id: int, reference: ?string, reason: string}>}
     */
    public function handle(array $ids, bool $unavailable): array
    {
        $from = $unavailable ? SaleStatus::Available : SaleStatus::Unavailable;
        $to = $unavailable ? SaleStatus::Unavailable : SaleStatus::Available;

        $units = Unit::query()->active()->whereIn('id', $ids)->get()->keyBy('id');

        $changed = 0;
        $skipped = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $unit = $units->get($id);

            if ($unit === null) {
                $skipped[] = ['id' => $id, 'reference' => null, 'reason' => 'not_found'];

                continue;
            }

            if ($unit->sale_status !== $from) {
                $skipped[] = ['id' => $id, 'reference' => $unit->reference, 'reason' => 'not_eligible'];

                continue;
            }

            // Quiet save (no per-unit status bell) + an explicit named audit entry.
            $unit->forceFill(['sale_status' => $to->value])->saveQuietly();
            $unit->logActivity($unavailable ? 'make_unavailable' : 'make_available');

            if (! $unavailable) {
                // Reactivated — let waiting desires hear about the freed unit.
                UnitRepriced::dispatch($unit);
            }

            $changed++;
        }

        return ['changed' => $changed, 'skipped' => $skipped];
    }
}
