<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A unit's details were edited in place — ordinary spec fields via UpdateUnit, or
 * a price/status correction via CorrectUnit — as opposed to being newly published
 * (UnitPublished) or moving sale state through the reservation lifecycle
 * (UnitStatusChanged, model hook). Collaboration listens (AnnounceUnitEdited) to
 * drop a durable "unit updated" record in every user's bell so the whole team
 * sees inventory changes made from the unit page / location's unit tab, not just
 * the agent who made them. $changed is the list of changed attribute keys so the
 * notification can name what moved. Kept in Inventory so the module stays free of
 * any Collaboration import.
 */
class UnitEdited
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $changed  changed attribute keys (e.g. floor_id, price)
     */
    public function __construct(public Unit $unit, public array $changed = []) {}
}
