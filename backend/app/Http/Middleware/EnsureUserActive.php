<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request whose authenticated user has since been deactivated
 * (is_active = false) or cancelled (status != active). Login only checks this at
 * sign-in, so without this guard a fired user's live SPA session (and, with token
 * revocation on deactivation, their mobile token) would keep working until the
 * session TTL. Runs on the api group after stateful session auth is resolved; an
 * unauthenticated request (login, ping) passes straight through.
 */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Block only the definitive off-states: explicitly deactivated
        // (is_active = false) or cancelled. isCancelled() is used rather than
        // ! isActive() so an unpersisted/unknown status never locks a user out.
        if ($user !== null && (! $user->is_active || $user->isCancelled())) {
            // Kill the SPA session so the next request is a clean 401, then
            // deny this one.
            if ($request->hasSession()) {
                auth('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(401, 'This account is no longer active.');
        }

        return $next($request);
    }
}
