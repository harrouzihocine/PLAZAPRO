<?php

declare(strict_types=1);

namespace App\Modules\Web\Actions;

use App\Modules\Web\Events\WebLeadCreated;
use App\Modules\Web\Models\WebLead;
use Illuminate\Http\Request;

/**
 * Store a public form submission and announce it. The request context
 * (locale, page, agent, hashed IP) is stamped here — an audit trail with no
 * raw PII beyond what the visitor typed.
 */
class CreateWebLead
{
    public function handle(array $data, Request $request): WebLead
    {
        $lead = new WebLead($data);

        // SetLocale already resolved the visitor's Accept-Language.
        $lead->locale = app()->getLocale();
        $lead->source_url = mb_substr((string) ($data['source_url'] ?? $request->headers->get('referer', '')), 0, 500) ?: null;
        $lead->user_agent = mb_substr((string) $request->userAgent(), 0, 255) ?: null;
        $lead->ip_hash = $request->ip() !== null
            ? hash('sha256', $request->ip().config('app.key'))
            : null;

        $lead->save();

        WebLeadCreated::dispatch($lead);

        return $lead;
    }
}
