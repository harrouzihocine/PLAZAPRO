<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitStatusChanged;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection as SupportCollection;

/**
 * An apartment / lot inside a location. price and sale_status corrections go
 * through HasVersions::supersedeWith (cancel-and-duplicate); ordinary spec edits
 * and interest-hold lifecycle transitions are plain updates.
 */
class Unit extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'location_id', 'reference', 'room_number_id', 'floor_id', 'area_sqm',
        'price', 'sale_status', 'reserved_expires_at', 'reserved_project_id',
        'block', 'stack_floor', 'position', 'gtm_priority',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'price' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'sale_status' => SaleStatus::class,
            'reserved_expires_at' => 'datetime',
            'gtm_priority' => GtmPriority::class,
        ]);
    }

    /**
     * Mirror every sale_status transition to the public "announcements" channel
     * so open unit views repaint live for all users (Actions still own the
     * business rules — this hook carries NO logic, only the broadcast).
     */
    protected static function booted(): void
    {
        static::updated(function (Unit $unit): void {
            if ($unit->wasChanged('sale_status') || $unit->wasChanged('reserved_expires_at')) {
                UnitStatusChanged::dispatch($unit, $unit->wasChanged('sale_status'));
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Number of rooms (a `room_numbers` dynamic-list item), e.g. F2 / F3. */
    public function roomNumber(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'room_number_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'floor_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** The client project that has this unit Reserved (paid a deposit), if any. */
    public function reservedProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'reserved_project_id');
    }

    /** The most-recent active hold (the Reserved holder's, or the latest backup). */
    public function activeReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)
            ->where('hold_status', HoldStatus::Active->value)
            ->latest('id');
    }

    /** All live interest holds — several projects can queue on a unit as backups. */
    public function activeReservations(): HasMany
    {
        return $this->hasMany(Reservation::class)
            ->where('hold_status', HoldStatus::Active->value);
    }

    /** Any live hold at all (drives whether a unit is still off "available"). */
    public function hasActiveHold(): bool
    {
        return $this->reservations()
            ->where('hold_status', HoldStatus::Active->value)
            ->exists();
    }

    /**
     * The ordered reservation queue — the "you are Nth in line" order agents
     * quote to clients: the Reserved deposit holder first (when the unit is
     * Reserved), then every other live project hold oldest-first. Anonymous
     * holds (no client project) hold nothing FOR anyone, so they never queue.
     *
     * @return SupportCollection<int, Reservation>
     */
    public function reservationQueue(): SupportCollection
    {
        // Reuse the eager-loaded holds when present (list views — no N+1),
        // else query, like UnitResource's interested counter.
        $holds = $this->relationLoaded('activeReservations')
            ? $this->activeReservations
                ->whereNotNull('client_project_id')
                ->sortBy([['held_at', 'asc'], ['id', 'asc']])
                ->values()
            : $this->activeReservations()
                ->whereNotNull('client_project_id')
                ->orderBy('held_at')
                ->orderBy('id')
                ->get();

        if ($this->sale_status !== SaleStatus::Reserved || $this->reserved_project_id === null) {
            return $holds->toBase();
        }

        // The deposit holder outranks the backups regardless of when they held.
        [$holder, $backups] = $holds->partition(
            fn (Reservation $r) => (int) $r->client_project_id === (int) $this->reserved_project_id,
        );

        return $holder->concat($backups)->values()->toBase();
    }

    /**
     * The queue entries a sale to $buyerProjectId would cancel — every queued
     * project except the buyer, with the place each holds right now. Snapshot
     * this BEFORE releasing any hold: it feeds the "your client was 2nd in
     * line" cancellation notice (BackupHoldsCancelled).
     *
     * @return list<array{client_project_id: int, position: int}>
     */
    public function queuedProjectsExcept(int $buyerProjectId): array
    {
        $cancelled = [];

        foreach ($this->reservationQueue()->values() as $index => $hold) {
            if ((int) $hold->client_project_id !== $buyerProjectId) {
                $cancelled[] = [
                    'client_project_id' => (int) $hold->client_project_id,
                    'position' => $index + 1,
                ];
            }
        }

        return $cancelled;
    }

    /** Distinct client projects holding this unit — the "Interested N" counter. */
    public function interestedCount(): int
    {
        return (int) $this->reservations()
            ->where('hold_status', HoldStatus::Active->value)
            ->whereNotNull('client_project_id')
            ->distinct()
            ->count('client_project_id');
    }

    /**
     * Return a unit to the market after a hold ends (deal lost / deposit lapsed):
     * clear the Reserved deposit lock and fall back to interested if backups
     * remain, else available. Never touches a sold unit (that reversal is
     * ReleaseWonDealUnit).
     */
    public function revertToMarket(): void
    {
        if ($this->sale_status === SaleStatus::Sold) {
            return;
        }

        $this->update([
            'reserved_expires_at' => null,
            'reserved_project_id' => null,
            'sale_status' => $this->hasActiveHold()
                ? SaleStatus::Interested->value
                : SaleStatus::Available->value,
        ]);
    }

    /** Units currently offered for sale. */
    public function scopeSaleStatus(Builder $query, string $status): Builder
    {
        return $query->where('sale_status', $status);
    }
}
