<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests\Concerns;

use App\Modules\Clients\Models\Client;
use Illuminate\Contracts\Validation\Validator;

/**
 * Workflow rule: a call log is the very first entity captured for a new client —
 * qualification happens on the phone before any visit, deal or desire exists.
 * Requests that would create one of those hook this after-check in. Actions and
 * seeders are intentionally NOT constrained (the rule is a UX invariant enforced
 * at the trust boundary, not data integrity).
 */
trait RequiresFirstCall
{
    /**
     * @param  Client|int|string|null  $client  route-bound model or a client id input
     * @param  string  $blocked  what the caller is trying to do, e.g. "scheduling a visit"
     */
    protected function requireFirstCall(Validator $validator, Client|int|string|null $client, string $blocked): void
    {
        $validator->after(function (Validator $v) use ($client, $blocked) {
            $client = $client instanceof Client ? $client : Client::find($client);

            if ($client !== null && ! $client->hasActiveCall()) {
                $v->errors()->add('client_id', "Log the client's first call before {$blocked}.");
            }
        });
    }
}
