<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Support\Facades\DB;

/**
 * Cancel (no-delete) a wilaya. Guarded: refuse while it still has active communes
 * or is referenced by active locations/desires — nobody should be left pointing at
 * a cancelled wilaya. (Cross-module references are checked via the query builder so
 * Settings stays import-free of Inventory/Clients.)
 */
class CancelWilaya
{
    public function handle(Wilaya $wilaya, string $reason): Wilaya
    {
        $active = RecordStatus::Active->value;

        abort_if(
            $wilaya->communes()->where('status', $active)->exists(),
            422,
            'Cancel or move this wilaya\'s communes before cancelling it.',
        );

        abort_if(
            DB::table('locations')->where('wilaya_id', $wilaya->id)->where('status', $active)->exists()
            || DB::table('desire_wilayas')
                ->join('desires', 'desires.id', '=', 'desire_wilayas.desire_id')
                ->where('desire_wilayas.wilaya_id', $wilaya->id)
                ->where('desires.status', $active)
                ->exists(),
            422,
            'This wilaya is still used by active locations or desires.',
        );

        return $wilaya->cancel($reason);
    }
}
