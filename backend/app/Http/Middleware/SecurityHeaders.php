<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the security-baseline HTTP headers to every response.
 * Register globally in bootstrap/app.php (withMiddleware->append(SecurityHeaders::class)).
 * See docs/phase-0-foundations/10-security-baseline.md.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Microphone allowed for chat voice notes (Phase 5); geolocation off.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(self)');

        // JSON API responses are never a document context: lock the CSP right
        // down so a response can never be leveraged as a script surface. Gated on
        // the JSON content type so inline media/PDF streams (also under /api) are
        // untouched. (The SPA's own HTML gets its richer CSP from nginx.)
        if ($request->is('api/*')
            && str_starts_with((string) $response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            );
        }

        // Production only: force HTTPS for a year (enable behind TLS).
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            // Tune Content-Security-Policy to the SPA + CDN origins during Phase 7.
        }

        return $response;
    }
}
