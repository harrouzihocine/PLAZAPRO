<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GETs for the API: hash every 200 JSON response into an ETag and
 * answer 304 (empty body) when the client's If-None-Match still holds.
 *
 * Why: reconnect after an offline stretch refetches every list the user is
 * looking at (reconnect self-heal + pre-warm). On a weak 3G link the payloads
 * are the cost, not the queries — an unchanged list now costs a handful of
 * header bytes. The browser handles the revalidation transparently (the
 * Cache-Control below makes stored responses always revalidate, never go
 * stale), so neither axios nor the stores change at all.
 *
 * Scope: GET + 200 + JSON only. Binary/streamed responses (media files,
 * exports — getContent() === false) and error payloads stay untouched.
 */
final class EtagOnGet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return $response;
        }

        $response->setEtag(md5($content));
        $response->headers->set('Cache-Control', 'private, no-cache');
        // Swaps to an empty 304 in place when If-None-Match matches.
        $response->isNotModified($request);

        return $response;
    }
}
