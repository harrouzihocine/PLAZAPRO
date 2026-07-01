<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Modules\Inventory\Console\ExpireHolds;
use App\Modules\Pipeline\Console\DispatchReminders;
use App\Modules\Pipeline\Console\MarkActionsOverdue;
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
    ->withCommands([
        // Modular commands (auto-discovery only covers app/Console).
        ExpireHolds::class,
        MarkActionsOverdue::class,
        DispatchReminders::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) auth for the first-party frontend.
        $middleware->statefulApi();

        // Security-baseline headers on every response.
        $middleware->append(SecurityHeaders::class);

        // Global API rate limiting (named limiter defined in RbacServiceProvider).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
