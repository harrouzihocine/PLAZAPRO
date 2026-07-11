<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

use App\Modules\Pipeline\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A visit's log task was just completed (in-site or office). Typically an
 * in-site field agent fills the log for a client they don't own — Collaboration
 * listens and tells the people who didn't do the work (the client's owner and
 * the dispatchers) that this log task is done, so oversight doesn't have to
 * chase it. The completer is passed so the listener can skip them.
 */
class VisitCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Visit $visit,
        public int $actorId,
    ) {}
}
