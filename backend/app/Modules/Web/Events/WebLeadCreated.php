<?php

declare(strict_types=1);

namespace App\Modules\Web\Events;

use App\Modules\Web\Models\WebLead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A visitor submitted the public lead form. Fans out (queued listener) to
 * every web.leads holder's bell/push.
 */
class WebLeadCreated
{
    use Dispatchable;

    public function __construct(public WebLead $webLead) {}
}
