<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Versement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment was recorded (or corrected) on a deal. Collaboration listens and
 * notifies the deal's owning agent. Kept in Payments so the module stays free of
 * any Collaboration import.
 */
class VersementRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Versement $versement) {}
}
