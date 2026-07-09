<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Collaboration\Actions\GrantFieldAgentChatAccess;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Listeners\AnnounceBoxEdited;
use App\Modules\Collaboration\Listeners\AnnounceNewBox;
use App\Modules\Collaboration\Listeners\AnnounceNewUnit;
use App\Modules\Collaboration\Listeners\AnnounceUnitEdited;
use App\Modules\Collaboration\Listeners\AnnounceUnitsImported;
use App\Modules\Collaboration\Listeners\AnnounceUnitSold;
use App\Modules\Collaboration\Listeners\AnnounceUnitStatusChange;
use App\Modules\Collaboration\Listeners\NotifyAgentsOfMatchingUnit;
use App\Modules\Collaboration\Listeners\NotifyHolderOfLapsedHold;
use App\Modules\Collaboration\Listeners\NotifyNextInReservationQueue;
use App\Modules\Collaboration\Listeners\NotifyParticipantsOfMessage;
use App\Modules\Collaboration\Listeners\NotifyQueueCancelledBySale;
use App\Modules\Collaboration\Listeners\SendDispatchRequestNotification;
use App\Modules\Collaboration\Listeners\SendDueReminderNotification;
use App\Modules\Collaboration\Listeners\SendPaymentNotification;
use App\Modules\Collaboration\Listeners\SendVisitAssignedNotification;
use App\Modules\Inventory\Events\BackupHoldsCancelled;
use App\Modules\Inventory\Events\BoxEdited;
use App\Modules\Inventory\Events\BoxPublished;
use App\Modules\Inventory\Events\ReservedLapsed;
use App\Modules\Inventory\Events\ReservedReleased;
use App\Modules\Inventory\Events\UnitEdited;
use App\Modules\Inventory\Events\UnitPublished;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Inventory\Events\UnitsImported;
use App\Modules\Inventory\Events\UnitSold;
use App\Modules\Inventory\Events\UnitStatusChanged;
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
        VisitAssigned::class => [SendVisitAssignedNotification::class, GrantFieldAgentChatAccess::class],
        InSiteDispatchRequested::class => [SendDispatchRequestNotification::class],
        MessageSent::class => [NotifyParticipantsOfMessage::class],
        VersementRecorded::class => [SendPaymentNotification::class],
        UnitPublished::class => [NotifyAgentsOfMatchingUnit::class, AnnounceNewUnit::class],
        UnitEdited::class => [AnnounceUnitEdited::class],
        UnitRepriced::class => [NotifyAgentsOfMatchingUnit::class],
        UnitsImported::class => [AnnounceUnitsImported::class],
        BoxPublished::class => [AnnounceNewBox::class],
        BoxEdited::class => [AnnounceBoxEdited::class],
        UnitSold::class => [AnnounceUnitSold::class],
        UnitStatusChanged::class => [AnnounceUnitStatusChange::class],
        ReservedLapsed::class => [NotifyHolderOfLapsedHold::class],
        // The reservation queue: promotion when the deposit lock lifts,
        // cancellation notices when a sale ends every queued hold.
        ReservedReleased::class => [NotifyNextInReservationQueue::class],
        BackupHoldsCancelled::class => [NotifyQueueCancelledBySale::class],
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
