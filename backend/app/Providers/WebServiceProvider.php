<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Web\Events\WebLeadCreated;
use App\Modules\Web\Listeners\SendWebLeadNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Web (public showcase) module's event pipeline. Explicit bindings,
 * like CollaborationServiceProvider — no auto-discovery.
 */
class WebServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    private array $events = [
        WebLeadCreated::class => [
            SendWebLeadNotification::class,
        ],
    ];

    public function boot(): void
    {
        foreach ($this->events as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
