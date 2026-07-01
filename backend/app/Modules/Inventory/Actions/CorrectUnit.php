<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Arr;

/**
 * Correct an immutable field (price and/or sale_status) via HasVersions: the
 * original row is cancelled and a linked replacement is inserted, so the change
 * is auditable and the old value is never overwritten. Returns the new version.
 */
class CorrectUnit
{
    public function handle(Unit $unit, array $data): Unit
    {
        $changes = Arr::only($data, ['price', 'sale_status']);
        $reason = $data['reason'] ?? 'Correction';

        return $unit->supersedeWith($changes, $reason);
    }
}
