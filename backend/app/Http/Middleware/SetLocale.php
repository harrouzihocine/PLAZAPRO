<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer every API request in the caller's language. The SPA sends its current
 * UI language as Accept-Language on each request (so validation errors match
 * the screen instantly, login included); the authenticated user's saved locale
 * is the fallback for clients that don't. Queued work (notifications, digest)
 * doesn't pass here — it uses User::preferredLocale() instead.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'fr', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        $locale = in_array($header, self::SUPPORTED, true)
            ? $header
            : $request->user()?->locale;

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
            // Dates too (translatedFormat): Algeria writes Arabic months
            // French-style (جانفي، فيفري…), which is Carbon's ar_DZ.
            \Illuminate\Support\Carbon::setLocale($locale === 'ar' ? 'ar_DZ' : $locale);
        }

        return $next($request);
    }
}
