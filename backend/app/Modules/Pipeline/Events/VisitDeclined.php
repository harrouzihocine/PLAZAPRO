<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A field agent declined an assigned in-site visit (with a reason). The plan
 * has already returned to the dispatch pool; Collaboration listens and tells
 * the dispatchers WHY, so they re-assign with the context in hand.
 */
class VisitDeclined
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Visit $visit,
        public int $agentId,
        public string $reason,
    ) {}
}
