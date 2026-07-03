<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Listeners\NotifyAgentsOfMatchingUnit;
use App\Modules\Collaboration\Listeners\NotifyParticipantsOfMessage;
use App\Modules\Collaboration\Listeners\SendDispatchRequestNotification;
use App\Modules\Collaboration\Listeners\SendDueReminderNotification;
use App\Modules\Collaboration\Listeners\SendPaymentNotification;
use App\Modules\Collaboration\Listeners\SendVisitAssignedNotification;
use App\Modules\Inventory\Events\UnitPublished;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Payments\Events\VersementRecorded;
use App\Modules\Pipeline\Events\InSiteDispatchRequested;
use App\Modules\Pipeline\Events\ReminderDue;
use App\Modules\Pipeline\Events\VisitAssigned;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Collaboration notification pipeline: domain events raised by other
 * modules (Pipeline, Payments, Inventory) are mapped here to the queued
 * listeners that turn them into DomainNotifications. Registering the bindings
 * explicitly (rather than via auto-discovery) keeps the modular listener
 * directories discoverable and the mapping easy to read/test.
 */
class CollaborationServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    private array $listen = [
        ReminderDue::class => [SendDueReminderNotification::class],
        VisitAssigned::class => [SendVisitAssignedNotification::class],
        InSiteDispatchRequested::class => [SendDispatchRequestNotification::class],
        MessageSent::class => [NotifyParticipantsOfMessage::class],
        VersementRecorded::class => [SendPaymentNotification::class],
        UnitPublished::class => [NotifyAgentsOfMatchingUnit::class],
        UnitRepriced::class => [NotifyAgentsOfMatchingUnit::class],
    ];

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
