<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Modules\Analytics\Console\SnapshotKpis;
use App\Modules\Clients\Console\FlagEmptyClients;
use App\Modules\Collaboration\Console\BackfillProjectChats;
use App\Modules\Inventory\Console\ExpireHolds;
use App\Modules\Inventory\Console\ExpireReserved;
use App\Modules\Inventory\Console\OptimizeExistingMedia;
use App\Modules\Payments\Console\MarkSchedulesOverdueCommand;
use App\Modules\Pipeline\Console\AggregateAgentMileage;
use App\Modules\Pipeline\Console\DispatchReminders;
use App\Modules\Pipeline\Console\MarkActionsOverdue;
use App\Modules\Pipeline\Console\SendUpcomingDigest;
use App\Modules\Pipeline\Console\RemindDutyStart;
use App\Modules\Pipeline\Console\SweepDispatchAlerts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Broadcast auth route (/broadcasting/auth, web middleware) + channel
    // callbacks for Reverb. Phase 5 — see routes/channels.php.
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withCommands([
        // Modular commands (auto-discovery only covers app/Console).
        ExpireHolds::class,
        ExpireReserved::class,
        MarkActionsOverdue::class,
        DispatchReminders::class,
        MarkSchedulesOverdueCommand::class,
        BackfillProjectChats::class,
        SendUpcomingDigest::class,
        FlagEmptyClients::class,
        OptimizeExistingMedia::class,
        SnapshotKpis::class,
        SweepDispatchAlerts::class,
        RemindDutyStart::class,
        AggregateAgentMileage::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) auth for the first-party frontend.
        $middleware->statefulApi();

        // The app always sits behind a proxy (dev: nginx/Vite; prod: cloudflared → nginx).
        // Without this, X-Forwarded-Proto is ignored — HTTPS detection, secure cookies and
        // signed URLs break, and the rate limiter keys every external user by the proxy IP
        // (one shared 120 req/min bucket). nginx rewrites the client IP from
        // CF-Connecting-IP (docker/nginx/prod.conf), so trusting all proxies is safe here.
        $middleware->trustProxies(at: '*');

        // Security-baseline headers on every response.
        $middleware->append(SecurityHeaders::class);

        // Reject requests from users deactivated/cancelled after they signed in
        // (login checks is_active only at sign-in). Appended to the api group so
        // it runs after Sanctum's stateful session is resolved.
        // SetLocale then answers in the caller's language (Accept-Language from
        // the SPA, or the user's saved locale) — validation errors included.
        $middleware->api(append: [EnsureUserActive::class, SetLocale::class]);

        // Global API rate limiting (named limiter defined in RbacServiceProvider).
        $middleware->throttleApi('api');

        // Replay protection for offline-queued writes (X-Idempotency-Key).
        // Route middleware — applied to the queueable field-agent endpoints.
        $middleware->alias(['idempotent' => IdempotencyKey::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
