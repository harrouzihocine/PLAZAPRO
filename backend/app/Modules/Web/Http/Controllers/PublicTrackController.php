<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Models\WebStatEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The showcase's anonymous analytics sink: the frontend batches events
 * (page/project/unit views, contact clicks) and posts them here. PII-free by
 * design — the "visitor" is a random client-side hex key, never an IP or an
 * account; junk ids are tolerated (no FKs) and simply won't join to anything
 * on the stats board. Own throttle; unknown fields are dropped by validation.
 */
class PublicTrackController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'session' => ['required', 'string', 'regex:/^[a-f0-9]{16,64}$/i'],
            'referrer' => ['sometimes', 'nullable', 'string', 'max:300'],
            // The site language the visitor chose (sendBeacon can't carry the
            // SPA's Accept-Language header, so it rides in the payload).
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'fr', 'ar'])],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*.event' => ['required', 'string', Rule::in(WebStatEvent::EVENTS)],
            'events.*.location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'events.*.unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'events.*.path' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $locale = $data['locale']
            ?? (substr((string) $request->getPreferredLanguage(['fr', 'ar', 'en']), 0, 2) ?: null);
        $isMobile = (bool) preg_match('/Mobi|Android/i', (string) $request->userAgent());
        $now = now();

        WebStatEvent::insert(array_map(fn (array $event) => [
            'session_key' => strtolower($data['session']),
            'event' => $event['event'],
            'location_id' => $event['location_id'] ?? null,
            'unit_id' => $event['unit_id'] ?? null,
            'path' => $event['path'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'locale' => $locale,
            'is_mobile' => $isMobile,
            'created_at' => $now,
        ], $data['events']));

        return response()->noContent();
    }
}
