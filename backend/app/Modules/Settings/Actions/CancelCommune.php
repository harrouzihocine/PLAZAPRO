<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Settings\Models\Commune;
use Illuminate\Support\Facades\DB;

/**
 * Cancel (no-delete) a commune. Guarded: refuse while it is referenced by active
 * locations/desires (checked via the query builder so Settings stays import-free
 * of Inventory/Clients).
 */
class CancelCommune
{
    public function handle(Commune $commune, string $reason): Commune
    {
        $active = RecordStatus::Active->value;

        abort_if(
            DB::table('locations')->where('commune_id', $commune->id)->where('status', $active)->exists()
            || DB::table('desire_communes')
                ->join('desires', 'desires.id', '=', 'desire_communes.desire_id')
                ->where('desire_communes.commune_id', $commune->id)
                ->where('desires.status', $active)
                ->exists(),
            422,
            'This commune is still used by active locations or desires.',
        );

        return $commune->cancel($reason);
    }
}
