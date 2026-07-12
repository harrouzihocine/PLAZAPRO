<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Settings\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires RBAC and rate limiting into the framework.
 *
 * A single Gate::before resolves ANY permission slug (e.g. "units.interest")
 * against the user's one role — so `can:units.interest` middleware just works,
 * without defining a gate per permission.
 */
class RbacServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->role?->slug === 'super-admin') {
                return true;                                  // super-admin shortcut
            }

            // true => allowed; null => fall through to real policies (then denied if none).
            return $user->hasPermission($ability) ?: null;
        });

        // Two layers: 5/min per account+IP slows guessing on one account
        // (the SPA sends the identifier as `login`, the token endpoint as
        // `email`); 20/min per IP blunts sweeps across many accounts from one
        // source without tripping an office NAT where users share an IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(
                mb_strtolower((string) ($request->input('login') ?? $request->input('email'))).'|'.$request->ip(),
            ),
            Limit::perMinute(20)->by('login-ip|'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // The public showcase (/api/v1/public/*): anonymous by design, so keyed
        // by IP only. 60/min covers a normal browse (~3 GETs per page) while
        // keeping scrapers off the inventory.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)
            ->by('pub|'.$request->ip()));

        // Media streaming is fan-out traffic: a single project page pulls tens
        // of thumbnails and the hero slideshow more, so it gets a much wider
        // per-IP budget than the JSON reads above.
        RateLimiter::for('public-media', fn (Request $request) => Limit::perMinute(300)
            ->by('pubmedia|'.$request->ip()));

        // Lead submissions are the abuse magnet: a human sends one or two,
        // never dozens. Hourly + daily ceilings per IP (an office NAT sharing
        // one IP still fits — leads come from the public internet, not staff).
        RateLimiter::for('public-leads', fn (Request $request) => [
            Limit::perHour(5)->by('publead-h|'.$request->ip()),
            Limit::perDay(15)->by('publead-d|'.$request->ip()),
        ]);

        // The analytics sink: the tracker batches, so a real visitor posts a
        // handful of requests per minute; anything past this is a bot.
        RateLimiter::for('public-track', fn (Request $request) => Limit::perMinute(30)
            ->by('pubtrack|'.$request->ip()));
    }
}
