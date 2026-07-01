<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Set the gallery order of a mediable's media from an ordered list of ids. Only
 * ids that actually belong to the mediable are moved.
 */
class ReorderMedia
{
    /** @param  list<int>  $orderedIds */
    public function handle(Model $mediable, array $orderedIds): void
    {
        $media = $mediable->media()->whereIn('id', $orderedIds)->get()->keyBy('id');

        DB::transaction(function () use ($media, $orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                $media[$id]?->update(['sort_order' => $index + 1]);
            }
        });
    }
}
