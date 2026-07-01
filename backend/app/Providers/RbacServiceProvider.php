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
 * A single Gate::before resolves ANY permission slug (e.g. "units.reserve")
 * against the user's one role — so `can:units.reserve` middleware just works,
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

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
