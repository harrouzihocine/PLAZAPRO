<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An in-site plan was created without a field agent — it sits in the dispatch
 * pool. Collaboration listens and notifies every visits.dispatch holder so one
 * of them assigns it from the weekly board.
 */
class InSiteDispatchRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public NextAction $nextAction) {}
}
