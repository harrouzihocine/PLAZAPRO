<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Events\BoxEdited;
use App\Modules\Inventory\Models\Box;
use Illuminate\Support\Arr;

class UpdateBox
{
    /** Editable fields — also the set whose changes are announced. */
    private const EDITABLE = ['reference', 'type_id', 'price', 'sale_status', 'unit_id'];

    public function handle(Box $box, array $data): Box
    {
        $box->update(Arr::only($data, self::EDITABLE));

        // Announce the edit to the whole team (Collaboration drops a "box updated"
        // bell for everyone + fires the live toast) — but only when something
        // actually moved, so re-saving an unchanged form stays silent.
        $changed = array_values(array_intersect(self::EDITABLE, array_keys($box->getChanges())));

        if ($changed !== []) {
            BoxEdited::dispatch($box, $changed);
        }

        return $box->fresh();
    }
}
