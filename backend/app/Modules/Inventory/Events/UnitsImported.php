<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A CSV unit import finished with at least one row applied. Import rows do NOT
 * fire the per-unit UnitPublished/UnitEdited events (a 200-row file would ring
 * every user's bell 200 times) — Collaboration listens to this one summary
 * event instead (AnnounceUnitsImported) and drops a single "X added, Y updated
 * by Z" notification for the whole team.
 */
class UnitsImported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public int $created,
        public int $updated,
    ) {}
}
